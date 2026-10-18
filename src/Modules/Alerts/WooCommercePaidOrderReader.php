<?php
/**
 * Reads a paid WooCommerce order for alerts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce CRUD only (HPOS and legacy storage alike), always in the
 * `edit` context so filters cannot change what is stored. Lines without a
 * product or with a quantity below 1 are skipped. The username is the
 * checkout field's order meta (`_merchboard_alert_username`, ADR-065 pkt. 9),
 * with MerchAlert's `_ccmerch_username` as the fallback for older orders
 * (ADR-065 pkt. 5). The image is the variation's own, else the product's.
 */
final class WooCommercePaidOrderReader implements PaidOrderReader {

	/**
	 * Order meta of the checkout username.
	 */
	public const USERNAME_META = '_merchboard_alert_username';

	/**
	 * MerchAlert's order meta of the username (read only, ADR-065 pkt. 5).
	 */
	public const LEGACY_USERNAME_META = '_ccmerch_username';

	/**
	 * The order, or null when it is not a `shop_order`.
	 *
	 * @param int $order_id Order id.
	 */
	public function read( int $order_id ): ?PaidOrder {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return null;
		}

		$lines = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$line = $item instanceof WC_Order_Item_Product ? self::line( $item ) : null;

			if ( null !== $line ) {
				$lines[] = $line;
			}
		}

		return new PaidOrder(
			$order_id,
			(string) $order->get_order_number(),
			self::text( $order->get_billing_first_name( 'edit' ) ),
			self::username( $order ),
			$lines
		);
	}

	/**
	 * A product line, or null without a product or a positive quantity.
	 *
	 * @param WC_Order_Item_Product $item Order line.
	 */
	private static function line( WC_Order_Item_Product $item ): ?PaidOrderLine {
		$product_id   = (int) $item->get_product_id( 'edit' );
		$variation_id = max( 0, (int) $item->get_variation_id( 'edit' ) );
		$quantity     = $item->get_quantity( 'edit' );

		if ( $product_id < 1 || (int) $quantity < 1 ) {
			return null;
		}

		return new PaidOrderLine(
			(int) $item->get_id(),
			$product_id,
			$variation_id,
			self::text( $item->get_name( 'edit' ) ),
			self::variation( $variation_id ),
			(int) $quantity,
			self::image( $product_id, $variation_id )
		);
	}

	/**
	 * The username from checkout, else MerchAlert's, else ''.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function username( WC_Order $order ): string {
		foreach ( array( self::USERNAME_META, self::LEGACY_USERNAME_META ) as $key ) {
			$value = self::text( $order->get_meta( $key, true, 'edit' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * The variation's attributes as plain text ("Size: L, Color: Red").
	 *
	 * @param int $variation_id Variation id, 0 for none.
	 */
	private static function variation( int $variation_id ): string {
		if ( $variation_id < 1 || ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_formatted_variation' ) ) {
			return '';
		}

		$variation = wc_get_product( $variation_id );

		return $variation instanceof WC_Product_Variation ? self::text( wc_get_formatted_variation( $variation, true, false, false ) ) : '';
	}

	/**
	 * The variation's featured image, else the product's; 0 when none.
	 *
	 * @param int $product_id   Parent product id.
	 * @param int $variation_id Variation id, 0 for none.
	 */
	private static function image( int $product_id, int $variation_id ): int {
		foreach ( $variation_id > 0 ? array( $variation_id, $product_id ) : array( $product_id ) as $id ) {
			$image = get_post_thumbnail_id( $id );

			if ( is_int( $image ) && $image > 0 ) {
				return $image;
			}
		}

		return 0;
	}

	/**
	 * Plain, trimmed text from a stored value; '' for anything else.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
	}
}
