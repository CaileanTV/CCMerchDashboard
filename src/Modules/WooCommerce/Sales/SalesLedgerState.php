<?php
/**
 * Stored ledger state of one order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the ledger holds for an order: its rows (by id) and its cycle
 * events. Read inside the write transaction and handed to the planner
 * (SalesRepository::write()); balances are computed by the caller.
 */
final readonly class SalesLedgerState {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<SalesEntry> $entries
	 *
	 * @param int                $order_id WooCommerce order.
	 * @param SalesEntry[]       $entries  Ledger rows of the order, by id.
	 * @param SalesCycleState    $cycles   Cycle events of the order.
	 * @param array<string, int> $taxes Stored actual tax by event key (ADR-048); rows without one have unknown tax.
	 * @throws InvalidArgumentException When a row or the cycles belong to another order.
	 */
	public function __construct( public int $order_id, public array $entries, public SalesCycleState $cycles, public array $taxes = array() ) {
		foreach ( $entries as $entry ) {
			if ( $entry->order_id !== $order_id ) {
				throw new InvalidArgumentException( 'Sales entry of another order.' );
			}
		}

		if ( $cycles->order_id !== $order_id ) {
			throw new InvalidArgumentException( 'Sales cycles of another order.' );
		}
	}

	/**
	 * Whether a row with the event key is stored.
	 *
	 * @param string $event_key Event key.
	 */
	public function has_event( string $event_key ): bool {
		foreach ( $this->entries as $entry ) {
			if ( $entry->event_key === $event_key ) {
				return true;
			}
		}

		return false;
	}
}
