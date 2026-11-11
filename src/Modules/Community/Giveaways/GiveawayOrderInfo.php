<?php
/**
 * A giveaway on the administrator's order page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Giveaways;

use MerchBoard\Core\Database\DatabaseException;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-110: the order page in wp-admin says that an order is a creator's own
 * giveaway paid at the base price, never a viewer's Twitch gift (the two
 * are kept apart everywhere), with the giveaway's status. Escaped; nothing
 * is changed.
 */
final class GiveawayOrderInfo {

	/**
	 * Constructor.
	 *
	 * @param GiveawayRepository $giveaways Giveaways.
	 */
	public function __construct( private GiveawayRepository $giveaways ) {
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
		$line = $order instanceof WC_Order ? GiveawayCheckout::line( $order ) : null;

		if ( null === $line ) {
			return;
		}

		$id = (int) $line->get_meta( GiveawayCheckout::LINE_META, true, 'edit' );

		try {
			$giveaway = $this->giveaways->find( $id );
		} catch ( DatabaseException ) {
			$giveaway = null;
		}

		echo '<div class="form-field form-field-wide merchboard-giveaway-info"><h3>' . esc_html__( 'Creator giveaway', 'merchboard' ) . '</h3><p>';
		/* translators: %d: giveaway id. */
		echo esc_html( sprintf( __( 'Giveaway #%d: the creator pays the base price and 50 kr. shipping for a product to give away. Not a Twitch gift from a viewer; the sale gives the creator no profit.', 'merchboard' ), $id ) );

		if ( null !== $giveaway ) {
			echo '<br>' . esc_html( self::status( $giveaway ) );
		}

		echo '</p></div>';
	}

	/**
	 * A giveaway's status in words.
	 *
	 * @param Giveaway $giveaway Giveaway.
	 */
	private static function status( Giveaway $giveaway ): string {
		return match ( $giveaway->status ) {
			Giveaway::AWAITING_PAYMENT => __( 'Awaiting payment.', 'merchboard' ),
			Giveaway::READY            => __( 'Paid; the giveaway is ready.', 'merchboard' ),
			Giveaway::USED             => __( 'The winner has used the code.', 'merchboard' ),
			Giveaway::EXPIRED          => __( 'The code ran out unused.', 'merchboard' ),
			default                    => __( 'Cancelled.', 'merchboard' ),
		};
	}
}
