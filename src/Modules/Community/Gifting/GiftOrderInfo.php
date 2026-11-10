<?php
/**
 * A gift on the administrator's order page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use MerchBoard\Core\Database\DatabaseException;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 9: the order page in wp-admin shows the gift of a gift order
 * (status, winner, draws) and, once a winner is drawn, warns that the order
 * must not be refunded: a refund does not change the gift. Escaped; nothing
 * is changed.
 */
final class GiftOrderInfo {

	/**
	 * Constructor.
	 *
	 * @param GiftRepository $gifts Gifts.
	 */
	public function __construct( private GiftRepository $gifts ) {
	}

	/**
	 * Hooks the information into the order page.
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'show' ) );
	}

	/**
	 * `woocommerce_admin_order_data_after_order_details`.
	 *
	 * @param mixed $order The order.
	 */
	public function show( mixed $order ): void {
		if ( ! $order instanceof WC_Order || null === GiftPurchase::gift_line( $order ) ) {
			return;
		}

		try {
			$gift = $this->gifts->find_by_order( (int) $order->get_id() );
		} catch ( DatabaseException ) {
			$gift = null;
		}

		echo '<div class="form-field form-field-wide merchboard-gift-info"><h3>' . esc_html__( 'Twitch gift', 'merchboard' ) . '</h3><p>';

		if ( null === $gift ) {
			echo esc_html__( 'Not paid yet: the gift is recorded when the order is paid.', 'merchboard' );
		} else {
			echo esc_html( self::status( $gift ) );

			if ( null !== $gift->winner_login && in_array( $gift->status, array( Gift::WON, Gift::CLAIMED ), true ) ) {
				/* translators: %s: the winner's Twitch name. */
				echo '<br>' . esc_html( sprintf( __( 'Winner: %s', 'merchboard' ), $gift->winner_login ) );
			}

			/* translators: %d: number of draws. */
			echo '<br>' . esc_html( sprintf( __( 'Draws: %d of 3', 'merchboard' ), $gift->draws ) );
		}

		echo '</p>';

		if ( null !== $gift && in_array( $gift->status, array( Gift::WON, Gift::CLAIMED, Gift::RETURNED ), true ) ) {
			echo '<p class="merchboard-gift-info__warning" style="color:#b32d2e"><strong>' . esc_html__( 'Do not refund this order: the gift has been drawn, and a refund does not take it back from the winner or the buyer.', 'merchboard' ) . '</strong></p>';
		}

		echo '</div>';
	}

	/**
	 * A gift's status in words.
	 *
	 * @param Gift $gift The gift.
	 */
	private static function status( Gift $gift ): string {
		return match ( $gift->status ) {
			Gift::WAITING  => __( 'Waiting for a draw in the stream.', 'merchboard' ),
			Gift::ENTERING => __( 'The chat is typing !enter now.', 'merchboard' ),
			Gift::WON      => __( 'Won; the winner has 24 hours to claim it.', 'merchboard' ),
			Gift::CLAIMED  => __( 'Claimed by the winner.', 'merchboard' ),
			Gift::RETURNED => __( 'Nobody claimed it after three draws; the buyer got the gift code.', 'merchboard' ),
			default        => __( 'Cancelled.', 'merchboard' ),
		};
	}
}
