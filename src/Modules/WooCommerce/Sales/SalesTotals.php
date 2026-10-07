<?php
/**
 * Sales totals of one currency.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The totals of one currency over a period (ADR-040 pkt. 2, 3, 9). Amounts
 * are decimal strings with four decimals (never floats).
 */
final readonly class SalesTotals {

	/**
	 * Constructor.
	 *
	 * @param string $currency    Currency (ISO 4217).
	 * @param string $sales       Sum of `sale` rows.
	 * @param string $adjustments Sum of all other rows (refund, reversal, correction).
	 * @param string $net_sales   `sales + adjustments`.
	 * @param int    $units       Sum of all rows' quantity (can be negative).
	 * @param int    $orders      Distinct orders with a `sale` row in the period.
	 */
	public function __construct(
		public string $currency,
		public string $sales,
		public string $adjustments,
		public string $net_sales,
		public int $units,
		public int $orders
	) {
	}
}
