<?php
/**
 * JobQueue on WooCommerce's bundled Action Scheduler.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use ActionScheduler_Action;
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

	/**
	 * Whether a job with this hook is pending or running (any arguments).
	 *
	 * @param string $hook Job hook.
	 */
	public function has_any( string $hook ): bool {
		return $this->available() && as_has_scheduled_action( $hook, null, self::GROUP );
	}

	/**
	 * The arguments of the latest failed or cancelled job with this hook, if
	 * no job with it completed later (action ids only grow).
	 *
	 * @param string $hook Job hook.
	 * @return array<mixed>|null
	 */
	public function resumable( string $hook ): ?array {
		if ( ! $this->available() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		$latest = static fn( array $statuses ): array => as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => $statuses,
				'orderby'  => 'action_id',
				'order'    => 'DESC',
				'per_page' => 1,
			)
		);

		$stopped = $latest( array( 'failed', 'canceled' ) );

		if ( array() === $stopped ) {
			return null;
		}

		$stopped_id = (int) array_key_first( $stopped );
		$done       = $latest( array( 'complete' ) );

		if ( array() !== $done && (int) array_key_first( $done ) > $stopped_id ) {
			return null;
		}

		$action = $stopped[ $stopped_id ];

		return $action instanceof ActionScheduler_Action ? $action->get_args() : null;
	}

	/**
	 * Cancels every pending job with this hook (Action Scheduler cannot
	 * cancel a running one).
	 *
	 * @param string $hook Job hook.
	 */
	public function cancel_all( string $hook ): void {
		if ( ! $this->available() || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		try {
			as_unschedule_all_actions( $hook );
		} catch ( Throwable ) {
			return;
		}
	}
}
