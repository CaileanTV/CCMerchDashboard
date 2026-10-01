<?php
/**
 * Runs the queued sales ledger backfill pages.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Logging\Logger;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback of SalesBackfillScheduler's jobs (ADR-038, status
 * note "ROADMAP trin 7", D5/D6). System work, no capability check.
 *
 * Failures, from small to large:
 * - one order: handled by the synchronization (rollback, failure marker,
 *   log), counted, and the page continues;
 * - the page (the orders cannot be read, the next page cannot be queued):
 *   logged, and the exception is rethrown so Action Scheduler marks the job
 *   failed with its unchanged cursor. No automatic retry; the run is resumed
 *   explicitly (`wp merchboard sales-backfill resume`);
 * - a system failure (e.g. WooCommerce not loaded) fails the page the same
 *   way. `sales_backfill_completed` is only written after the last page.
 *
 * An invalid payload is logged and dropped.
 */
final class SalesBackfillJobs {

	/**
	 * Constructor.
	 *
	 * @param SalesBackfill          $backfill  Pages.
	 * @param SalesBackfillScheduler $scheduler Next pages.
	 * @param AuditTrail             $audit     For `sales_backfill_completed`.
	 * @param Logger                 $logger    Technical log.
	 */
	public function __construct(
		private SalesBackfill $backfill,
		private SalesBackfillScheduler $scheduler,
		private AuditTrail $audit,
		private Logger $logger
	) {
	}

	/**
	 * Hooks the callback (every request: Action Scheduler runs jobs from
	 * WP-Cron, async requests and WP-CLI).
	 */
	public function register(): void {
		add_action( SalesBackfillScheduler::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Processes one page, then queues the next one or records the summary.
	 *
	 * @param mixed $payload Job payload (SalesBackfillRun::to_array()).
	 * @throws RuntimeException When the next page cannot be queued (the job is marked failed; resumable).
	 * @throws Throwable When the page fails (the job is marked failed; resumable).
	 */
	public function run( mixed $payload ): void {
		try {
			$run = SalesBackfillRun::from_array( $payload );
		} catch ( InvalidArgumentException ) {
			$this->logger->error( 'Invalid sales backfill job dropped.', array( 'job' => SalesBackfillScheduler::HOOK ) );

			return;
		}

		try {
			$next = $this->backfill->run_page( $run );

			if ( ! $next->complete ) {
				if ( ! $this->scheduler->continue_run( $next ) ) {
					throw new RuntimeException( 'The next sales backfill page could not be queued.' );
				}

				return;
			}

			$this->audit->record_system( AuditAction::SalesBackfillCompleted, null, self::summary( $next ) );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Sales backfill page failed; the run stopped and can be resumed.',
				array(
					'run_id' => $run->run_id->value,
					'offset' => $run->offset,
					'reason' => $e->getMessage(),
				)
			);

			throw $e;
		}
	}

	/**
	 * The audit context of a finished run: run id, the user who started it
	 * and the counts. No order ids, no amounts.
	 *
	 * @param SalesBackfillRun $run Finished run.
	 * @return array<string, mixed>
	 */
	public static function summary( SalesBackfillRun $run ): array {
		$context = array( 'run_id' => $run->run_id->value );

		if ( null !== $run->trigger_user_id ) {
			$context['trigger_user_id'] = $run->trigger_user_id;
		}

		return $context + $run->tally->to_array();
	}
}
