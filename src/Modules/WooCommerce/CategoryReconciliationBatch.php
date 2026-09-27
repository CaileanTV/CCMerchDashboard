<?php
/**
 * Category reconciliation in batches, with dry run.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use LogicException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Reconciles the products of a ReconciliationScope (ReconciliationCandidates)
 * with CreatorCategoryReconciler's rules, page by page (ADR-033).
 *
 * - One ReconciliationRunId per run, carried to every product audit record
 *   and to `category_sync_completed`.
 * - Dry run (preview()): the same evaluation, nothing written, no audit at
 *   all; returns the counts, the expected changes and the products that
 *   need attention (including manual links that deviate from their
 *   categories; those still count as skipped_manual).
 * - Real run: execute() runs every page now; run_page() runs one page, so a
 *   run can be spread over Action Scheduler jobs (CategoryReconciliationJobs)
 *   that carry the run id, cursor and counts. Each change goes through
 *   ProductCreatorLink::apply_category_link() (ProtectedMetaWrite and audit in
 *   one transaction per product).
 *
 * Failure semantics: a product that fails is rolled back on its own (its
 * transaction), logged with the run id and counted under `errors`; the run
 * continues. A run is complete when its last page was processed; only then is
 * `category_sync_completed` written (actor NULL), with the error count. If a
 * page cannot be read, the paging misbehaves or anything unexpected is thrown,
 * the page is logged and the exception is rethrown: no completed record.
 * Products reconciled before that keep their (individually atomic) result;
 * repeating the page from the same cursor, or a new run, picks up the rest
 * (state-based, idempotent).
 */
final class CategoryReconciliationBatch {

	/**
	 * Candidates per page.
	 */
	public const PAGE = 100;

	/**
	 * Expected changes listed in a dry-run report.
	 */
	public const MAX_CHANGES = 500;

	/**
	 * Constructor.
	 *
	 * @param ReconciliationCandidates  $candidates Products to look at.
	 * @param CreatorCategoryReconciler $reconciler The ownership rules.
	 * @param AuditTrail                $audit      For `category_sync_completed`.
	 * @param Logger                    $logger     Technical log for aborted pages.
	 */
	public function __construct(
		private ReconciliationCandidates $candidates,
		private CreatorCategoryReconciler $reconciler,
		private AuditTrail $audit,
		private Logger $logger
	) {
	}

	/**
	 * Dry run: what a real run would do now. Writes nothing (no product
	 * data, no audit).
	 *
	 * @param CategoryReconciliationTrigger $trigger         Why the run happens.
	 * @param int|null                      $trigger_user_id User who started it, if known.
	 * @param ReconciliationScope|null      $scope           Which products (default: all).
	 * @throws DatabaseException When a page of candidates cannot be read.
	 * @throws LogicException When the paging does not advance.
	 */
	public function preview( CategoryReconciliationTrigger $trigger = CategoryReconciliationTrigger::FullSync, ?int $trigger_user_id = null, ?ReconciliationScope $scope = null ): CategorySyncReport {
		$scope          = $scope ?? ReconciliationScope::all();
		$run_id         = ReconciliationRunId::generate();
		$result         = CategoryReconciliationResult::empty();
		$changes        = array();
		$more           = false;
		$attention      = array();
		$more_attention = false;
		$after          = 0;
		$pages          = 0;

		do {
			$count = $this->page(
				$scope,
				$run_id,
				$after,
				$pages,
				function ( int $product_id ) use ( $trigger, $trigger_user_id, $run_id, &$result, &$changes, &$more, &$attention, &$more_attention ): void {
					$plan   = $this->reconciler->plan_product( $product_id, $trigger, $trigger_user_id, $run_id );
					$result = $result->with( $plan->outcome );

					if ( $plan->writes ) {
						if ( count( $changes ) < self::MAX_CHANGES ) {
							$changes[] = $plan->to_array();
						} else {
							$more = true;
						}
					}

					if ( null !== $plan->conflict || in_array( $plan->outcome, CategorySyncReport::ATTENTION, true ) ) {
						if ( count( $attention ) < self::MAX_CHANGES ) {
							$attention[] = self::attention_entry( $plan );
						} else {
							$more_attention = true;
						}
					}
				}
			);
		} while ( self::PAGE === $count );

		return new CategorySyncReport( $run_id, true, $trigger, $scope, $result, $pages, $changes, $more, $attention, $more_attention );
	}

	/**
	 * Real run of every page now, then `category_sync_completed`.
	 *
	 * @param CategoryReconciliationTrigger $trigger         Why the run happens (audit reasons).
	 * @param int|null                      $trigger_user_id User who started it, if known.
	 * @param ReconciliationScope|null      $scope           Which products (default: all).
	 * @throws DatabaseException When a page cannot be read or the completed record cannot be written.
	 * @throws LogicException When the paging does not advance.
	 */
	public function execute( CategoryReconciliationTrigger $trigger = CategoryReconciliationTrigger::FullSync, ?int $trigger_user_id = null, ?ReconciliationScope $scope = null ): CategorySyncReport {
		$page = new CategorySyncPage( ReconciliationRunId::generate(), $scope ?? ReconciliationScope::all(), $trigger, $trigger_user_id, CategoryReconciliationResult::empty(), 0, 0, false );

		do {
			$page = $this->run_page( $page );
		} while ( ! $page->complete );

		return new CategorySyncReport( $page->run_id, false, $trigger, $page->scope, $page->result, $page->pages );
	}

	/**
	 * Real run of the next page after $state's cursor. When it is the last
	 * page, records `category_sync_completed` with the counts of the whole run.
	 *
	 * @param CategorySyncPage $state Run so far (cursor, counts, pages).
	 * @return CategorySyncPage The run after this page.
	 * @throws DatabaseException When the page cannot be read or the completed record cannot be written.
	 * @throws LogicException When the paging does not advance or the run is already complete.
	 */
	public function run_page( CategorySyncPage $state ): CategorySyncPage {
		self::assert_open( $state );

		$result = $state->result;
		$after  = $state->after_id;
		$pages  = $state->pages;

		$count = $this->page(
			$state->scope,
			$state->run_id,
			$after,
			$pages,
			function ( int $product_id ) use ( $state, &$result ): void {
				$result = $result->with( $this->reconciler->reconcile_product( $product_id, $state->trigger, $state->trigger_user_id, $state->run_id ) );
			}
		);

		$next = new CategorySyncPage( $state->run_id, $state->scope, $state->trigger, $state->trigger_user_id, $result, $after, $pages, self::PAGE !== $count );

		if ( $next->complete ) {
			$report = new CategorySyncReport( $next->run_id, false, $next->trigger, $next->scope, $next->result, $next->pages );
			$this->audit->record_system( AuditAction::CategorySyncCompleted, null, $report->audit_context( $next->trigger_user_id ) );
		}

		return $next;
	}

	/**
	 * Refuses to continue a completed run.
	 *
	 * @param CategorySyncPage $state Run state.
	 * @throws LogicException When the run is already complete.
	 */
	private static function assert_open( CategorySyncPage $state ): void {
		if ( $state->complete ) {
			throw new LogicException( 'The reconciliation run is already complete.' );
		}
	}

	/**
	 * A "needs attention" entry of a dry run. A manual link that deviates
	 * from its categories also names the conflict, the linked creator and the
	 * mapped creators; its outcome stays skipped_manual.
	 *
	 * @param ReconciliationPlan $plan Plan that needs attention.
	 * @return array{product_id: int, outcome: string, conflict?: string, creator_id?: int|null, mapped_creator_ids?: list<int>}
	 */
	private static function attention_entry( ReconciliationPlan $plan ): array {
		$entry = array(
			'product_id' => $plan->product_id,
			'outcome'    => $plan->outcome->value,
		);

		if ( null !== $plan->conflict ) {
			$entry['conflict']           = $plan->conflict->value;
			$entry['creator_id']         = $plan->from;
			$entry['mapped_creator_ids'] = $plan->mapped;
		}

		return $entry;
	}

	/**
	 * Calls $each for every candidate of the page after $after (keyset by id)
	 * and moves the cursor. Anything unexpected is logged with the run id and
	 * rethrown.
	 *
	 * @param ReconciliationScope $scope  Which products.
	 * @param ReconciliationRunId $run_id Run id (for the log).
	 * @param int                 $after  Cursor; set to the last processed id.
	 * @param int                 $pages  Pages so far; incremented for a non-empty page.
	 * @param callable(int): void $each   Per product.
	 * @return int Number of candidates on the page.
	 * @throws LogicException When the paging does not advance.
	 * @throws Throwable Rethrown after logging: a DatabaseException when a page cannot be read, or anything $each throws.
	 */
	private function page( ReconciliationScope $scope, ReconciliationRunId $run_id, int &$after, int &$pages, callable $each ): int {
		try {
			$ids   = $this->candidates->after( $scope, $after, self::PAGE );
			$count = count( $ids );

			foreach ( $ids as $product_id ) {
				// Ascending and after the previous id: never twice, never looping.
				if ( $product_id <= $after ) {
					throw new LogicException( 'Reconciliation candidates did not advance.' );
				}

				$each( $product_id );
				$after = $product_id;
			}

			if ( $count > 0 ) {
				++$pages;
			}

			return $count;
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Category reconciliation run aborted.',
				array(
					'run_id'    => $run_id->value,
					'pages'     => $pages,
					'after_id'  => $after,
					'exception' => $e,
				)
			);

			throw $e;
		}
	}
}
