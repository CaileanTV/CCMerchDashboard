<?php
/**
 * Paying a giveaway in the shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Giveaways;

use Closure;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Community\Gifting\GiftPurchase;
use MerchBoard\Modules\WooCommerce\SubDiscount\SubDiscountMeta;
use WC_Cart;
use WC_Discounts;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-110 pkt. 4: when the balance does not cover a giveaway, the creator
 * pays it in the shop. "Pay in the shop" in MitCCMerch opens a checkout of
 * the product's cheapest variant at the base price with the 50 kr. gift
 * shipping, alone and without codes. The line carries the creator's profit
 * as a discount the creator pays (`SubDiscountMeta::LINE_DISCOUNT`, with the
 * order flag `ORDER_CREATOR_PAID`), so the order gives the creator no profit.
 * A paid order makes the giveaway ready; a cancelled or refunded one
 * cancels it while nobody has got the gift.
 */
final class GiveawayCheckout {

	/**
	 * Cart item data: the giveaway's id.
	 */
	public const ITEM_KEY = 'merchboard_giveaway';

	/**
	 * Line meta: the giveaway's id.
	 */
	public const LINE_META = '_merchboard_giveaway';

	/**
	 * Query argument of the checkout link.
	 */
	private const QUERY = 'merchboard_giveaway_pay';

	/**
	 * Nonce action (per giveaway).
	 */
	private const NONCE = 'merchboard_giveaway_pay_';

	/**
	 * The creator of the logged-in user, or null.
	 *
	 * @var Closure(): ?int
	 */
	private Closure $creator;

	/**
	 * The base price of each giveaway line's product object in this request
	 * (by object id): only that cart object is priced, nothing is stored.
	 *
	 * @var array<int, float>
	 */
	private array $prices = array();

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): ?int $creator
	 *
	 * @param GiveawayRepository $giveaways Giveaways.
	 * @param Giveaways          $service   Giveaways service.
	 * @param Closure            $creator   The logged-in user's creator id, or null.
	 * @param Logger             $logger    Technical log.
	 */
	public function __construct( private GiveawayRepository $giveaways, private Giveaways $service, Closure $creator, private Logger $logger ) {
		$this->creator = $creator;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'add' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'only_the_giveaway' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'no_codes' ), 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'price' ), 20 );
		add_filter( 'woocommerce_product_get_price', array( $this, 'line_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'line_price' ), 99, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'quantity_html' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( $this, 'quantity_maximum' ), 20, 3 );
		add_filter( 'woocommerce_package_rates', array( $this, 'shipping' ), 50, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'line_meta' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'order_meta' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'order_meta' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancelled' ) );
	}

	/**
	 * The link that opens the checkout of a giveaway (for the logged-in creator).
	 *
	 * @param int $giveaway_id Giveaway.
	 */
	public static function url( int $giveaway_id ): string {
		return add_query_arg(
			array(
				self::QUERY => $giveaway_id,
				'_wpnonce'  => wp_create_nonce( self::NONCE . $giveaway_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * `template_redirect`: "Pay in the shop" puts the giveaway in an empty cart.
	 */
	public function add(): void {
		$id = isset( $_GET[ self::QUERY ] ) ? absint( $_GET[ self::QUERY ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.

		if ( $id < 1 || ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart ) {
			return;
		}

		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$giveaway = false === wp_verify_nonce( $nonce, self::NONCE . $id ) ? null : $this->payable( $id );
		$product  = null === $giveaway ? null : wc_get_product( $giveaway->product_id );

		if ( null === $giveaway || ! $product instanceof WC_Product ) {
			wc_add_notice( __( 'The giveaway cannot be paid right now. Open it again in your dashboard.', 'merchboard' ), 'error' );
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// Already in the cart (the creator pressed the button again): on to the checkout.
		$item = self::item( WC()->cart );

		if ( null !== $item && $giveaway->id === $item[ self::ITEM_KEY ] && 1 === count( WC()->cart->get_cart() ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( ! WC()->cart->is_empty() ) {
			wc_add_notice( __( 'A giveaway is paid on its own. Please finish or empty your cart first, and then pay the giveaway.', 'merchboard' ), 'notice' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		[ $variation_id, $attributes ] = GiftPurchase::cheapest( $product );

		if ( false === WC()->cart->add_to_cart( $giveaway->product_id, 1, $variation_id, $attributes, array( self::ITEM_KEY => $giveaway->id ) ) ) {
			wc_add_notice( __( 'The giveaway cannot be paid right now. Open it again in your dashboard.', 'merchboard' ), 'error' );
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * `woocommerce_add_to_cart_validation`: nothing joins a giveaway.
	 *
	 * @param mixed $passed WooCommerce' verdict.
	 */
	public function only_the_giveaway( mixed $passed ): bool {
		if ( $passed && function_exists( 'WC' ) && null !== self::item( WC()->cart ) ) {
			wc_add_notice( __( 'A giveaway is paid on its own. Finish the giveaway first.', 'merchboard' ), 'error' );

			return false;
		}

		return (bool) $passed;
	}

	/**
	 * `woocommerce_check_cart_items`: a giveaway is alone, one unit, awaiting
	 * payment and the logged-in creator's own.
	 */
	public function check_cart(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$item = self::item( $cart );

		if ( null === $item || ! $cart instanceof WC_Cart ) {
			return;
		}

		if ( 1 !== count( $cart->get_cart() ) || 1 !== (int) ( $item['quantity'] ?? 1 ) ) {
			wc_add_notice( __( 'A giveaway is paid on its own. Remove the other products from the cart to pay the giveaway.', 'merchboard' ), 'error' );

			return;
		}

		if ( null === $this->payable( (int) $item[ self::ITEM_KEY ] ) ) {
			wc_add_notice( __( 'This giveaway cannot be paid any more. Please remove it from the cart.', 'merchboard' ), 'error' );
		}
	}

	/**
	 * `woocommerce_coupon_is_valid`: no code on a giveaway (its price is
	 * already the base price).
	 *
	 * @param mixed $valid     WooCommerce' verdict.
	 * @param mixed $coupon    Coupon.
	 * @param mixed $discounts The discounts' cart or order.
	 * @throws Exception With the reason, which WooCommerce shows.
	 */
	public function no_codes( mixed $valid, mixed $coupon, mixed $discounts = null ): bool {
		unset( $coupon );
		$object   = $discounts instanceof WC_Discounts ? $discounts->get_object() : null;
		$giveaway = ( $object instanceof WC_Cart && null !== self::item( $object ) ) || ( $object instanceof WC_Order && null !== self::line( $object ) );

		if ( $giveaway && (bool) $valid ) {
			throw new Exception( esc_html__( 'Codes cannot be used on a giveaway.', 'merchboard' ) );
		}

		return (bool) $valid;
	}

	/**
	 * `woocommerce_before_calculate_totals`: one unit at the base price the
	 * giveaway was created with.
	 *
	 * @param mixed $cart The cart.
	 */
	public function price( mixed $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			$id      = $item[ self::ITEM_KEY ] ?? null;
			$product = $item['data'] ?? null;

			if ( ! is_int( $id ) || ! $product instanceof WC_Product ) {
				continue;
			}

			if ( 1 !== (int) ( $item['quantity'] ?? 1 ) ) {
				$cart->set_quantity( (string) $key, 1, false );
			}

			try {
				$giveaway = $this->giveaways->find( $id );
			} catch ( DatabaseException ) {
				$giveaway = null;
			}

			if ( null !== $giveaway ) {
				$this->prices[ spl_object_id( $product ) ] = self::entered( $product, self::base_gross( $giveaway ) );
			}
		}
	}

	/**
	 * `woocommerce_product_get_price` / `_variation_get_price`: the base price of
	 * a giveaway line's product object (no other object, and nothing stored).
	 *
	 * @param mixed $price   The price.
	 * @param mixed $product The product object.
	 * @return mixed
	 */
	public function line_price( mixed $price, mixed $product ): mixed {
		return $product instanceof WC_Product && isset( $this->prices[ spl_object_id( $product ) ] ) ? $this->prices[ spl_object_id( $product ) ] : $price;
	}

	/**
	 * `woocommerce_get_item_data`: what the line is.
	 *
	 * @param mixed $data Item data.
	 * @param mixed $item Cart item.
	 * @return mixed
	 */
	public function item_data( mixed $data, mixed $item ): mixed {
		if ( ! is_array( $item ) || ! is_int( $item[ self::ITEM_KEY ] ?? null ) ) {
			return $data;
		}

		return array(
			array(
				'key'   => __( 'Giveaway', 'merchboard' ),
				'value' => __( 'Your own giveaway: you pay the base price and 50 kr. shipping. The winner chooses size and colour.', 'merchboard' ),
			),
		);
	}

	/**
	 * `woocommerce_cart_item_quantity`: one giveaway.
	 *
	 * @param mixed $html Quantity field.
	 * @param mixed $key  Cart item key.
	 * @param mixed $item Cart item.
	 * @return mixed
	 */
	public function quantity_html( mixed $html, mixed $key, mixed $item = null ): mixed {
		unset( $key );

		return is_array( $item ) && is_int( $item[ self::ITEM_KEY ] ?? null ) ? '1' : $html;
	}

	/**
	 * `woocommerce_store_api_product_quantity_maximum`: one giveaway in the
	 * block cart.
	 *
	 * @param mixed $maximum Maximum.
	 * @param mixed $product Product.
	 * @param mixed $item    Cart item.
	 * @return mixed
	 */
	public function quantity_maximum( mixed $maximum, mixed $product, mixed $item = null ): mixed {
		unset( $product );

		return is_array( $item ) && is_int( $item[ self::ITEM_KEY ] ?? null ) ? 1 : $maximum;
	}

	/**
	 * `woocommerce_package_rates`: the giveaway shipping of 50 incl. VAT.
	 *
	 * @param mixed $rates   Rates.
	 * @param mixed $package Package.
	 * @return mixed
	 */
	public function shipping( mixed $rates, mixed $package ): mixed {
		$contents = is_array( $package ) && is_array( $package['contents'] ?? null ) ? $package['contents'] : array();
		$only     = 1 === count( $contents ) ? reset( $contents ) : null;

		if ( ! is_array( $only ) || ! is_int( $only[ self::ITEM_KEY ] ?? null ) || 1 !== (int) ( $only['quantity'] ?? 1 ) ) {
			return $rates;
		}

		// Its own rate, so the order is never mistaken for a viewer's Twitch gift.
		return array( 'merchboard_giveaway' => GiftPurchase::rate( 'merchboard_giveaway', __( 'Giveaway shipping', 'merchboard' ) ) );
	}

	/**
	 * `woocommerce_checkout_create_order_line_item`: the line keeps its
	 * giveaway and the discount the creator pays (the full price less the
	 * base price, incl. VAT).
	 *
	 * @param mixed $item          The new order line.
	 * @param mixed $cart_item_key Its cart item key.
	 * @param mixed $values        The cart item.
	 */
	public function line_meta( mixed $item, mixed $cart_item_key, mixed $values ): void {
		unset( $cart_item_key );
		$id = is_array( $values ) ? ( $values[ self::ITEM_KEY ] ?? null ) : null;

		if ( ! $item instanceof WC_Order_Item_Product || ! is_int( $id ) ) {
			return;
		}

		try {
			$giveaway = $this->giveaways->find( $id );
		} catch ( DatabaseException ) {
			$giveaway = null;
		}

		$item->update_meta_data( self::LINE_META, (string) $id );

		if ( null !== $giveaway ) {
			$discount = max( 0.0, (float) $giveaway->unit_price - self::base_gross( $giveaway ) );
			$item->update_meta_data( SubDiscountMeta::LINE_DISCOUNT, number_format( $discount, 4, '.', '' ) );
		}
	}

	/**
	 * `woocommerce_checkout_create_order` / the block checkout: the order is
	 * marked as a giveaway the creator pays, so the ledger reads the line's
	 * discount (SubDiscountMeta::ORDER_CREATOR_PAID).
	 *
	 * @param mixed $order Order.
	 */
	public function order_meta( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$line = self::line( $order );

		if ( null !== $line ) {
			$order->update_meta_data( SubDiscountMeta::ORDER_CREATOR_PAID, (string) $line->get_meta( self::LINE_META, true, 'edit' ) );
		}
	}

	/**
	 * `woocommerce_order_status_processing`/`_completed`: the giveaway is paid.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function paid( mixed $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		$line  = $order instanceof WC_Order ? self::line( $order ) : null;

		if ( ! $order instanceof WC_Order || null === $line ) {
			return;
		}

		try {
			$this->service->paid( (int) $line->get_meta( self::LINE_META, true, 'edit' ), (int) $order->get_id() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A paid giveaway could not be made ready.', array( 'order_id' => (int) $order->get_id() ) + $e->context() );
		}
	}

	/**
	 * `woocommerce_order_status_cancelled`/`_refunded`.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function cancelled( mixed $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		$line  = $order instanceof WC_Order ? self::line( $order ) : null;

		if ( ! $order instanceof WC_Order || null === $line ) {
			return;
		}

		try {
			$this->service->order_cancelled( (int) $line->get_meta( self::LINE_META, true, 'edit' ), (int) $order->get_id() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A giveaway could not be cancelled with its order.', array( 'order_id' => (int) $order->get_id() ) + $e->context() );
		}
	}

	/**
	 * The giveaway line of an order, or null.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function line( WC_Order $order ): ?WC_Order_Item_Product {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product && '' !== (string) $item->get_meta( self::LINE_META, true, 'edit' ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * The giveaway item of a cart, or null.
	 *
	 * @param mixed $cart The cart.
	 * @return array<string, mixed>|null
	 */
	private static function item( mixed $cart ): ?array {
		if ( ! $cart instanceof WC_Cart ) {
			return null;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( is_int( $item[ self::ITEM_KEY ] ?? null ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * The logged-in creator's giveaway awaiting payment, or null (never while
	 * an administrator looks at the creator's dashboard: only the creator pays).
	 *
	 * @param int $id Giveaway.
	 */
	private function payable( int $id ): ?Giveaway {
		$creator_id = null === ImpersonationSession::creator_id() ? ( $this->creator )() : null;

		try {
			$giveaway = null === $creator_id ? null : $this->giveaways->find( $id );
		} catch ( DatabaseException ) {
			return null;
		}

		return null !== $giveaway && $creator_id === $giveaway->creator_id && Giveaway::AWAITING_PAYMENT === $giveaway->status ? $giveaway : null;
	}

	/**
	 * The giveaway's base price incl. VAT: its cost less the shipping.
	 *
	 * @param Giveaway $giveaway Giveaway.
	 */
	private static function base_gross( Giveaway $giveaway ): float {
		return max( 0.0, (float) $giveaway->cost - (float) $giveaway->shipping_credit );
	}

	/**
	 * A price incl. VAT as the shop enters the product's prices.
	 *
	 * @param WC_Product $product The cart's product.
	 * @param float      $gross   Price incl. VAT.
	 */
	private static function entered( WC_Product $product, float $gross ): float {
		if ( ! wc_tax_enabled() || wc_prices_include_tax() ) {
			return round( $gross, 4 );
		}

		// Prices are entered without VAT: the product's own rate from 100.
		$with_tax = (float) wc_get_price_including_tax( $product, array( 'price' => 100 ) );

		return $with_tax > 0 ? round( $gross * 100 / $with_tax, 4 ) : round( $gross, 4 );
	}
}
