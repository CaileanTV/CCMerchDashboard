<?php
/**
 * The Kick endpoints of the alert chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * Calls only the Kick endpoints approved in ADR-069 pkt. 3: OAuth 2.1 with
 * PKCE at `id.kick.com` (authorize, token, revoke) and `api.kick.com/public/v1`
 * (`channels`, `chat`, `events/subscriptions`, `public-key`). No SDK
 * (ADR-004); the transport is injectable for tests. Tokens and secrets are
 * never logged or put into exception messages.
 */
final class KickApi {

	public const AUTHORIZE_URL = 'https://id.kick.com/oauth/authorize';

	private const TOKEN_URL         = 'https://id.kick.com/oauth/token';
	private const REVOKE_URL        = 'https://id.kick.com/oauth/revoke';
	private const CHANNELS_URL      = 'https://api.kick.com/public/v1/channels';
	private const CHAT_URL          = 'https://api.kick.com/public/v1/chat';
	private const SUBSCRIPTIONS_URL = 'https://api.kick.com/public/v1/events/subscriptions';
	private const PUBLIC_KEY_URL    = 'https://api.kick.com/public/v1/public-key';

	/**
	 * Longest message: 500 characters and 2048 bytes in UTF-8.
	 */
	public const MESSAGE_MAX       = 500;
	public const MESSAGE_MAX_BYTES = 2048;

	/**
	 * Seconds to wait after a 429 without `Retry-After` (Kick documents no
	 * rate limit headers).
	 */
	public const DEFAULT_RETRY_AFTER = 60;

	/**
	 * Sends a request: (method, URL, headers, body) to status, headers
	 * (lowercase names) and body; status 0 for a transport error.
	 *
	 * @var Closure(string, string, array<string, string>, string|null): array{status: int, headers: array<string, string>, body: string}
	 */
	private Closure $http;

	/**
	 * Constructor.
	 *
	 * @param (Closure(string, string, array<string, string>, string|null): array{status: int, headers: array<string, string>, body: string})|null $http Transport; defaults to wp_remote_request().
	 */
	public function __construct( ?Closure $http = null ) {
		$this->http = $http ?? self::wordpress_http( ... );
	}

	/**
	 * The URL that asks a Kick user to authorize the app (PKCE, S256).
	 *
	 * @param string $client_id    Client ID.
	 * @param string $redirect_uri Registered redirect URI.
	 * @param string $scopes       Space-separated scopes.
	 * @param string $state        Single-use state.
	 * @param string $verifier     PKCE code verifier (kept on the server).
	 */
	public static function authorize_url( string $client_id, string $redirect_uri, string $scopes, string $state, string $verifier ): string {
		return self::AUTHORIZE_URL . '?' . http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => $scopes,
				'state'                 => $state,
				'code_challenge'        => self::challenge( $verifier ),
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * A new PKCE code verifier (43 characters of base64url).
	 */
	public static function verifier(): string {
		return self::base64url( random_bytes( 32 ) );
	}

	/**
	 * An app access token (client credentials).
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @return array{token: string, expires_in: int}
	 * @throws KickException When the credentials are refused or Kick cannot be reached.
	 */
	public function app_token( string $client_id, string $secret ): array {
		$answer = $this->form(
			self::TOKEN_URL,
			array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $client_id,
				'client_secret' => $secret,
			)
		);
		$token  = $answer['access_token'] ?? null;

		if ( ! is_string( $token ) || '' === $token ) {
			throw new KickException( KickException::UNAVAILABLE );
		}

		return array(
			'token'      => $token,
			'expires_in' => is_int( $answer['expires_in'] ?? null ) ? $answer['expires_in'] : 3600,
		);
	}

	/**
	 * The user tokens of an authorization code.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $secret       Client secret.
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri The redirect URI of the authorization.
	 * @param string $verifier     The PKCE code verifier of the authorization.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws KickException When Kick refuses the code or cannot be reached.
	 */
	public function exchange_code( string $client_id, string $secret, string $code, string $redirect_uri, string $verifier ): array {
		return self::user_tokens(
			$this->form(
				self::TOKEN_URL,
				array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $client_id,
					'client_secret' => $secret,
					'redirect_uri'  => $redirect_uri,
					'code_verifier' => $verifier,
					'code'          => $code,
				)
			)
		);
	}

	/**
	 * New user tokens for a refresh token (Kick may rotate the refresh token).
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @param string $refresh   Refresh token.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws KickException When Kick refuses it (INVALID_CREDENTIALS: the authorization is gone) or cannot be reached.
	 */
	public function refresh( string $client_id, string $secret, string $refresh ): array {
		return self::user_tokens(
			$this->form(
				self::TOKEN_URL,
				array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $client_id,
					'client_secret' => $secret,
					'refresh_token' => $refresh,
				)
			)
		);
	}

	/**
	 * Revokes a token; failures are ignored (the token is forgotten anyway).
	 *
	 * @param string $token Access or refresh token.
	 * @param string $hint  'access_token' or 'refresh_token'.
	 */
	public function revoke( string $token, string $hint ): void {
		( $this->http )(
			'POST',
			self::REVOKE_URL . '?' . http_build_query(
				array(
					'token'           => $token,
					'token_hint_type' => $hint,
				)
			),
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			''
		);
	}

	/**
	 * The channel of a user access token (scope `channel:read`).
	 *
	 * @param string $access User access token.
	 * @return array{id: string, slug: string}
	 * @throws KickException When the token is refused or Kick cannot be reached.
	 */
	public function token_channel( string $access ): array {
		$answer  = $this->json( 'GET', self::CHANNELS_URL, $access, null );
		$channel = is_array( $answer['body']['data'] ?? null ) ? ( $answer['body']['data'][0] ?? null ) : null;

		self::check( $answer['status'] );

		$id   = is_array( $channel ) ? ( $channel['broadcaster_user_id'] ?? null ) : null;
		$slug = is_array( $channel ) ? ( $channel['slug'] ?? null ) : null;

		if ( ! is_int( $id ) || $id <= 0 || ! is_string( $slug ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,25}$/', $slug ) ) {
			throw new KickException( KickException::UNAVAILABLE );
		}

		return array(
			'id'   => (string) $id,
			'slug' => strtolower( $slug ),
		);
	}

	/**
	 * Sends a chat message as the app's bot in the channel of the user
	 * access token (`type: bot`, ADR-069 pkt. 4).
	 *
	 * @param string $access  The broadcaster's user access token (`chat:write`).
	 * @param string $message Message (cut to the limits).
	 * @return array{result: string, reason: string, retry_after: int|null} result: sent, dropped, rate_limited, unauthorized, forbidden or failed.
	 */
	public function send_message( string $access, string $message ): array {
		$answer = $this->json(
			'POST',
			self::CHAT_URL,
			$access,
			array(
				'type'    => 'bot',
				'content' => self::cut( $message ),
			)
		);
		$status = $answer['status'];
		$result = match ( true ) {
			429 === $status => 'rate_limited',
			401 === $status => 'unauthorized',
			403 === $status => 'forbidden',
			400 === $status => 'dropped',
			200 !== $status => 'failed',
			default         => 'sent',
		};
		$sent = is_array( $answer['body']['data'] ?? null ) ? $answer['body']['data'] : null;

		if ( 'sent' === $result && ( ! is_array( $sent ) || true !== ( $sent['is_sent'] ?? null ) ) ) {
			$result = 'dropped';
		}

		$retry = $answer['headers']['retry-after'] ?? '';

		return array(
			'result'      => $result,
			'reason'      => 'dropped' === $result && 400 === $status ? 'invalid_message' : ( 'failed' === $result ? 'http_' . $status : '' ),
			'retry_after' => 'rate_limited' === $result ? ( 1 === preg_match( '/^\d{1,5}$/', $retry ) ? max( 1, (int) $retry ) : self::DEFAULT_RETRY_AFTER ) : null,
		);
	}

	/**
	 * Subscribes the app to a channel's chat (`chat.message.sent`, webhook),
	 * with the app access token.
	 *
	 * @param string $app_token      App access token.
	 * @param string $broadcaster_id Channel.
	 * @return array{id: string, status: string}
	 * @throws KickException When Kick refuses it or cannot be reached.
	 */
	public function subscribe_chat( string $app_token, string $broadcaster_id ): array {
		$answer = $this->json(
			'POST',
			self::SUBSCRIPTIONS_URL,
			$app_token,
			array(
				'broadcaster_user_id' => (int) $broadcaster_id,
				'events'              => array(
					array(
						'name'    => 'chat.message.sent',
						'version' => 1,
					),
				),
				'method'              => 'webhook',
			)
		);

		if ( ! in_array( $answer['status'], array( 200, 201 ), true ) ) {
			self::check( $answer['status'] );
		}

		$data  = is_array( $answer['body']['data'] ?? null ) ? ( $answer['body']['data'][0] ?? null ) : null;
		$id    = is_array( $data ) && is_string( $data['subscription_id'] ?? null ) ? substr( $data['subscription_id'], 0, 64 ) : '';
		$error = is_array( $data ) && is_string( $data['error'] ?? null ) ? $data['error'] : '';

		if ( '' === $id ) {
			throw new KickException( '' !== $error ? KickException::FORBIDDEN : KickException::UNAVAILABLE );
		}

		return array(
			'id'     => $id,
			'status' => 'enabled',
		);
	}

	/**
	 * Ends a chat subscription; an unknown id is no error.
	 *
	 * @param string $app_token App access token.
	 * @param string $id        Subscription id.
	 * @throws KickException When Kick cannot be reached or refuses the token.
	 */
	public function unsubscribe( string $app_token, string $id ): void {
		if ( '' === $id ) {
			return;
		}

		$answer = $this->json( 'DELETE', self::SUBSCRIPTIONS_URL . '?' . http_build_query( array( 'id' => $id ) ), $app_token, null );

		if ( 404 !== $answer['status'] && 200 !== $answer['status'] ) {
			self::check( $answer['status'], 204 );
		}
	}

	/**
	 * Kick's public key for the webhook signatures (PEM).
	 *
	 * @throws KickException When Kick cannot be reached or answers without a key.
	 */
	public function public_key(): string {
		$answer = ( $this->http )( 'GET', self::PUBLIC_KEY_URL, array( 'Accept' => 'application/json' ), null );

		self::check( $answer['status'] );

		$decoded = json_decode( $answer['body'], true );
		$key     = is_array( $decoded ) && is_array( $decoded['data'] ?? null ) ? ( $decoded['data']['public_key'] ?? null ) : $answer['body'];

		if ( ! is_string( $key ) || ! str_contains( $key, 'BEGIN PUBLIC KEY' ) ) {
			throw new KickException( KickException::UNAVAILABLE );
		}

		return $key;
	}

	/**
	 * A message within Kick's limits (characters and UTF-8 bytes).
	 *
	 * @param string $message Message.
	 */
	public static function cut( string $message ): string {
		// mb_strcut() never splits a character.
		return mb_strcut( mb_substr( $message, 0, self::MESSAGE_MAX ), 0, self::MESSAGE_MAX_BYTES, 'UTF-8' );
	}

	/**
	 * The S256 code challenge of a verifier.
	 *
	 * @param string $verifier Code verifier.
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Bytes.
	 */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE (RFC 7636), not code.
	}

	/**
	 * User tokens from a token answer.
	 *
	 * @param array<mixed> $answer Token endpoint answer.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws KickException When the answer has no tokens.
	 */
	private static function user_tokens( array $answer ): array {
		$access  = $answer['access_token'] ?? null;
		$refresh = $answer['refresh_token'] ?? null;

		if ( ! is_string( $access ) || '' === $access || ! is_string( $refresh ) || '' === $refresh ) {
			throw new KickException( KickException::UNAVAILABLE );
		}

		return array(
			'access'     => $access,
			'refresh'    => $refresh,
			'expires_in' => is_int( $answer['expires_in'] ?? null ) ? $answer['expires_in'] : 3600,
		);
	}

	/**
	 * A form POST to the OAuth token endpoint.
	 *
	 * @param string                $url    URL.
	 * @param array<string, string> $fields Fields.
	 * @return array<mixed>
	 * @throws KickException When Kick refuses it or cannot be reached.
	 */
	private function form( string $url, array $fields ): array {
		$answer = ( $this->http )( 'POST', $url, array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $fields ) );

		if ( in_array( $answer['status'], array( 400, 401, 403 ), true ) ) {
			throw new KickException( KickException::INVALID_CREDENTIALS );
		}

		self::check( $answer['status'] );
		$body = json_decode( $answer['body'], true );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * An API request with a bearer token.
	 *
	 * @param string                    $method Method.
	 * @param string                    $url    URL.
	 * @param string                    $token  Access token.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array{status: int, headers: array<string, string>, body: array<mixed>}
	 */
	private function json( string $method, string $url, string $token, ?array $body ): array {
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
		);

		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$answer  = ( $this->http )( $method, $url, $headers, null === $body ? null : (string) wp_json_encode( $body ) );
		$decoded = json_decode( $answer['body'], true );

		return array(
			'status'  => $answer['status'],
			'headers' => $answer['headers'],
			'body'    => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Requires a successful status.
	 *
	 * @param int $status   HTTP status (0: transport error).
	 * @param int $expected The expected success status.
	 * @throws KickException When it is not a success.
	 */
	private static function check( int $status, int $expected = 200 ): void {
		if ( $expected === $status ) {
			return;
		}

		throw new KickException(
			match ( $status ) {
				401 => KickException::UNAUTHORIZED,
				403 => KickException::FORBIDDEN,
				default => KickException::UNAVAILABLE,
			}
		);
	}

	/**
	 * The default transport.
	 *
	 * @param string                $method  Method.
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Body.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function wordpress_http( string $method, string $url, array $headers, ?string $body ): array {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => 10,
			'redirection' => 0,
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 0,
				'headers' => array(),
				'body'    => '',
			);
		}

		$headers = array();

		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'body'    => wp_remote_retrieve_body( $response ),
		);
	}
}
