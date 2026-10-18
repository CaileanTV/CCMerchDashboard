<?php
/**
 * Reads a paid order for alerts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Behind an interface so the routing can be tested without WooCommerce.
 */
interface PaidOrderReader {

	/**
	 * The order, or null when it is not a WooCommerce order (`shop_order`).
	 *
	 * @param int $order_id Order id.
	 */
	public function read( int $order_id ): ?PaidOrder;
}
