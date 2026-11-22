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
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
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
 *   and colour later), 1–10 units (ADR-111: each unit is its own gift with
 *   its own draw), nothing else in the cart, and one shipping rate of 50
 *   (incl. VAT) per gift.
 * - The buyer's optional name is the alerts' username field (with its
 *   checkbox in the block checkout); without one the gift is anonymous.
 * - A collection whose designs cost the same can be given with free choice
 *   (ADR-120): the cart holds the design the purchase is booked on, the
 *   collection is never a line, and the designs and prices are locked on
 *   the order line at the checkout.
 * - When the order is paid its gifts are recorded once per unit and handed
 *   to the draws; a cancelled or refunded order cancels the gifts that have
 *   no winner.
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
	 * Cart item key: the collection given with free choice (ADR-120).
	 */
	public const FREE_KEY = 'merchboard_gift_free';

	/**
	 * Order line meta: what was locked with free choice.
	 */
	public const FREE_META = '_merchboard_gift_free';

	/**
	 * The gift shipping (incl. VAT).
	 */
	public const SHIPPING = '50';

	/**
	 * The most gifts in one purchase (ADR-111).
	 */
	public const MAX_GIFTS = 10;

	/**
	 * Query argument of the gift button.
	 */
	private const QUERY = 'merchboard_gift';

	/**
	 * Query argument of the number of gifts.
	 */
	private const QUANTITY = 'merchboard_gift_quantity';

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
	 * Called with a newly paid gift and the number of gifts in its order.
	 *
	 * @var Closure(Gift, int): void
	 */
	private Closure $on_paid;

	/**
	 * A creator's name and Twitch channel login for the gift box, or null.
	 *
	 * @var Closure(int): ?array{name: string, login: string}
	 */
	private ?Closure $channel;

	/**
	 * Whether a collection's page is showing a design's form, whose gift box
	 * the collection's own replaces.
	 *
	 * @var bool
	 */
	private bool $in_collection = false;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?int $creator_of
	 * @phpstan-param Closure(int): ?bool $live
	 * @phpstan-param Closure(Gift, int): void $on_paid
	 * @phpstan-param (Closure(int): ?array{name: string, login: string})|null $channel
	 *
	 * @param GiftingSettingsRepository $settings   The creators' gifting settings.
	 * @param GiftRepository            $gifts      Gifts.
	 * @param Closure                   $creator_of The creator of a parent product, or null.
	 * @param Closure                   $live       Whether a creator is live; null without a connected channel.
	 * @param Closure                   $on_paid    Called with a newly paid gift and its order's number of gifts (the draws).
	 * @param Logger                    $logger     Technical log.
	 * @param Closure|null              $channel    A creator's name and Twitch login for the gift box; null shows no channel link.
	 * @param DesignCollections         $collections The design collections (ADR-120).
	 * @param FreeChoiceDesigns|null    $free        Free choice in a collection; null offers none.
	 */
	public function __construct( private GiftingSettingsRepository $settings, private GiftRepository $gifts, Closure $creator_of, Closure $live, Closure $on_paid, private Logger $logger, ?Closure $channel = null, private DesignCollections $collections = new DesignCollections(), private ?FreeChoiceDesigns $free = null ) {
		$this->creator_of = $creator_of;
		$this->live       = $live;
		$this->on_paid    = $on_paid;
		$this->channel    = $channel;
	}

	/**
	 * Hooks the purchase into WooCommerce.
	 */
	public function register(): void {
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'button' ) );
		// A collection's page (ADR-120): around the plugin's own callback at 10.
		add_action( 'woocommerce_' . DesignCollections::TYPE . '_add_to_cart', array( $this, 'collection_start' ), 1 );
		add_action( 'woocommerce_' . DesignCollections::TYPE . '_add_to_cart', array( $this, 'collection_box' ), 20 );
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
	 * `woocommerce_after_add_to_cart_form`: the gift box (ADR-111): the
	 * creator live on Twitch, a number of gifts and the button.
	 */
	public function button(): void {
		global $product;

		// Inside a collection the design shown is only the plugin's preselection.
		$creator_id = ! $this->in_collection && $product instanceof WC_Product ? $this->giftable( (int) $product->get_id() ) : null;

		if ( ! $product instanceof WC_Product || null === $creator_id ) {
			return;
		}

		$this->box( $creator_id, (int) $product->get_id() );
	}

	/**
	 * Before the collection plugin's form: the form inside gets no gift box
	 * of its own.
	 */
	public function collection_start(): void {
		$this->in_collection = true;
	}

	/**
	 * After the collection plugin's form: one gift box, where the buyer
	 * chooses the design (ADR-120 pkt. 6).
	 */
	public function collection_box(): void {
		global $product;

		$this->in_collection = false;

		if ( ! $product instanceof WC_Product || ! $this->collections->is_collection( (int) $product->get_id() ) ) {
			return;
		}

		$asked  = isset( $_GET['design'] ) ? absint( $_GET['design'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The plugin's read-only choice of design.
		$choice = ( new GiftDesigns( $this->collections, $this->gift_of( ... ) ) )->choice( (int) $product->get_id(), $asked );

		if ( null === $choice ) {
			return;
		}

		// Read only here, where the creator is live: every design's prices are read.
		$offer = null === $choice['creator'] ? null : $this->free?->offer( (int) $product->get_id() );
		$free  = null !== $offer && $this->giftable( $offer['product_id'] ) === $choice['creator'];

		$this->box( $choice['creator'], (int) $product->get_id(), $choice['designs'], $choice['chosen'], $free );
	}

	/**
	 * A design's creator and name when it can be given now, or null.
	 *
	 * @param int $product_id Design.
	 * @return array{creator: int, name: string}|null
	 */
	private function gift_of( int $product_id ): ?array {
		$design = wc_get_product( $product_id );

		if ( ! $design instanceof WC_Product || 'publish' !== $design->get_status() || ! $design->is_purchasable() || ! $design->is_in_stock() ) {
			return null;
		}

		$creator_id = $this->giftable( $product_id );

		return null === $creator_id ? null : array(
			'creator' => $creator_id,
			'name'    => wp_strip_all_tags( $design->get_name() ),
		);
	}

	/**
	 * The gift box.
	 *
	 * @phpstan-param list<array{id: int, name: string}> $designs
	 *
	 * @param int|null $creator_id The creator, null when a collection has several.
	 * @param int      $product_id The page's product.
	 * @param array[]  $designs    A collection's designs to choose from.
	 * @param int      $chosen     The design the buyer chose, 0 for none.
	 * @param bool     $free       Whether the collection can be given with free choice.
	 */
	private function box( ?int $creator_id, int $product_id, array $designs = array(), int $chosen = 0, bool $free = false ): void {
		$channel        = null === $this->channel || null === $creator_id ? null : ( $this->channel )( $creator_id );
		$merchboard_box = array(
			'action'   => self::permalink( $product_id ),
			'product'  => $product_id,
			'designs'  => $designs,
			'chosen'   => $chosen,
			// The collection's own id stands for free choice.
			'free'     => $free ? $product_id : '',
			'query'    => self::QUERY,
			'quantity' => self::QUANTITY,
			'nonce'    => wp_create_nonce( self::NONCE ),
			'max'      => self::MAX_GIFTS,
			'name'     => null === $channel ? '' : $channel['name'],
			'login'    => null === $channel ? '' : $channel['login'],
			'shipping' => self::SHIPPING,
		);

		include dirname( __DIR__, 4 ) . '/templates/community/gift-box.php';
	}

	/**
	 * `template_redirect`: the gift button adds the gift to an empty cart.
	 */
	public function add(): void {
		$product_id = isset( $_GET[ self::QUERY ] ) ? absint( $_GET[ self::QUERY ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.

		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart ) {
			return;
		}

		if ( $product_id < 1 ) {
			$page_id = isset( $_GET[ self::QUERY ] ) ? (int) get_queried_object_id() : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nothing is changed: only the reason is shown.

			// The collection's box sent without a design.
			if ( $page_id > 0 && $this->collections->is_collection( $page_id ) ) {
				wc_add_notice( __( 'Choose which design you give.', 'merchboard' ), 'error' );
				wp_safe_redirect( self::permalink( $page_id ) );
				exit;
			}

			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$valid = false !== wp_verify_nonce( $nonce, self::NONCE );
		$back  = $this->collections->design_url( $product_id );
		$back  = '' === $back ? home_url( '/' ) : $back;
		$data  = array( self::ITEM_KEY => true );

		// A collection is free choice, and never the line: the design it is booked on is.
		if ( $valid && $this->collections->is_collection( $product_id ) ) {
			$offer = $this->free?->offer( $product_id );

			if ( null !== $offer ) {
				$data[ self::FREE_KEY ] = $product_id;
				$product_id             = $offer['product_id'];
			}
		}

		$product = $valid ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof WC_Product || null === $this->giftable( $product_id ) ) {
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
		$quantity                      = isset( $_GET[ self::QUANTITY ] ) ? self::quantity( absint( $_GET[ self::QUANTITY ] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.

		if ( false === WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes, $data ) ) {
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
	 * gift is alone, 1–MAX_GIFTS units, and its creator's gifting is still
	 * open. A guest who logs in gets the saved cart merged in; this stops that
	 * order.
	 */
	public function check_cart(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$gift = self::gift_item( $cart );

		if ( null === $gift || ! $cart instanceof WC_Cart ) {
			return;
		}

		$units = (int) ( $gift['quantity'] ?? 1 );

		if ( 1 !== count( $cart->get_cart() ) || $units < 1 || $units > self::MAX_GIFTS ) {
			wc_add_notice( __( 'A gift is bought on its own. Remove the other products from the cart to give the gift.', 'merchboard' ), 'error' );

			return;
		}

		$product    = $gift['data'] ?? null;
		$parent     = $product instanceof WC_Product ? self::parent_of( $product ) : 0;
		$creator_id = $parent > 0 ? ( $this->creator_of )( $parent ) : null;

		if ( null === $creator_id || ! Settings::twitch_gifting_enabled() || ! $this->settings->find( $creator_id )->open() ) {
			wc_add_notice( __( 'Gifts cannot be bought for this stream right now. Please remove the gift from the cart.', 'merchboard' ), 'error' );

			return;
		}

		$collection = self::free_of( $gift );

		if ( $collection > 0 && null === $this->lock_now( $collection, $parent, $creator_id ) ) {
			wc_add_notice( __( 'This collection can no longer be given with free choice. Please remove the gift from the cart and choose a design to give.', 'merchboard' ), 'error' );
		}
	}

	/**
	 * What free choice locks at this moment, or null when the cart's design
	 * cannot be booked with free choice in that collection any more.
	 *
	 * @param int $collection_id The collection.
	 * @param int $product_id    The design in the cart.
	 * @param int $creator_id    Its creator.
	 */
	private function lock_now( int $collection_id, int $product_id, int $creator_id ): ?FreeChoiceLock {
		$offer = $this->free?->offer( $collection_id );

		return null !== $offer && $creator_id === $offer['creator'] && in_array( $product_id, $offer['lock']->product_ids(), true ) ? $offer['lock'] : null;
	}

	/**
	 * The collection a cart item gives with free choice, or 0.
	 *
	 * @param array<mixed> $item Cart item.
	 */
	private static function free_of( array $item ): int {
		$id = $item[ self::FREE_KEY ] ?? null;

		return is_int( $id ) && $id > 0 ? $id : 0;
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
	 * `woocommerce_before_calculate_totals`: a gift purchase is 1–MAX_GIFTS units.
	 *
	 * @param mixed $cart The cart.
	 */
	public function one_unit( mixed $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			$units = (int) ( $item['quantity'] ?? 1 );

			if ( true === ( $item[ self::ITEM_KEY ] ?? false ) && self::quantity( $units ) !== $units ) {
				$cart->set_quantity( (string) $key, self::quantity( $units ), false );
			}
		}
	}

	/**
	 * A number of gifts within 1–MAX_GIFTS.
	 *
	 * @param int $units Units asked for.
	 */
	public static function quantity( int $units ): int {
		return max( 1, min( self::MAX_GIFTS, $units ) );
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

		$collection = self::free_of( $item );

		if ( $collection > 0 ) {
			return array(
				array(
					'key'   => __( 'Free choice', 'merchboard' ),
					/* translators: %s: the collection's name. */
					'value' => sprintf( __( '%s: each gift is won by a viewer in the stream, who chooses design, size and colour.', 'merchboard' ), FreeChoiceDesigns::name( $collection ) ),
				),
			);
		}

		return array(
			array(
				'key'   => __( 'Gift', 'merchboard' ),
				'value' => __( 'Each gift is won by a viewer in the stream, who chooses size and colour.', 'merchboard' ),
			),
		);
	}

	/**
	 * `woocommerce_cart_item_quantity`: the number of gifts is chosen on the
	 * product page and shown as it is here.
	 *
	 * @param mixed $html Quantity field.
	 * @param mixed $key  Cart item key.
	 * @param mixed $item Cart item.
	 * @return mixed
	 */
	public function quantity_html( mixed $html, mixed $key, mixed $item = null ): mixed {
		unset( $key );

		return is_array( $item ) && true === ( $item[ self::ITEM_KEY ] ?? false ) ? (string) self::quantity( (int) ( $item['quantity'] ?? 1 ) ) : $html;
	}

	/**
	 * `woocommerce_store_api_product_quantity_maximum`: at most MAX_GIFTS
	 * gifts in the block cart.
	 *
	 * @param mixed $maximum Maximum.
	 * @param mixed $product Product.
	 * @param mixed $item    Cart item.
	 * @return mixed
	 */
	public function quantity_maximum( mixed $maximum, mixed $product, mixed $item = null ): mixed {
		unset( $product );

		return is_array( $item ) && true === ( $item[ self::ITEM_KEY ] ?? false ) ? self::MAX_GIFTS : $maximum;
	}

	/**
	 * `woocommerce_package_rates`: a gift purchase has one shipping rate of
	 * 50 incl. VAT per gift (the winner pays more when the shipping to the
	 * winner costs more).
	 *
	 * @param mixed $rates   Rates.
	 * @param mixed $package Package.
	 * @return mixed
	 */
	public function shipping( mixed $rates, mixed $package ): mixed {
		$contents = is_array( $package ) && is_array( $package['contents'] ?? null ) ? $package['contents'] : array();
		$only     = 1 === count( $contents ) ? reset( $contents ) : null;

		// Only a package of the gift alone (anything else is stopped by check_cart()).
		$units = is_array( $only ) ? (int) ( $only['quantity'] ?? 1 ) : 0;

		if ( ! is_array( $only ) || true !== ( $only[ self::ITEM_KEY ] ?? false ) || self::quantity( $units ) !== $units || ! class_exists( WC_Shipping_Rate::class ) ) {
			return $rates;
		}

		return array( 'merchboard_gift' => self::rate( 'merchboard_gift', __( 'Gift shipping', 'merchboard' ), (float) self::SHIPPING * $units ) );
	}

	/**
	 * A shipping rate of 50 incl. VAT: a viewer's gift, or (with its own id and
	 * name, never mistaken for a gift) a creator's giveaway paid in the shop
	 * (ADR-110).
	 *
	 * @param string     $id    Rate id.
	 * @param string     $label Name shown in the checkout and on the order.
	 * @param float|null $gross Amount incl. VAT; null for SHIPPING.
	 */
	public static function rate( string $id, string $label, ?float $gross = null ): WC_Shipping_Rate {
		$gross     = $gross ?? (float) self::SHIPPING;
		$tax_rates = wc_tax_enabled() ? WC_Tax::get_shipping_tax_rates() : array();
		$net       = array() === $tax_rates ? $gross : $gross / ( 1 + array_sum( array_map( static fn( array $rate ): float => is_numeric( $rate['rate'] ?? null ) ? (float) $rate['rate'] / 100 : 0.0, $tax_rates ) ) );
		$taxes     = array() === $tax_rates ? array() : WC_Tax::calc_shipping_tax( $net, $tax_rates );

		return new WC_Shipping_Rate( $id, $label, round( $net, 4 ), $taxes, $id );
	}

	/**
	 * `woocommerce_checkout_create_order_line_item`: marks the gift line
	 * with its creator, and with free choice with the collection's name and
	 * what is locked now.
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

		$product_id = (int) $item->get_product_id( 'edit' );
		$creator_id = ( $this->creator_of )( $product_id );

		if ( null === $creator_id ) {
			return;
		}

		$item->update_meta_data( self::LINE_META, (string) $creator_id );

		$collection = self::free_of( $values );

		if ( $collection < 1 ) {
			return;
		}

		$lock = $this->lock_now( $collection, $product_id, $creator_id );

		if ( null === $lock ) {
			// check_cart() refuses it just before; changed in between, it is a gift of the one design.
			$this->logger->warning(
				'A gift with free choice could not be locked and is of one design.',
				array(
					'collection_id' => $collection,
					'product_id'    => $product_id,
				)
			);

			return;
		}

		$item->update_meta_data( self::FREE_META, $lock->to_array() );
		// Shown to the buyer: the line itself is the design the purchase is booked on.
		$item->update_meta_data( __( 'Free choice', 'merchboard' ), $lock->name );
	}

	/**
	 * `woocommerce_order_status_processing`/`_completed`: records a paid
	 * order's gifts once per unit (ADR-111) and hands them to the draws.
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

		$paid  = (float) $line->get_total( 'edit' ) + (float) $line->get_total_tax( 'edit' );
		$units = self::quantity( (int) $line->get_quantity() );

		if ( $paid <= 0 ) {
			// Nothing paid for the product: nothing to give.
			$this->logger->warning( 'A gift order without a paid product was not made a gift.', array( 'order_id' => (int) $order->get_id() ) );

			return;
		}

		$product_id = (int) $line->get_product_id( 'edit' );
		$lock       = FreeChoiceLock::from_stored( $line->get_meta( self::FREE_META, true, 'edit' ) );

		// A lock counts only on the design it was bought with (a line can be edited).
		if ( null !== $lock && ! in_array( $product_id, $lock->product_ids(), true ) ) {
			$lock = null;
		}

		$name     = WooCommercePaidOrderReader::username( $order );
		$shipping = ( (float) $order->get_shipping_total( 'edit' ) + (float) $order->get_shipping_tax( 'edit' ) ) / $units;
		$new      = array();

		// One gift per unit; each is stored once, so a second "paid" adds none.
		try {
			for ( $unit = 1; $unit <= $units; $unit++ ) {
				$gift = $this->gifts->create(
					(int) $line->get_meta( self::LINE_META, true, 'edit' ),
					(int) $order->get_id(),
					$product_id,
					number_format( $paid / $units, 4, '.', '' ),
					number_format( min( (float) self::SHIPPING, $shipping ), 4, '.', '' ),
					(string) $order->get_currency( 'edit' ),
					'' === $name ? null : $name,
					$unit,
					$lock
				);

				if ( null !== $gift ) {
					$new[] = $gift;
				}
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A paid gift could not be recorded.', array( 'order_id' => (int) $order->get_id() ) + $e->context() );
		}

		foreach ( $new as $gift ) {
			( $this->on_paid )( $gift, $units );
		}
	}

	/**
	 * `woocommerce_order_status_cancelled`/`_refunded`: the order's gifts
	 * without a winner are cancelled; those with a winner stay (ADR-107 pkt. 9).
	 *
	 * @param mixed $order_id Order id.
	 */
	public function cancelled( mixed $order_id ): void {
		try {
			foreach ( is_numeric( $order_id ) ? $this->gifts->for_order( (int) $order_id ) : array() as $gift ) {
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
