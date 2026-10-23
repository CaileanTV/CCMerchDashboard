<?php
/**
 * A product line of a paid order, as an alert shows it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * What an alert needs from one product line, read when the order is paid.
 * The creator is not part of it: AlertRouter finds the creator through the
 * product link (ADR-065 pkt. 2).
 */
final readonly class PaidOrderLine {

	/**
	 * Constructor.
	 *
	 * @param int         $order_item_id Order line id.
	 * @param int         $product_id    Parent product id.
	 * @param int         $variation_id  Variation id, 0 for a simple product.
	 * @param string      $product_name  Line name as sold.
	 * @param string      $variation     The variation's attributes as text, '' for a simple product.
	 * @param int         $quantity      Quantity (at least 1).
	 * @param int         $image_id      Image attachment id, 0 when none.
	 * @param string|null $line_total What the customer paid for the line (VAT included, after discounts), four decimals; null when unknown.
	 */
	public function __construct(
		public int $order_item_id,
		public int $product_id,
		public int $variation_id,
		public string $product_name,
		public string $variation,
		public int $quantity,
		public int $image_id,
		public ?string $line_total = null
	) {
	}
}
