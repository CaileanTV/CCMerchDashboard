<?php
/**
 * REST: Twitch's redirect and EventSub webhook.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatLogRepository;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchException;
use MerchBoard\Modules\Alerts\Chatbot\TwitchOAuth;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The two routes Twitch calls (ADR-066, status note 2026-10-20). Both are
 * public, because Twitch cannot log in; each proves itself another way.
 *
 * - `GET /alerts/twitch/callback`: Twitch's redirect after an authorization.
 *   The single-use state (TwitchOAuth) is the proof; the browser is then
 *   sent back to the admin page or the dashboard (302).
 * - `POST /alerts/twitch/eventsub`: EventSub's webhook. Only a request with
 *   Twitch's valid HMAC-SHA256 signature (`Twitch-Eventsub-Message-Signature`
 *   over the message id, the timestamp and the raw body, with the webhook
 *   secret) and a timestamp of the last ten minutes is read; everything
 *   else gets 403. It answers Twitch's verification with the challenge as
 *   plain text, notes a revoked subscription, and stores chat messages of
 *   the bot's channels for the admin's chat window.
 *
 * `GET /alerts/twitch/chat/{creator}?after={id}` serves that chat window
 * (cookie and `X-WP-Nonce`, only `merchboard_manage_alerts`): the channel's
 * messages after a cursor, as plain text fields.
 */
final class TwitchController extends RestController {

	private const CALLBACK = '/alerts/twitch/callback';
	private const EVENTSUB = '/alerts/twitch/eventsub';

	/**
	 * Oldest accepted EventSub message in seconds (Twitch's advice).
	 */
	private const MAX_AGE = 600;

	/**
	 * Constructor.
	 *
	 * @param TwitchOAuth              $oauth    Authorizations.
	 * @param TwitchAccount            $account  App and bot.
	 * @param ChatbotChannelRepository $channels The creators' channels.
	 * @param ChatLogRepository        $chat     Chat window messages.
	 * @param Logger                   $logger   Technical log.
	 */
	public function __construct( private TwitchOAuth $oauth, private TwitchAccount $account, private ChatbotChannelRepository $channels, private ChatLogRepository $chat, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes and the plain-text answer of the verification.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::CALLBACK,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'callback' ),
					'permission_callback' => '__return_true',
					'args'                => array(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::EVENTSUB,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'eventsub' ),
					'permission_callback' => '__return_true',
					'args'                => array(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/alerts/twitch/chat/(?P<creator>[1-9][0-9]{0,18})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'chat' ),
					'permission_callback' => static fn(): bool => current_user_can( Capabilities::MANAGE_ALERTS ),
					'args'                => array(
						'after' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_plain' ), 10, 3 );
	}

	/**
	 * `GET /alerts/twitch/chat/{creator}`: `{ messages: [...] }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			'/alerts/twitch/chat',
			function () use ( $request ): WP_REST_Response {
				$creator  = $request->get_param( 'creator' );
				$after    = $request->get_param( 'after' );
				$channel  = $this->channels->find( is_string( $creator ) ? (int) $creator : 0 );
				$messages = $channel->connected() ? $this->chat->after( $channel->broadcaster_id, is_int( $after ) ? $after : 0 ) : array();
				$response = new WP_REST_Response( array( 'messages' => $messages ), 200 );
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}

	/**
	 * `GET /alerts/twitch/callback`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function callback( WP_REST_Request $request ): WP_REST_Response {
		$text     = static fn( string $name ): string => is_string( $request->get_param( $name ) ) ? sanitize_text_field( (string) $request->get_param( $name ) ) : '';
		$location = $this->oauth->finish( $text( 'state' ), $text( 'code' ), $text( 'error' ) );
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', wp_validate_redirect( $location, home_url( '/' ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}

	/**
	 * `POST /alerts/twitch/eventsub`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function eventsub( WP_REST_Request $request ): WP_REST_Response {
		$id        = (string) $request->get_header( 'twitch_eventsub_message_id' );
		$timestamp = (string) $request->get_header( 'twitch_eventsub_message_timestamp' );
		$signature = (string) $request->get_header( 'twitch_eventsub_message_signature' );
		$type      = (string) $request->get_header( 'twitch_eventsub_message_type' );
		$body      = $request->get_body();

		if ( ! $this->verified( $id, $timestamp, $signature, $body ) ) {
			return new WP_REST_Response( null, 403 );
		}

		$payload = json_decode( $body, true );
		$payload = is_array( $payload ) ? $payload : array();

		try {
			return $this->handle( $type, $payload );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A Twitch event could not be stored.', array( 'reason' => $e->getMessage() ) );

			return new WP_REST_Response( null, 500 );
		}
	}

	/**
	 * Sends the verification's challenge as plain text instead of JSON
	 * (`rest_pre_serve_request`).
	 *
	 * @param mixed           $served  Whether the request was served.
	 * @param mixed           $result  Response.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function serve_plain( mixed $served, mixed $result, WP_REST_Request $request ): mixed {
		if ( '/' . self::NAMESPACE . self::EVENTSUB !== $request->get_route() || ! $result instanceof WP_REST_Response || ! is_string( $result->get_data() ) ) {
			return $served;
		}

		echo esc_html( $result->get_data() );

		return true;
	}

	/**
	 * Whether a request carries Twitch's valid, recent signature.
	 *
	 * @param string $id        Message id.
	 * @param string $timestamp RFC 3339 timestamp.
	 * @param string $signature `sha256=` and the HMAC.
	 * @param string $body      Raw body.
	 */
	private function verified( string $id, string $timestamp, string $signature, string $body ): bool {
		$time = strtotime( $timestamp );

		if ( '' === $id || false === $time || abs( time() - $time ) > self::MAX_AGE || ! str_starts_with( $signature, 'sha256=' ) ) {
			return false;
		}

		try {
			$secret = $this->account->webhook_secret();
		} catch ( TwitchException ) {
			return false;
		}

		return hash_equals( 'sha256=' . hash_hmac( 'sha256', $id . $timestamp . $body, $secret ), $signature );
	}

	/**
	 * Handles a verified message.
	 *
	 * @param string       $type    Message type.
	 * @param array<mixed> $payload Body.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function handle( string $type, array $payload ): WP_REST_Response {
		$subscription = is_array( $payload['subscription'] ?? null ) ? $payload['subscription'] : array();
		$condition    = is_array( $subscription['condition'] ?? null ) ? $subscription['condition'] : array();
		$broadcaster  = is_string( $condition['broadcaster_user_id'] ?? null ) ? $condition['broadcaster_user_id'] : '';
		$channel      = $this->channels->find_by_broadcaster( $broadcaster );

		if ( 'webhook_callback_verification' === $type ) {
			if ( null !== $channel ) {
				$this->channels->set_subscription( $channel->creator_id, is_string( $subscription['id'] ?? null ) ? $subscription['id'] : $channel->subscription_id, 'enabled' );
			}

			$challenge = is_string( $payload['challenge'] ?? null ) ? $payload['challenge'] : '';

			return new WP_REST_Response( $challenge, 200, array( 'Content-Type' => 'text/plain; charset=utf-8' ) );
		}

		if ( 'revocation' === $type && null !== $channel ) {
			$this->channels->set_subscription( $channel->creator_id, '', is_string( $subscription['status'] ?? null ) ? sanitize_key( $subscription['status'] ) : 'revoked' );
		}

		$event = is_array( $payload['event'] ?? null ) ? $payload['event'] : array();

		if ( 'notification' === $type && null !== $channel && 'channel.chat.message' === ( $subscription['type'] ?? null ) ) {
			$message = is_array( $event['message'] ?? null ) ? $event['message'] : array();
			$this->chat->add(
				$broadcaster,
				is_string( $event['message_id'] ?? null ) ? $event['message_id'] : '',
				is_string( $event['chatter_user_login'] ?? null ) ? $event['chatter_user_login'] : '',
				is_string( $event['chatter_user_name'] ?? null ) ? $event['chatter_user_name'] : '',
				is_string( $event['color'] ?? null ) ? $event['color'] : '',
				is_string( $message['text'] ?? null ) ? $message['text'] : ''
			);
		}

		return new WP_REST_Response( null, 204 );
	}
}
