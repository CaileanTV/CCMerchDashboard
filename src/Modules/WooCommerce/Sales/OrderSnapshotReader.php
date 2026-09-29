<?php
/**
 * Contract for reading an order for the sales planner.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WooCommerce data into the planner's input (ADR-038). Returns null for
 * anything that is not a synchronizable order: missing, not a `shop_order`,
 * or in the trash (ADR-038, Q8).
 */
interface OrderSnapshotReader {

	/**
	 * Cheap status check before any ledger work: whether the order counts,
	 * or null when it is not a synchronizable order.
	 *
	 * @param int $order_id Order id.
	 */
	public function counts( int $order_id ): ?bool;

	/**
	 * The order as the planner sees it.
	 *
	 * @param int      $order_id          Order id.
	 * @param int|null $deleted_refund_id Refund a delete hook reported as deleted (ADR-038, B6).
	 * @throws \InvalidArgumentException When WooCommerce returns a value that cannot be converted deterministically (ADR-038, Q6).
	 */
	public function read( int $order_id, ?int $deleted_refund_id = null ): ?OrderSnapshot;
}
