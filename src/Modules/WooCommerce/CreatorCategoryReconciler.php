<?php
/**
 * Category-based product ownership reconciliation (ADR-033).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Brings a product's category-managed link in line with the creator ↔
 * category mappings (ADR-033). State-based and idempotent: the result depends
 * only on the product's current direct categories, the mappings, the
 * creators' status and the current link, so running it again changes
 * nothing. Called by the queued jobs (CategoryReconciliationJobs), the batch
 * runs (CategoryReconciliationBatch) and, for evaluation only, the admin
 * previews.
 *
 * Per parent product (variations and trash/auto-draft are left alone):
 * - A manual link (`admin`, or a link without origin) or an invalid link is
 *   never touched (skipped_manual). A dry run (plan_product()) also reports
 *   whether it deviates from the categories (ManualLinkConflict).
 * - The relevant creators are the distinct creators mapped to ANY of the
 *   product's direct `product_cat` terms, not only the category that
 *   triggered the run. Child categories do not count; unmapped categories
 *   are ignored.
 * - None: a category link is removed (trigger's unlink reason).
 * - Two or more: ambiguous. No link is created and a category link is
 *   removed (reason `ambiguous`); nothing is chosen by order or history.
 * - Exactly one creator C:
 *   - the category link is already C: unchanged (also while C is inactive);
 *   - C is inactive: no new link; a category link to another creator is
 *     removed (trigger's unlink reason);
 *   - no link: link to C (trigger's link reason);
 *   - a category link to another creator: moved to C (`category_moved`).
 *
 * Every write goes through ProductCreatorLink::apply_category_link(), i.e. a
 * ProtectedMetaWrite for exactly that product with the audit records in one
 * transaction. A product that fails is logged and counted as an error; the
 * run continues. Mappings and creators are only read.
 *
 * The decision is made by one read-only evaluation (a ReconciliationPlan):
 * plan_product() returns it for a dry run, reconcile_product() applies it.
 * Full runs over every relevant product are CategoryReconciliationBatch's.
 */
final class CreatorCategoryReconciler {

	/**
	 * Products per page of a category run.
	 */
	private const PAGE = 100;

	/**
	 * Post statuses the reconciliation leaves alone (ProductCreatorLink).
	 */
	private const SKIPPED_STATUSES = array( 'trash', 'auto-draft' );

	/**
	 * Constructor.
	 *
	 * @param ProductStore       $products Product reads.
	 * @param MappingLookup      $mappings Mapping reads.
	 * @param CreatorRepository  $creators Creator reads.
	 * @param ProductCreatorLink $links    The only writer of the link.
	 * @param Logger             $logger   Technical log for failed products.
	 */
	public function __construct(
		private ProductStore $products,
		private MappingLookup $mappings,
		private CreatorRepository $creators,
		private ProductCreatorLink $links,
		private Logger $logger
	) {
	}

	/**
	 * Reconciles every parent product directly in the category, page by page.
	 * Each product is judged by all its mapped categories.
	 *
	 * Products that left the category are not found this way; they are
	 * reconciled per product (reconcile_product()) or by a full run
	 * (CategoryReconciliationBatch).
	 *
	 * @param int                           $term_id         `product_cat` term id.
	 * @param CategoryReconciliationTrigger $trigger         Why the run happens (audit reasons).
	 * @param int|null                      $trigger_user_id User who triggered the run, if known.
	 */
	public function reconcile_category( int $term_id, CategoryReconciliationTrigger $trigger, ?int $trigger_user_id = null ): CategoryReconciliationResult {
		$result = CategoryReconciliationResult::empty();

		if ( $term_id < 1 ) {
			return $result;
		}

		$offset = 0;

		do {
			$ids  = $this->products->in_category( $term_id, self::PAGE, $offset );
			$page = count( $ids );

			foreach ( $ids as $product_id ) {
				$result = $result->with( $this->reconcile_product( $product_id, $trigger, $trigger_user_id ) );
			}

			$offset += self::PAGE;
		} while ( self::PAGE === $page );

		return $result;
	}

	/**
	 * Reconciles one product: evaluates it (plan_product()) and applies a
	 * change through ProductCreatorLink::apply_category_link().
	 *
	 * @param int                           $product_id      Product id.
	 * @param CategoryReconciliationTrigger $trigger         Why the run happens (audit reasons).
	 * @param int|null                      $trigger_user_id User who triggered the run, if known.
	 * @param ReconciliationRunId|null      $run_id          Batch run, if any (audit context).
	 */
	public function reconcile_product( int $product_id, CategoryReconciliationTrigger $trigger, ?int $trigger_user_id = null, ?ReconciliationRunId $run_id = null ): ReconciliationOutcome {
		try {
			$plan = $this->evaluate( $product_id, $trigger, $trigger_user_id, $run_id );

			if ( $plan->writes ) {
				$this->links->apply_category_link( $plan->product_id, $plan->to, $plan->reason, $plan->context );
			}

			return $plan->outcome;
		} catch ( ProductCreatorLinkException | DatabaseException $e ) {
			$this->log_failure( $product_id, $e, $run_id );

			return ReconciliationOutcome::Error;
		}
	}

	/**
	 * What reconcile_product() would do, without writing anything (dry run).
	 * Same evaluation; a product that cannot be read is an error plan. A kept
	 * manual link additionally carries its conflict with the categories, if
	 * any (manual_plan()); the outcome stays skipped_manual.
	 *
	 * @param int                           $product_id      Product id.
	 * @param CategoryReconciliationTrigger $trigger         Why the run happens (audit reasons).
	 * @param int|null                      $trigger_user_id User who triggered the run, if known.
	 * @param ReconciliationRunId|null      $run_id          Batch run, if any.
	 */
	public function plan_product( int $product_id, CategoryReconciliationTrigger $trigger, ?int $trigger_user_id = null, ?ReconciliationRunId $run_id = null ): ReconciliationPlan {
		try {
			return $this->evaluate( $product_id, $trigger, $trigger_user_id, $run_id, true );
		} catch ( DatabaseException $e ) {
			$this->log_failure( $product_id, $e, $run_id );

			return ReconciliationPlan::failed( $product_id );
		}
	}

	/**
	 * The state machine of the class comment. Reads only.
	 *
	 * @param int                           $product_id      Product id.
	 * @param CategoryReconciliationTrigger $trigger         Trigger.
	 * @param int|null                      $trigger_user_id Triggering user.
	 * @param ReconciliationRunId|null      $run_id          Batch run.
	 * @param bool                          $diagnose        Whether to classify a kept manual link's conflict (dry runs only).
	 * @throws DatabaseException When a read fails.
	 */
	private function evaluate( int $product_id, CategoryReconciliationTrigger $trigger, ?int $trigger_user_id, ?ReconciliationRunId $run_id, bool $diagnose = false ): ReconciliationPlan {
		$product = $product_id > 0 ? $this->products->find( $product_id ) : null;

		if ( null === $product || $product->is_variation || in_array( $product->status, self::SKIPPED_STATUSES, true ) ) {
			return ReconciliationPlan::keep( $product_id, ReconciliationOutcome::Unchanged );
		}

		if ( $product->has_manual_link() ) {
			return $diagnose ? $this->manual_plan( $product ) : ReconciliationPlan::keep( $product->id, ReconciliationOutcome::SkippedManual );
		}

		$mapped  = $this->mapped_creators( $product->category_ids );
		$current = $product->creator_id();
		$context = array( 'category_ids' => array_keys( $mapped ) );

		if ( null !== $trigger_user_id && $trigger_user_id > 0 ) {
			$context['trigger_user_id'] = $trigger_user_id;
		}

		if ( null !== $run_id ) {
			$context['run_id'] = $run_id->value;
		}

		$creators = array_values( array_unique( $mapped ) );
		$remove   = static fn( ReconciliationOutcome $outcome, string $reason ): ReconciliationPlan => ReconciliationPlan::change( $product->id, $outcome, $current, null, $reason, $context );

		if ( array() === $creators ) {
			return null === $current
				? ReconciliationPlan::keep( $product->id, ReconciliationOutcome::Unchanged )
				: $remove( ReconciliationOutcome::Unlinked, $trigger->unlink_reason() );
		}

		if ( count( $creators ) > 1 ) {
			return null === $current
				? ReconciliationPlan::keep( $product->id, ReconciliationOutcome::Ambiguous )
				: $remove( ReconciliationOutcome::Ambiguous, 'ambiguous' );
		}

		$creator_id = $creators[0];

		if ( $current === $creator_id ) {
			return ReconciliationPlan::keep( $product->id, ReconciliationOutcome::Unchanged );
		}

		if ( CreatorStatus::Active !== $this->creators->find( $creator_id )?->status ) {
			return null === $current
				? ReconciliationPlan::keep( $product->id, ReconciliationOutcome::SkippedInactive )
				: $remove( ReconciliationOutcome::Unlinked, $trigger->unlink_reason() );
		}

		return null === $current
			? ReconciliationPlan::change( $product->id, ReconciliationOutcome::Linked, null, $creator_id, $trigger->link_reason(), $context )
			: ReconciliationPlan::change( $product->id, ReconciliationOutcome::Moved, $current, $creator_id, 'category_moved', $context );
	}

	/**
	 * A kept manual link (`admin`, no or an unknown origin, or an invalid
	 * link) and how it deviates from the product's direct creator categories
	 * (ADR-033 point 3 and 8). The link is never changed; the result is
	 * diagnostic only:
	 * - no mapped creator, or only the linked one (active or not): no conflict;
	 * - two or more mapped creators: ambiguous;
	 * - exactly one other creator: other creator, or inactive creator when it
	 *   is inactive.
	 *
	 * @param ProductRecord $product Product with a manual link.
	 * @throws DatabaseException When a read fails.
	 */
	private function manual_plan( ProductRecord $product ): ReconciliationPlan {
		$current  = $product->creator_id();
		$creators = array_values( array_unique( $this->mapped_creators( $product->category_ids ) ) );
		sort( $creators );

		if ( array() === $creators || array( $current ) === $creators ) {
			return ReconciliationPlan::keep( $product->id, ReconciliationOutcome::SkippedManual );
		}

		if ( count( $creators ) > 1 ) {
			return ReconciliationPlan::manual_conflict( $product->id, $current, ManualLinkConflict::Ambiguous, $creators );
		}

		$conflict = CreatorStatus::Active === $this->creators->find( $creators[0] )?->status ? ManualLinkConflict::OtherCreator : ManualLinkConflict::InactiveCreator;

		return ReconciliationPlan::manual_conflict( $product->id, $current, $conflict, $creators );
	}

	/**
	 * Logs a product that could not be reconciled (never swallowed silently).
	 *
	 * @param int                      $product_id Product id.
	 * @param Throwable                $e          Failure.
	 * @param ReconciliationRunId|null $run_id     Batch run.
	 */
	private function log_failure( int $product_id, Throwable $e, ?ReconciliationRunId $run_id ): void {
		$context = array(
			'product_id' => $product_id,
			'reason'     => $e instanceof ProductCreatorLinkException ? $e->reason() : 'database',
		);

		if ( null !== $run_id ) {
			$context['run_id'] = $run_id->value;
		}

		$this->logger->warning( 'Category reconciliation of a product failed.', $context );
	}

	/**
	 * Creators mapped to the given categories, by term id (unmapped terms left out).
	 *
	 * @phpstan-param list<int> $category_ids
	 *
	 * @param int[] $category_ids Direct `product_cat` term ids.
	 * @return array<int, int> Term id => creator id.
	 * @throws DatabaseException When a mapping cannot be read.
	 */
	private function mapped_creators( array $category_ids ): array {
		$mapped = array();

		foreach ( $category_ids as $term_id ) {
			$mapping = $term_id > 0 ? $this->mappings->find( $term_id ) : null;

			if ( null !== $mapping ) {
				$mapped[ $mapping->term_id ] = $mapping->creator_id;
			}
		}

		ksort( $mapped );

		return $mapped;
	}
}
