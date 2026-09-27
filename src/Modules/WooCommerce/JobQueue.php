<?php
/**
 * Background job queue used by the category reconciliation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The few queue operations the reconciliation needs, behind an interface so
 * the scheduling rules can be tested without Action Scheduler (ADR-033).
 * There is no synchronous or cron fallback: when the queue is not available,
 * nothing is queued.
 */
interface JobQueue {

	/**
	 * Whether jobs can be queued now.
	 */
	public function available(): bool;

	/**
	 * Whether a job with exactly this hook and these arguments is pending or
	 * running.
	 *
	 * @param string       $hook Job hook.
	 * @param array<mixed> $args Job arguments.
	 */
	public function has_pending( string $hook, array $args ): bool;

	/**
	 * Queues a job, to run as soon as possible or after a delay.
	 *
	 * @param string       $hook  Job hook.
	 * @param array<mixed> $args  Job arguments (passed to the hook's callback).
	 * @param int          $delay Seconds to wait (0: as soon as possible).
	 * @return bool Whether the job was queued.
	 */
	public function enqueue( string $hook, array $args, int $delay = 0 ): bool;
}
