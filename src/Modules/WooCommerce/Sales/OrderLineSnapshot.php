<?php
/**
 * One order line as the sales planner sees it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A normalized WooCommerce product line (`line_item`) of an order. The reader
 * (ROADMAP trin 5) fills it from CRUD data; the planner never talks to
 * WooCommerce.
 *
 * - `net` is the line total after discounts, excl. tax, in 1/10000 units,
 *   before refunds (ADR-037).
 * - `tax` is the line's actual tax (WooCommerce' `total_tax`, after
 *   discounts) in 1/10000 units, or null when unknown (ADR-048).
 * - `subtotal` is the line's subtotal before coupons incl. tax
 *   (WooCommerce' `subtotal` + `subtotal_tax`) in 1/10000 units, or null
 *   when unknown. Only a designer product's campaign reads it (a sale price,
 *   ADR-080 pkt. 9).
 * - `creator_id` is the product's current, valid creator link (the parent's
 *   for a variation), or null. It only matters for a line without ledger
 *   history; a line with history keeps its snapshot creator (ADR-038, B3).
 */
final readonly class OrderLineSnapshot {

	/**
	 * Constructor.
	 *
	 * @param int      $item_id      Order item id.
	 * @param int      $product_id   Parent product, or 0 when the line has none.
	 * @param int      $variation_id Variation, or 0.
	 * @param string   $name         Line name.
	 * @param int      $quantity     Quantity.
	 * @param int      $net          Line total after discounts, excl. tax, in 1/10000 units.
	 * @param int|null $creator_id   Current creator of the product, or null.
	 * @param int|null $tax          Actual line tax in 1/10000 units, or null when unknown.
	 * @param int|null $subtotal     Line subtotal before coupons incl. tax in 1/10000 units, or null when unknown.
	 * @throws InvalidArgumentException When an id is invalid.
	 */
	public function __construct(
		public int $item_id,
		public int $product_id,
		public int $variation_id,
		public string $name,
		public int $quantity,
		public int $net,
		public ?int $creator_id,
		public ?int $tax = null,
		public ?int $subtotal = null
	) {
		if ( $item_id < 1 || $product_id < 0 || $variation_id < 0 || ( null !== $creator_id && ( $creator_id < 1 || $product_id < 1 ) ) ) {
			throw new InvalidArgumentException( 'Invalid order line snapshot.' );
		}
	}
}
