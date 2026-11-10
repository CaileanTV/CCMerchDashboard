<?php
/**
 * The Twitch subscriber discount as a coupon of its own type.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerLogin;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponCode;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfitDiscount;
use MerchBoard\Modules\WooCommerce\SubDiscount\SubDiscountMeta;
use WC_Abstract_Order;
use WC_Cart;
use WC_Coupon;
use WC_Discounts;
use WC_Order;
use WC_Order_Item_Coupon;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106 pkt. 4 and 6: a virtual coupon (reserved code, own type) that the
 * plugin adds to and removes from the cart itself.
 *
 * - Per unit: the creator's percent of the line's price before coupons (a
 *   sale price too), at most the creator's profit on the unit.
 * - Best discount wins per line: the coupon is calculated after every
 *   other coupon and only tops the line's other discounts up to the
 *   subscriber discount (never adds to them).
 * - It stays next to an individual-use coupon (a creator code).
 * - The order keeps each line's discount in the paid basis (the ledger's
 *   S), the tier and percent, the unit discount for a later recalculation,
 *   and the customer's Twitch account per order.
 */
final class TwitchSubCoupon {

	/**
	 * Sort value: after WooCommerce' own types (1–3).
	 */
	private const SORT = 100;

	/**
	 * Line meta: the unit discount before other coupons (the price basis
	 * WooCommerce discounts), for a recalculation of the order.
	 */
	public const LINE_UNIT = '_merchboard_sub_unit';

	/**
	 * Line meta: the line's creator.
	 */
	public const LINE_CREATOR = '_merchboard_sub_creator';

	/**
	 * The calculation running now.
	 *
	 * @var WC_Discounts|null
	 */
	private ?WC_Discounts $discounts = null;

	/**
	 * Unit prices of the running calculation by item key.
	 *
	 * @var array<string, float>
	 */
	private array $prices = array();

	/**
	 * Discounts given in the cart by cart item key.
	 *
	 * @var array<string, array{amount: float, unit: float, creator_id: int, tier: int, bps: int, gifted: bool}>
	 */
	private array $given = array();

	/**
	 * Discounts given in an order recalculation by order item id.
	 *
	 * @var array<int, float>
	 */
	private array $recalculated = array();

	/**
	 * Constructor.
	 *
	 * @param TwitchSubDiscount     $discount Which lines qualify.
	 * @param CreatorProfitDiscount $profits  The creator's profit on a unit (the cap).
	 * @param TwitchCustomerLogin   $login    The customer's Twitch login.
	 * @param Logger                $logger   Technical log.
	 */
	public function __construct( private TwitchSubDiscount $discount, private CreatorProfitDiscount $profits, private TwitchCustomerLogin $login, private Logger $logger ) {
	}

	/**
	 * Hooks the coupon into WooCommerce.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'discount_types' ) );
		add_filter( 'woocommerce_product_coupon_types', array( $this, 'product_types' ) );
		add_filter( 'woocommerce_coupon_sort', array( $this, 'sort' ), 10, 2 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'items_to_apply' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'valid_for_product' ), 10, 4 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'discount_amount' ), 10, 5 );
		add_filter( 'woocommerce_apply_individual_use_coupon', array( $this, 'keep_with_individual_use' ), 10, 2 );
		add_filter( 'woocommerce_apply_with_individual_use_coupon', array( $this, 'apply_with_individual_use' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync' ), 20 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'label' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'coupon_html' ), 10, 3 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'order_coupon' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'record_line' ), 10, 4 );
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'record_recalculation' ), 10, 2 );
	}

	/**
	 * `woocommerce_get_shop_coupon_data`: the reserved code is a virtual
	 * coupon of this type without limits.
	 *
	 * @param mixed $data Coupon data so far (false: none).
	 * @param mixed $code The code.
	 * @return mixed
	 */
	public function coupon_data( mixed $data, mixed $code ): mixed {
		if ( ! is_string( $code ) || SubDiscountMeta::COUPON_CODE !== CouponCode::normalize( $code ) ) {
			return $data;
		}

		return array(
			'discount_type'  => SubDiscountMeta::COUPON_TYPE,
			'amount'         => 0,
			'individual_use' => false,
			'usage_limit'    => 0,
		);
	}

	/**
	 * `woocommerce_coupon_discount_types`.
	 *
	 * @param mixed $types Discount types.
	 * @return array<mixed>
	 */
	public function discount_types( mixed $types ): array {
		$types                                 = is_array( $types ) ? $types : array();
		$types[ SubDiscountMeta::COUPON_TYPE ] = __( 'Merch Dashboard: Twitch subscriber discount', 'merchboard' );

		return $types;
	}

	/**
	 * `woocommerce_product_coupon_types`: the type applies per product.
	 *
	 * @param mixed $types Product coupon types.
	 * @return array<mixed>
	 */
	public function product_types( mixed $types ): array {
		$types   = is_array( $types ) ? $types : array();
		$types[] = SubDiscountMeta::COUPON_TYPE;

		return array_values( array_unique( $types ) );
	}

	/**
	 * `woocommerce_coupon_sort`: after every other coupon.
	 *
	 * @param mixed $sort   Sort value.
	 * @param mixed $coupon Coupon.
	 * @return mixed
	 */
	public function sort( mixed $sort, mixed $coupon ): mixed {
		return self::ours( $coupon ) ? self::SORT : $sort;
	}

	/**
	 * `woocommerce_coupon_get_items_to_apply`: remembers the calculation and
	 * the unit prices before this coupon.
	 *
	 * @param mixed $items     Items (WooCommerce' objects with key, quantity, price in cents).
	 * @param mixed $coupon    Coupon.
	 * @param mixed $discounts The calculation.
	 * @return mixed
	 */
	public function items_to_apply( mixed $items, mixed $coupon, mixed $discounts = null ): mixed {
		if ( ! self::ours( $coupon ) || ! is_array( $items ) ) {
			return $items;
		}

		$this->discounts = $discounts instanceof WC_Discounts ? $discounts : null;
		$this->prices    = array();
		$precision       = 10 ** wc_get_price_decimals();

		foreach ( $items as $item ) {
			if ( is_object( $item ) && isset( $item->key, $item->price, $item->quantity ) && is_numeric( $item->price ) && is_numeric( $item->quantity ) && (float) $item->quantity > 0 ) {
				$this->prices[ (string) $item->key ] = (float) $item->price / $precision / (float) $item->quantity;
			}
		}

		return $items;
	}

	/**
	 * `woocommerce_coupon_is_valid_for_product`: for this type, a line that
	 * gets the subscriber discount (in an order: a line that had it).
	 *
	 * @param mixed $valid   WooCommerce' verdict.
	 * @param mixed $product Product.
	 * @param mixed $coupon  Coupon.
	 * @param mixed $item    Cart item (array) or order item.
	 */
	public function valid_for_product( mixed $valid, mixed $product, mixed $coupon, mixed $item = null ): bool {
		if ( ! self::ours( $coupon ) ) {
			return (bool) $valid;
		}

		if ( $item instanceof WC_Order_Item_Product ) {
			return (float) $item->get_meta( self::LINE_UNIT, true, 'edit' ) > 0;
		}

		return $product instanceof WC_Product && null !== $this->discount->rate( self::parent_id( $product ) );
	}

	/**
	 * `woocommerce_coupon_get_discount_amount`: the top-up of the line to
	 * the subscriber discount, per unit when WooCommerce asks for one unit.
	 *
	 * @param mixed $discount           WooCommerce' amount (0 for a custom type).
	 * @param mixed $discounting_amount The unit price.
	 * @param mixed $item               Cart item (array) or order item.
	 * @param mixed $single             Whether the amount is for one unit.
	 * @param mixed $coupon             Coupon.
	 */
	public function discount_amount( mixed $discount, mixed $discounting_amount, mixed $item, mixed $single, mixed $coupon ): float {
		if ( ! self::ours( $coupon ) ) {
			return is_numeric( $discount ) ? (float) $discount : 0.0;
		}

		$line = $this->line( $item, is_numeric( $discounting_amount ) ? (float) $discounting_amount : 0.0 );

		if ( null === $line || $line['quantity'] < 1 ) {
			return 0.0;
		}

		$amount = self::top_up( $line['quantity'], $line['unit'], $this->other_discounts( $line['key'] ), wc_get_price_decimals() );

		if ( null !== $line['order_item_id'] ) {
			$this->recalculated[ $line['order_item_id'] ] = $amount;
		} elseif ( null !== $line['rate'] ) {
			$this->given[ $line['key'] ] = array(
				'amount' => $amount,
				'unit'   => $line['unit'],
			) + $line['rate'];
		}

		return (bool) $single ? $amount / $line['quantity'] : $amount;
	}

	/**
	 * `woocommerce_apply_individual_use_coupon`: an individual-use coupon (a
	 * creator code) keeps this coupon.
	 *
	 * @param mixed $keep   Codes to keep.
	 * @param mixed $coupon The individual-use coupon.
	 * @return array<mixed>
	 */
	public function keep_with_individual_use( mixed $keep, mixed $coupon ): array {
		unset( $coupon );
		$keep   = is_array( $keep ) ? $keep : array();
		$keep[] = SubDiscountMeta::COUPON_CODE;

		return array_values( array_unique( $keep ) );
	}

	/**
	 * `woocommerce_apply_with_individual_use_coupon`: this coupon may join an
	 * individual-use coupon.
	 *
	 * @param mixed $apply  WooCommerce' verdict.
	 * @param mixed $coupon The coupon being applied.
	 */
	public function apply_with_individual_use( mixed $apply, mixed $coupon ): bool {
		return self::ours( $coupon ) || (bool) $apply;
	}

	/**
	 * `woocommerce_before_calculate_totals`: adds the coupon when a line
	 * qualifies and removes it otherwise, without notices.
	 *
	 * @param mixed $cart The cart.
	 */
	public function sync( mixed $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		$this->given = array();
		$applied     = array_map( static fn( mixed $code ): string => CouponCode::normalize( (string) $code ), $cart->get_applied_coupons() );
		$has         = in_array( SubDiscountMeta::COUPON_CODE, $applied, true );
		$qualifies   = false;

		try {
			foreach ( $cart->get_cart() as $cart_item ) {
				$product = $cart_item['data'] ?? null;

				if ( $product instanceof WC_Product && null !== $this->discount->rate( self::parent_id( $product ) ) ) {
					$qualifies = true;
					break;
				}
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The Twitch subscriber discount could not be checked; it does not apply.', $e->context() );
			$qualifies = false;
		}

		if ( $qualifies && ! $has ) {
			$applied[] = SubDiscountMeta::COUPON_CODE;
			$cart->set_applied_coupons( $applied );
		} elseif ( ! $qualifies && $has ) {
			$cart->set_applied_coupons( array_values( array_diff( $applied, array( SubDiscountMeta::COUPON_CODE ) ) ) );
		}
	}

	/**
	 * `woocommerce_cart_totals_coupon_label`: a name instead of the code.
	 *
	 * @param mixed $label  Label.
	 * @param mixed $coupon Coupon.
	 * @return mixed
	 */
	public function label( mixed $label, mixed $coupon ): mixed {
		return self::ours( $coupon ) ? __( 'Twitch subscriber discount', 'merchboard' ) : $label;
	}

	/**
	 * `woocommerce_cart_totals_coupon_html`: the amount without a remove
	 * link (the discount follows the login).
	 *
	 * @param mixed $html     WooCommerce' HTML.
	 * @param mixed $coupon   Coupon.
	 * @param mixed $discount The amount's HTML.
	 * @return mixed
	 */
	public function coupon_html( mixed $html, mixed $coupon, mixed $discount = null ): mixed {
		return self::ours( $coupon ) && is_string( $discount ) ? $discount : $html;
	}

	/**
	 * `woocommerce_order_recalculate_coupons_coupon_object`: in an order the
	 * reserved code keeps this type (WooCommerce rebuilds it as a virtual
	 * coupon from the order item).
	 *
	 * @param mixed $coupon      Coupon WooCommerce built.
	 * @param mixed $code        The order's coupon code.
	 * @param mixed $coupon_item The order's coupon item.
	 * @return mixed
	 */
	public function order_coupon( mixed $coupon, mixed $code, mixed $coupon_item ): mixed {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon_item instanceof WC_Order_Item_Coupon || ! is_string( $code ) || SubDiscountMeta::COUPON_CODE !== CouponCode::normalize( $code ) ) {
			return $coupon;
		}

		$coupon->set_discount_type( SubDiscountMeta::COUPON_TYPE );
		$coupon->set_amount( 0 );

		return $coupon;
	}

	/**
	 * `woocommerce_checkout_create_order_line_item` (classic and block
	 * checkout): stores the line's discount, tier and percent, and the
	 * customer's Twitch account on the order.
	 *
	 * @param mixed $item          The new order line.
	 * @param mixed $cart_item_key Its cart item key.
	 * @param mixed $values        The cart item.
	 * @param mixed $order         The order.
	 */
	public function record_line( mixed $item, mixed $cart_item_key, mixed $values, mixed $order ): void {
		unset( $values );
		$given = is_string( $cart_item_key ) ? ( $this->given[ $cart_item_key ] ?? null ) : null;

		if ( null === $given || $given['amount'] <= 0 || ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Abstract_Order ) {
			return;
		}

		$item->update_meta_data( SubDiscountMeta::LINE_DISCOUNT, self::paid_basis( $item, $given['amount'] ) );
		$item->update_meta_data( SubDiscountMeta::LINE_TIER, (string) $given['tier'] );
		$item->update_meta_data( SubDiscountMeta::LINE_BPS, (string) $given['bps'] );
		$item->update_meta_data( self::LINE_UNIT, number_format( $given['unit'], 4, '.', '' ) );
		$item->update_meta_data( self::LINE_CREATOR, (string) $given['creator_id'] );

		$account = $this->login->account();
		$stored  = json_decode( (string) $order->get_meta( SubDiscountMeta::ORDER, true, 'edit' ), true );
		$stored  = is_array( $stored ) ? $stored : array();

		if ( null !== $account ) {
			$stored['twitch_user_id'] = $account['id'];
			$stored['login']          = $account['login'];
		}

		$stored['checked_at']                                = gmdate( 'Y-m-d\TH:i:s\Z' );
		$stored['creators'][ (string) $given['creator_id'] ] = array(
			'tier'   => $given['tier'],
			'gifted' => $given['gifted'],
			'bps'    => $given['bps'],
		);

		$order->update_meta_data( SubDiscountMeta::ORDER, (string) wp_json_encode( $stored ) );
	}

	/**
	 * `woocommerce_order_after_calculate_totals`: after an administrator's
	 * recalculation the lines keep the discount they got now.
	 *
	 * @param mixed $and_taxes Whether taxes were calculated.
	 * @param mixed $order     The order.
	 */
	public function record_recalculation( mixed $and_taxes, mixed $order ): void {
		unset( $and_taxes );

		if ( ! $order instanceof WC_Order || array() === $this->recalculated ) {
			return;
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$id = (int) $item->get_id();

			if ( $item instanceof WC_Order_Item_Product && isset( $this->recalculated[ $id ] ) ) {
				$item->update_meta_data( SubDiscountMeta::LINE_DISCOUNT, self::paid_basis( $item, $this->recalculated[ $id ] ) );
				$item->save();
			}
		}

		$this->recalculated = array();
	}

	/**
	 * The line of a cart or order item: its key, quantity, the full unit
	 * discount and (in a cart) the rate; null when it gets none.
	 *
	 * @param mixed $item               Cart item (array) or order item.
	 * @param float $discounting_amount WooCommerce' unit price (a fallback).
	 * @return array{key: string, quantity: int, unit: float, order_item_id: int|null, rate: array{creator_id: int, tier: int, bps: int, gifted: bool}|null}|null
	 */
	private function line( mixed $item, float $discounting_amount ): ?array {
		if ( $item instanceof WC_Order_Item_Product ) {
			$unit = (float) $item->get_meta( self::LINE_UNIT, true, 'edit' );

			return $unit > 0 ? array(
				'key'           => (string) $item->get_id(),
				'quantity'      => (int) $item->get_quantity(),
				'unit'          => $unit,
				'order_item_id' => (int) $item->get_id(),
				'rate'          => null,
			) : null;
		}

		if ( ! is_array( $item ) || ! ( $item['data'] ?? null ) instanceof WC_Product || ! is_string( $item['key'] ?? null ) ) {
			return null;
		}

		$product = $item['data'];
		$parent  = self::parent_id( $product );
		$rate    = $this->discount->rate( $parent );

		if ( null === $rate ) {
			return null;
		}

		$price = $this->prices[ $item['key'] ] ?? $discounting_amount;

		try {
			$cap = $this->profits->per_unit( $parent, $rate['creator_id'], $price, (int) $product->get_parent_id() > 0 ? (int) $product->get_id() : 0 ) ?? 0.0;
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The creator\'s profit could not be read; the Twitch subscriber discount does not apply.', $e->context() );

			return null;
		}

		$unit = min( round( $price * $rate['bps'] / 10000, wc_get_price_decimals() ), $cap );

		return $unit > 0 ? array(
			'key'           => $item['key'],
			'quantity'      => is_numeric( $item['quantity'] ?? null ) ? (int) $item['quantity'] : 0,
			'unit'          => $unit,
			'order_item_id' => null,
			'rate'          => $rate,
		) : null;
	}

	/**
	 * What the other coupons of the running calculation gave an item.
	 *
	 * @param string $key Item key.
	 */
	private function other_discounts( string $key ): float {
		if ( null === $this->discounts ) {
			return 0.0;
		}

		$sum = 0.0;

		foreach ( $this->discounts->get_discounts() as $code => $by_item ) {
			if ( SubDiscountMeta::COUPON_CODE !== CouponCode::normalize( (string) $code ) && is_numeric( $by_item[ $key ] ?? null ) ) {
				$sum += (float) $by_item[ $key ];
			}
		}

		return $sum;
	}

	/**
	 * A line's discount in the paid basis (incl. VAT, like net + tax): the
	 * amount WooCommerce discounted × what the line costs incl. VAT ÷ the
	 * price basis it discounted (incl. VAT when the shop's prices include
	 * it). As a decimal with 4 places.
	 *
	 * @param WC_Order_Item_Product $item   Order line.
	 * @param float                 $amount The discount in the price basis.
	 */
	private static function paid_basis( WC_Order_Item_Product $item, float $amount ): string {
		return self::in_paid_basis( $amount, (float) $item->get_subtotal( 'edit' ), (float) $item->get_subtotal_tax( 'edit' ), wc_prices_include_tax() );
	}

	/**
	 * Best discount wins per line: what this coupon adds to a line, given
	 * the full subscriber discount per unit and what the other coupons
	 * already gave the line; never below 0, rounded to the store precision.
	 *
	 * @param int   $quantity Units.
	 * @param float $unit     Subscriber discount per unit.
	 * @param float $others   The other coupons' discount on the line.
	 * @param int   $decimals Store price decimals.
	 */
	public static function top_up( int $quantity, float $unit, float $others, int $decimals ): float {
		return max( 0.0, round( $quantity * $unit - $others, $decimals ) );
	}

	/**
	 * A discount in the paid basis (incl. VAT, like the ledger's net + tax),
	 * as a decimal with 4 places: × (subtotal + its tax) ÷ the price basis
	 * WooCommerce discounted (incl. VAT when the shop's prices include it).
	 *
	 * @param float $amount               The discount in the price basis.
	 * @param float $subtotal             The line's subtotal excl. VAT.
	 * @param float $subtotal_tax         Its VAT.
	 * @param bool  $prices_include_tax   Whether the shop's prices include VAT.
	 */
	public static function in_paid_basis( float $amount, float $subtotal, float $subtotal_tax, bool $prices_include_tax ): string {
		$entered = $prices_include_tax ? $subtotal + $subtotal_tax : $subtotal;
		$paid    = $entered > 0 ? $amount * ( $subtotal + $subtotal_tax ) / $entered : $amount;

		return number_format( max( 0.0, $paid ), 4, '.', '' );
	}

	/**
	 * Whether a coupon is this one.
	 *
	 * @param mixed $coupon Coupon.
	 */
	private static function ours( mixed $coupon ): bool {
		return $coupon instanceof WC_Coupon && $coupon->is_type( SubDiscountMeta::COUPON_TYPE );
	}

	/**
	 * A product's parent (the product itself when it has none).
	 *
	 * @param WC_Product $product Product or variation.
	 */
	private static function parent_id( WC_Product $product ): int {
		$parent = (int) $product->get_parent_id();

		return $parent > 0 ? $parent : (int) $product->get_id();
	}
}
