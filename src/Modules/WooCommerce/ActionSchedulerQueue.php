<?php
/**
 * JobQueue on WooCommerce's bundled Action Scheduler.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use Closure;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler ships with every supported WooCommerce (≥ 10.3, ADR-030
 * D1; ADR-033). Jobs go into the `merchboard` group. Before Action Scheduler
 * has initialized (`action_scheduler_init`), or without it, the queue is not
 * available and nothing is queued.
 */
final class ActionSchedulerQueue implements JobQueue {

	/**
	 * Action Scheduler group of all Merch Dashboard jobs.
	 */
	public const GROUP = 'merchboard';

	/**
	 * Availability check.
	 *
	 * @var Closure(): bool
	 */
	private Closure $available;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(): bool)|null $available
	 *
	 * @param Closure|null $available Availability check (tests); default: Action Scheduler's functions exist and it has initialized.
	 */
	public function __construct( ?Closure $available = null ) {
		$this->available = $available ?? static fn(): bool => function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_has_scheduled_action' )
			&& did_action( 'action_scheduler_init' ) > 0;
	}

	/**
	 * Whether Action Scheduler can take jobs now.
	 */
	public function available(): bool {
		return ( $this->available )();
	}

	/**
	 * Whether an identical job is pending or running.
	 *
	 * @param string       $hook Job hook.
	 * @param array<mixed> $args Job arguments.
	 */
	public function has_pending( string $hook, array $args ): bool {
		return $this->available() && as_has_scheduled_action( $hook, $args, self::GROUP );
	}

	/**
	 * Queues a job.
	 *
	 * @param string       $hook  Job hook.
	 * @param array<mixed> $args  Job arguments.
	 * @param int          $delay Seconds to wait (0: as soon as possible).
	 * @return bool Whether the job was queued.
	 */
	public function enqueue( string $hook, array $args, int $delay = 0 ): bool {
		if ( ! $this->available() ) {
			return false;
		}

		try {
			$id = $delay > 0
				? as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP )
				: as_enqueue_async_action( $hook, $args, self::GROUP );
		} catch ( Throwable ) {
			return false;
		}

		return $id > 0;
	}
}
