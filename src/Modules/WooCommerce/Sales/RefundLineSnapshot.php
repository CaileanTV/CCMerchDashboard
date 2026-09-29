<?php
/**
 * One product line of a refund as the sales planner sees it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A refund's `line_item`: the original order line (`_refunded_item_id`, 0 when
 * missing) and the refunded quantity and amount. WooCommerce stores both as
 * negative numbers; the planner rejects positive ones as inconsistent data.
 */
final readonly class RefundLineSnapshot {

	/**
	 * Constructor.
	 *
	 * @param int $refunded_item_id Original order item id, or 0 when WooCommerce has none.
	 * @param int $quantity         Refunded quantity (≤ 0).
	 * @param int $net              Refunded amount excl. tax, in 1/10000 units (≤ 0).
	 * @throws InvalidArgumentException When the item id is negative.
	 */
	public function __construct( public int $refunded_item_id, public int $quantity, public int $net ) {
		if ( $refunded_item_id < 0 ) {
			throw new InvalidArgumentException( 'Invalid refund line snapshot.' );
		}
	}
}
