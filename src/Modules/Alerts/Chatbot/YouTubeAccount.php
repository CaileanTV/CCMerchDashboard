<?php
/**
 * The YouTube app and bot of the alert chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's Google app and YouTube bot (ADR-070): the OAuth
 * client (Client ID and Secret) and the bot's channel with its tokens.
 * YouTube sends chat messages with the bot's own user token, so the bot's
 * refresh token is stored (pkt. 3). Secret and tokens are encrypted
 * (ChatCipher, YouTube's domain); the option is not autoloaded. When Google
 * refuses the bot's refresh token (revoked, or after 7 days while the Google
 * app is in testing), the bot is marked and the administrator logs in again.
 * Every change requires `merchboard_manage_alerts` and is audited without
 * secrets.
 */
final class YouTubeAccount {

	/**
	 * Option (no autoload) holding the app, the bot and the encrypted secrets.
	 */
	public const OPTION = 'merchboard_alert_youtube';

	/**
	 * Scope the bot account authorizes (writing in live chats).
	 */
	public const BOT_SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';

	/**
	 * Scope a creator authorizes (finding the own livestream).
	 */
	public const CHANNEL_SCOPE = 'https://www.googleapis.com/auth/youtube.readonly';

	/**
	 * An access token is renewed this long before it expires (s).
	 */
	private const TOKEN_MARGIN = 300;

	/**
	 * Constructor.
	 *
	 * @param YouTubeApi  $api         YouTube.
	 * @param ChatCipher  $cipher      Encryption (YouTube's domain).
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Database transaction.
	 */
	public function __construct( private YouTubeApi $api, private ChatCipher $cipher, private AuditTrail $audit, private Transaction $transaction ) {
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
	 * The bot's channel, or null before it is connected.
	 *
	 * @return array{id: string, name: string, connected_at: int, invalid: bool}|null
	 */
	public function bot(): ?array {
		$stored = $this->stored();

		return '' === $stored['bot_id'] ? null : array(
			'id'           => $stored['bot_id'],
			'name'         => $stored['bot_name'],
			'connected_at' => $stored['bot_connected_at'],
			'invalid'      => 1 === $stored['bot_invalid'],
		);
	}

	/**
	 * Whether messages can be sent: the app and a working bot are set up.
	 */
	public function ready(): bool {
		$bot = $this->bot();

		return $this->has_app() && null !== $bot && ! $bot['invalid'];
	}

	/**
	 * The URL Google redirects to after an authorization; registered in the
	 * Google OAuth client.
	 */
	public static function redirect_uri(): string {
		return rest_url( 'merchboard/v1/alerts/youtube/callback' );
	}

	/**
	 * Saves the app. Google cannot check credentials without a login, so
	 * only their format is checked; a new Client ID disconnects the bot.
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @throws ChatbotException When not authorized, the values are invalid or cannot be encrypted.
	 * @throws DatabaseException When the audit record cannot be written (nothing is saved).
	 */
	public function save_app( string $client_id, string $secret ): void {
		$this->authorize();
		$client_id = trim( $client_id );
		$secret    = trim( $secret );

		self::check_values( $client_id, $secret );

		$stored = $this->stored();
		$new    = $client_id !== $stored['client_id'];
		$values = array_merge(
			$new ? self::empty() : $stored,
			array(
				'client_id' => $client_id,
				'secret'    => $this->encrypt( $secret ),
			)
		);

		$this->transaction->run(
			function () use ( $new ): void {
				$this->audit->record( AuditAction::AlertYouTubeAppChanged, null, array( 'new_app' => $new ) );
			}
		);
		$this->store( $values );
	}

	/**
	 * Removes the app, the bot and every secret (the bot's tokens are
	 * revoked at Google first).
	 *
	 * @throws ChatbotException When not authorized.
	 * @throws DatabaseException When the audit record cannot be written (nothing is removed).
	 */
	public function remove_app(): void {
		$this->authorize();
		$this->transaction->run(
			function (): void {
				$this->audit->record( AuditAction::AlertYouTubeAppRemoved, null );
			}
		);
		$this->revoke_bot_tokens();
		delete_option( self::OPTION );
	}

	/**
	 * Connects the bot channel with its tokens (after its authorization).
	 *
	 * @param array{id: string, name: string}                         $channel The bot's channel.
	 * @param array{access: string, refresh: string, expires_in: int} $tokens  The bot's tokens.
	 * @throws ChatbotException When not authorized or the tokens cannot be encrypted.
	 * @throws DatabaseException When the audit record cannot be written (nothing is saved).
	 */
	public function connect_bot( array $channel, array $tokens ): void {
		$this->authorize();
		$values = array_merge(
			$this->stored(),
			array(
				'bot_id'           => $channel['id'],
				'bot_name'         => $channel['name'],
				'bot_connected_at' => time(),
				'bot_invalid'      => 0,
				'bot_access'       => $this->encrypt( $tokens['access'] ),
				'bot_refresh'      => $this->encrypt( $tokens['refresh'] ),
				'bot_expires'      => time() + max( 60, $tokens['expires_in'] ),
			)
		);

		$this->transaction->run(
			function () use ( $channel ): void {
				$this->audit->record(
					AuditAction::AlertChatbotBotConnected,
					null,
					array(
						'login'    => $channel['name'],
						'platform' => ChatPlatform::YouTube->value,
					)
				);
			}
		);
		$this->store( $values );
	}

	/**
	 * Disconnects the bot (its tokens are revoked at Google).
	 *
	 * @throws ChatbotException When not authorized.
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
				$this->audit->record(
					AuditAction::AlertChatbotBotDisconnected,
					null,
					array(
						'login'    => $bot['name'],
						'platform' => ChatPlatform::YouTube->value,
					)
				);
			}
		);
		$this->revoke_bot_tokens();
		$this->store( array_merge( $this->stored(), self::no_bot() ) );
	}

	/**
	 * The app's Client Secret.
	 *
	 * @throws ChatbotException When the app is not set up or the secret cannot be read.
	 */
	public function require_secret(): string {
		$secret = $this->secret();

		if ( '' === $this->client_id() || null === $secret ) {
			throw new ChatbotException( ChatbotException::NOT_CONFIGURED );
		}

		return $secret;
	}

	/**
	 * A valid access token of the bot: the stored one, or a renewed one when
	 * it expires soon or $fresh asks for it. When Google refuses the refresh
	 * token, the bot is marked as needing a new login.
	 *
	 * @param bool $fresh Renew it (after Google refused it).
	 * @throws ChatbotException UNAUTHORIZED when the bot must log in again; others when Google cannot be reached.
	 */
	public function bot_access( bool $fresh = false ): string {
		$stored  = $this->stored();
		$access  = '' === $stored['bot_access'] ? null : $this->cipher->decrypt( $stored['bot_access'] );
		$refresh = self::require_token( '' === $stored['bot_refresh'] || 1 === $stored['bot_invalid'] ? null : $this->cipher->decrypt( $stored['bot_refresh'] ) );

		if ( ! $fresh && null !== $access && $stored['bot_expires'] > time() + self::TOKEN_MARGIN ) {
			return $access;
		}

		$tokens = $this->renew_bot( $refresh );
		$this->store(
			array_merge(
				$this->stored(),
				array(
					'bot_access'  => $this->encrypt( $tokens['access'] ),
					'bot_refresh' => '' === $tokens['refresh'] ? $stored['bot_refresh'] : $this->encrypt( $tokens['refresh'] ),
					'bot_expires' => time() + max( 60, $tokens['expires_in'] ),
				)
			)
		);

		return $tokens['access'];
	}

	/**
	 * Notes that the day's quota is used up: YouTube messages wait until the
	 * quota resets (midnight Pacific Time, ADR-070 pkt. 6).
	 */
	public function note_quota_exhausted(): void {
		$reset = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Los_Angeles' ) ) )->setTime( 0, 0 )->modify( '+1 day' );

		$this->store( array_merge( $this->stored(), array( 'quota_until' => $reset->getTimestamp() ) ) );
	}

	/**
	 * When the quota resets, or 0 when it is not used up.
	 */
	public function quota_until(): int {
		$until = $this->stored()['quota_until'];

		return $until > time() ? $until : 0;
	}

	/**
	 * Seconds to wait before the next message may be sent (0: send now).
	 */
	public function wait_seconds(): int {
		$until = $this->quota_until();

		return 0 === $until ? 0 : $until - time() + 1;
	}

	/**
	 * New bot tokens; marks the bot when Google refuses the refresh token.
	 *
	 * @param string $refresh Refresh token.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws ChatbotException UNAUTHORIZED when refused; others when Google cannot be reached.
	 */
	private function renew_bot( string $refresh ): array {
		try {
			return $this->api->refresh( $this->client_id(), $this->require_secret(), $refresh );
		} catch ( ChatbotException $e ) {
			if ( ChatbotException::INVALID_CREDENTIALS !== $e->reason() ) {
				throw $e;
			}

			$this->store( array_merge( $this->stored(), array( 'bot_invalid' => 1 ) ) );

			throw new ChatbotException( ChatbotException::UNAUTHORIZED, $e->status() );
		}
	}

	/**
	 * Revokes the bot's tokens at Google; failures are ignored.
	 */
	private function revoke_bot_tokens(): void {
		$stored = $this->stored();

		foreach ( array( $stored['bot_refresh'], $stored['bot_access'] ) as $ciphertext ) {
			$token = '' === $ciphertext ? null : $this->cipher->decrypt( $ciphertext );

			if ( null !== $token ) {
				$this->api->revoke( $token );
			}
		}
	}

	/**
	 * Requires a readable token.
	 *
	 * @param string|null $token Token.
	 * @throws ChatbotException UNAUTHORIZED when there is none.
	 */
	private static function require_token( ?string $token ): string {
		if ( null === $token ) {
			throw new ChatbotException( ChatbotException::UNAUTHORIZED );
		}

		return $token;
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
	 * @throws ChatbotException When it cannot be encrypted (no sodium).
	 */
	private function encrypt( string $plain ): string {
		$encrypted = $this->cipher->encrypt( $plain );

		if ( null === $encrypted ) {
			throw new ChatbotException( ChatbotException::UNREADABLE );
		}

		return $encrypted;
	}

	/**
	 * The stored values, normalized.
	 *
	 * @return array{client_id: string, secret: string, bot_id: string, bot_name: string, bot_connected_at: int, bot_invalid: int, bot_access: string, bot_refresh: string, bot_expires: int, quota_until: int}
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
	 * @return array{client_id: string, secret: string, bot_id: string, bot_name: string, bot_connected_at: int, bot_invalid: int, bot_access: string, bot_refresh: string, bot_expires: int, quota_until: int}
	 */
	private static function empty(): array {
		return array_merge(
			array(
				'client_id' => '',
				'secret'    => '',
			),
			self::no_bot(),
			array( 'quota_until' => 0 )
		);
	}

	/**
	 * No bot connected.
	 *
	 * @return array{bot_id: string, bot_name: string, bot_connected_at: int, bot_invalid: int, bot_access: string, bot_refresh: string, bot_expires: int}
	 */
	private static function no_bot(): array {
		return array(
			'bot_id'           => '',
			'bot_name'         => '',
			'bot_connected_at' => 0,
			'bot_invalid'      => 0,
			'bot_access'       => '',
			'bot_refresh'      => '',
			'bot_expires'      => 0,
		);
	}

	/**
	 * Requires values of Google's format.
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @throws ChatbotException When they cannot be Google's.
	 */
	private static function check_values( string $client_id, string $secret ): void {
		if ( 1 !== preg_match( '/^[0-9]{6,30}-[a-z0-9]{10,64}\.apps\.googleusercontent\.com$/', $client_id ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $secret ) ) {
			throw new ChatbotException( ChatbotException::INVALID_CREDENTIALS );
		}
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws ChatbotException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new ChatbotException( ChatbotException::NOT_AUTHORIZED );
		}
	}
}
