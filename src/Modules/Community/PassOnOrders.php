<?php
/**
 * Gift and giveaway orders are completed when paid.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community;

use MerchBoard\Modules\Community\Gifting\GiftPurchase;
use MerchBoard\Modules\Community\Giveaways\GiveawayCheckout;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * A viewer's gift order (ADR-107) and a creator's giveaway paid in the shop
 * (ADR-110) ship nothing themselves: they pay for a code that is passed on,
 * and the winner's own order is the one that is shipped. So such an order
 * goes straight to `completed` when it is paid (projektejerens ønske
 * 2026-11-12), and nobody has to mark it by hand.
 *
 * Only an order whose every product line is a gift or giveaway line; a
 * gift or giveaway is always bought alone, so any other line keeps the
 * order as it is.
 */
final class PassOnOrders {

	/**
	 * Hooks.
	 */
	public function register(): void {
		// Paid by a payment gateway: completed at once, without `processing`.
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'payment_status' ), 20, 3 );
		// Set to `processing` another way (e.g. by hand after a bank transfer), after the other listeners.
		add_action( 'woocommerce_order_status_processing', array( $this, 'complete' ), 99 );
	}

	/**
	 * `woocommerce_payment_complete_order_status`.
	 *
	 * @param mixed $status   The status WooCommerce chose.
	 * @param mixed $order_id Order id.
	 * @param mixed $order    The order.
	 * @return mixed
	 */
	public function payment_status( mixed $status, mixed $order_id = 0, mixed $order = null ): mixed {
		$order = $order instanceof WC_Order ? $order : ( is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false );

		return $order instanceof WC_Order && self::passed_on( $order ) ? 'completed' : $status;
	}

	/**
	 * `woocommerce_order_status_processing`: completes a gift or giveaway order.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function complete( mixed $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;

		if ( ! $order instanceof WC_Order || 'processing' !== $order->get_status( 'edit' ) || ! self::passed_on( $order ) ) {
			return;
		}

		try {
			$order->update_status( 'completed', __( 'Completed automatically: the gift is passed on as a code, and the winner\'s own order is the one that is shipped.', 'merchboard' ) );
		} catch ( Throwable ) {
			// The order stays `processing`; it can still be completed by hand.
			return;
		}
	}

	/**
	 * Whether every product line of an order is a gift or a giveaway.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function passed_on( WC_Order $order ): bool {
		$lines = 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			++$lines;

			if ( '' === (string) $item->get_meta( GiftPurchase::LINE_META, true, 'edit' ) && '' === (string) $item->get_meta( GiveawayCheckout::LINE_META, true, 'edit' ) ) {
				return false;
			}
		}

		return $lines > 0;
	}
}
