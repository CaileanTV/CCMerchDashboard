<?php
/**
 * Contract for synchronizing an order's sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * What the hooks call (SalesSynchronizer). Implementations never throw.
 */
interface SalesSync {

	/**
	 * Synchronizes an order.
	 *
	 * @param int      $order_id          Order id.
	 * @param int|null $deleted_refund_id Refund a delete hook reported as deleted.
	 */
	public function sync( int $order_id, ?int $deleted_refund_id = null ): void;

	/**
	 * Marks an order as being deleted and closes its cycle from the ledger alone.
	 *
	 * @param int $order_id Order id.
	 */
	public function order_deleting( int $order_id ): void;
}
