<?php
/**
 * The discount type of creator codes in WooCommerce.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfitDiscount;
use WC_Coupon;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Coupon;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The discount type `merchboard_creator_profit` (ADR-078): a coupon of this
 * type gives, on each of its creator's products, exactly the creator's
 * profit, and nothing on other products.
 *
 * - The type is a product coupon type (`woocommerce_product_coupon_types`),
 *   so WooCommerce asks per item whether the coupon applies
 *   (`woocommerce_coupon_is_valid_for_product`) and, as a custom type, how
 *   much one unit is discounted (`woocommerce_coupon_get_discount_amount` in
 *   `WC_Discounts::apply_coupon_custom()`).
 * - In a cart, an item applies only when the coupon is a creator's **active**
 *   registration (same coupon id) and the product (or a variation's parent)
 *   belongs to that creator. A coupon of this type that is no registration
 *   gives no discount.
 * - In an order (e.g. "Recalculate" in the order editor), the registration
 *   that covered the order's creation applies, also when the code has been
 *   reset or ended since, like the order exclusion (ADR-068). When the
 *   coupon has been trashed since, WooCommerce would rebuild it as a
 *   `fixed_cart` coupon spread over all items; such a coupon of this type
 *   keeps the type instead (`woocommerce_order_recalculate_coupons_coupon_object`).
 * - Fail closed: when the registration or pricing cannot be read, the item
 *   does not apply (logged).
 */
final class CreatorProfitCoupon {

	public const TYPE = 'merchboard_creator_profit';

	/**
	 * The creator of a coupon in this request, by coupon id, code and order
	 * time (null: none).
	 *
	 * @var array<string, int|null>
	 */
	private array $creators = array();

	/**
	 * Constructor.
	 *
	 * @param CreatorCodeRepository $codes     Registrations.
	 * @param CreatorProfitDiscount $discounts The creator's profit as a discount.
	 * @param Logger                $logger    Technical log.
	 */
	public function __construct( private CreatorCodeRepository $codes, private CreatorProfitDiscount $discounts, private Logger $logger ) {
	}

	/**
	 * Hooks the discount type into WooCommerce.
	 */
	public function register(): void {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'discount_types' ) );
		add_filter( 'woocommerce_product_coupon_types', array( $this, 'product_types' ) );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'valid_for_product' ), 10, 4 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'discount_amount' ), 10, 5 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'order_coupon' ), 10, 3 );
	}

	/**
	 * `woocommerce_coupon_discount_types`: adds the type (shown in the coupon editor).
	 *
	 * @param mixed $types Discount types.
	 * @return array<mixed>
	 */
	public function discount_types( mixed $types ): array {
		$types               = is_array( $types ) ? $types : array();
		$types[ self::TYPE ] = __( 'Merch Dashboard: the creator\'s profit', 'merchboard' );

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
		$types[] = self::TYPE;

		return array_values( array_unique( $types ) );
	}

	/**
	 * `woocommerce_coupon_is_valid_for_product`: for this type, only the
	 * products of the coupon's creator.
	 *
	 * @param mixed $valid   WooCommerce' verdict.
	 * @param mixed $product Product.
	 * @param mixed $coupon  Coupon.
	 * @param mixed $item    Cart item (array) or order item.
	 */
	public function valid_for_product( mixed $valid, mixed $product, mixed $coupon, mixed $item = null ): bool {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon->is_type( self::TYPE ) ) {
			return (bool) $valid;
		}

		return $product instanceof WC_Product && $this->applies( (int) $coupon->get_id(), (string) $coupon->get_code( 'edit' ), self::parent_id( $product ), self::order_time( $item ) );
	}

	/**
	 * `woocommerce_coupon_get_discount_amount`: for this type, the creator's
	 * profit on one unit of the item.
	 *
	 * @param mixed $discount           WooCommerce' amount (0 for a custom type).
	 * @param mixed $discounting_amount The unit price.
	 * @param mixed $item               Cart item (array) or order item.
	 * @param mixed $single             Whether the amount is for one unit.
	 * @param mixed $coupon             Coupon.
	 */
	public function discount_amount( mixed $discount, mixed $discounting_amount, mixed $item, mixed $single, mixed $coupon ): float {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon->is_type( self::TYPE ) ) {
			return is_numeric( $discount ) ? (float) $discount : 0.0;
		}

		$product = self::product( $item );

		if ( null === $product || ! is_numeric( $discounting_amount ) ) {
			return 0.0;
		}

		return $this->unit_discount( (int) $coupon->get_id(), (string) $coupon->get_code( 'edit' ), self::parent_id( $product ), (float) $discounting_amount, self::order_time( $item ) );
	}

	/**
	 * `woocommerce_order_recalculate_coupons_coupon_object`: a code of this
	 * type whose coupon is no longer published keeps the type instead of
	 * becoming a `fixed_cart` coupon over all items (amount 0; the discount
	 * comes from the registration that covered the order). WooCommerce marks
	 * such a temporary coupon, rebuilt from the order item, as virtual; it
	 * keeps the old coupon's id.
	 *
	 * @param mixed $coupon      Coupon WooCommerce built.
	 * @param mixed $code        The order's coupon code.
	 * @param mixed $coupon_item The order's coupon item.
	 * @return mixed
	 */
	public function order_coupon( mixed $coupon, mixed $code, mixed $coupon_item ): mixed {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon->get_virtual() || ! $coupon_item instanceof WC_Order_Item_Coupon || ! self::was_this_type( $coupon_item ) ) {
			return $coupon;
		}

		$coupon->set_discount_type( self::TYPE );
		$coupon->set_amount( 0 );

		return $coupon;
	}

	/**
	 * Whether a coupon of this type applies to a parent product: the coupon
	 * is a creator's registration (the active one with this coupon id, or
	 * for an order the one that covered it) and the product is that
	 * creator's. False when it cannot be checked.
	 *
	 * @param int                    $coupon_id  Coupon id.
	 * @param string                 $code       The coupon's code.
	 * @param int                    $product_id Parent product.
	 * @param DateTimeImmutable|null $order_time The order's creation (UTC); null in a cart.
	 */
	public function applies( int $coupon_id, string $code, int $product_id, ?DateTimeImmutable $order_time = null ): bool {
		$creator_id = $this->creator_of( $coupon_id, $code, $order_time );

		try {
			return null !== $creator_id && $this->discounts->owns( $product_id, $creator_id );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The creator code could not be checked; the discount does not apply.', $e->context() );

			return false;
		}
	}

	/**
	 * The discount of one unit of a parent product: the creator's profit on
	 * it, 0 when the coupon does not apply or it cannot be checked.
	 *
	 * @param int                    $coupon_id  Coupon id.
	 * @param string                 $code       The coupon's code.
	 * @param int                    $product_id Parent product.
	 * @param float                  $unit_price The unit price WooCommerce discounts.
	 * @param DateTimeImmutable|null $order_time The order's creation (UTC); null in a cart.
	 */
	public function unit_discount( int $coupon_id, string $code, int $product_id, float $unit_price, ?DateTimeImmutable $order_time = null ): float {
		$creator_id = $this->creator_of( $coupon_id, $code, $order_time );

		try {
			return null === $creator_id ? 0.0 : $this->discounts->per_unit( $product_id, $creator_id, $unit_price ) ?? 0.0;
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The creator code could not be checked; the discount does not apply.', $e->context() );

			return 0.0;
		}
	}

	/**
	 * The creator of the registration: in a cart the active one with this
	 * coupon id, in an order the one that covered the order's creation.
	 *
	 * @param int                    $coupon_id  Coupon id.
	 * @param string                 $code       The coupon's code.
	 * @param DateTimeImmutable|null $order_time The order's creation (UTC); null in a cart.
	 */
	private function creator_of( int $coupon_id, string $code, ?DateTimeImmutable $order_time ): ?int {
		$code = CouponCode::normalize( $code );
		$key  = $coupon_id . '|' . $code . '|' . ( null === $order_time ? '' : $order_time->format( 'U' ) );

		if ( array_key_exists( $key, $this->creators ) ) {
			return $this->creators[ $key ];
		}

		$creator_id = null;

		try {
			if ( null === $order_time ) {
				$registration = $this->codes->active_for_code( $code );
				$creator_id   = null !== $registration && $registration->coupon_id === $coupon_id ? $registration->creator_id : null;
			} else {
				foreach ( $this->codes->matching( array( $code ) ) as $registration ) {
					if ( $registration->covers( $order_time ) ) {
						$creator_id = $registration->creator_id;
					}
				}
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The creator code could not be checked; the discount does not apply.', $e->context() );

			return null;
		}

		$this->creators[ $key ] = $creator_id;

		return $creator_id;
	}

	/**
	 * The creation time of the order an order item belongs to (UTC), or null
	 * for a cart item.
	 *
	 * @param mixed $item Cart item (array) or order item.
	 */
	private static function order_time( mixed $item ): ?DateTimeImmutable {
		if ( ! $item instanceof WC_Order_Item ) {
			return null;
		}

		$order   = $item->get_order();
		$created = $order instanceof WC_Order ? $order->get_date_created( 'edit' ) : null;

		// An order without a creation time yet is being created now.
		return null === $created
			? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			: ( new DateTimeImmutable( '@' . $created->getTimestamp() ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Whether the order's coupon item was a coupon of this type when the
	 * order used it (WooCommerce' `coupon_info`, or the older `coupon_data`).
	 *
	 * @param WC_Order_Item_Coupon $item Coupon item.
	 */
	private static function was_this_type( WC_Order_Item_Coupon $item ): bool {
		return self::stored_type_is_this( $item->get_meta( 'coupon_info' ), $item->get_meta( 'coupon_data' ) );
	}

	/**
	 * Whether a coupon item's stored coupon is of this type: `coupon_info`
	 * (JSON `[id, code, type, amount, …]`, WooCommerce 8.7+) or else the
	 * older `coupon_data` array.
	 *
	 * @param mixed $info `coupon_info` meta.
	 * @param mixed $data `coupon_data` meta.
	 */
	public static function stored_type_is_this( mixed $info, mixed $data ): bool {
		if ( is_string( $info ) && '' !== $info ) {
			$decoded = json_decode( $info, true );

			return is_array( $decoded ) && self::TYPE === ( $decoded[2] ?? null );
		}

		return is_array( $data ) && self::TYPE === ( $data['discount_type'] ?? null );
	}

	/**
	 * The product of a cart item or order item, or null.
	 *
	 * @param mixed $item Cart item (array with `data`) or order item.
	 */
	private static function product( mixed $item ): ?WC_Product {
		if ( is_array( $item ) && ( $item['data'] ?? null ) instanceof WC_Product ) {
			return $item['data'];
		}

		if ( $item instanceof WC_Order_Item_Product ) {
			$product = $item->get_product();

			return $product instanceof WC_Product ? $product : null;
		}

		return null;
	}

	/**
	 * A product's parent (the product itself when it has none): ownership
	 * and shares are set on parent products (ADR-033, ADR-049).
	 *
	 * @param WC_Product $product Product or variation.
	 */
	private static function parent_id( WC_Product $product ): int {
		$parent = (int) $product->get_parent_id();

		return $parent > 0 ? $parent : (int) $product->get_id();
	}
}
