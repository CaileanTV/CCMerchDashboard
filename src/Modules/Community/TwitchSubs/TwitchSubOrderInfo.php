<?php
/**
 * The Twitch subscriber discount on the administrator's order page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use Closure;
use MerchBoard\Modules\WooCommerce\SubDiscount\SubDiscountMeta;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106 pkt. 8: the order page in wp-admin shows the customer's Twitch
 * account and, per creator, the tier and percent that gave the discount
 * (from the order meta stored at checkout). Escaped; nothing is changed.
 */
final class TwitchSubOrderInfo {

	/**
	 * A creator's name.
	 *
	 * @var Closure(int): string
	 */
	private Closure $creator_name;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): string $creator_name
	 *
	 * @param Closure $creator_name A creator's name ('' when unknown).
	 */
	public function __construct( Closure $creator_name ) {
		$this->creator_name = $creator_name;
	}

	/**
	 * Hooks the information into the order page.
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'show' ) );
	}

	/**
	 * `woocommerce_admin_order_data_after_billing_address`.
	 *
	 * @param mixed $order The order.
	 */
	public function show( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$stored = json_decode( (string) $order->get_meta( SubDiscountMeta::ORDER, true, 'edit' ), true );

		if ( ! is_array( $stored ) || ! is_string( $stored['login'] ?? null ) ) {
			return;
		}

		$lines = array();

		foreach ( is_array( $stored['creators'] ?? null ) ? $stored['creators'] : array() as $creator_id => $rate ) {
			if ( ! is_array( $rate ) || ! is_int( $rate['tier'] ?? null ) || ! is_int( $rate['bps'] ?? null ) ) {
				continue;
			}

			$name    = ( $this->creator_name )( (int) $creator_id );
			$lines[] = sprintf(
				/* translators: 1: creator, 2: Twitch tier, 3: percent. */
				__( '%1$s: tier %2$d, %3$s %%', 'merchboard' ),
				'' === $name ? '#' . (int) $creator_id : $name,
				$rate['tier'],
				TwitchSubCampaign::percent_label( $rate['bps'] )
			) . ( true === ( $rate['gifted'] ?? false ) ? ' ' . __( '(gifted)', 'merchboard' ) : '' );
		}

		echo '<p><strong>' . esc_html__( 'Twitch subscriber discount', 'merchboard' ) . ':</strong> ' . esc_html( $stored['login'] );

		foreach ( $lines as $line ) {
			echo '<br>' . esc_html( $line );
		}

		echo '</p>';
	}
}
