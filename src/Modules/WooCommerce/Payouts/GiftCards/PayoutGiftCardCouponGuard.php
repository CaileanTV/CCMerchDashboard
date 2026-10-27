<?php
/**
 * Refuses payout gift cards as coupons on orders (G1).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

use Closure;
use Exception;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\WooCommerce\Sales\SalesNotices;
use Throwable;
use WC_Abstract_Order;
use WC_Coupon;
use WC_Discounts;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces report 16.3, point 4: a way of redeeming a payout gift card that
 * the gift card plugin does not record on the card is closed.
 *
 * The runtime test (16.5) showed that the plugin records the order on the
 * card at checkout (classic coupon field, Store API, its own form), but not
 * when a card is added as a coupon to an order in the admin (`apply_coupon`)
 * or through the REST API v3 (`coupon_lines`): there the discount is given,
 * the card is not charged and the order is not recorded. Both call
 * `WC_Discounts::is_coupon_valid()` with the order as object (verified in
 * WooCommerce 11.1.2), so `woocommerce_coupon_is_valid` with an order object
 * refuses a code that is a card registered on a completed payout. A cart
 * (checkout) is never touched.
 *
 * Throwing from the filter is how WooCommerce expects a custom message
 * (it turns the exception into a `WP_Error`); the admin sees the message,
 * the REST API answers 400 and leaves the order it created as a draft
 * without the coupon. When the check itself fails (an unknown or unavailable
 * provider of a registered payout card, a failed lookup, an unreadable
 * payout table), the code is refused with its own message (fail closed,
 * owner decision B5 2026-10-16). The check runs whatever G1's activation and
 * cut-off are (B3). Nothing is written; the code is never stored or logged.
 *
 * Not covered: coupon lines written directly (REST API v1/v2 `coupon_lines`,
 * code that adds `WC_Order_Item_Coupon` items itself). WooCommerce applies
 * no coupon there: the card is neither used nor charged, the discount is
 * whatever the client sends.
 */
final class PayoutGiftCardCouponGuard {

	/**
	 * After WooCommerce' own checks (10).
	 */
	public const PRIORITY = 20;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(string): bool $is_payout_card
	 *
	 * @param Closure $is_payout_card Whether a code is a payout gift card (`Closure(string): bool`, PayoutGiftCardOrders::is_payout_card()).
	 * @param Logger  $logger         Technical log (ids only).
	 */
	public function __construct( private Closure $is_payout_card, private Logger $logger ) {
	}

	/**
	 * Registers the filter.
	 */
	public function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'coupon_is_valid' ), self::PRIORITY, 3 );
	}

	/**
	 * `woocommerce_coupon_is_valid`.
	 *
	 * @param mixed $valid     Valid so far.
	 * @param mixed $coupon    WC_Coupon.
	 * @param mixed $discounts WC_Discounts of a cart or an order.
	 * @return mixed The unchanged value.
	 * @throws Exception When a payout gift card is applied to an order, or the code cannot be checked.
	 */
	public function coupon_is_valid( mixed $valid, mixed $coupon, mixed $discounts ): mixed {
		if ( ! $valid || ! $coupon instanceof WC_Coupon || ! $discounts instanceof WC_Discounts ) {
			return $valid;
		}

		$order = $discounts->get_object();

		if ( ! $order instanceof WC_Abstract_Order ) {
			return $valid;
		}

		$code = (string) $coupon->get_code();

		if ( '' === trim( $code ) ) {
			return $valid;
		}

		try {
			$refuse = ( $this->is_payout_card )( $code );
		} catch ( Throwable $e ) {
			// Fail closed (owner decision B5, 2026-10-16): a card that cannot
			// be checked is never let through as "not a payout card".
			$this->logger->error(
				'A coupon on an order could not be checked against the payout gift cards.',
				array(
					'order_id' => (int) $order->get_id(),
					'error'    => $e::class,
					'reason'   => $e instanceof GiftCardProviderException ? $e->reason() : null,
				)
			);

			throw new Exception( esc_html__( 'Gift card order registration could not be checked', 'merchboard' ) );
		}

		if ( ! $refuse ) {
			return $valid;
		}

		$this->logger->warning( 'A payout gift card was refused as a coupon on an order.', array( 'order_id' => (int) $order->get_id() ) );
		// Never the code: only the order.
		do_action( 'merchboard_notify', 'admin_gift_card_coupon', null, array( 'order_number' => SalesNotices::order_number( (int) $order->get_id() ) ), 'order:' . (int) $order->get_id() );

		throw new Exception( esc_html__( 'This gift card was issued as a creator payout. It can only be redeemed at checkout, not added to an order here.', 'merchboard' ) );
	}
}
