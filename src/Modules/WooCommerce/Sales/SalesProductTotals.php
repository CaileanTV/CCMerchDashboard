<?php
/**
 * Sales of one product in one currency.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The sums of one parent product (variations included) in one currency over
 * a period (ADR-040 pkt. 4). `item_name` is the snapshot of the product's
 * latest ledger row in the period, the fallback when the product no longer
 * exists (ADR-039 P6); the current name is added by the caller.
 */
final readonly class SalesProductTotals {

	/**
	 * Constructor.
	 *
	 * @param string  $currency   Currency (ISO 4217).
	 * @param int     $product_id Parent product id (0: rows without a product).
	 * @param string  $net_sales  Sum of all rows' amounts, four decimals.
	 * @param int     $units      Sum of all rows' quantity.
	 * @param string  $item_name  Item name snapshot of the latest row.
	 * @param ?string $net_sales_incl_vat `net_sales` plus the rows' stored tax (ADR-053); null when a row has none.
	 */
	public function __construct(
		public string $currency,
		public int $product_id,
		public string $net_sales,
		public int $units,
		public string $item_name,
		public ?string $net_sales_incl_vat = null
	) {
	}
}
