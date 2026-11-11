<?php
/**
 * A viewer buys a gift in a creator's Twitch stream.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\WooCommercePaidOrderReader;
use WC_Cart;
use WC_Discounts;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Variable;
use WC_Shipping_Rate;
use WC_Tax;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 3:
 *
 * - The product page shows "Give as a gift in the stream" while the
 *   product's creator has gifting open (on, not paused), a connected Twitch
 *   channel, and is live.
 * - The gift is bought alone: the cheapest variant (the winner chooses size
 *   and colour later), quantity 1, nothing else in the cart, and one
 *   shipping rate of 50 (incl. VAT).
 * - The buyer's optional name is the alerts' username field (with its
 *   checkbox in the block checkout); without one the gift is anonymous.
 * - When the order is paid the gift is recorded once and handed to the
 *   draws; a cancelled or refunded order cancels a gift that has no winner.
 */
final class GiftPurchase {

	/**
	 * Cart item and order line key.
	 */
	public const ITEM_KEY = 'merchboard_gift';

	/**
	 * Order line meta: the gift's creator.
	 */
	public const LINE_META = '_merchboard_gift';

	/**
	 * The gift shipping (incl. VAT).
	 */
	public const SHIPPING = '50';

	/**
	 * Query argument of the gift button.
	 */
	private const QUERY = 'merchboard_gift';

	/**
	 * Nonce action of the gift button.
	 */
	private const NONCE = 'merchboard_gift_buy';

	/**
	 * The creator of a parent product.
	 *
	 * @var Closure(int): ?int
	 */
	private Closure $creator_of;

	/**
	 * Whether a creator's channel is live (null: no connected channel).
	 *
	 * @var Closure(int): ?bool
	 */
	private Closure $live;

	/**
	 * Called with a newly paid gift.
	 *
	 * @var Closure(Gift): void
	 */
	private Closure $on_paid;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?int $creator_of
	 * @phpstan-param Closure(int): ?bool $live
	 * @phpstan-param Closure(Gift): void $on_paid
	 *
	 * @param GiftingSettingsRepository $settings   The creators' gifting settings.
	 * @param GiftRepository            $gifts      Gifts.
	 * @param Closure                   $creator_of The creator of a parent product, or null.
	 * @param Closure                   $live       Whether a creator is live; null without a connected channel.
	 * @param Closure                   $on_paid    Called with a newly paid gift (the draws).
	 * @param Logger                    $logger     Technical log.
	 */
	public function __construct( private GiftingSettingsRepository $settings, private GiftRepository $gifts, Closure $creator_of, Closure $live, Closure $on_paid, private Logger $logger ) {
		$this->creator_of = $creator_of;
		$this->live       = $live;
		$this->on_paid    = $on_paid;
	}

	/**
	 * Hooks the purchase into WooCommerce.
	 */
	public function register(): void {
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'button' ) );
		add_action( 'template_redirect', array( $this, 'add' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'only_the_gift' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'no_codes' ), 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'one_unit' ), 5 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'quantity_html' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( $this, 'quantity_maximum' ), 20, 3 );
		add_filter( 'woocommerce_package_rates', array( $this, 'shipping' ), 50, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'line_meta' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancelled' ) );
	}

	/**
	 * The creator whose gifts can be bought on a parent product now, or null
	 * (also while an administrator has gifting switched off; ADR-108).
	 *
	 * @param int $product_id Parent product.
	 */
	public function giftable( int $product_id ): ?int {
		if ( ! Settings::twitch_gifting_enabled() ) {
			return null;
		}

		$creator_id = ( $this->creator_of )( $product_id );

		if ( null === $creator_id || ! $this->settings->find( $creator_id )->open() ) {
			return null;
		}

		return true === ( $this->live )( $creator_id ) ? $creator_id : null;
	}

	/**
	 * `woocommerce_after_add_to_cart_form`: the gift button.
	 */
	public function button(): void {
		global $product;

		if ( ! $product instanceof WC_Product || null === $this->giftable( (int) $product->get_id() ) ) {
			return;
		}

		$url = add_query_arg(
			array(
				self::QUERY => (int) $product->get_id(),
				'_wpnonce'  => wp_create_nonce( self::NONCE ),
			),
			self::permalink( (int) $product->get_id() )
		);

		echo '<p class="merchboard-gift-button"><a class="button alt" href="' . esc_url( $url ) . '">' . esc_html__( 'Give as a gift in the stream', 'merchboard' ) . '</a><br><small>' . esc_html__( 'A viewer in the chat wins it and chooses size and colour. Shipping of the gift: 50 kr.', 'merchboard' ) . '</small></p>';
	}

	/**
	 * `template_redirect`: the gift button adds the gift to an empty cart.
	 */
	public function add(): void {
		$product_id = isset( $_GET[ self::QUERY ] ) ? absint( $_GET[ self::QUERY ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.

		if ( $product_id < 1 || ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart ) {
			return;
		}

		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$product = wc_get_product( $product_id );
		$back    = self::permalink( $product_id );

		if ( false === wp_verify_nonce( $nonce, self::NONCE ) || ! $product instanceof WC_Product || null === $this->giftable( $product_id ) ) {
			wc_add_notice( __( 'The gift cannot be bought right now.', 'merchboard' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		if ( ! WC()->cart->is_empty() ) {
			wc_add_notice( __( 'A gift is bought on its own. Please finish or empty your cart first, and then give the gift.', 'merchboard' ), 'notice' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		[ $variation_id, $attributes ] = self::cheapest( $product );

		if ( false === WC()->cart->add_to_cart( $product_id, 1, $variation_id, $attributes, array( self::ITEM_KEY => true ) ) ) {
			wc_add_notice( __( 'The gift cannot be bought right now.', 'merchboard' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * `woocommerce_add_to_cart_validation`: nothing joins a gift.
	 *
	 * @param mixed $passed WooCommerce' verdict.
	 */
	public function only_the_gift( mixed $passed ): bool {
		if ( $passed && null !== self::gift_item( WC()->cart ) ) {
			wc_add_notice( __( 'A gift is bought on its own. Finish the gift first.', 'merchboard' ), 'error' );

			return false;
		}

		return (bool) $passed;
	}

	/**
	 * `woocommerce_check_cart_items` (also the block checkout's cart check): a
	 * gift is alone, one unit, and its creator's gifting is still open. A
	 * guest who logs in gets the saved cart merged in; this stops that order.
	 */
	public function check_cart(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$gift = self::gift_item( $cart );

		if ( null === $gift || ! $cart instanceof WC_Cart ) {
			return;
		}

		if ( 1 !== count( $cart->get_cart() ) || 1 !== (int) ( $gift['quantity'] ?? 1 ) ) {
			wc_add_notice( __( 'A gift is bought on its own. Remove the other products from the cart to give the gift.', 'merchboard' ), 'error' );

			return;
		}

		$product    = $gift['data'] ?? null;
		$creator_id = $product instanceof WC_Product ? ( $this->creator_of )( self::parent_of( $product ) ) : null;

		if ( null === $creator_id || ! Settings::twitch_gifting_enabled() || ! $this->settings->find( $creator_id )->open() ) {
			wc_add_notice( __( 'Gifts cannot be bought for this stream right now. Please remove the gift from the cart.', 'merchboard' ), 'error' );
		}
	}

	/**
	 * `woocommerce_coupon_is_valid`: no code on a gift (its paid price is
	 * what the winner's code covers).
	 *
	 * @param mixed $valid     WooCommerce' verdict.
	 * @param mixed $coupon    Coupon.
	 * @param mixed $discounts The discounts' cart or order.
	 * @throws Exception With the reason, which WooCommerce shows.
	 */
	public function no_codes( mixed $valid, mixed $coupon, mixed $discounts = null ): bool {
		$object = $discounts instanceof WC_Discounts ? $discounts->get_object() : null;
		$gift   = ( $object instanceof WC_Cart && null !== self::gift_item( $object ) ) || ( $object instanceof WC_Order && null !== self::gift_line( $object ) );

		if ( $gift && (bool) $valid ) {
			throw new Exception( esc_html__( 'Codes cannot be used on a gift.', 'merchboard' ) );
		}

		return (bool) $valid;
	}

	/**
	 * `woocommerce_before_calculate_totals`: a gift is always one unit.
	 *
	 * @param mixed $cart The cart.
	 */
	public function one_unit( mixed $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( true === ( $item[ self::ITEM_KEY ] ?? false ) && 1 !== (int) ( $item['quantity'] ?? 1 ) ) {
				$cart->set_quantity( (string) $key, 1, false );
			}
		}
	}

	/**
	 * `woocommerce_get_item_data`: a gift shows what it is instead of the
	 * cheapest variant's size and colour.
	 *
	 * @param mixed $data Item data.
	 * @param mixed $item Cart item.
	 * @return mixed
	 */
	public function item_data( mixed $data, mixed $item ): mixed {
		if ( ! is_array( $item ) || true !== ( $item[ self::ITEM_KEY ] ?? false ) ) {
			return $data;
		}

		return array(
			array(
				'key'   => __( 'Gift', 'merchboard' ),
				'value' => __( 'A viewer in the stream wins it and chooses size and colour.', 'merchboard' ),
			),
		);
	}

	/**
	 * `woocommerce_cart_item_quantity`: a gift's quantity cannot be changed.
	 *
	 * @param mixed $html Quantity field.
	 * @param mixed $key  Cart item key.
	 * @param mixed $item Cart item.
	 * @return mixed
	 */
	public function quantity_html( mixed $html, mixed $key, mixed $item = null ): mixed {
		unset( $key );

		return is_array( $item ) && true === ( $item[ self::ITEM_KEY ] ?? false ) ? '1' : $html;
	}

	/**
	 * `woocommerce_store_api_product_quantity_maximum`: one gift in the
	 * block cart.
	 *
	 * @param mixed $maximum Maximum.
	 * @param mixed $product Product.
	 * @param mixed $item    Cart item.
	 * @return mixed
	 */
	public function quantity_maximum( mixed $maximum, mixed $product, mixed $item = null ): mixed {
		unset( $product );

		return is_array( $item ) && true === ( $item[ self::ITEM_KEY ] ?? false ) ? 1 : $maximum;
	}

	/**
	 * `woocommerce_package_rates`: a gift has one shipping rate of 50 incl.
	 * VAT (the winner pays more when the shipping to the winner costs more).
	 *
	 * @param mixed $rates   Rates.
	 * @param mixed $package Package.
	 * @return mixed
	 */
	public function shipping( mixed $rates, mixed $package ): mixed {
		$contents = is_array( $package ) && is_array( $package['contents'] ?? null ) ? $package['contents'] : array();
		$only     = 1 === count( $contents ) ? reset( $contents ) : null;

		// Only a package of the gift alone (anything else is stopped by check_cart()).
		if ( ! is_array( $only ) || true !== ( $only[ self::ITEM_KEY ] ?? false ) || 1 !== (int) ( $only['quantity'] ?? 1 ) || ! class_exists( WC_Shipping_Rate::class ) ) {
			return $rates;
		}

		return array( 'merchboard_gift' => self::rate( 'merchboard_gift', __( 'Gift shipping', 'merchboard' ) ) );
	}

	/**
	 * A shipping rate of 50 incl. VAT: a viewer's gift, or (with its own id and
	 * name, never mistaken for a gift) a creator's giveaway paid in the shop
	 * (ADR-110).
	 *
	 * @param string $id    Rate id.
	 * @param string $label Name shown in the checkout and on the order.
	 */
	public static function rate( string $id, string $label ): WC_Shipping_Rate {
		$gross     = (float) self::SHIPPING;
		$tax_rates = wc_tax_enabled() ? WC_Tax::get_shipping_tax_rates() : array();
		$net       = array() === $tax_rates ? $gross : $gross / ( 1 + array_sum( array_map( static fn( array $rate ): float => is_numeric( $rate['rate'] ?? null ) ? (float) $rate['rate'] / 100 : 0.0, $tax_rates ) ) );
		$taxes     = array() === $tax_rates ? array() : WC_Tax::calc_shipping_tax( $net, $tax_rates );

		return new WC_Shipping_Rate( $id, $label, round( $net, 4 ), $taxes, $id );
	}

	/**
	 * `woocommerce_checkout_create_order_line_item`: marks the gift line
	 * with its creator.
	 *
	 * @param mixed $item          The new order line.
	 * @param mixed $cart_item_key Its cart item key.
	 * @param mixed $values        The cart item.
	 */
	public function line_meta( mixed $item, mixed $cart_item_key, mixed $values ): void {
		unset( $cart_item_key );

		if ( ! $item instanceof WC_Order_Item_Product || ! is_array( $values ) || true !== ( $values[ self::ITEM_KEY ] ?? false ) ) {
			return;
		}

		$creator_id = ( $this->creator_of )( (int) $item->get_product_id() );

		if ( null !== $creator_id ) {
			$item->update_meta_data( self::LINE_META, (string) $creator_id );
		}
	}

	/**
	 * `woocommerce_order_status_processing`/`_completed`: records a paid
	 * gift once and hands it to the draws.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function paid( mixed $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$line = self::gift_line( $order );

		if ( null === $line ) {
			return;
		}

		$paid = (float) $line->get_total( 'edit' ) + (float) $line->get_total_tax( 'edit' );

		if ( $paid <= 0 ) {
			// Nothing paid for the product: nothing to give.
			$this->logger->warning( 'A gift order without a paid product was not made a gift.', array( 'order_id' => (int) $order->get_id() ) );

			return;
		}

		try {
			if ( null !== $this->gifts->find_by_order( (int) $order->get_id() ) ) {
				return;
			}

			$name = WooCommercePaidOrderReader::username( $order );
			$gift = $this->gifts->create(
				(int) $line->get_meta( self::LINE_META, true, 'edit' ),
				(int) $order->get_id(),
				(int) $line->get_product_id(),
				number_format( $paid, 4, '.', '' ),
				number_format( min( (float) self::SHIPPING, (float) $order->get_shipping_total( 'edit' ) + (float) $order->get_shipping_tax( 'edit' ) ), 4, '.', '' ),
				(string) $order->get_currency( 'edit' ),
				'' === $name ? null : $name
			);
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A paid gift could not be recorded.', array( 'order_id' => (int) $order->get_id() ) + $e->context() );

			return;
		}

		if ( null !== $gift ) {
			( $this->on_paid )( $gift );
		}
	}

	/**
	 * `woocommerce_order_status_cancelled`/`_refunded`: a gift without a
	 * winner is cancelled; one with a winner stays (ADR-107 pkt. 9).
	 *
	 * @param mixed $order_id Order id.
	 */
	public function cancelled( mixed $order_id ): void {
		try {
			$gift = is_numeric( $order_id ) ? $this->gifts->find_by_order( (int) $order_id ) : null;

			if ( null !== $gift ) {
				$this->gifts->cancel( $gift->id );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A gift could not be cancelled.', $e->context() );
		}
	}

	/**
	 * A product's page, or the home page.
	 *
	 * @param int $product_id Product.
	 */
	private static function permalink( int $product_id ): string {
		$url = get_permalink( $product_id );

		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * The gift line of an order, or null.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function gift_line( WC_Order $order ): ?WC_Order_Item_Product {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product && '' !== (string) $item->get_meta( self::LINE_META, true, 'edit' ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * The gift item of a cart, or null.
	 *
	 * @param mixed $cart The cart.
	 * @return array<string, mixed>|null
	 */
	public static function gift_item( mixed $cart ): ?array {
		if ( ! $cart instanceof WC_Cart ) {
			return null;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( true === ( $item[ self::ITEM_KEY ] ?? false ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * The cheapest purchasable variant of a product (0 and no attributes for
	 * a simple product).
	 *
	 * @param WC_Product $product Product.
	 * @return array{0: int, 1: array<string, string>}
	 */
	public static function cheapest( WC_Product $product ): array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return array( 0, array() );
		}

		$best  = null;
		$price = null;

		foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
			if ( ! $variation instanceof WC_Product || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
				continue;
			}

			$amount = (float) $variation->get_price();

			if ( null === $price || $amount < $price ) {
				$best  = $variation;
				$price = $amount;
			}
		}

		if ( null === $best ) {
			return array( 0, array() );
		}

		// A variation for "any" value has '' there: the product's first option.
		$options = array();

		foreach ( $product->get_variation_attributes() as $name => $values ) {
			$options[ sanitize_title( (string) $name ) ] = array() !== $values ? (string) reset( $values ) : '';
		}

		$attributes = array();

		foreach ( $best->get_attributes() as $name => $value ) {
			$value                              = (string) $value;
			$attributes[ 'attribute_' . $name ] = '' === $value ? ( $options[ sanitize_title( (string) $name ) ] ?? '' ) : $value;
		}

		return array( (int) $best->get_id(), $attributes );
	}

	/**
	 * A product's parent (the product itself when it has none).
	 *
	 * @param WC_Product $product Product or variation.
	 */
	private static function parent_of( WC_Product $product ): int {
		$parent = (int) $product->get_parent_id();

		return $parent > 0 ? $parent : (int) $product->get_id();
	}
}
