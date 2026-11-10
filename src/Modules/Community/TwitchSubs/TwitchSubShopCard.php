<?php
/**
 * The Twitch login card in the shop's cart and checkout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerLogin;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\Alerts\Rest\ShopTwitchController;
use WC_Cart;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106 pkt. 8: while the cart has products with a Twitch subscriber
 * discount on offer, the cart and the checkout show a card (classic pages
 * through WooCommerce's hooks, block pages in front of the cart and
 * checkout blocks):
 *
 * - not logged in: "Log in with Twitch" and the highest percent on offer
 *   (for a remembered account: "Check my subscription as …");
 * - logged in: the Twitch account, whether the discount applies, and "Log
 *   out of Twitch".
 *
 * The result of a login (`?twitch=…`) is shown once. Everything is escaped;
 * customers never see the names of production partners. Uses WooCommerce's
 * own notice classes, so the theme styles it.
 */
final class TwitchSubShopCard {

	/**
	 * Whether the card was shown in this request (classic hooks and blocks
	 * may both fire).
	 *
	 * @var bool
	 */
	private bool $shown = false;

	/**
	 * Constructor.
	 *
	 * @param TwitchSubDiscount     $discount Which lines qualify.
	 * @param TwitchCustomerLogin   $login    The customer's Twitch login.
	 * @param TwitchCustomerSession $session  The customer's session.
	 */
	public function __construct( private TwitchSubDiscount $discount, private TwitchCustomerLogin $login, private TwitchCustomerSession $session ) {
	}

	/**
	 * Hooks the card into the cart and the checkout.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_cart', array( $this, 'classic_cart' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'classic_checkout' ) );
		add_filter( 'render_block', array( $this, 'block' ), 10, 2 );
	}

	/**
	 * `woocommerce_before_cart`.
	 */
	public function classic_cart(): void {
		echo $this->html( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in html().
	}

	/**
	 * `woocommerce_before_checkout_form`.
	 */
	public function classic_checkout(): void {
		echo $this->html( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in html().
	}

	/**
	 * `render_block`: the card in front of the cart and checkout blocks.
	 *
	 * @param mixed $content Block HTML.
	 * @param mixed $block   Parsed block.
	 * @return mixed
	 */
	public function block( mixed $content, mixed $block ): mixed {
		$name = is_array( $block ) ? ( $block['blockName'] ?? null ) : null;

		if ( ! is_string( $content ) || ! in_array( $name, array( 'woocommerce/cart', 'woocommerce/checkout' ), true ) ) {
			return $content;
		}

		return $this->html( 'woocommerce/checkout' === $name ? 'checkout' : 'cart' ) . $content;
	}

	/**
	 * The card for a page, or '' when there is nothing to offer.
	 *
	 * @param string $target `cart` or `checkout`.
	 */
	public function html( string $target ): string {
		if ( $this->shown || ! $this->login->available() ) {
			return '';
		}

		$products = self::cart_products();
		$highest  = array() === $products ? 0 : $this->discount->highest_offer( $products );

		if ( 0 === $highest ) {
			return '';
		}

		$this->shown = true;
		$account     = $this->session->account();
		$token       = null === $account ? null : $this->session->token();
		$parts       = array();
		$result      = self::result();

		if ( '' !== $result ) {
			$parts[] = '<p>' . esc_html( $result ) . '</p>';
		}

		if ( null !== $account && null !== $token ) {
			$applies = false;

			foreach ( $products as $product ) {
				if ( null !== $this->discount->rate( $product ) ) {
					$applies = true;
					break;
				}
			}

			$parts[] = '<p>' . esc_html(
				sprintf(
					/* translators: %s: the customer's Twitch name. */
					__( 'You are logged in with Twitch as %s.', 'merchboard' ),
					$account['login']
				)
			) . ' ' . esc_html(
				$applies
					? __( 'Your subscriber discount is taken off the eligible products.', 'merchboard' )
					: __( 'Your Twitch account has no subscription that gives a discount on these products.', 'merchboard' )
			) . '</p>';
			$parts[] = '<p><a href="' . esc_url( ShopTwitchController::link( 'logout', $target ) ) . '">' . esc_html__( 'Log out of Twitch', 'merchboard' ) . '</a></p>';
		} else {
			$remembered = $this->session->remembered( get_current_user_id() );
			$parts[]    = '<p>' . esc_html(
				sprintf(
					/* translators: %d: the highest discount in percent. */
					__( 'Do you subscribe on Twitch? Log in with Twitch and get up to %d %% off as a subscriber.', 'merchboard' ),
					$highest
				)
			) . '</p>';
			$parts[] = '<p><a class="button" href="' . esc_url( ShopTwitchController::link( 'login', $target ) ) . '">' . esc_html(
				null === $remembered
					? __( 'Log in with Twitch', 'merchboard' )
					: sprintf(
						/* translators: %s: the customer's remembered Twitch name. */
						__( 'Check my subscription as %s', 'merchboard' ),
						$remembered['login']
					)
			) . '</a></p>';
		}

		return '<div class="woocommerce-info merchboard-twitch-sub" role="status">' . implode( '', $parts ) . '</div>';
	}

	/**
	 * The message of a login that just came back (`?twitch=…`), or ''.
	 */
	private static function result(): string {
		// A display-only flag from our own redirect; nothing is changed by it.
		$code = isset( $_GET['twitch'] ) && is_string( $_GET['twitch'] ) ? sanitize_key( wp_unslash( $_GET['twitch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		return match ( $code ) {
			''            => '',
			'connected'   => __( 'You are now logged in with Twitch.', 'merchboard' ),
			'denied'      => __( 'The Twitch login was cancelled.', 'merchboard' ),
			'unavailable' => __( 'Twitch login is not available right now.', 'merchboard' ),
			default       => __( 'The Twitch login did not work. Please try again.', 'merchboard' ),
		};
	}

	/**
	 * The parent products in the cart.
	 *
	 * @return list<int>
	 */
	private static function cart_products(): array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart instanceof WC_Cart ) {
			return array();
		}

		$products = array();

		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;

			if ( $product instanceof WC_Product ) {
				$parent          = (int) $product->get_parent_id();
				$id              = $parent > 0 ? $parent : (int) $product->get_id();
				$products[ $id ] = $id;
			}
		}

		return array_values( $products );
	}
}
