<?php
/**
 * The discount type of creator codes in WooCommerce.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfitDiscount;
use WC_Coupon;
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
 * - An item applies only when the coupon is a creator's **active**
 *   registration (same coupon id) and the product (or a variation's parent)
 *   belongs to that creator. A coupon of this type that is no registration
 *   gives no discount.
 * - Fail closed: when the registration or pricing cannot be read, the item
 *   does not apply (logged).
 */
final class CreatorProfitCoupon {

	public const TYPE = 'merchboard_creator_profit';

	/**
	 * The creator of a coupon in this request, by coupon id (null: none).
	 *
	 * @var array<int, int|null>
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
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'valid_for_product' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'discount_amount' ), 10, 5 );
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
	 */
	public function valid_for_product( mixed $valid, mixed $product, mixed $coupon ): bool {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon->is_type( self::TYPE ) ) {
			return (bool) $valid;
		}

		return $product instanceof WC_Product && $this->applies( (int) $coupon->get_id(), (string) $coupon->get_code( 'edit' ), self::parent_id( $product ) );
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

		return $this->unit_discount( (int) $coupon->get_id(), (string) $coupon->get_code( 'edit' ), self::parent_id( $product ), (float) $discounting_amount );
	}

	/**
	 * Whether a coupon of this type applies to a parent product: the coupon
	 * is a creator's active registration and the product is that creator's.
	 * False when it cannot be checked.
	 *
	 * @param int    $coupon_id  Coupon id.
	 * @param string $code       The coupon's code.
	 * @param int    $product_id Parent product.
	 */
	public function applies( int $coupon_id, string $code, int $product_id ): bool {
		$creator_id = $this->creator_of( $coupon_id, $code );

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
	 * @param int    $coupon_id  Coupon id.
	 * @param string $code       The coupon's code.
	 * @param int    $product_id Parent product.
	 * @param float  $unit_price The unit price WooCommerce discounts.
	 */
	public function unit_discount( int $coupon_id, string $code, int $product_id, float $unit_price ): float {
		$creator_id = $this->creator_of( $coupon_id, $code );

		try {
			return null === $creator_id ? 0.0 : $this->discounts->per_unit( $product_id, $creator_id, $unit_price ) ?? 0.0;
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The creator code could not be checked; the discount does not apply.', $e->context() );

			return 0.0;
		}
	}

	/**
	 * The creator whose active registration the coupon is, or null.
	 *
	 * @param int    $coupon_id Coupon id.
	 * @param string $code      The coupon's code.
	 */
	private function creator_of( int $coupon_id, string $code ): ?int {
		if ( array_key_exists( $coupon_id, $this->creators ) ) {
			return $this->creators[ $coupon_id ];
		}

		try {
			$registration = $this->codes->active_for_code( CouponCode::normalize( $code ) );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The creator code could not be checked; the discount does not apply.', $e->context() );

			return null;
		}

		$this->creators[ $coupon_id ] = null !== $registration && $registration->coupon_id === $coupon_id ? $registration->creator_id : null;

		return $this->creators[ $coupon_id ];
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
