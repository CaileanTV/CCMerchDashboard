<?php
/**
 * A shop customer logs in with Twitch.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * The customer's own Twitch login (ADR-106 pkt. 1–2): OAuth authorization
 * code with scope `user:read:subscriptions` through the app's existing
 * callback (TwitchOAuth hands states it does not know to this class).
 *
 * - The state is random, single use, valid for 10 minutes and bound to a
 *   random value in an HttpOnly cookie of the browser that started (and to
 *   the WordPress user, when logged in), so a link with someone else's
 *   state logs nobody in.
 * - The browser always goes back to the shop's cart or checkout.
 * - The token is kept only in the customer's session (TwitchCustomerSession).
 */
final class TwitchCustomerLogin {

	/**
	 * The scope.
	 */
	public const SCOPES = 'user:read:subscriptions';

	/**
	 * Transient prefix of a customer state.
	 */
	private const STATE_PREFIX = 'merchboard_twitch_cstate_';

	/**
	 * Cookie that binds a state to the browser.
	 */
	public const COOKIE = 'merchboard_twitch_oauth';

	/**
	 * Where the customer goes back to: the cart, the checkout, or a gift's
	 * claim page (`claim:<token>`) or its button (`redeem:<token>`, ADR-107).
	 */
	public const TARGETS = array( 'cart', 'checkout' );

	/**
	 * Whether a target is allowed.
	 *
	 * @param mixed $target Target.
	 */
	public static function valid_target( mixed $target ): bool {
		return in_array( $target, self::TARGETS, true ) || ( is_string( $target ) && 1 === preg_match( '/^(claim|redeem):[0-9a-f]{32}$/', $target ) );
	}

	/**
	 * The shop URL of a target.
	 *
	 * @var Closure(string): string
	 */
	private Closure $target_url;

	/**
	 * Sets or clears the binding cookie.
	 *
	 * @var Closure(string, int): void
	 */
	private Closure $set_cookie;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(string): string $target_url
	 * @phpstan-param (Closure(string, int): void)|null $set_cookie
	 *
	 * @param TwitchAccount         $account    The Twitch app.
	 * @param TwitchApi             $api        Twitch.
	 * @param TwitchCustomerSession $session    The customer's login.
	 * @param Closure               $target_url The shop URL of a target (`cart`, `checkout`).
	 * @param Closure|null          $set_cookie Sets the binding cookie (value, lifetime; '' and 0 clear it).
	 */
	public function __construct( private TwitchAccount $account, private TwitchApi $api, private TwitchCustomerSession $session, Closure $target_url, ?Closure $set_cookie = null ) {
		$this->target_url = $target_url;
		$this->set_cookie = $set_cookie ?? static function ( string $value, int $lifetime ): void {
			if ( ! headers_sent() ) {
				setcookie(
					self::COOKIE,
					$value,
					array(
						'expires'  => '' === $value ? time() - 3600 : time() + $lifetime,
						'path'     => defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
		};
	}

	/**
	 * Whether customers can log in (the Twitch app is set up).
	 */
	public function available(): bool {
		return $this->account->has_app();
	}

	/**
	 * The URL that sends the customer to Twitch, or the target with
	 * `twitch=unavailable` when the app is not set up.
	 *
	 * @param string $target  `cart`, `checkout` or `claim:<token>`.
	 * @param int    $user_id The logged-in WordPress user, 0 for a guest.
	 */
	public function start( string $target, int $user_id ): string {
		$target = self::valid_target( $target ) ? $target : 'cart';

		if ( ! $this->available() ) {
			return $this->result( $target, 'unavailable' );
		}

		$state   = bin2hex( random_bytes( 16 ) );
		$binding = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'binding' => $binding,
				'user'    => $user_id,
				'target'  => $target,
			),
			TwitchOAuth::STATE_TTL
		);
		( $this->set_cookie )( $binding, TwitchOAuth::STATE_TTL );

		return TwitchApi::authorize_url( $this->account->client_id(), TwitchAccount::redirect_uri(), self::SCOPES, $state, false );
	}

	/**
	 * Whether a state is a customer state (still valid).
	 *
	 * @param string $state State from Twitch.
	 */
	public function owns( string $state ): bool {
		return 1 === preg_match( '/^[0-9a-f]{32}$/', $state ) && is_array( get_transient( self::STATE_PREFIX . $state ) );
	}

	/**
	 * Finishes the customer's login (Twitch's redirect) and gives the URL to
	 * send the browser to: the cart or checkout with `twitch=<result>`.
	 *
	 * @param string $state   State from Twitch.
	 * @param string $code    Authorization code ('' when refused).
	 * @param string $error   Twitch's error ('' for none).
	 * @param string $binding The binding cookie the browser sent ('' for none).
	 * @param int    $user_id The WordPress user the browser is logged in as (0: none).
	 */
	public function finish( string $state, string $code, string $error, string $binding, int $user_id ): string {
		$data = 1 === preg_match( '/^[0-9a-f]{32}$/', $state ) ? get_transient( self::STATE_PREFIX . $state ) : false;

		if ( ! is_array( $data ) || ! is_string( $data['binding'] ?? null ) || ! is_int( $data['user'] ?? null ) || ! self::valid_target( $data['target'] ?? null ) ) {
			return ( $this->target_url )( 'cart' );
		}

		// Single use.
		delete_transient( self::STATE_PREFIX . $state );
		( $this->set_cookie )( '', 0 );

		$target = (string) $data['target'];

		if ( '' !== $error || '' === $code || '' === $binding || ! hash_equals( $data['binding'], $binding ) || $data['user'] !== $user_id ) {
			return $this->result( $target, TwitchException::DENIED );
		}

		// The callback is a REST request without the REST nonce, so WordPress runs
		// it as nobody, and WooCommerce would throw away a logged-in customer's
		// session (cart, coupons). The state is bound to this user and browser.
		if ( $user_id > 0 && get_current_user_id() !== $user_id ) {
			wp_set_current_user( $user_id );
		}

		try {
			$client = $this->account->client_id();
			$token  = $this->api->user_token( $client, $this->account->require_secret(), $code, TwitchAccount::redirect_uri() );
			$user   = $this->api->token_user( $client, $token['token'] );

			$this->session->store( $user['id'], $user['login'], $token['token'], $token['expires_in'], $user_id );
		} catch ( TwitchException $e ) {
			return $this->result( $target, $e->reason() );
		}

		return $this->result( $target, 'connected' );
	}

	/**
	 * The customer's subscription to a channel: the tier (1–3, 0 for none)
	 * and whether it was a gift; null when the customer is not logged in
	 * with a usable token. A fresh check always asks Twitch (the check
	 * before the order); otherwise a recent answer is reused.
	 *
	 * @param string $broadcaster_id Channel.
	 * @param bool   $fresh          Whether to ask Twitch even with a recent answer.
	 * @return array{tier: int, gifted: bool}|null
	 * @throws TwitchException When Twitch cannot be reached (`unavailable`); a refused token logs the customer out and gives null.
	 */
	public function subscription( string $broadcaster_id, bool $fresh = false ): ?array {
		$account = $this->session->account();

		// Without a usable token nothing counts, not even a recent answer.
		$token = null === $account ? null : $this->session->token();

		if ( null === $account || null === $token ) {
			return null;
		}

		if ( ! $fresh ) {
			$cached = $this->session->cached_subscription( $broadcaster_id );

			if ( null !== $cached ) {
				return $cached;
			}
		}

		try {
			$sub = $this->api->user_subscription( $this->account->client_id(), $token, $broadcaster_id, $account['id'] );
		} catch ( TwitchException $e ) {
			if ( in_array( $e->reason(), array( TwitchException::UNAUTHORIZED, TwitchException::FORBIDDEN ), true ) ) {
				$this->session->forget( 0 );

				return null;
			}

			throw $e;
		}

		$tier   = $sub['tier'] ?? 0;
		$gifted = $sub['gifted'] ?? false;
		$this->session->keep_subscription( $broadcaster_id, $tier, $gifted );

		return array(
			'tier'   => $tier,
			'gifted' => $gifted,
		);
	}

	/**
	 * Keeps "no subscription" for a channel Twitch could not answer about,
	 * so the cart does not ask again on every page (the next fresh check
	 * asks again).
	 *
	 * @param string $broadcaster_id Channel.
	 */
	public function forget_subscription( string $broadcaster_id ): void {
		$this->session->keep_subscription( $broadcaster_id, 0, false );
	}

	/**
	 * The customer's Twitch account of this visit, or null.
	 *
	 * @return array{id: string, login: string}|null
	 */
	public function account(): ?array {
		return $this->session->account();
	}

	/**
	 * The target's URL with the result.
	 *
	 * @param string $target `cart` or `checkout`.
	 * @param string $result Result code.
	 */
	private function result( string $target, string $result ): string {
		return add_query_arg( 'twitch', rawurlencode( $result ), ( $this->target_url )( $target ) );
	}
}
