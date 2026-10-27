<?php
/**
 * Runs the queued category reconciliation jobs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use InvalidArgumentException;
use MerchBoard\Core\Logging\Logger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callbacks of CategoryReconciliationScheduler's jobs
 * (ADR-033). They only call the existing services: CreatorCategoryReconciler
 * for one product, CategoryReconciliationBatch::run_page() for one page of a
 * run. No capability check: this is system work (ADR-033 point 7).
 *
 * Retry (owner decision, step 9b): a failed job is repeated at most
 * MAX_RETRIES times, after RETRY_DELAYS seconds, with the same payload (for a
 * run: the same run id, cursor and counts), then given up and logged. A run
 * that is given up writes no `category_sync_completed`. Repeats are safe: the
 * reconciliation is state-based and idempotent and never touches manual links.
 * A failed page is also rethrown, so Action Scheduler marks that action failed.
 * An invalid payload is logged and dropped (never retried).
 */
final class CategoryReconciliationJobs {

	/**
	 * Repeats after the first attempt.
	 */
	public const MAX_RETRIES = 3;

	/**
	 * Delay in seconds before repeat 1, 2 and 3.
	 */
	public const RETRY_DELAYS = array( 60, 300, 900 );

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryReconciler       $reconciler One product.
	 * @param CategoryReconciliationBatch     $batch      One page of a run.
	 * @param CategoryReconciliationScheduler $scheduler  Follow-up pages and repeats.
	 * @param Logger                          $logger     Technical log.
	 */
	public function __construct(
		private CreatorCategoryReconciler $reconciler,
		private CategoryReconciliationBatch $batch,
		private CategoryReconciliationScheduler $scheduler,
		private Logger $logger
	) {
	}

	/**
	 * Hooks the job callbacks (every request: Action Scheduler runs jobs from
	 * WP-Cron, async requests and WP-CLI).
	 */
	public function register(): void {
		add_action( CategoryReconciliationScheduler::PRODUCT_HOOK, array( $this, 'run_product' ), 10, 1 );
		add_action( CategoryReconciliationScheduler::RUN_HOOK, array( $this, 'run_page' ), 10, 1 );
	}

	/**
	 * Reconciles one product. A product that could not be reconciled
	 * (outcome `errors`, already logged) is repeated.
	 *
	 * @param mixed $payload Job payload.
	 */
	public function run_product( mixed $payload ): void {
		$product_id = is_array( $payload ) ? ( $payload['product_id'] ?? null ) : null;
		$trigger    = is_array( $payload ) && is_string( $payload['trigger'] ?? null ) ? CategoryReconciliationTrigger::tryFrom( $payload['trigger'] ) : null;
		$user_id    = is_array( $payload ) ? ( $payload['trigger_user_id'] ?? null ) : null;
		$attempt    = is_array( $payload ) ? ( $payload['attempt'] ?? null ) : null;

		if ( ! is_int( $product_id ) || $product_id < 1 || null === $trigger || ( null !== $user_id && ! is_int( $user_id ) ) || ! is_int( $attempt ) || $attempt < 0 ) {
			$this->logger->error( 'Invalid category reconciliation job dropped.', array( 'job' => CategoryReconciliationScheduler::PRODUCT_HOOK ) );

			return;
		}

		$outcome = $this->reconciler->reconcile_product( $product_id, $trigger, $user_id );

		if ( ReconciliationOutcome::Error !== $outcome ) {
			return;
		}

		if ( $attempt >= self::MAX_RETRIES ) {
			$this->logger->error(
				'Category reconciliation of a product given up after retries.',
				array(
					'product_id' => $product_id,
					'attempts'   => $attempt + 1,
				)
			);

			return;
		}

		$this->scheduler->retry_product( $product_id, $trigger, $user_id, $attempt + 1, self::RETRY_DELAYS[ $attempt ] );
	}

	/**
	 * Runs one page of a run and queues the next one, or repeats the page
	 * after a failure.
	 *
	 * @param mixed $payload Job payload.
	 * @throws Throwable When the page fails (after queuing a repeat, if any is left).
	 */
	public function run_page( mixed $payload ): void {
		try {
			[ $state, $attempt ] = self::state( $payload );
		} catch ( InvalidArgumentException ) {
			$this->logger->error( 'Invalid category reconciliation job dropped.', array( 'job' => CategoryReconciliationScheduler::RUN_HOOK ) );

			return;
		}

		try {
			$next = $this->batch->run_page( $state );
		} catch ( Throwable $e ) {
			$context = array(
				'run_id'   => $state->run_id->value,
				'after_id' => $state->after_id,
				'attempts' => $attempt + 1,
			);

			if ( $attempt < self::MAX_RETRIES ) {
				$this->scheduler->continue_run( $state, $attempt + 1, self::RETRY_DELAYS[ $attempt ] );
				$this->logger->warning( 'Category reconciliation page failed; repeat queued.', $context );
			} else {
				$this->logger->error( 'Category reconciliation run given up after retries; not completed.', $context );
				do_action( 'merchboard_notify', 'admin_reconciliation_failed', null, array( 'run' => substr( (string) $state->run_id->value, 0, 12 ) ), 'run:' . $state->run_id->value );
			}

			throw $e;
		}

		if ( ! $next->complete ) {
			$this->scheduler->continue_run( $next );
		}
	}

	/**
	 * The run state and attempt of a run job; a first job starts a new run.
	 *
	 * @param mixed $payload Job payload.
	 * @return array{CategorySyncPage, int}
	 * @throws InvalidArgumentException When the payload is invalid.
	 */
	private static function state( mixed $payload ): array {
		$attempt = is_array( $payload ) ? ( $payload['attempt'] ?? null ) : null;

		if ( ! is_array( $payload ) || ! is_int( $attempt ) || $attempt < 0 ) {
			throw new InvalidArgumentException( 'Invalid run job.' );
		}

		if ( is_array( $payload['run'] ?? null ) ) {
			return array( CategorySyncPage::from_array( $payload['run'] ), $attempt );
		}

		$trigger = is_string( $payload['trigger'] ?? null ) ? CategoryReconciliationTrigger::tryFrom( $payload['trigger'] ) : null;
		$user_id = $payload['trigger_user_id'] ?? null;

		if ( null !== ( $payload['run'] ?? null ) || ! is_array( $payload['scope'] ?? null ) || null === $trigger || ( null !== $user_id && ! is_int( $user_id ) ) ) {
			throw new InvalidArgumentException( 'Invalid run job.' );
		}

		return array( CategorySyncPage::start( ReconciliationScope::from_array( $payload['scope'] ), $trigger, $user_id ), $attempt );
	}
}
