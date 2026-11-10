<?php
/**
 * The winner claims a gift.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\Alerts\Rest\ShopTwitchController;
use WC_Cart;
use WC_Session;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 6: the bot's link (`?merchboard_gift_claim=<token>`) asks
 * for the winner's Twitch login (ADR-106) and only gives the gift code to
 * the Twitch account that won. The code is created then; the winner is sent
 * to the product, and the code is applied as soon as the product is in the
 * cart. A winner who comes back sees the code again.
 */
final class GiftClaim {

	/**
	 * Session key of a code to apply.
	 */
	private const SESSION_KEY = 'merchboard_gift_code';

	/**
	 * A product's page.
	 *
	 * @var Closure(int): string
	 */
	private Closure $product_url;

	/**
	 * Tells about a gift.
	 *
	 * @var Closure(string, Gift, array<string, string>): void
	 */
	private Closure $notify;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): string $product_url
	 * @phpstan-param Closure(string, Gift, array<string, string>): void $notify
	 *
	 * @param GiftRepository        $gifts       Gifts.
	 * @param GiftCodes             $codes       Gift codes.
	 * @param TwitchCustomerSession $session     The customer's Twitch login.
	 * @param Closure               $product_url A product's page.
	 * @param Closure               $notify      Tells the creator and the buyer.
	 * @param Logger                $logger      Technical log.
	 */
	public function __construct( private GiftRepository $gifts, private GiftCodes $codes, private TwitchCustomerSession $session, Closure $product_url, Closure $notify, private Logger $logger ) {
		$this->product_url = $product_url;
		$this->notify      = $notify;
	}

	/**
	 * Hooks the claim link and the code into the shop.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'claim' ), 5 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'apply' ), 20 );
	}

	/**
	 * `template_redirect`: handles the claim link.
	 */
	public function claim(): void {
		$token = isset( $_GET[ ShopTwitchController::CLAIM_QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ ShopTwitchController::CLAIM_QUERY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token is the secret; Twitch login proves the winner.

		if ( '' === $token ) {
			return;
		}

		try {
			$location = $this->handle( $token );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A gift could not be claimed.', $e->context() );
			wc_add_notice( __( 'The gift could not be claimed right now. Please try again in a moment.', 'merchboard' ), 'error' );
			$location = home_url( '/' );
		}

		wp_safe_redirect( $location );
		exit;
	}

	/**
	 * `woocommerce_add_to_cart`: applies a claimed gift code once its
	 * product is in the cart.
	 */
	public function apply(): void {
		$cart    = function_exists( 'WC' ) ? WC()->cart : null;
		$session = self::session();
		$code    = null === $session ? null : $session->get( self::SESSION_KEY );

		if ( null === $session || ! $cart instanceof WC_Cart || ! is_string( $code ) || '' === $code || in_array( $code, $cart->get_applied_coupons(), true ) ) {
			return;
		}

		if ( $cart->apply_coupon( $code ) ) {
			$session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * The customer's WooCommerce session, or null.
	 */
	private static function session(): ?WC_Session {
		$session = function_exists( 'WC' ) ? WC()->session : null;

		return $session instanceof WC_Session ? $session : null;
	}

	/**
	 * Where the claim link leads.
	 *
	 * @param string $token Claim token.
	 * @throws DatabaseException When the gift cannot be read or stored.
	 */
	private function handle( string $token ): string {
		$gift    = $this->gifts->find_by_token( $token );
		$claimed = null !== $gift && Gift::CLAIMED === $gift->status && null !== $gift->coupon_id;
		$open    = null !== $gift && Gift::WON === $gift->status && ( null === $gift->claim_deadline || $gift->claim_deadline >= GiftRepository::now() );

		if ( null === $gift || ( ! $claimed && ! $open ) ) {
			wc_add_notice( __( 'This gift link is no longer valid.', 'merchboard' ), 'error' );

			return home_url( '/' );
		}

		$account = $this->session->account();

		if ( null === $account || null === $this->session->token() ) {
			// Twitch login first; it comes back to this link.
			return ShopTwitchController::link( 'login', 'claim:' . $token );
		}

		if ( $account['id'] !== $gift->winner_twitch_id ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: the customer's Twitch login, 2: the winner's Twitch login. */
					__( 'You are logged in with Twitch as %1$s, but the gift was won by %2$s. Log out of Twitch and log in as the winner.', 'merchboard' ),
					$account['login'],
					(string) $gift->winner_login
				),
				'error'
			);

			return ShopTwitchController::link( 'logout', 'claim:' . $token );
		}

		if ( $claimed ) {
			// The winner came back: the same code again.
			$code = $this->codes->code( (int) $gift->coupon_id );

			if ( '' === $code ) {
				wc_add_notice( __( 'This gift link is no longer valid.', 'merchboard' ), 'error' );

				return home_url( '/' );
			}
		} else {
			$coupon = $this->codes->create( $gift );

			if ( ! $this->gifts->claim( $gift->id, $coupon['id'] ) ) {
				wp_delete_post( $coupon['id'], true );
				wc_add_notice( __( 'This gift link is no longer valid.', 'merchboard' ), 'error' );

				return home_url( '/' );
			}

			$code = $coupon['code'];
			( $this->notify )( 'claimed', $gift, array( 'code' => $code ) );
		}

		self::session()?->set( self::SESSION_KEY, $code );

		wc_add_notice(
			sprintf(
				/* translators: %s: the gift code. */
				__( 'Congratulations! Choose size and colour and add the product to the cart; your gift code %s is used there. You pay only if you choose a dearer variant or shipping.', 'merchboard' ),
				$code
			),
			'success'
		);

		return ( $this->product_url )( $gift->product_id );
	}
}
