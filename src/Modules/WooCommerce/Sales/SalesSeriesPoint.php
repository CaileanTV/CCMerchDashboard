<?php
/**
 * One period of one currency in a sales series.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The sums of one currency in one period (ADR-040). Only periods with ledger
 * rows are returned; a dense series is built from the periods and these
 * points (index = SalesPeriod::$index).
 */
final readonly class SalesSeriesPoint {

	/**
	 * Constructor.
	 *
	 * @param int     $index     Period index (SalesPeriod::$index).
	 * @param string  $currency  Currency (ISO 4217).
	 * @param string  $net_sales Sum of all rows' amounts, four decimals.
	 * @param int     $units     Sum of all rows' quantity.
	 * @param int     $orders    Distinct orders with a `sale` row in the period.
	 * @param ?string $net_sales_incl_vat `net_sales` plus the rows' stored tax (ADR-053); null when a row has none.
	 */
	public function __construct(
		public int $index,
		public string $currency,
		public string $net_sales,
		public int $units,
		public int $orders,
		public ?string $net_sales_incl_vat = null
	) {
	}
}
