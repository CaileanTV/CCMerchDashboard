<?php
/**
 * The Kick app of the alert chatbot.
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
 * The administrator's Kick app (ADR-069): Client ID, Client Secret and the
 * cached app access token (for the chat subscriptions), with the name of the
 * app's bot (created at Kick under the app; shown to creators). The secret
 * and the app token are encrypted (ChatCipher, Kick's domain); the option is
 * not autoloaded. Every change requires `merchboard_manage_alerts` and is
 * audited without secrets.
 */
final class KickAccount {

	/**
	 * Option (no autoload) holding the app and the encrypted secrets.
	 */
	public const OPTION = 'merchboard_alert_kick';

	/**
	 * Scopes a creator authorizes for the own channel: read the channel,
	 * let the bot post (`type: bot`) and subscribe to its chat.
	 */
	public const CHANNEL_SCOPES = 'channel:read chat:write events:subscribe';

	/**
	 * A cached app access token is renewed this long before it expires (s).
	 */
	private const TOKEN_MARGIN = 3600;

	/**
	 * Constructor.
	 *
	 * @param KickApi     $api         Kick.
	 * @param ChatCipher  $cipher      Encryption (Kick's domain).
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Database transaction.
	 */
	public function __construct( private KickApi $api, private ChatCipher $cipher, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * The app's Client ID; '' before the app is set up.
	 */
	public function client_id(): string {
		return $this->stored()['client_id'];
	}

	/**
	 * The bot's name at Kick ('' when not entered).
	 */
	public function bot_name(): string {
		return $this->stored()['bot_name'];
	}

	/**
	 * Whether the app is set up and its secret can be read.
	 */
	public function has_app(): bool {
		return '' !== $this->client_id() && null !== $this->secret();
	}

	/**
	 * The URL Kick redirects to after an authorization; registered in the
	 * Kick app.
	 */
	public static function redirect_uri(): string {
		return rest_url( 'merchboard/v1/alerts/kick/callback' );
	}

	/**
	 * The URL Kick sends chat events to; entered in the Kick app's settings.
	 */
	public static function webhook_url(): string {
		return rest_url( 'merchboard/v1/alerts/kick/webhook' );
	}

	/**
	 * Whether Kick can reach the webhook.
	 */
	public static function webhooks_possible(): bool {
		return ChatPlatforms::reachable( self::webhook_url() );
	}

	/**
	 * Saves the app after Kick has accepted its credentials. A new Client ID
	 * empties the cache (the old app's token is useless).
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @param string $bot_name  The bot's name at Kick ('' for none).
	 * @throws KickException When not authorized, the values are invalid, Kick refuses the credentials or cannot be reached.
	 * @throws DatabaseException When the audit record cannot be written (nothing is saved).
	 */
	public function save_app( string $client_id, string $secret, string $bot_name ): void {
		$this->authorize();
		$client_id = trim( $client_id );
		$secret    = trim( $secret );
		$bot_name  = trim( $bot_name );

		self::check_values( $client_id, $secret, $bot_name );

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
				'bot_name'    => $bot_name,
			)
		);

		$this->transaction->run(
			function () use ( $new ): void {
				$this->audit->record( AuditAction::AlertKickAppChanged, null, array( 'new_app' => $new ) );
			}
		);
		$this->store( $values );
	}

	/**
	 * Removes the app and every secret.
	 *
	 * @throws KickException When not authorized.
	 * @throws DatabaseException When the audit record cannot be written (nothing is removed).
	 */
	public function remove_app(): void {
		$this->authorize();
		$this->transaction->run(
			function (): void {
				$this->audit->record( AuditAction::AlertKickAppRemoved, null );
			}
		);
		delete_option( self::OPTION );
	}

	/**
	 * The app's Client Secret.
	 *
	 * @throws KickException When the app is not set up or the secret cannot be read.
	 */
	public function require_secret(): string {
		$secret = $this->secret();

		if ( '' === $this->client_id() || null === $secret ) {
			throw new KickException( KickException::NOT_CONFIGURED );
		}

		return $secret;
	}

	/**
	 * An app access token: the cached one, or a new one when it expires
	 * within an hour, cannot be read or $fresh asks for it.
	 *
	 * @param bool $fresh Fetch a new token.
	 * @throws KickException When the app is not set up or Kick refuses it or cannot be reached.
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
	 * Notes that Kick asked to wait (429).
	 *
	 * @param int $seconds Seconds to wait.
	 */
	public function note_backoff( int $seconds ): void {
		$this->store( array_merge( $this->stored(), array( 'backoff_until' => time() + max( 1, $seconds ) ) ) );
	}

	/**
	 * Seconds to wait before the next message may be sent (0: send now).
	 */
	public function wait_seconds(): int {
		return max( 0, $this->stored()['backoff_until'] - time() );
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
	 * @throws KickException When it cannot be encrypted (no sodium).
	 */
	private function encrypt( string $plain ): string {
		$encrypted = $this->cipher->encrypt( $plain );

		if ( null === $encrypted ) {
			throw new KickException( KickException::UNREADABLE );
		}

		return $encrypted;
	}

	/**
	 * The stored values, normalized.
	 *
	 * @return array{client_id: string, secret: string, app_token: string, app_expires: int, bot_name: string, backoff_until: int}
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
	 * @return array{client_id: string, secret: string, app_token: string, app_expires: int, bot_name: string, backoff_until: int}
	 */
	private static function empty(): array {
		return array(
			'client_id'     => '',
			'secret'        => '',
			'app_token'     => '',
			'app_expires'   => 0,
			'bot_name'      => '',
			'backoff_until' => 0,
		);
	}

	/**
	 * Requires credentials of a plausible format and a valid bot name.
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @param string $bot_name  Bot name ('' allowed).
	 * @throws KickException When they cannot be Kick's.
	 */
	private static function check_values( string $client_id, string $secret, string $bot_name ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{10,128}$/', $client_id ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{10,128}$/', $secret ) || ( '' !== $bot_name && 1 !== preg_match( '/^[A-Za-z0-9_]{1,25}$/', $bot_name ) ) ) {
			throw new KickException( KickException::INVALID_CREDENTIALS );
		}
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws KickException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new KickException( KickException::NOT_AUTHORIZED );
		}
	}
}
