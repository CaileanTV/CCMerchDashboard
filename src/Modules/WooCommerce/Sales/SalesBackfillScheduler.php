<?php
/**
 * Queues the pages of a sales ledger backfill.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Starts, continues and resumes backfill runs on the existing JobQueue
 * (Action Scheduler, group `merchboard`; ADR-038, status note "ROADMAP trin
 * 7", D6). One job = one page; the job's payload is the run's cursor
 * (SalesBackfillRun).
 *
 * - Only one run at a time: no new run and no resume while a backfill job is
 *   pending or running.
 * - No automatic retry: a page that fails leaves its job failed with the
 *   unchanged cursor, and resume() queues that payload again when asked.
 *   The same applies to a run whose jobs were cancelled (deactivation).
 */
final class SalesBackfillScheduler {

	/**
	 * The Action Scheduler hook of a backfill page. Core's lifecycle cancels
	 * it by this name (a test keeps Core\Lifecycle\ScheduledJobs in step).
	 */
	public const HOOK = 'merchboard_sales_backfill';

	public const STARTED           = 'started';
	public const RESUMED           = 'resumed';
	public const ALREADY_RUNNING   = 'already_running';
	public const NOTHING_TO_RESUME = 'nothing_to_resume';
	public const QUEUE_UNAVAILABLE = 'queue_unavailable';
	public const NOT_QUEUED        = 'not_queued';

	/**
	 * Constructor.
	 *
	 * @param JobQueue             $queue   Background queue.
	 * @param CountedOrderStatuses $counted Statuses of a new run.
	 */
	public function __construct( private JobQueue $queue, private CountedOrderStatuses $counted ) {
	}

	/**
	 * Starts a new run with the counted statuses of now.
	 *
	 * @param int      $batch_size      Orders per page.
	 * @param int|null $trigger_user_id User who starts it, if known.
	 * @return array{0: string, 1: SalesBackfillRun|null} [outcome, run]
	 * @throws InvalidArgumentException When the page size is invalid.
	 */
	public function start( int $batch_size, ?int $trigger_user_id ): array {
		$run = SalesBackfillRun::start( $this->counted->statuses(), $batch_size, $trigger_user_id );

		if ( ! $this->queue->available() ) {
			return array( self::QUEUE_UNAVAILABLE, null );
		}

		if ( $this->active() ) {
			return array( self::ALREADY_RUNNING, null );
		}

		return $this->queue( $run ) ? array( self::STARTED, $run ) : array( self::NOT_QUEUED, null );
	}

	/**
	 * Queues the stopped run's last page again, from its unchanged cursor.
	 *
	 * @return array{0: string, 1: SalesBackfillRun|null} [outcome, run]
	 */
	public function resume(): array {
		if ( ! $this->queue->available() ) {
			return array( self::QUEUE_UNAVAILABLE, null );
		}

		if ( $this->active() ) {
			return array( self::ALREADY_RUNNING, null );
		}

		$run = $this->resumable();

		if ( null === $run ) {
			return array( self::NOTHING_TO_RESUME, null );
		}

		return $this->queue( $run ) ? array( self::RESUMED, $run ) : array( self::NOT_QUEUED, null );
	}

	/**
	 * Queues the next page of a run.
	 *
	 * @param SalesBackfillRun $run Run after the processed page.
	 */
	public function continue_run( SalesBackfillRun $run ): bool {
		return $this->queue( $run );
	}

	/**
	 * Whether a backfill job is pending or running.
	 */
	public function active(): bool {
		return $this->queue->has_any( self::HOOK );
	}

	/**
	 * The run that stopped (failed or cancelled page) and was not finished
	 * since, if any.
	 */
	public function resumable(): ?SalesBackfillRun {
		$args = $this->queue->resumable( self::HOOK );

		try {
			return null === $args ? null : SalesBackfillRun::from_array( $args[0] ?? null );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * Queues one page.
	 *
	 * @param SalesBackfillRun $run Run before the page.
	 */
	private function queue( SalesBackfillRun $run ): bool {
		return $this->queue->enqueue( self::HOOK, array( $run->to_array() ) );
	}
}
