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
use Throwable;
use WC_Cart;
use WC_Session;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 6 (with the status note of 2026-11-11): the bot's link
 * (`?merchboard_gift_claim=<token>`) opens a page with the prize, the winner
 * and the deadline, and a button "Log in with Twitch". The button
 * (`&merchboard_gift_redeem=1`) asks for the winner's Twitch login (ADR-106),
 * comes back to itself and only gives the gift code to the Twitch account
 * that won. The code is created then; the winner is sent to the product, and
 * the code is applied as soon as the product is in the cart. A winner who
 * comes back sees the code again.
 */
final class GiftClaim {

	/**
	 * Session key of a code to apply.
	 */
	private const SESSION_KEY = 'merchboard_gift_code';

	/**
	 * A product's name, page and image.
	 *
	 * @var Closure(int): array{name: string, url: string, image: string}
	 */
	private Closure $product;

	/**
	 * Tells about a gift.
	 *
	 * @var Closure(string, Gift, array<string, string>): void
	 */
	private Closure $notify;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): array{name: string, url: string, image: string} $product
	 * @phpstan-param Closure(string, Gift, array<string, string>): void $notify
	 *
	 * @param GiftRepository        $gifts   Gifts.
	 * @param GiftCodes             $codes   Gift codes.
	 * @param TwitchCustomerSession $session The customer's Twitch login.
	 * @param Closure               $product A product's name, page and image.
	 * @param Closure               $notify  Tells the creator and the buyer.
	 * @param Logger                $logger  Technical log.
	 */
	public function __construct( private GiftRepository $gifts, private GiftCodes $codes, private TwitchCustomerSession $session, Closure $product, Closure $notify, private Logger $logger ) {
		$this->product = $product;
		$this->notify  = $notify;
	}

	/**
	 * Hooks the claim link and the code into the shop.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'claim' ), 5 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'apply' ), 20 );
	}

	/**
	 * `template_redirect`: the claim page, or the button behind it.
	 */
	public function claim(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The token is the secret; Twitch login proves the winner.
		$token  = isset( $_GET[ ShopTwitchController::CLAIM_QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ ShopTwitchController::CLAIM_QUERY ] ) ) : '';
		$redeem = isset( $_GET[ ShopTwitchController::REDEEM_QUERY ] );
		// phpcs:enable

		if ( '' === $token ) {
			return;
		}

		if ( $redeem ) {
			try {
				$location = $this->redeem( $token );
			} catch ( DatabaseException $e ) {
				$this->logger->error( 'A gift could not be claimed.', $e->context() );
				$location = ShopTwitchController::target_url( 'claim:' . $token );
			}

			wp_safe_redirect( $location );
			exit;
		}

		try {
			$html = $this->render( $this->view( $token ) );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A gift page could not be shown.', $e->context() );
			$html = $this->render( array( 'state' => 'error' ) + $this->view_base() );
		}

		foreach ( self::headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes every value.
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

		if ( null === $session || ! $cart instanceof WC_Cart || ! is_string( $code ) || '' === $code || in_array( $code, $cart->get_applied_coupons(), true ) || null !== GiftPurchase::gift_item( $cart ) ) {
			return;
		}

		if ( $cart->apply_coupon( $code ) ) {
			$session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * The headers of the claim page: no caching, no index and no referrer
	 * (the token is in the address).
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return array(
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' https: data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
			'Referrer-Policy'         => 'no-referrer',
			'X-Content-Type-Options'  => 'nosniff',
			'X-Robots-Tag'            => 'noindex, nofollow',
		);
	}

	/**
	 * What the claim page shows. States: `invalid` (unknown, too late, given
	 * back), `login` (log in with Twitch), `redeem` (logged in as the winner),
	 * `other` (logged in with another Twitch account) and `error`.
	 *
	 * @param string $token Claim token.
	 * @return array<string, mixed>
	 * @throws DatabaseException When the gift cannot be read.
	 */
	public function view( string $token ): array {
		$base = $this->view_base();
		$gift = $this->gifts->find_by_token( $token );

		if ( null === $gift || ( ! self::claimed( $gift ) && ! self::open( $gift ) ) ) {
			return array( 'state' => 'invalid' ) + $base;
		}

		$product = ( $this->product )( $gift->product_id );
		$view    = array(
			'product'  => $product['name'],
			'image'    => $product['image'],
			'winner'   => (string) $gift->winner_login,
			'claimed'  => self::claimed( $gift ),
			'deadline' => null === $gift->claim_deadline ? '' : (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) strtotime( $gift->claim_deadline . ' UTC' ) ),
			'redeem'   => ShopTwitchController::target_url( 'redeem:' . $token ),
		) + $base;

		// A giveaway's link (ADR-110): whoever has it redeems it, without Twitch.
		if ( self::link( $gift ) ) {
			return array( 'state' => 'link' ) + $view;
		}

		$account = $this->session->account();

		if ( null === $account || null === $this->session->token() ) {
			return array( 'state' => 'login' ) + $view;
		}

		if ( $account['id'] !== $gift->winner_twitch_id ) {
			return array(
				'state'   => 'other',
				'account' => $account['login'],
				'logout'  => ShopTwitchController::link( 'logout', 'claim:' . $token ),
			) + $view;
		}

		return array( 'state' => 'redeem' ) + $view;
	}

	/**
	 * The claim page.
	 *
	 * @param array<string, mixed> $merchboard_view View data.
	 * @throws Throwable When the template fails (after its output is discarded).
	 */
	public function render( array $merchboard_view ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Read by the template.
		ob_start();

		try {
			require dirname( __DIR__, 4 ) . '/templates/community/gift-claim.php';

			return (string) ob_get_clean();
		} catch ( Throwable $e ) {
			ob_end_clean();

			throw $e;
		}
	}

	/**
	 * Where the button leads: Twitch login (back here), the claim page when
	 * something is wrong, or the product with the code.
	 *
	 * @param string $token Claim token.
	 * @throws DatabaseException When the gift cannot be read or stored.
	 */
	public function redeem( string $token ): string {
		$page = ShopTwitchController::target_url( 'claim:' . $token );
		$gift = $this->gifts->find_by_token( $token );

		if ( null === $gift || ( ! self::claimed( $gift ) && ! self::open( $gift ) ) ) {
			return $page;
		}

		// A giveaway's link (ADR-110) needs no Twitch login.
		if ( ! self::link( $gift ) ) {
			$account = $this->session->account();

			if ( null === $account || null === $this->session->token() ) {
				// Twitch login first; it comes back to this button.
				return ShopTwitchController::link( 'login', 'redeem:' . $token );
			}

			if ( $account['id'] !== $gift->winner_twitch_id ) {
				// The page tells who is logged in and offers to log out.
				return $page;
			}
		}

		if ( self::claimed( $gift ) ) {
			// The winner came back: the same code again.
			$code = $this->codes->code( (int) $gift->coupon_id );

			if ( '' === $code ) {
				return $page;
			}
		} else {
			$coupon = $this->codes->create( $gift );

			if ( ! $this->gifts->claim( $gift->id, $coupon['id'] ) ) {
				wp_delete_post( $coupon['id'], true );

				return $page;
			}

			$code = $coupon['code'];
			( $this->notify )( 'claimed', $gift, array( 'code' => $code ) );
		}

		self::session()?->set( self::SESSION_KEY, $code );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: the gift code. */
					__( 'Congratulations! Choose size and colour and add the product to the cart; your gift code %s is used there. You pay only if you choose a dearer variant or shipping.', 'merchboard' ),
					$code
				),
				'success'
			);
		}

		return ( $this->product )( $gift->product_id )['url'];
	}

	/**
	 * The page's fixed parts: the site and the shop.
	 *
	 * @return array{site: string, shop: string}
	 */
	private function view_base(): array {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';

		return array(
			'site' => (string) get_bloginfo( 'name' ),
			'shop' => '' === $shop ? home_url( '/' ) : $shop,
		);
	}

	/**
	 * Whether the gift was claimed and has its code.
	 *
	 * @param Gift $gift Gift.
	 */
	private static function claimed( Gift $gift ): bool {
		return Gift::CLAIMED === $gift->status && null !== $gift->coupon_id;
	}

	/**
	 * Whether the gift was won and can still be claimed.
	 *
	 * @param Gift $gift Gift.
	 */
	private static function open( Gift $gift ): bool {
		return Gift::WON === $gift->status && ( null === $gift->claim_deadline || $gift->claim_deadline >= GiftRepository::now() );
	}

	/**
	 * Whether the gift is a giveaway's link (ADR-110), redeemed by whoever has
	 * it: a giveaway's gift without a Twitch winner.
	 *
	 * @param Gift $gift Gift.
	 */
	private static function link( Gift $gift ): bool {
		return $gift->giveaway() && null === $gift->winner_twitch_id;
	}

	/**
	 * The customer's WooCommerce session, or null.
	 */
	private static function session(): ?WC_Session {
		$session = function_exists( 'WC' ) ? WC()->session : null;

		return $session instanceof WC_Session ? $session : null;
	}
}
