<?php
/**
 * A collection is never a cart line itself.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use WC_Cart;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 3: the customer buys a design's own product.
 *
 * - The validation refuses the shop's forms with a reason.
 * - The quantity filter refuses also what is added in code, as that passes
 *   no validation: WooCommerce adds nothing of quantity 0.
 * - The cart check stops what is in a cart already, also the block
 *   checkout's.
 */
class CollectionCartGuard {

	/**
	 * Constructor.
	 *
	 * @param DesignCollections $collections The collections.
	 */
	public function __construct( private DesignCollections $collections ) {
	}

	/**
	 * Hooks the guard into the cart.
	 */
	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 5, 2 );
		add_filter( 'woocommerce_add_to_cart_quantity', array( $this, 'quantity' ), 100, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );
	}

	/**
	 * `woocommerce_add_to_cart_validation`.
	 *
	 * @param mixed $passed     WooCommerce' verdict.
	 * @param mixed $product_id Parent product.
	 * @return mixed
	 */
	public function validate( mixed $passed, mixed $product_id = 0 ): mixed {
		if ( true !== (bool) $passed || ! $this->refused( $product_id ) ) {
			return $passed;
		}

		wc_add_notice( __( 'Choose a design before you add the product to the cart.', 'merchboard' ), 'error' );

		return false;
	}

	/**
	 * `woocommerce_add_to_cart_quantity`: 0 for a collection.
	 *
	 * @param mixed $quantity   Quantity.
	 * @param mixed $product_id Parent product.
	 * @return mixed
	 */
	public function quantity( mixed $quantity, mixed $product_id = 0 ): mixed {
		return $this->refused( $product_id ) ? 0 : $quantity;
	}

	/**
	 * `woocommerce_check_cart_items`.
	 */
	public function check_cart(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( $this->refused( $item['product_id'] ?? 0 ) ) {
				wc_add_notice( __( 'A collection cannot be bought itself. Remove it from the cart and choose a design on its page.', 'merchboard' ), 'error' );

				return;
			}
		}
	}

	/**
	 * Whether a product is refused as a cart line.
	 *
	 * @param mixed $product_id Parent product.
	 */
	private function refused( mixed $product_id ): bool {
		return is_numeric( $product_id ) && $this->collections->is_collection( (int) $product_id );
	}
}
