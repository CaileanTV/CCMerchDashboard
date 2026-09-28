<?php
/**
 * Cycle state of one order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The stored cycle events of an order, read as state (ADR-038, docs/DATABASE.md):
 * the latest cycle is the highest cycle number with any event; it is active
 * while it has no `close` event. Only reads events; it decides nothing about
 * the ledger.
 */
final readonly class SalesCycleState {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<SalesCycleEvent> $events
	 *
	 * @param int               $order_id WooCommerce order.
	 * @param SalesCycleEvent[] $events   The order's cycle events.
	 * @throws InvalidArgumentException When an event belongs to another order.
	 */
	public function __construct( public int $order_id, public array $events ) {
		foreach ( $events as $event ) {
			if ( $event->order_id !== $order_id ) {
				throw new InvalidArgumentException( 'Sales cycle event of another order.' );
			}
		}
	}

	/**
	 * Highest cycle number of the order; 0 when it never had a cycle.
	 */
	public function latest_cycle(): int {
		$latest = 0;

		foreach ( $this->events as $event ) {
			$latest = max( $latest, $event->cycle );
		}

		return $latest;
	}

	/**
	 * The open cycle, or null when there is none (never opened, or closed).
	 */
	public function active_cycle(): ?int {
		$latest = $this->latest_cycle();

		return 0 !== $latest && $this->has( $latest, SalesCycleEventType::Open ) && ! $this->has( $latest, SalesCycleEventType::Close ) ? $latest : null;
	}

	/**
	 * Number the next cycle gets.
	 */
	public function next_cycle(): int {
		return $this->latest_cycle() + 1;
	}

	/**
	 * Whether a cycle has a `close` event.
	 *
	 * @param int $cycle Cycle number.
	 */
	public function is_closed( int $cycle ): bool {
		return $this->has( $cycle, SalesCycleEventType::Close );
	}

	/**
	 * Whether the cycle has the event.
	 *
	 * @param int                 $cycle Cycle number.
	 * @param SalesCycleEventType $type  Event.
	 */
	public function has( int $cycle, SalesCycleEventType $type ): bool {
		foreach ( $this->events as $event ) {
			if ( $event->cycle === $cycle && $event->event === $type ) {
				return true;
			}
		}

		return false;
	}
}
