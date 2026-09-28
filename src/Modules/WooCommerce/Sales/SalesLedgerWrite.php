<?php
/**
 * Rows to write for one order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The new ledger rows and cycle events of one synchronization, written by
 * SalesRepository::write() in one transaction. Empty means "nothing to do".
 */
final readonly class SalesLedgerWrite {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<SalesEntry> $entries
	 * @phpstan-param list<SalesCycleEvent> $cycle_events
	 *
	 * @param SalesEntry[]      $entries      New ledger rows.
	 * @param SalesCycleEvent[] $cycle_events New cycle events.
	 */
	public function __construct( public array $entries = array(), public array $cycle_events = array() ) {
	}

	/**
	 * Whether there is nothing to write.
	 */
	public function is_empty(): bool {
		return array() === $this->entries && array() === $this->cycle_events;
	}
}
