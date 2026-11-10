<?php
/**
 * The Twitch subscription is checked again before the order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponCode;
use MerchBoard\Modules\WooCommerce\SubDiscount\SubDiscountMeta;
use WC_Cart;
use WC_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106 pkt. 5: right before the order is created (classic checkout:
 * after its validation; block checkout: when the Store API takes the
 * order), Twitch is asked again for every creator whose discount the cart
 * has. When a rate changed (another tier, a subscription ended, an expired
 * token, Twitch not answering), the checkout stops with a message and the
 * cart is priced again, so the customer sees the new total before paying.
 * The next attempt goes through with the new price.
 */
final class TwitchSubCheckout {

	/**
	 * Constructor.
	 *
	 * @param TwitchSubDiscount $discount Which lines qualify.
	 */
	public function __construct( private TwitchSubDiscount $discount ) {
	}

	/**
	 * Hooks the check into both checkouts.
	 */
	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'classic' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'blocks' ), 20, 0 );
	}

	/**
	 * `woocommerce_after_checkout_validation`.
	 *
	 * @param mixed $data   Posted data.
	 * @param mixed $errors Validation errors.
	 */
	public function classic( mixed $data, mixed $errors ): void {
		unset( $data );

		if ( $errors instanceof WP_Error && $this->changed() ) {
			$errors->add( 'merchboard_twitch_sub_changed', self::message() );
		}
	}

	/**
	 * `woocommerce_store_api_checkout_update_order_from_request`.
	 *
	 * @throws RouteException When the discount changed (the checkout stops with the message).
	 */
	public function blocks(): void {
		if ( $this->changed() ) {
			throw new RouteException( 'merchboard_twitch_sub_changed', self::message(), 409 );
		}
	}

	/**
	 * The customer's message.
	 */
	public static function message(): string {
		return __( 'Your Twitch subscriber discount has changed. Please check the new total and place the order again.', 'merchboard' );
	}

	/**
	 * Whether the cart has the discount and a rate changed now; the cart is
	 * priced again then.
	 */
	private function changed(): bool {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart instanceof WC_Cart ) {
			return false;
		}

		$applied = array_map( static fn( mixed $code ): string => CouponCode::normalize( (string) $code ), $cart->get_applied_coupons() );

		if ( ! in_array( SubDiscountMeta::COUPON_CODE, $applied, true ) ) {
			return false;
		}

		$products = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;

			if ( $product instanceof WC_Product ) {
				$parent     = (int) $product->get_parent_id();
				$products[] = $parent > 0 ? $parent : (int) $product->get_id();
			}
		}

		if ( ! $this->discount->recheck( array_values( array_unique( $products ) ) ) ) {
			return false;
		}

		$cart->calculate_totals();

		return true;
	}
}
