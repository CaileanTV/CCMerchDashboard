<?php
/**
 * The optional username field in the WooCommerce checkout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Checkout;

use Closure;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\WooCommercePaidOrderReader;
use WC_Cart;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a customer give an optional username for the creator's alerts and
 * chat messages (ADR-065 pkt. 9, step 25.9.1), in the classic and the block
 * checkout. Shown only while alerts are on and the administrator switched
 * the field on (Settings → Alerts; off by default), and only when the cart
 * holds a product of a creator; checked when the hooks run, not at boot.
 *
 * The customer first ticks a checkbox whose text says that the name is shown
 * publicly; only then the username field appears, and only then the name is
 * used. So nobody fills it in by mistake.
 *
 * - **Classic checkout:** a checkbox and the field in the "order" group (a
 *   small inline script shows the field when the box is ticked); too long is
 *   a checkout error; saved as order meta `_merchboard_alert_username`
 *   through WooCommerce CRUD (HPOS) when the box is ticked.
 * - **Block checkout:** two additional checkout fields (location "order"),
 *   the field hidden by WooCommerce's rules until the box is ticked, both
 *   hidden when the cart holds no creator's product (Store API cart data
 *   `extensions.merchboard.has_creator_products`). WooCommerce saves them as
 *   `_wc_other/…`; the order reader uses the name only with the box ticked.
 */
final class CheckoutUsernameField {

	/**
	 * The creator of a product (or variation), or null.
	 *
	 * @var Closure(int): ?int
	 */
	private Closure $creator_of;

	/**
	 * Constructor.
	 *
	 * @param Closure $creator_of The creator of a product or variation id (ProductCreatorLink::creator_id()).
	 *
	 * @phpstan-param Closure(int): ?int $creator_of
	 */
	public function __construct( Closure $creator_of ) {
		$this->creator_of = $creator_of;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'add_classic_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_field' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_field' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'print_classic_toggle' ) );
		add_action( 'woocommerce_init', array( $this, 'register_block_fields' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'show_in_admin' ) );

		// WooCommerce fires this before the modules boot (plugins_loaded).
		if ( did_action( 'woocommerce_blocks_loaded' ) > 0 ) {
			$this->register_cart_data();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_cart_data' ) );
		}
	}

	/**
	 * Whether the field is switched on: alerts and the field both are.
	 */
	public static function active(): bool {
		return Settings::alerts_enabled() && Settings::checkout_username_enabled();
	}

	/**
	 * Whether the current cart holds a product of a creator (true when there
	 * is no cart to look at, so the field is not hidden by mistake).
	 */
	public function cart_has_creator_products(): bool {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart instanceof WC_Cart ) {
			return true;
		}

		foreach ( $cart->get_cart() as $item ) {
			$product_id = is_numeric( $item['product_id'] ?? null ) ? (int) $item['product_id'] : 0;

			if ( $product_id > 0 && null !== ( $this->creator_of )( $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `woocommerce_checkout_fields`: the checkbox and the field in the
	 * "order" group, when the cart holds a creator's product.
	 *
	 * @param mixed $fields Checkout fields by group.
	 * @return mixed
	 */
	public function add_classic_field( mixed $fields ): mixed {
		if ( ! is_array( $fields ) || ! self::active() || ! $this->cart_has_creator_products() ) {
			return $fields;
		}

		$order = is_array( $fields['order'] ?? null ) ? $fields['order'] : array();

		$order[ CheckoutUsername::CLASSIC_SHOW_FIELD ] = array(
			'type'     => 'checkbox',
			'label'    => CheckoutUsername::help(),
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 110,
		);
		$order[ CheckoutUsername::CLASSIC_FIELD ]      = array(
			'type'      => 'text',
			'label'     => CheckoutUsername::label(),
			'required'  => false,
			'class'     => array( 'form-row-wide' ),
			'priority'  => 111,
			'maxlength' => CheckoutUsername::MAX_LENGTH,
		);

		$fields['order'] = $order;

		return $fields;
	}

	/**
	 * `woocommerce_after_checkout_form`: shows the field only while the box
	 * is ticked (classic checkout).
	 */
	public function print_classic_toggle(): void {
		if ( ! self::active() ) {
			return;
		}

		wp_print_inline_script_tag(
			sprintf(
				'(function(){var b=document.getElementById(%1$s),r=document.getElementById(%2$s);if(!b||!r){return;}var s=function(){r.style.display=b.checked?"":"none";};b.addEventListener("change",s);s();})();',
				(string) wp_json_encode( CheckoutUsername::CLASSIC_SHOW_FIELD ),
				(string) wp_json_encode( CheckoutUsername::CLASSIC_FIELD . '_field' )
			)
		);
	}

	/**
	 * `woocommerce_after_checkout_validation`: a too long username is an
	 * error the customer sees (only with the box ticked).
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors Checkout errors.
	 */
	public function validate_classic_field( mixed $data, mixed $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof WP_Error || ! self::active() || ! self::ticked( $data[ CheckoutUsername::CLASSIC_SHOW_FIELD ] ?? null ) ) {
			return;
		}

		if ( CheckoutUsername::too_long( CheckoutUsername::clean( $data[ CheckoutUsername::CLASSIC_FIELD ] ?? '' ) ) ) {
			$errors->add( 'merchboard_alert_username', CheckoutUsername::too_long_message() );
		}
	}

	/**
	 * `woocommerce_checkout_create_order`: the username as order meta, saved
	 * with the order; only with the box ticked, and nothing when it is empty
	 * or too long.
	 *
	 * @param mixed $order The new order.
	 * @param mixed $data  Posted checkout data.
	 */
	public function save_classic_field( mixed $order, mixed $data ): void {
		if ( ! $order instanceof WC_Order || ! is_array( $data ) || ! self::active() || ! self::ticked( $data[ CheckoutUsername::CLASSIC_SHOW_FIELD ] ?? null ) ) {
			return;
		}

		$username = CheckoutUsername::clean( $data[ CheckoutUsername::CLASSIC_FIELD ] ?? '' );

		if ( '' !== $username && ! CheckoutUsername::too_long( $username ) ) {
			$order->update_meta_data( WooCommercePaidOrderReader::USERNAME_META, $username );
		}
	}

	/**
	 * `woocommerce_init`: the checkbox and the field in the block checkout
	 * (WooCommerce's additional checkout fields with visibility rules).
	 */
	public function register_block_fields(): void {
		if ( ! self::active() || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => CheckoutUsername::BLOCK_SHOW_FIELD_ID,
				'label'    => CheckoutUsername::help(),
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => false,
				'hidden'   => self::without_creator_products(),
			)
		);
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => CheckoutUsername::BLOCK_FIELD_ID,
				'label'             => CheckoutUsername::label(),
				'location'          => 'order',
				'type'              => 'text',
				'required'          => false,
				'hidden'            => array(
					'anyOf' => array(
						self::without_creator_products(),
						array(
							'type'       => 'object',
							'properties' => array(
								'checkout' => array(
									'properties' => array(
										'additional_fields' => array(
											'properties' => array(
												CheckoutUsername::BLOCK_SHOW_FIELD_ID => array( 'not' => array( 'enum' => array( true, '1', 1 ) ) ),
											),
										),
									),
								),
							),
						),
					),
				),
				'attributes'        => array( 'maxLength' => CheckoutUsername::MAX_LENGTH ),
				'sanitize_callback' => static fn( mixed $value ): string => CheckoutUsername::clean( $value ),
				'validate_callback' => static fn( mixed $value ): WP_Error|bool => CheckoutUsername::too_long( CheckoutUsername::clean( $value ) ) ? new WP_Error( 'merchboard_alert_username', CheckoutUsername::too_long_message() ) : true,
			)
		);
	}

	/**
	 * On (or after) `woocommerce_blocks_loaded`: whether the cart holds a creator's
	 * product, in the Store API cart data (`extensions.merchboard`), for the
	 * block checkout's visibility rules.
	 */
	public function register_cart_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => 'merchboard',
				'data_callback'   => fn(): array => array( 'has_creator_products' => $this->cart_has_creator_products() ),
				'schema_callback' => static fn(): array => array(
					'has_creator_products' => array(
						'description' => 'Whether the cart holds a product of a creator.',
						'type'        => 'boolean',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * The username on the admin's order page (classic checkout; WooCommerce
	 * shows the block fields itself).
	 *
	 * @param mixed $order Order.
	 */
	public function show_in_admin( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$username = CheckoutUsername::clean( $order->get_meta( WooCommercePaidOrderReader::USERNAME_META, true, 'edit' ) );

		if ( '' !== $username ) {
			echo '<p><strong>' . esc_html( CheckoutUsername::label() ) . ':</strong> ' . esc_html( $username ) . '</p>';
		}
	}

	/**
	 * Whether a posted checkbox is ticked.
	 *
	 * @param mixed $value Posted value.
	 */
	public static function ticked( mixed $value ): bool {
		return in_array( $value, array( true, 1, '1', 'yes', 'on' ), true );
	}

	/**
	 * The visibility rule "the cart holds no creator's product" (only when the
	 * cart data says so; without it, nothing is hidden).
	 *
	 * @return array<string, mixed>
	 */
	private static function without_creator_products(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'cart' ),
			'properties' => array(
				'cart' => array(
					'required'   => array( 'extensions' ),
					'properties' => array(
						'extensions' => array(
							'required'   => array( 'merchboard' ),
							'properties' => array(
								'merchboard' => array(
									'required'   => array( 'has_creator_products' ),
									'properties' => array(
										'has_creator_products' => array( 'const' => false ),
									),
								),
							),
						),
					),
				),
			),
		);
	}
}
