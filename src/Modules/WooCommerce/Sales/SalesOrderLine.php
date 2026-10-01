<?php
/**
 * One order line of a creator, from the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * An order line as the ledger knows it (ADR-039 P2): the line's balance as
 * quantity, whether a refund still applies, whether the order counts now and
 * the date of the line's first sale row. Product id, variation id and the
 * item name come from that first sale row (the attribution snapshot), so the
 * caller can look up the current product name and fall back to the snapshot
 * (P6). No amounts (P3) and no customer data.
 */
final readonly class SalesOrderLine {

	/**
	 * Constructor.
	 *
	 * @param int               $order_id      Order id.
	 * @param int               $order_item_id Order item id (globally unique).
	 * @param DateTimeImmutable $date          `occurred_at` of the line's first sale row (UTC).
	 * @param int               $quantity      Sum of the line's ledger quantities.
	 * @param bool              $refunded      A refund row without a `refund_deleted` correction exists.
	 * @param bool              $counted       The order has an open cycle.
	 * @param int               $product_id    Parent product id from the first sale row.
	 * @param int               $variation_id  Variation id from the first sale row (0 for none).
	 * @param string            $item_name     Item name snapshot from the first sale row.
	 */
	public function __construct(
		public int $order_id,
		public int $order_item_id,
		public DateTimeImmutable $date,
		public int $quantity,
		public bool $refunded,
		public bool $counted,
		public int $product_id,
		public int $variation_id,
		public string $item_name
	) {
	}
}
