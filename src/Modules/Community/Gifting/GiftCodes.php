<?php
/**
 * The gift code a winner (or, in the end, the buyer) uses.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponCode;
use Throwable;
use WC_Cart;
use WC_Coupon;
use WC_Order;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 6: a single-use WooCommerce coupon of its own type, valid
 * only for the gift's product (every variant), covering one unit up to the
 * gift's paid price, plus a negative fee of up to the gift's shipping credit
 * (50 incl. VAT). A dearer variant or shipping is paid by the winner. The
 * line's amount after the code is what the ledger sees, so only what the
 * winner pays gives the creator profit (pkt. 8).
 */
final class GiftCodes {

	/**
	 * The coupon's discount type.
	 */
	public const TYPE = 'merchboard_twitch_gift';

	/**
	 * Coupon meta: the gift.
	 */
	public const GIFT_META = '_merchboard_gift_id';

	/**
	 * Gifts read in this request, by coupon id.
	 *
	 * @var array<int, Gift|null>
	 */
	private array $by_coupon = array();

	/**
	 * Constructor.
	 *
	 * @param GiftRepository $gifts  Gifts.
	 * @param Logger         $logger Technical log.
	 */
	public function __construct( private GiftRepository $gifts, private Logger $logger ) {
	}

	/**
	 * Hooks the coupon into WooCommerce.
	 */
	public function register(): void {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'discount_types' ) );
		add_filter( 'woocommerce_product_coupon_types', array( $this, 'product_types' ) );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'valid_for_product' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'discount_amount' ), 10, 5 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'shipping_credit' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'used' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'used' ) );
	}

	/**
	 * Creates the code of a gift.
	 *
	 * @param Gift $gift The gift.
	 * @return array{id: int, code: string} The coupon.
	 * @throws DatabaseException When the coupon cannot be created.
	 */
	public function create( Gift $gift ): array {
		$code = 'gave-' . strtolower( wp_generate_password( 10, false, false ) );

		try {
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( self::TYPE );
			$coupon->set_amount( $gift->unit_price );
			$coupon->set_individual_use( false );
			$coupon->set_usage_limit( 1 );
			/* translators: %d: gift id. */
			$coupon->set_description( sprintf( __( 'Merch Dashboard: the code of Twitch gift #%d. Valid once, only for the gifted product; managed by Merch Dashboard.', 'merchboard' ), $gift->id ) );
			$coupon->update_meta_data( self::GIFT_META, (string) $gift->id );
			$id = (int) $coupon->save();
		} catch ( Throwable ) {
			throw new DatabaseException( 'Could not create the gift code.', array( 'gift_id' => $gift->id ) );
		}

		if ( $id < 1 ) {
			throw new DatabaseException( 'Could not create the gift code.', array( 'gift_id' => $gift->id ) );
		}

		return array(
			'id'   => $id,
			'code' => $code,
		);
	}

	/**
	 * The code of a gift's coupon ('' when it is gone).
	 *
	 * @param int $coupon_id Coupon.
	 */
	public function code( int $coupon_id ): string {
		try {
			return (string) ( new WC_Coupon( $coupon_id ) )->get_code();
		} catch ( Throwable ) {
			return '';
		}
	}

	/**
	 * `woocommerce_coupon_discount_types`.
	 *
	 * @param mixed $types Types.
	 * @return array<mixed>
	 */
	public function discount_types( mixed $types ): array {
		$types               = is_array( $types ) ? $types : array();
		$types[ self::TYPE ] = __( 'Merch Dashboard: Twitch gift', 'merchboard' );

		return $types;
	}

	/**
	 * `woocommerce_product_coupon_types`.
	 *
	 * @param mixed $types Types.
	 * @return array<mixed>
	 */
	public function product_types( mixed $types ): array {
		$types   = is_array( $types ) ? $types : array();
		$types[] = self::TYPE;

		return array_values( array_unique( $types ) );
	}

	/**
	 * `woocommerce_coupon_is_valid_for_product`: only the gift's product.
	 *
	 * @param mixed $valid   WooCommerce' verdict.
	 * @param mixed $product Product.
	 * @param mixed $coupon  Coupon.
	 */
	public function valid_for_product( mixed $valid, mixed $product, mixed $coupon ): bool {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon->is_type( self::TYPE ) ) {
			return (bool) $valid;
		}

		$gift = $this->gift( $coupon );

		return null !== $gift && $product instanceof WC_Product && self::parent_id( $product ) === $gift->product_id;
	}

	/**
	 * `woocommerce_coupon_get_discount_amount`: one unit, up to the gift's
	 * paid price.
	 *
	 * @param mixed $discount           WooCommerce' amount.
	 * @param mixed $discounting_amount The unit price.
	 * @param mixed $item               Cart item or order item.
	 * @param mixed $single             Whether for one unit.
	 * @param mixed $coupon             Coupon.
	 */
	public function discount_amount( mixed $discount, mixed $discounting_amount, mixed $item, mixed $single, mixed $coupon ): float {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon->is_type( self::TYPE ) ) {
			return is_numeric( $discount ) ? (float) $discount : 0.0;
		}

		$gift     = $this->gift( $coupon );
		$quantity = is_array( $item ) && is_numeric( $item['quantity'] ?? null ) ? max( 1, (int) $item['quantity'] ) : 1;

		if ( null === $gift || ! is_numeric( $discounting_amount ) ) {
			return 0.0;
		}

		// One unit is covered; spread over the line when WooCommerce asks per unit.
		$covered = min( (float) $discounting_amount, (float) $gift->unit_price );

		return (bool) $single ? $covered / $quantity : $covered;
	}

	/**
	 * `woocommerce_cart_calculate_fees`: the gift's shipping credit.
	 *
	 * @param mixed $cart The cart.
	 */
	public function shipping_credit( mixed $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new WC_Coupon( (string) $code );
			$gift   = $coupon->is_type( self::TYPE ) ? $this->gift( $coupon ) : null;

			if ( null === $gift ) {
				continue;
			}

			$shipping = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
			$credit   = min( (float) $gift->shipping_credit, $shipping );

			if ( $credit > 0 ) {
				$cart->add_fee( __( 'Gift shipping covered', 'merchboard' ), -$credit, false );
			}
		}
	}

	/**
	 * `woocommerce_order_status_processing`/`_completed`: remembers the
	 * order a gift code was used in.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function used( mixed $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon = new WC_Coupon( (string) $code );

			if ( ! $coupon->is_type( self::TYPE ) ) {
				continue;
			}

			$gift = $this->gift( $coupon );

			try {
				if ( null !== $gift ) {
					$this->gifts->used_in( $gift->id, (int) $order->get_id() );
				}
			} catch ( DatabaseException $e ) {
				$this->logger->error( 'The use of a gift code could not be recorded.', $e->context() );
			}
		}
	}

	/**
	 * The gift of a coupon, or null.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 */
	private function gift( WC_Coupon $coupon ): ?Gift {
		$id = (int) $coupon->get_id();

		if ( ! array_key_exists( $id, $this->by_coupon ) ) {
			$gift_id = (int) $coupon->get_meta( self::GIFT_META, true, 'edit' );

			try {
				$gift = $gift_id > 0 ? $this->gifts->find( $gift_id ) : null;
			} catch ( DatabaseException $e ) {
				$this->logger->error( 'A gift code could not be checked; it gives nothing.', $e->context() );
				$gift = null;
			}

			$this->by_coupon[ $id ] = null !== $gift && in_array( $gift->status, array( Gift::CLAIMED, Gift::RETURNED ), true ) && $gift->coupon_id === $id ? $gift : null;
		}

		return $this->by_coupon[ $id ];
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

	/**
	 * Whether a code is a gift code (normalized).
	 *
	 * @param string $code Coupon code.
	 */
	public static function is_gift_code( string $code ): bool {
		return str_starts_with( CouponCode::normalize( $code ), 'gave-' );
	}
}
