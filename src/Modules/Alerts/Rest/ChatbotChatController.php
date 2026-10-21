<?php
/**
 * REST: the admin's chat window.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatLogRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /alerts/chat/{platform}/{creator}?after={id}` (phase 25, steps 8 and
 * 8.2): the latest chat messages of a creator's channel, which the
 * platform's webhook stored (TwitchController, KickController), for the
 * admin's chat window. Cookie and `X-WP-Nonce`; only
 * `merchboard_manage_alerts`. Plain text fields; the window inserts them as
 * text.
 */
final class ChatbotChatController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param ChatbotChannelRepository $channels The creators' channels.
	 * @param ChatLogRepository        $chat     The chat log.
	 * @param Logger                   $logger   Technical log.
	 */
	public function __construct( private ChatbotChannelRepository $channels, private ChatLogRepository $chat, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/alerts/chat/(?P<platform>twitch|kick|youtube)/(?P<creator>[1-9][0-9]{0,18})',
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
	}

	/**
	 * `{ messages: [...] }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			'/alerts/chat',
			function () use ( $request ): WP_REST_Response {
				$platform = ChatPlatform::parse( $request->get_param( 'platform' ) ) ?? ChatPlatform::Twitch;
				$creator  = $request->get_param( 'creator' );
				$after    = $request->get_param( 'after' );
				$channel  = $this->channels->find( is_string( $creator ) ? (int) $creator : 0, $platform );
				$messages = $channel->connected() ? $this->chat->after( $platform, $channel->broadcaster_id, is_int( $after ) ? $after : 0 ) : array();
				$response = new WP_REST_Response( array( 'messages' => $messages ), 200 );
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}
}
