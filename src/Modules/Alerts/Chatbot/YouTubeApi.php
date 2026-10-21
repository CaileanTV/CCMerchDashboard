<?php
/**
 * The YouTube endpoints of the alert chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * Calls only the Google endpoints approved in ADR-070 pkt. 2: OAuth at
 * `accounts.google.com`/`oauth2.googleapis.com` (authorize, token, revoke)
 * and the YouTube Data API v3 (`channels`, `liveBroadcasts`,
 * `liveChat/messages`). No SDK (ADR-004); the transport is injectable for
 * tests. Tokens and secrets are never logged or put into exception
 * messages.
 */
final class YouTubeApi {

	public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	private const TOKEN_URL      = 'https://oauth2.googleapis.com/token';
	private const REVOKE_URL     = 'https://oauth2.googleapis.com/revoke';
	private const CHANNELS_URL   = 'https://www.googleapis.com/youtube/v3/channels';
	private const BROADCASTS_URL = 'https://www.googleapis.com/youtube/v3/liveBroadcasts';
	private const MESSAGES_URL   = 'https://www.googleapis.com/youtube/v3/liveChat/messages';

	/**
	 * Longest live chat message (YouTube's limit).
	 */
	public const MESSAGE_MAX = 200;

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
	 * The URL that asks a Google account to authorize the app (PKCE, S256,
	 * offline access so a refresh token is issued).
	 *
	 * @param string $client_id    Client ID.
	 * @param string $redirect_uri Registered redirect URI.
	 * @param string $scope        Scope.
	 * @param string $state        Single-use state.
	 * @param string $verifier     PKCE code verifier (kept on the server).
	 * @param bool   $choose       Whether Google always asks which account (the bot).
	 */
	public static function authorize_url( string $client_id, string $redirect_uri, string $scope, string $state, string $verifier, bool $choose ): string {
		return self::AUTHORIZE_URL . '?' . http_build_query(
			array(
				'response_type'          => 'code',
				'client_id'              => $client_id,
				'redirect_uri'           => $redirect_uri,
				'scope'                  => $scope,
				'state'                  => $state,
				'access_type'            => 'offline',
				'prompt'                 => $choose ? 'consent select_account' : 'consent',
				'include_granted_scopes' => 'false',
				'code_challenge'         => KickApi::challenge( $verifier ),
				'code_challenge_method'  => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * The tokens of an authorization code.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $secret       Client secret.
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri The redirect URI of the authorization.
	 * @param string $verifier     The PKCE code verifier of the authorization.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws ChatbotException When Google refuses the code, gives no refresh token or cannot be reached.
	 */
	public function exchange_code( string $client_id, string $secret, string $code, string $redirect_uri, string $verifier ): array {
		$tokens = self::tokens(
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

		return self::with_refresh( $tokens );
	}

	/**
	 * A new access token for a refresh token. Google usually keeps the
	 * refresh token; then 'refresh' is ''.
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @param string $refresh   Refresh token.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws ChatbotException INVALID_CREDENTIALS when Google refuses it (revoked or expired); others when Google cannot be reached.
	 */
	public function refresh( string $client_id, string $secret, string $refresh ): array {
		return self::tokens(
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
	 */
	public function revoke( string $token ): void {
		( $this->http )( 'POST', self::REVOKE_URL, array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( array( 'token' => $token ) ) );
	}

	/**
	 * The YouTube channel of a user access token.
	 *
	 * @param string $access User access token.
	 * @return array{id: string, name: string}
	 * @throws ChatbotException When the token is refused, the account has no channel or Google cannot be reached.
	 */
	public function my_channel( string $access ): array {
		$answer  = $this->json(
			'GET',
			self::CHANNELS_URL . '?' . http_build_query(
				array(
					'part' => 'snippet',
					'mine' => 'true',
				)
			),
			$access,
			null
		);
		$channel = is_array( $answer['body']['items'] ?? null ) ? ( $answer['body']['items'][0] ?? null ) : null;

		self::check( $answer['status'] );

		$id      = is_array( $channel ) ? ( $channel['id'] ?? null ) : null;
		$snippet = is_array( $channel ) && is_array( $channel['snippet'] ?? null ) ? $channel['snippet'] : array();
		$handle  = is_string( $snippet['customUrl'] ?? null ) ? ltrim( $snippet['customUrl'], '@' ) : '';
		$title   = is_string( $snippet['title'] ?? null ) ? $snippet['title'] : '';

		if ( ! is_string( $id ) || 1 !== preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $id ) ) {
			throw new ChatbotException( ChatbotException::UNEXPECTED, $answer['status'] );
		}

		return array(
			'id'   => $id,
			'name' => mb_substr( '' !== $handle ? $handle : ( '' !== $title ? $title : $id ), 0, 100 ),
		);
	}

	/**
	 * The authorized user's current livestream, or null when the user is
	 * not live (`liveBroadcasts.list`, 1 quota unit).
	 *
	 * @param string $access The creator's access token.
	 * @return array{video: string, chat: string}|null
	 * @throws ChatbotException When the token is refused or Google cannot be reached.
	 */
	public function active_broadcast( string $access ): ?array {
		$answer = $this->json(
			'GET',
			self::BROADCASTS_URL . '?' . http_build_query(
				array(
					'part'            => 'snippet',
					'broadcastStatus' => 'active',
					'broadcastType'   => 'all',
				)
			),
			$access,
			null
		);

		self::check( $answer['status'] );

		foreach ( is_array( $answer['body']['items'] ?? null ) ? $answer['body']['items'] : array() as $item ) {
			$video = is_array( $item ) ? ( $item['id'] ?? null ) : null;
			$chat  = is_array( $item ) && is_array( $item['snippet'] ?? null ) ? ( $item['snippet']['liveChatId'] ?? null ) : null;

			if ( is_string( $video ) && '' !== $video && is_string( $chat ) && '' !== $chat ) {
				return array(
					'video' => $video,
					'chat'  => $chat,
				);
			}
		}

		return null;
	}

	/**
	 * Sends a message in a live chat as the bot.
	 *
	 * @param string $access  The bot's access token.
	 * @param string $chat    Live chat id.
	 * @param string $message Message (cut to MESSAGE_MAX).
	 * @return array{result: string, reason: string, retry_after: int|null} result: sent, dropped, not_live, quota, rate_limited, unauthorized, forbidden or failed.
	 */
	public function send_message( string $access, string $chat, string $message ): array {
		$answer = $this->json(
			'POST',
			self::MESSAGES_URL . '?' . http_build_query( array( 'part' => 'snippet' ) ),
			$access,
			array(
				'snippet' => array(
					'liveChatId'         => $chat,
					'type'               => 'textMessageEvent',
					'textMessageDetails' => array( 'messageText' => mb_substr( $message, 0, self::MESSAGE_MAX ) ),
				),
			)
		);
		$status = $answer['status'];
		$error  = self::error_reason( $answer['body'] );
		$result = match ( true ) {
			200 === $status => 'sent',
			'quotaExceeded' === $error, 'dailyLimitExceeded' === $error => 'quota',
			'rateLimitExceeded' === $error, 429 === $status => 'rate_limited',
			in_array( $error, array( 'liveChatEnded', 'liveChatNotFound' ), true ) => 'not_live',
			401 === $status => 'unauthorized',
			403 === $status => 'forbidden',
			400 === $status => 'dropped',
			default => 'failed',
		};

		return array(
			'result'      => $result,
			'reason'      => 'sent' === $result ? '' : substr( sanitize_key( '' !== $error ? $error : 'http_' . $status ), 0, 60 ),
			'retry_after' => 'rate_limited' === $result ? 60 : null,
		);
	}

	/**
	 * The reason of a Google error answer ('' for none).
	 *
	 * @param array<mixed> $body Decoded body.
	 */
	private static function error_reason( array $body ): string {
		$error  = is_array( $body['error'] ?? null ) ? $body['error'] : array();
		$errors = is_array( $error['errors'] ?? null ) ? $error['errors'] : array();
		$first  = $errors[0] ?? null;

		return is_array( $first ) && is_string( $first['reason'] ?? null ) ? $first['reason'] : '';
	}

	/**
	 * Tokens from a token answer.
	 *
	 * @param array<mixed> $answer Token endpoint answer.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws ChatbotException When the answer has no access token.
	 */
	private static function tokens( array $answer ): array {
		$access  = $answer['access_token'] ?? null;
		$refresh = $answer['refresh_token'] ?? '';

		if ( ! is_string( $access ) || '' === $access ) {
			throw new ChatbotException( ChatbotException::UNEXPECTED, 200 );
		}

		return array(
			'access'     => $access,
			'refresh'    => is_string( $refresh ) ? $refresh : '',
			'expires_in' => is_int( $answer['expires_in'] ?? null ) ? $answer['expires_in'] : 3600,
		);
	}

	/**
	 * Requires a refresh token (Google issues it only with offline access
	 * and consent).
	 *
	 * @param array{access: string, refresh: string, expires_in: int} $tokens Tokens.
	 * @return array{access: string, refresh: string, expires_in: int}
	 * @throws ChatbotException When there is none.
	 */
	private static function with_refresh( array $tokens ): array {
		if ( '' === $tokens['refresh'] ) {
			throw new ChatbotException( ChatbotException::UNEXPECTED, 200 );
		}

		return $tokens;
	}

	/**
	 * A form POST to the token endpoint.
	 *
	 * @param string                $url    URL.
	 * @param array<string, string> $fields Fields.
	 * @return array<mixed>
	 * @throws ChatbotException When Google refuses it or cannot be reached.
	 */
	private function form( string $url, array $fields ): array {
		$answer = ( $this->http )( 'POST', $url, array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $fields ) );

		if ( in_array( $answer['status'], array( 400, 401, 403 ), true ) ) {
			throw new ChatbotException( ChatbotException::INVALID_CREDENTIALS, $answer['status'] );
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
	 * @param int $status HTTP status (0: transport error).
	 * @throws ChatbotException When it is not a success.
	 */
	private static function check( int $status ): void {
		if ( 200 === $status ) {
			return;
		}

		throw new ChatbotException(
			match ( $status ) {
				401 => ChatbotException::UNAUTHORIZED,
				403 => ChatbotException::FORBIDDEN,
				default => ChatbotException::UNAVAILABLE,
			},
			$status
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
