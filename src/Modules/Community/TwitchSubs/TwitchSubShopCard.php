<?php
/**
 * The Twitch login card in the shop's cart and checkout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerLogin;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\Alerts\Rest\ShopTwitchController;
use MerchBoard\Modules\Community\Gifting\GiftPurchase;
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
 * customers never see the names of production partners.
 *
 * ADR-111 pkt. 4: the card is a modern card (templates/community/
 * twitch-card.php) with the highest discount per tier in the cart. Classic
 * pages show it right above the products (`woocommerce_before_cart_table`)
 * and above the billing details (`woocommerce_checkout_before_customer_details`);
 * block pages in front of the block; the shortcode `[merchboard_twitch_card]`
 * places it anywhere else. It shows once per page.
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
		add_action( 'woocommerce_before_cart_table', array( $this, 'classic_cart' ) );
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'classic_checkout' ) );
		add_filter( 'render_block', array( $this, 'block' ), 10, 2 );
		add_shortcode( 'merchboard_twitch_card', array( $this, 'shortcode' ) );
	}

	/**
	 * `[merchboard_twitch_card]`: the card where a page builder places it.
	 */
	public function shortcode(): string {
		return $this->html( function_exists( 'is_checkout' ) && is_checkout() ? 'checkout' : 'cart' );
	}

	/**
	 * `woocommerce_before_cart_table`: right above the products.
	 */
	public function classic_cart(): void {
		echo $this->html( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in html().
	}

	/**
	 * `woocommerce_checkout_before_customer_details`: right above the billing details.
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

		$this->shown     = true;
		$account         = $this->session->account();
		$token           = null === $account ? null : $this->session->token();
		$merchboard_card = array(
			'result' => self::result(),
			'tiers'  => $this->tiers( $products ),
		);

		if ( null !== $account && null !== $token ) {
			$applies = false;

			foreach ( $products as $product ) {
				if ( null !== $this->discount->rate( $product ) ) {
					$applies = true;
					break;
				}
			}

			$merchboard_card += array(
				'account' => (string) $account['login'],
				'applies' => $applies,
				'logout'  => ShopTwitchController::link( 'logout', $target ),
			);
		} else {
			$remembered       = $this->session->remembered( get_current_user_id() );
			$merchboard_card += array(
				'login'      => ShopTwitchController::link( 'login', $target ),
				'remembered' => null === $remembered ? '' : (string) $remembered['login'],
			);
		}

		ob_start();
		require dirname( __DIR__, 4 ) . '/templates/community/twitch-card.php';

		return (string) ob_get_clean();
	}

	/**
	 * The highest discount on offer per tier among the cart's products,
	 * highest tier first, as shown (e.g. [3 => '15', 2 => '10', 1 => '5']).
	 *
	 * @phpstan-param list<int> $products
	 *
	 * @param int[] $products Parent products.
	 * @return array<int, string>
	 */
	private function tiers( array $products ): array {
		$best = array(
			3 => 0,
			2 => 0,
			1 => 0,
		);

		foreach ( $products as $product ) {
			try {
				$offer = $this->discount->offer( $product );
			} catch ( DatabaseException ) {
				$offer = null;
			}

			if ( null === $offer ) {
				continue;
			}

			foreach ( array_keys( $best ) as $tier ) {
				$best[ $tier ] = max( $best[ $tier ], $offer['campaign']->bps( $tier ) );
			}
		}

		return array_map( array( TwitchSubCampaign::class, 'percent_label' ), array_filter( $best ) );
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
	 * The parent products in the cart; none in a gift purchase.
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
			// A Twitch gift is bought without discounts (ADR-107): no card.
			if ( true === ( $item[ GiftPurchase::ITEM_KEY ] ?? false ) ) {
				return array();
			}

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
