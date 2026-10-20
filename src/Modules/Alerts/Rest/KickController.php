<?php
/**
 * REST: Kick's redirect and webhook.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatLogRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\KickOAuth;
use MerchBoard\Modules\Alerts\Chatbot\KickWebhookVerifier;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The two routes Kick calls (ADR-069 pkt. 6 and 7). Both are public,
 * because Kick cannot log in; each proves itself another way.
 *
 * - `GET /alerts/kick/callback`: Kick's redirect after a creator authorized
 *   the app. The single-use state (KickOAuth) is the proof; the browser is
 *   then sent back to the dashboard (302).
 * - `POST /alerts/kick/webhook`: Kick's events. Only a request with Kick's
 *   valid RSA signature and a timestamp of the last ten minutes is read
 *   (KickWebhookVerifier); everything else gets 403. Chat messages
 *   (`chat.message.sent`) of the bot's channels are stored for the admin's
 *   chat window; other events are ignored.
 */
final class KickController extends RestController {

	private const CALLBACK = '/alerts/kick/callback';
	private const WEBHOOK  = '/alerts/kick/webhook';

	/**
	 * Constructor.
	 *
	 * @param KickOAuth                $oauth    Authorizations.
	 * @param KickWebhookVerifier      $verifier Webhook signatures.
	 * @param ChatbotChannelRepository $channels The creators' channels.
	 * @param ChatLogRepository        $chat     The chat log.
	 * @param Logger                   $logger   Technical log.
	 */
	public function __construct( private KickOAuth $oauth, private KickWebhookVerifier $verifier, private ChatbotChannelRepository $channels, private ChatLogRepository $chat, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
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
			self::WEBHOOK,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'webhook' ),
					'permission_callback' => '__return_true',
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * `GET /alerts/kick/callback`.
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
	 * `POST /alerts/kick/webhook`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function webhook( WP_REST_Request $request ): WP_REST_Response {
		$id        = (string) $request->get_header( 'kick_event_message_id' );
		$timestamp = (string) $request->get_header( 'kick_event_message_timestamp' );
		$signature = (string) $request->get_header( 'kick_event_signature' );
		$type      = (string) $request->get_header( 'kick_event_type' );
		$body      = $request->get_body();

		if ( ! $this->verifier->verify( $id, $timestamp, $signature, $body ) ) {
			return new WP_REST_Response( null, 403 );
		}

		$payload = json_decode( $body, true );

		if ( 'chat.message.sent' !== $type || ! is_array( $payload ) ) {
			return new WP_REST_Response( null, 200 );
		}

		try {
			$this->store( $payload );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A Kick event could not be stored.', array( 'reason' => $e->getMessage() ) );

			return new WP_REST_Response( null, 500 );
		}

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Stores a chat message of a channel with the bot.
	 *
	 * @param array<mixed> $payload `chat.message.sent` payload.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function store( array $payload ): void {
		$broadcaster = is_array( $payload['broadcaster'] ?? null ) ? $payload['broadcaster'] : array();
		$sender      = is_array( $payload['sender'] ?? null ) ? $payload['sender'] : array();
		$identity    = is_array( $sender['identity'] ?? null ) ? $sender['identity'] : array();
		$id          = $broadcaster['user_id'] ?? null;
		$message_id  = is_string( $payload['message_id'] ?? null ) ? $payload['message_id'] : '';
		$channel     = is_int( $id ) || is_string( $id ) ? $this->channels->find_by_broadcaster( ChatPlatform::Kick, (string) $id ) : null;

		if ( null === $channel || '' === $message_id ) {
			return;
		}

		$this->chat->add(
			ChatPlatform::Kick,
			$channel->broadcaster_id,
			$message_id,
			is_string( $sender['channel_slug'] ?? null ) ? $sender['channel_slug'] : '',
			is_string( $sender['username'] ?? null ) ? $sender['username'] : '',
			is_string( $identity['username_color'] ?? null ) ? $identity['username_color'] : '',
			is_string( $payload['content'] ?? null ) ? $payload['content'] : ''
		);
	}
}
