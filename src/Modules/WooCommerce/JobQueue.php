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

	/**
	 * Whether any job with this hook is pending or running, whatever its
	 * arguments.
	 *
	 * @param string $hook Job hook.
	 */
	public function has_any( string $hook ): bool;

	/**
	 * The arguments of the latest failed or cancelled job with this hook, if
	 * no job with the hook completed after it (a run that stopped and can be
	 * resumed); null otherwise.
	 *
	 * @param string $hook Job hook.
	 * @return array<mixed>|null
	 */
	public function resumable( string $hook ): ?array;

	/**
	 * Cancels every pending job with this hook. A running job cannot be
	 * cancelled; it finishes.
	 *
	 * @param string $hook Job hook.
	 */
	public function cancel_all( string $hook ): void;
}
