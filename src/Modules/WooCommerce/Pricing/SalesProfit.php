<?php
/**
 * Realized creator profit and payout of sales ledger rows.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesTaxRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Read model joining a ledger row with its stored actual tax (ADR-048) and
 * its order line's share snapshot (ADR-049). Nothing is stored: profit and
 * payout are derived on demand from immutable sources, so they never depend
 * on today's markup, VAT status, WooCommerce prices or tax settings, and
 * there is no profit, payout or VAT column in `merchboard_sales`. Unknown
 * (null) without a share snapshot or the row's tax.
 */
final class SalesProfit {

	/**
	 * Constructor.
	 *
	 * @param SalesLinePricingRepository $snapshots Snapshots.
	 * @param SalesTaxRepository         $taxes     Actual tax per ledger row.
	 */
	public function __construct( private SalesLinePricingRepository $snapshots, private SalesTaxRepository $taxes ) {
	}

	/**
	 * Profit of a ledger row in units (1/10 000), or null when unknown
	 * (RealizedProfit).
	 *
	 * @param SalesEntry $entry Ledger row.
	 * @throws DatabaseException When the snapshot or the tax cannot be read.
	 */
	public function for_entry( SalesEntry $entry ): ?int {
		return RealizedProfit::for_entry( $entry, $this->snapshots->find( $entry->order_item_id ), $this->taxes->find( $entry->event_key ) );
	}

	/**
	 * Payout of a ledger row in units (1/10 000), or null when unknown
	 * (RealizedPayout).
	 *
	 * @param SalesEntry $entry Ledger row.
	 * @throws DatabaseException When the snapshot or the tax cannot be read.
	 */
	public function payout_for_entry( SalesEntry $entry ): ?int {
		return RealizedPayout::for_entry( $entry, $this->snapshots->find( $entry->order_item_id ), $this->taxes->find( $entry->event_key ) );
	}
}
