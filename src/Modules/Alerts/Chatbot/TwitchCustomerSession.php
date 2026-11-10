<?php
/**
 * A shop customer's Twitch login during the visit.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * The customer's Twitch account and access token in the customer's session
 * (ADR-106 pkt. 2):
 *
 * - The token is encrypted (ChatCipher with its own domain), never leaves
 *   the server and is dropped when it expires; refresh tokens are never
 *   kept.
 * - Subscription answers are kept per channel for a few minutes, so the
 *   cart does not ask Twitch on every page; the check before the order
 *   always asks again.
 * - A logged-in customer's account (Twitch id and login only) is remembered
 *   in user meta, so the next visit needs one click. Logging out of Twitch
 *   forgets both.
 */
final class TwitchCustomerSession {

	/**
	 * Session key.
	 */
	public const SESSION_KEY = 'merchboard_twitch';

	/**
	 * User meta of a remembered account.
	 */
	public const USER_META = 'merchboard_twitch_customer';

	/**
	 * Seconds a subscription answer is reused in the cart.
	 */
	public const SUB_TTL = 600;

	/**
	 * Seconds before the token's end when it is no longer used.
	 */
	private const MARGIN = 60;

	/**
	 * The current time.
	 *
	 * @var Closure(): int
	 */
	private Closure $now;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(): int)|null $now
	 *
	 * @param CustomerSessionStore $store  The customer's session.
	 * @param ChatCipher           $cipher Encryption of the token.
	 * @param Closure|null         $now    Current Unix time (default: time()).
	 */
	public function __construct( private CustomerSessionStore $store, private ChatCipher $cipher, ?Closure $now = null ) {
		$this->now = $now ?? static fn(): int => time();
	}

	/**
	 * The logged-in Twitch account of this visit, or null.
	 *
	 * @return array{id: string, login: string}|null
	 */
	public function account(): ?array {
		$data = $this->data();

		return null === $data ? null : array(
			'id'    => $data['id'],
			'login' => $data['login'],
		);
	}

	/**
	 * The account a logged-in customer chose to remember, or null.
	 *
	 * @param int $user_id WordPress user (0: none).
	 * @return array{id: string, login: string}|null
	 */
	public function remembered( int $user_id ): ?array {
		if ( $user_id < 1 ) {
			return null;
		}

		return self::account_of( get_user_meta( $user_id, self::USER_META, true ) );
	}

	/**
	 * The usable access token of this visit, or null (none, expired or
	 * unreadable; an expired one is dropped).
	 */
	public function token(): ?string {
		$data = $this->data();

		if ( null === $data ) {
			return null;
		}

		$token = $data['expires'] - self::MARGIN > ( $this->now )() ? $this->cipher->decrypt( $data['token'] ) : null;

		if ( null === $token || '' === $token ) {
			$this->store->set( self::SESSION_KEY, null );

			return null;
		}

		return $token;
	}

	/**
	 * Stores a new login; earlier subscription answers are dropped. A
	 * logged-in customer's account is remembered.
	 *
	 * @param string $id         Twitch user id.
	 * @param string $login      Twitch login.
	 * @param string $token      Access token.
	 * @param int    $expires_in Its lifetime in seconds.
	 * @param int    $user_id    WordPress user (0: a guest).
	 * @throws TwitchException When the token cannot be encrypted (`not_configured`).
	 */
	public function store( string $id, string $login, string $token, int $expires_in, int $user_id ): void {
		$ciphertext = $this->cipher->encrypt( $token );

		if ( null === $ciphertext ) {
			throw new TwitchException( TwitchException::NOT_CONFIGURED );
		}

		$this->store->set(
			self::SESSION_KEY,
			array(
				'id'      => $id,
				'login'   => $login,
				'token'   => $ciphertext,
				'expires' => ( $this->now )() + $expires_in,
				'subs'    => array(),
			)
		);

		if ( $user_id > 0 ) {
			update_user_meta(
				$user_id,
				self::USER_META,
				array(
					'id'    => $id,
					'login' => $login,
				)
			);
		}
	}

	/**
	 * Logs out of Twitch: the visit's login and the remembered account.
	 *
	 * @param int $user_id WordPress user (0: a guest).
	 */
	public function forget( int $user_id ): void {
		$this->store->set( self::SESSION_KEY, null );

		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::USER_META );
		}
	}

	/**
	 * A subscription answer kept for a channel, if still fresh: `tier` 0
	 * means no subscription.
	 *
	 * @param string $broadcaster_id Channel.
	 * @return array{tier: int, gifted: bool}|null Null when there is none.
	 */
	public function cached_subscription( string $broadcaster_id ): ?array {
		$sub = $this->data()['subs'][ $broadcaster_id ] ?? null;

		if ( ! is_array( $sub ) || ( $this->now )() - $sub['at'] >= self::SUB_TTL ) {
			return null;
		}

		return array(
			'tier'   => $sub['tier'],
			'gifted' => $sub['gifted'],
		);
	}

	/**
	 * Keeps a subscription answer for a channel.
	 *
	 * @param string $broadcaster_id Channel.
	 * @param int    $tier           Tier 1–3, 0 for none.
	 * @param bool   $gifted         Whether it was a gift.
	 */
	public function keep_subscription( string $broadcaster_id, int $tier, bool $gifted ): void {
		$data = $this->data();

		if ( null === $data ) {
			return;
		}

		$data['subs'][ $broadcaster_id ] = array(
			'tier'   => $tier,
			'gifted' => $gifted,
			'at'     => ( $this->now )(),
		);

		$this->store->set( self::SESSION_KEY, $data );
	}

	/**
	 * The session's validated data, or null.
	 *
	 * @return array{id: string, login: string, token: string, expires: int, subs: array<string, array{tier: int, gifted: bool, at: int}>}|null
	 */
	private function data(): ?array {
		$data    = $this->store->get( self::SESSION_KEY );
		$account = self::account_of( $data );

		if ( null === $account || ! is_array( $data ) || ! is_string( $data['token'] ?? null ) || ! is_int( $data['expires'] ?? null ) ) {
			return null;
		}

		$subs = array();

		foreach ( is_array( $data['subs'] ?? null ) ? $data['subs'] : array() as $channel => $sub ) {
			if ( is_string( $channel ) && 1 === preg_match( '/^\d{1,20}$/', $channel ) && is_array( $sub ) && in_array( $sub['tier'] ?? null, array( 0, 1, 2, 3 ), true ) && is_bool( $sub['gifted'] ?? null ) && is_int( $sub['at'] ?? null ) ) {
				$subs[ $channel ] = array(
					'tier'   => $sub['tier'],
					'gifted' => $sub['gifted'],
					'at'     => $sub['at'],
				);
			}
		}

		return $account + array(
			'token'   => $data['token'],
			'expires' => $data['expires'],
			'subs'    => $subs,
		);
	}

	/**
	 * A validated account.
	 *
	 * @param mixed $value Stored value.
	 * @return array{id: string, login: string}|null
	 */
	private static function account_of( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! is_string( $value['id'] ?? null ) || ! is_string( $value['login'] ?? null ) || 1 !== preg_match( '/^\d{1,20}$/', $value['id'] ) || 1 !== preg_match( '/^\w{1,25}$/', $value['login'] ) ) {
			return null;
		}

		return array(
			'id'    => $value['id'],
			'login' => $value['login'],
		);
	}
}
