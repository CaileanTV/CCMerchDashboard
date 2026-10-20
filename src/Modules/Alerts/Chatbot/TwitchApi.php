<?php
/**
 * The Twitch endpoints the chatbot uses.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * The approved Twitch endpoints (ADR-066, status note 2026-10-20) and
 * nothing else: OAuth (authorization code and client credentials), the
 * token owner (`helix/users`), Send Chat Message and EventSub subscriptions.
 *
 * - **Calls:** 10-second timeout, no redirects. A transport error is an
 *   UNAVAILABLE failure; tokens and secrets are never part of an exception
 *   or a log message.
 * - **Tokens:** messages and EventSub use the app access token, so the bot
 *   gets Twitch's chatbot badge and chat identity; a user token is only used
 *   once, to learn whose account it is.
 */
final class TwitchApi {

	public const AUTHORIZE_URL = 'https://id.twitch.tv/oauth2/authorize';

	private const TOKEN_URL         = 'https://id.twitch.tv/oauth2/token';
	private const USERS_URL         = 'https://api.twitch.tv/helix/users';
	private const MESSAGES_URL      = 'https://api.twitch.tv/helix/chat/messages';
	private const SUBSCRIPTIONS_URL = 'https://api.twitch.tv/helix/eventsub/subscriptions';

	/**
	 * Twitch's longest chat message in characters.
	 */
	public const MESSAGE_MAX = 500;

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
	 * The URL that asks a Twitch user to authorize the app.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $redirect_uri Registered redirect URI.
	 * @param string $scopes       Space-separated scopes.
	 * @param string $state        Single-use state.
	 * @param bool   $force_verify Whether Twitch always asks again.
	 */
	public static function authorize_url( string $client_id, string $redirect_uri, string $scopes, string $state, bool $force_verify ): string {
		return self::AUTHORIZE_URL . '?' . http_build_query(
			array(
				'response_type' => 'code',
				'client_id'     => $client_id,
				'redirect_uri'  => $redirect_uri,
				'scope'         => $scopes,
				'state'         => $state,
				'force_verify'  => $force_verify ? 'true' : 'false',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * An app access token (client credentials).
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Client secret.
	 * @return array{token: string, expires_in: int}
	 * @throws TwitchException When the credentials are refused or Twitch cannot be reached.
	 */
	public function app_token( string $client_id, string $secret ): array {
		$answer = $this->form(
			self::TOKEN_URL,
			array(
				'client_id'     => $client_id,
				'client_secret' => $secret,
				'grant_type'    => 'client_credentials',
			)
		);
		$token  = $answer['access_token'] ?? null;

		if ( ! is_string( $token ) || '' === $token ) {
			throw new TwitchException( TwitchException::UNAVAILABLE );
		}

		return array(
			'token'      => $token,
			'expires_in' => is_int( $answer['expires_in'] ?? null ) ? $answer['expires_in'] : 3600,
		);
	}

	/**
	 * The user access token of an authorization code.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $secret       Client secret.
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri The redirect URI of the authorization.
	 * @throws TwitchException When Twitch refuses the code or cannot be reached.
	 */
	public function exchange_code( string $client_id, string $secret, string $code, string $redirect_uri ): string {
		$answer = $this->form(
			self::TOKEN_URL,
			array(
				'client_id'     => $client_id,
				'client_secret' => $secret,
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $redirect_uri,
			)
		);
		$token  = $answer['access_token'] ?? null;

		if ( ! is_string( $token ) || '' === $token ) {
			throw new TwitchException( TwitchException::UNAVAILABLE );
		}

		return $token;
	}

	/**
	 * The Twitch account a user access token belongs to.
	 *
	 * @param string $client_id  Client ID.
	 * @param string $user_token User access token.
	 * @return array{id: string, login: string}
	 * @throws TwitchException When the token is refused or Twitch cannot be reached.
	 */
	public function token_user( string $client_id, string $user_token ): array {
		$answer = $this->json( 'GET', self::USERS_URL, $client_id, $user_token, null );
		$user   = is_array( $answer['body']['data'] ?? null ) ? ( $answer['body']['data'][0] ?? null ) : null;

		self::check( $answer['status'] );

		if ( ! is_array( $user ) || ! is_string( $user['id'] ?? null ) || ! is_string( $user['login'] ?? null ) || 1 !== preg_match( '/^\d{1,20}$/', $user['id'] ) || 1 !== preg_match( '/^\w{1,25}$/', $user['login'] ) ) {
			throw new TwitchException( TwitchException::UNAVAILABLE );
		}

		return array(
			'id'    => $user['id'],
			'login' => strtolower( $user['login'] ),
		);
	}

	/**
	 * Sends a chat message as the bot, with the app access token.
	 *
	 * @param string $client_id      Client ID.
	 * @param string $app_token      App access token.
	 * @param string $broadcaster_id Channel.
	 * @param string $sender_id      Bot account.
	 * @param string $message        Message (cut to MESSAGE_MAX).
	 * @return array{result: string, reason: string, limit: int|null, remaining: int|null, reset: int|null} result: sent, dropped, rate_limited, unauthorized, forbidden or failed.
	 */
	public function send_message( string $client_id, string $app_token, string $broadcaster_id, string $sender_id, string $message ): array {
		$answer = $this->json(
			'POST',
			self::MESSAGES_URL,
			$client_id,
			$app_token,
			array(
				'broadcaster_id' => $broadcaster_id,
				'sender_id'      => $sender_id,
				'message'        => mb_substr( $message, 0, self::MESSAGE_MAX ),
			)
		);
		$status = $answer['status'];
		$result = match ( true ) {
			429 === $status => 'rate_limited',
			401 === $status => 'unauthorized',
			403 === $status => 'forbidden',
			200 !== $status => 'failed',
			default         => 'sent',
		};
		$reason = '';
		$sent   = is_array( $answer['body']['data'] ?? null ) ? ( $answer['body']['data'][0] ?? null ) : null;

		if ( 'sent' === $result && ( ! is_array( $sent ) || true !== ( $sent['is_sent'] ?? null ) ) ) {
			$result = 'dropped';
			$reason = is_array( $sent ) && is_array( $sent['drop_reason'] ?? null ) && is_string( $sent['drop_reason']['code'] ?? null ) ? substr( sanitize_key( $sent['drop_reason']['code'] ), 0, 60 ) : '';
		}

		return array(
			'result'    => $result,
			'reason'    => $reason,
			'limit'     => self::header_int( $answer['headers'], 'ratelimit-limit' ),
			'remaining' => self::header_int( $answer['headers'], 'ratelimit-remaining' ),
			'reset'     => self::header_int( $answer['headers'], 'ratelimit-reset' ),
		);
	}

	/**
	 * Subscribes the bot to a channel's chat (EventSub `channel.chat.message`
	 * over webhook, with the app access token).
	 *
	 * @param string $client_id      Client ID.
	 * @param string $app_token      App access token.
	 * @param string $broadcaster_id Channel.
	 * @param string $bot_id         Bot account.
	 * @param string $callback       Webhook URL (HTTPS).
	 * @param string $secret         Webhook secret (10–100 characters).
	 * @return array{id: string, status: string}
	 * @throws TwitchException When Twitch refuses it (FORBIDDEN without `channel:bot`) or cannot be reached.
	 */
	public function subscribe_chat( string $client_id, string $app_token, string $broadcaster_id, string $bot_id, string $callback, string $secret ): array {
		$answer = $this->json(
			'POST',
			self::SUBSCRIPTIONS_URL,
			$client_id,
			$app_token,
			array(
				'type'      => 'channel.chat.message',
				'version'   => '1',
				'condition' => array(
					'broadcaster_user_id' => $broadcaster_id,
					'user_id'             => $bot_id,
				),
				'transport' => array(
					'method'   => 'webhook',
					'callback' => $callback,
					'secret'   => $secret,
				),
			)
		);

		// Already subscribed: the existing subscription is used.
		if ( 409 === $answer['status'] ) {
			return $this->find_subscription( $client_id, $app_token, $broadcaster_id ) ?? array(
				'id'     => '',
				'status' => 'enabled',
			);
		}

		self::check( $answer['status'], 202 );
		$data = is_array( $answer['body']['data'] ?? null ) ? ( $answer['body']['data'][0] ?? null ) : null;

		return array(
			'id'     => is_array( $data ) && is_string( $data['id'] ?? null ) ? substr( $data['id'], 0, 64 ) : '',
			'status' => is_array( $data ) && is_string( $data['status'] ?? null ) ? substr( sanitize_key( $data['status'] ), 0, 60 ) : 'pending',
		);
	}

	/**
	 * Ends a chat subscription; an unknown id is no error.
	 *
	 * @param string $client_id Client ID.
	 * @param string $app_token App access token.
	 * @param string $id        Subscription id.
	 * @throws TwitchException When Twitch cannot be reached or refuses the token.
	 */
	public function unsubscribe( string $client_id, string $app_token, string $id ): void {
		if ( '' === $id ) {
			return;
		}

		$answer = $this->json( 'DELETE', self::SUBSCRIPTIONS_URL . '?' . http_build_query( array( 'id' => $id ) ), $client_id, $app_token, null );

		if ( 404 !== $answer['status'] ) {
			self::check( $answer['status'], 204 );
		}
	}

	/**
	 * The bot's chat subscription of a channel, or null.
	 *
	 * @param string $client_id      Client ID.
	 * @param string $app_token      App access token.
	 * @param string $broadcaster_id Channel.
	 * @return array{id: string, status: string}|null
	 * @throws TwitchException When Twitch cannot be reached or refuses the token.
	 */
	private function find_subscription( string $client_id, string $app_token, string $broadcaster_id ): ?array {
		$answer = $this->json( 'GET', self::SUBSCRIPTIONS_URL . '?' . http_build_query( array( 'type' => 'channel.chat.message' ) ), $client_id, $app_token, null );

		self::check( $answer['status'] );

		foreach ( is_array( $answer['body']['data'] ?? null ) ? $answer['body']['data'] : array() as $subscription ) {
			if ( is_array( $subscription ) && is_array( $subscription['condition'] ?? null ) && ( $subscription['condition']['broadcaster_user_id'] ?? null ) === $broadcaster_id && is_string( $subscription['id'] ?? null ) ) {
				return array(
					'id'     => substr( $subscription['id'], 0, 64 ),
					'status' => is_string( $subscription['status'] ?? null ) ? substr( sanitize_key( $subscription['status'] ), 0, 60 ) : 'enabled',
				);
			}
		}

		return null;
	}

	/**
	 * A form POST to the OAuth token endpoint.
	 *
	 * @param string                $url    URL.
	 * @param array<string, string> $fields Fields.
	 * @return array<mixed>
	 * @throws TwitchException When Twitch refuses it or cannot be reached.
	 */
	private function form( string $url, array $fields ): array {
		$answer = ( $this->http )( 'POST', $url, array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $fields ) );

		if ( in_array( $answer['status'], array( 400, 401, 403 ), true ) ) {
			throw new TwitchException( TwitchException::INVALID_CREDENTIALS );
		}

		self::check( $answer['status'] );
		$body = json_decode( $answer['body'], true );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * A Helix request with a bearer token.
	 *
	 * @param string                    $method    Method.
	 * @param string                    $url       URL.
	 * @param string                    $client_id Client ID.
	 * @param string                    $token     Access token.
	 * @param array<string, mixed>|null $body      JSON body.
	 * @return array{status: int, headers: array<string, string>, body: array<mixed>}
	 */
	private function json( string $method, string $url, string $client_id, string $token, ?array $body ): array {
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Client-Id'     => $client_id,
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
	 * @throws TwitchException When it is not a success.
	 */
	private static function check( int $status, int $expected = 200 ): void {
		if ( $expected === $status ) {
			return;
		}

		throw new TwitchException(
			match ( $status ) {
				401 => TwitchException::UNAUTHORIZED,
				403 => TwitchException::FORBIDDEN,
				default => TwitchException::UNAVAILABLE,
			}
		);
	}

	/**
	 * An integer header, or null.
	 *
	 * @param array<string, string> $headers Lowercase headers.
	 * @param string                $name    Lowercase name.
	 */
	private static function header_int( array $headers, string $name ): ?int {
		$value = $headers[ $name ] ?? null;

		return is_string( $value ) && 1 === preg_match( '/^\d{1,12}$/', $value ) ? (int) $value : null;
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
