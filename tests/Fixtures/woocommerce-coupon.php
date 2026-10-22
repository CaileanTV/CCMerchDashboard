<?php
/**
 * Stand-in for WooCommerce' coupon, cart and discounts (only what the payout
 * gift card guard reads), plus is_coupon_valid() exactly as WooCommerce
 * 11.1.2 turns a filter result or an exception into a WP_Error. Loaded only
 * in isolated test processes (global classes cannot be undone), together
 * with woocommerce-order.php and wp-rest.php.
 *
 * @package MerchBoard
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.NamingConventions.NoReservedKeywordParameterNames, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.VariableComment.Missing -- Mirrors WooCommerce's global classes and signatures.

/**
 * WooCommerce coupon.
 */
class WC_Coupon {

	private $code;

	public function __construct( $code = '' ) {
		$this->code = (string) $code;
	}

	public function get_code( $context = 'view' ) {
		return $this->code;
	}
}

/**
 * WooCommerce cart.
 */
class WC_Cart {

	/**
	 * Cart items by key, as WC_Cart::get_cart() returns them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public $cart_contents = array();

	public function get_cart() {
		return $this->cart_contents;
	}
}

/**
 * WooCommerce discounts of a cart or an order.
 */
class WC_Discounts {

	private $object;

	public function __construct( $object = null ) {
		$this->object = $object;
	}

	public function get_object() {
		return $this->object;
	}

	/**
	 * As WC_Discounts::is_coupon_valid() in 11.1.2 (after its own checks);
	 * the callbacks of `woocommerce_coupon_is_valid` are in
	 * $GLOBALS['wc_test_coupon_filters'] (Brain Monkey runs no filters).
	 */
	public function is_coupon_valid( $coupon ) {
		try {
			$valid = true;

			foreach ( $GLOBALS['wc_test_coupon_filters'] ?? array() as $filter ) {
				$valid = $filter( $valid, $coupon, $this );
			}

			if ( ! $valid ) {
				throw new Exception( 'Coupon is not valid.', 100 );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid_coupon', $e->getMessage(), array( 'status' => 400 ) );
		}

		return true;
	}
}
