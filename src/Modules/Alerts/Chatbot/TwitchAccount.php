<?php
/**
 * The Twitch app and the bot account.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The central chatbot (ADR-066, status note 2026-10-20): the project
 * owner's Twitch app (Client ID and Secret) and the bot account an
 * administrator connects. Stored in one option without autoload; the secret,
 * the app access token and the webhook secret are encrypted (TwitchCipher)
 * and never leave the server or show in the UI again.
 *
 * - **App access token:** fetched with client credentials when needed and
 *   kept until an hour before it expires (Twitch gives no refresh token for
 *   it); a refused token is fetched again once.
 * - **Bot:** only the account's id and login are kept. Twitch keeps the
 *   bot's authorization (`user:read:chat user:write:chat user:bot`), which
 *   the app access token uses; the bot's own token is not stored.
 * - **Rate limit:** the last `Ratelimit-*` values of Send Chat Message, so
 *   the queue can wait and the admin page shows the limit (higher for a
 *   verified bot).
 * - **Changes:** only `merchboard_manage_alerts`; saving or removing the app
 *   and connecting or disconnecting the bot are audited, without secrets.
 */
final class TwitchAccount {

	/**
	 * Option (no autoload) holding the app, the bot and the encrypted secrets.
	 */
	public const OPTION = 'merchboard_alert_twitch';

	/**
	 * Scopes the bot account authorizes (chatbot badge and chat identity).
	 */
	public const BOT_SCOPES = 'user:read:chat user:write:chat user:bot';

	/**
	 * Scope a creator authorizes for the own channel.
	 */
	public const CHANNEL_SCOPES = 'channel:bot';

	/**
	 * A cached app access token is renewed this long before it expires (s).
	 */
	private const TOKEN_MARGIN = 3600;

	/**
	 * Constructor.
	 *
	 * @param TwitchApi    $api         Twitch.
	 * @param TwitchCipher $cipher      Encryption.
	 * @param AuditTrail   $audit       Audit trail.
	 * @param Transaction  $transaction Database transaction.
	 */
	public function __construct( private TwitchApi $api, private TwitchCipher $cipher, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * The app's Client ID; '' before the app is set up.
	 */
	public function client_id(): string {
		return $this->stored()['client_id'];
	}

	/**
	 * Whether the app is set up and its secret can be read.
	 */
	public function has_app(): bool {
		return '' !== $this->client_id() && null !== $this->secret();
	}

	/**
	 * The bot account, or null before it is connected.
	 *
	 * @return array{id: string, login: string, connected_at: int}|null
	 */
	public function bot(): ?array {
		$stored = $this->stored();

		return '' === $stored['bot_id'] ? null : array(
			'id'           => $stored['bot_id'],
			'login'        => $stored['bot_login'],
			'connected_at' => $stored['bot_connected_at'],
		);
	}

	/**
	 * Whether messages can be sent: the app and the bot are set up.
	 */
	public function ready(): bool {
		return $this->has_app() && null !== $this->bot();
	}

	/**
	 * The URL Twitch redirects to after an authorization; registered in the
	 * Twitch app.
	 */
	public static function redirect_uri(): string {
		return rest_url( 'merchboard/v1/alerts/twitch/callback' );
	}

	/**
	 * The URL Twitch sends chat events to.
	 */
	public static function webhook_url(): string {
		return rest_url( 'merchboard/v1/alerts/twitch/eventsub' );
	}

	/**
	 * Whether Twitch can reach the webhook: only an HTTPS site on port 443
	 * (not a local site); elsewhere the admin page shows Twitch's embedded
	 * chat instead.
	 */
	public static function webhooks_possible(): bool {
		$url  = self::webhook_url();
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port = wp_parse_url( $url, PHP_URL_PORT );

		return str_starts_with( $url, 'https://' ) && ( null === $port || 443 === $port ) && 1 !== preg_match( '/(^localhost$|\.local$|\.test$|^127\.|^10\.|^192\.168\.)/', $host );
	}

	/**
	 * Saves the app after Twitch has accepted its credentials. A new Client
	 * ID disconnects the bot (its authorization belongs to the old app).
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @throws TwitchException When not authorized, the credentials are refused or Twitch cannot be reached.
	 * @throws DatabaseException When the audit record cannot be written (nothing is saved).
	 */
	public function save_app( string $client_id, string $secret ): void {
		$this->authorize();
		$client_id = trim( $client_id );
		$secret    = trim( $secret );

		self::check_credentials( $client_id, $secret );

		$token  = $this->api->app_token( $client_id, $secret );
		$stored = $this->stored();
		$new    = $client_id !== $stored['client_id'];
		$values = array_merge(
			$new ? self::empty() : $stored,
			array(
				'client_id'   => $client_id,
				'secret'      => $this->encrypt( $secret ),
				'app_token'   => $this->encrypt( $token['token'] ),
				'app_expires' => time() + $token['expires_in'],
			)
		);

		$this->transaction->run(
			function () use ( $new ): void {
				$this->audit->record( AuditAction::AlertTwitchAppChanged, null, array( 'new_app' => $new ) );
			}
		);
		$this->store( $values );
	}

	/**
	 * Removes the app, the bot and every secret.
	 *
	 * @throws TwitchException When not authorized.
	 * @throws DatabaseException When the audit record cannot be written (nothing is removed).
	 */
	public function remove_app(): void {
		$this->authorize();
		$this->transaction->run(
			function (): void {
				$this->audit->record( AuditAction::AlertTwitchAppRemoved, null );
			}
		);
		delete_option( self::OPTION );
	}

	/**
	 * Connects the bot account (after its authorization, TwitchOAuth).
	 *
	 * @param string $id    Twitch user id.
	 * @param string $login Twitch login.
	 * @throws TwitchException When not authorized.
	 * @throws DatabaseException When the audit record cannot be written (nothing is saved).
	 */
	public function connect_bot( string $id, string $login ): void {
		$this->authorize();
		$this->transaction->run(
			function () use ( $login ): void {
				$this->audit->record( AuditAction::AlertChatbotBotConnected, null, array( 'login' => $login ) );
			}
		);
		$this->store(
			array_merge(
				$this->stored(),
				array(
					'bot_id'           => $id,
					'bot_login'        => $login,
					'bot_connected_at' => time(),
				)
			)
		);
	}

	/**
	 * Disconnects the bot account.
	 *
	 * @throws TwitchException When not authorized.
	 * @throws DatabaseException When the audit record cannot be written (nothing is changed).
	 */
	public function disconnect_bot(): void {
		$this->authorize();
		$bot = $this->bot();

		if ( null === $bot ) {
			return;
		}

		$this->transaction->run(
			function () use ( $bot ): void {
				$this->audit->record( AuditAction::AlertChatbotBotDisconnected, null, array( 'login' => $bot['login'] ) );
			}
		);
		$this->store(
			array_merge(
				$this->stored(),
				array(
					'bot_id'           => '',
					'bot_login'        => '',
					'bot_connected_at' => 0,
				)
			)
		);
	}

	/**
	 * The app's Client Secret.
	 *
	 * @throws TwitchException When the app is not set up or the secret cannot be read.
	 */
	public function require_secret(): string {
		$secret = $this->secret();

		if ( '' === $this->client_id() || null === $secret ) {
			throw new TwitchException( TwitchException::NOT_CONFIGURED );
		}

		return $secret;
	}

	/**
	 * An app access token: the cached one, or a new one when it expires
	 * within an hour, cannot be read or $fresh asks for it.
	 *
	 * @param bool $fresh Fetch a new token (after Twitch refused the cached one).
	 * @throws TwitchException When the app is not set up or Twitch refuses it or cannot be reached.
	 */
	public function app_token( bool $fresh = false ): string {
		$stored = $this->stored();
		$cached = '' === $stored['app_token'] ? null : $this->cipher->decrypt( $stored['app_token'] );

		if ( ! $fresh && null !== $cached && $stored['app_expires'] > time() + self::TOKEN_MARGIN ) {
			return $cached;
		}

		$token = $this->api->app_token( $this->client_id(), $this->require_secret() );
		$this->store(
			array_merge(
				$stored,
				array(
					'app_token'   => $this->encrypt( $token['token'] ),
					'app_expires' => time() + $token['expires_in'],
				)
			)
		);

		return $token['token'];
	}

	/**
	 * When the cached app access token expires (0 for none).
	 */
	public function app_token_expires(): int {
		return $this->stored()['app_expires'];
	}

	/**
	 * The secret Twitch signs the webhook with; created on first use.
	 *
	 * @throws TwitchException When it cannot be encrypted.
	 */
	public function webhook_secret(): string {
		$stored = $this->stored();
		$secret = '' === $stored['webhook_secret'] ? null : $this->cipher->decrypt( $stored['webhook_secret'] );

		if ( null !== $secret ) {
			return $secret;
		}

		$secret = bin2hex( random_bytes( 24 ) );
		$this->store( array_merge( $stored, array( 'webhook_secret' => $this->encrypt( $secret ) ) ) );

		return $secret;
	}

	/**
	 * Notes the rate limit of the latest Send Chat Message.
	 *
	 * @param int|null $limit     `Ratelimit-Limit`.
	 * @param int|null $remaining `Ratelimit-Remaining`.
	 * @param int|null $reset     `Ratelimit-Reset` (Unix time).
	 */
	public function note_rate_limit( ?int $limit, ?int $remaining, ?int $reset ): void {
		if ( null === $limit && null === $remaining && null === $reset ) {
			return;
		}

		$stored = $this->stored();
		$this->store(
			array_merge(
				$stored,
				array(
					'rate_limit'     => $limit ?? $stored['rate_limit'],
					'rate_remaining' => $remaining ?? $stored['rate_remaining'],
					'rate_reset'     => $reset ?? $stored['rate_reset'],
					'rate_seen_at'   => time(),
				)
			)
		);
	}

	/**
	 * The last rate limit Twitch reported.
	 *
	 * @return array{limit: int, remaining: int, reset: int, seen_at: int}
	 */
	public function rate_limit(): array {
		$stored = $this->stored();

		return array(
			'limit'     => $stored['rate_limit'],
			'remaining' => $stored['rate_remaining'],
			'reset'     => $stored['rate_reset'],
			'seen_at'   => $stored['rate_seen_at'],
		);
	}

	/**
	 * Seconds to wait before the next message may be sent (0: send now).
	 */
	public function wait_seconds(): int {
		$rate = $this->rate_limit();

		return 0 === $rate['remaining'] && $rate['seen_at'] > 0 && $rate['reset'] > time() ? $rate['reset'] - time() + 1 : 0;
	}

	/**
	 * The app's Client Secret, or null.
	 */
	private function secret(): ?string {
		$stored = $this->stored()['secret'];

		return '' === $stored ? null : $this->cipher->decrypt( $stored );
	}

	/**
	 * Encrypts a value.
	 *
	 * @param string $plain Plain text.
	 * @throws TwitchException When it cannot be encrypted (no sodium).
	 */
	private function encrypt( string $plain ): string {
		$encrypted = $this->cipher->encrypt( $plain );

		if ( null === $encrypted ) {
			throw new TwitchException( TwitchException::UNREADABLE );
		}

		return $encrypted;
	}

	/**
	 * The stored values, normalized.
	 *
	 * @return array{client_id: string, secret: string, app_token: string, app_expires: int, bot_id: string, bot_login: string, bot_connected_at: int, webhook_secret: string, rate_limit: int, rate_remaining: int, rate_reset: int, rate_seen_at: int}
	 */
	private function stored(): array {
		$raw    = get_option( self::OPTION, array() );
		$raw    = is_array( $raw ) ? $raw : array();
		$values = self::empty();

		foreach ( $values as $key => $default ) {
			$value = $raw[ $key ] ?? null;

			if ( is_int( $default ) ) {
				$values[ $key ] = is_int( $value ) ? $value : 0;
			} else {
				$values[ $key ] = is_string( $value ) ? $value : '';
			}
		}

		return $values;
	}

	/**
	 * Stores the values (without autoload).
	 *
	 * @param array<string, string|int> $values Values.
	 */
	private function store( array $values ): void {
		update_option( self::OPTION, $values, false );
	}

	/**
	 * Nothing set up.
	 *
	 * @return array{client_id: string, secret: string, app_token: string, app_expires: int, bot_id: string, bot_login: string, bot_connected_at: int, webhook_secret: string, rate_limit: int, rate_remaining: int, rate_reset: int, rate_seen_at: int}
	 */
	private static function empty(): array {
		return array(
			'client_id'        => '',
			'secret'           => '',
			'app_token'        => '',
			'app_expires'      => 0,
			'bot_id'           => '',
			'bot_login'        => '',
			'bot_connected_at' => 0,
			'webhook_secret'   => '',
			'rate_limit'       => 0,
			'rate_remaining'   => 0,
			'rate_reset'       => 0,
			'rate_seen_at'     => 0,
		);
	}

	/**
	 * Requires credentials of Twitch's format (lowercase letters and digits).
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @throws TwitchException When they cannot be Twitch credentials.
	 */
	private static function check_credentials( string $client_id, string $secret ): void {
		if ( 1 !== preg_match( '/^[a-z0-9]{10,64}$/', $client_id ) || 1 !== preg_match( '/^[a-z0-9]{10,64}$/', $secret ) ) {
			throw new TwitchException( TwitchException::INVALID_CREDENTIALS );
		}
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws TwitchException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new TwitchException( TwitchException::NOT_AUTHORIZED );
		}
	}
}
