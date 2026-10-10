<?php
/**
 * WooCommerce hook that triggers the order line pricing snapshot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * `woocommerce_new_order_item` (ADR-047 point 1). WooCommerce fires it once
 * per line, from the order item data store's create(), right after the line
 * row is inserted; for HPOS and legacy storage alike, for checkout, wp-admin,
 * REST and `add_product()`. At that point the line id, the order id and the
 * line's product and variation exist, and the product's pricing is still
 * the one the customer saw.
 *
 * Only product lines of `shop_order` objects are recorded: refunds create
 * line items too (on the refund object), and those must not get a
 * snapshot. Updating a line fires no create hook, so a line is never
 * captured twice by design; the repository's append-only insert is the
 * final protection if a line is created again (first snapshot wins).
 */
final class SalesLinePricingHooks {

	/**
	 * Constructor.
	 *
	 * @param SalesLinePricingRecorder $recorder Recorder.
	 */
	public function __construct( private SalesLinePricingRecorder $recorder ) {
	}

	/**
	 * Registers the hook.
	 */
	public function register(): void {
		add_action( 'woocommerce_new_order_item', array( $this, 'item_created' ), 10, 3 );
	}

	/**
	 * `woocommerce_new_order_item`.
	 *
	 * @param mixed $item_id  Order item id.
	 * @param mixed $item     Order item.
	 * @param mixed $order_id Order id.
	 */
	public function item_created( mixed $item_id, mixed $item = null, mixed $order_id = null ): void {
		if ( ! is_numeric( $item_id ) || ! is_numeric( $order_id ) || ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return;
		}

		$this->recorder->record(
			(int) $item_id,
			(int) $order_id,
			(int) $item->get_product_id( 'edit' ),
			(int) $item->get_variation_id( 'edit' ),
			(string) $order->get_currency( 'edit' )
		);
	}
}
