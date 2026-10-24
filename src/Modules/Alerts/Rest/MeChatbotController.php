<?php
/**
 * REST: the creator's chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotException;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\CreatorChatbot;
use MerchBoard\Modules\Alerts\Chatbot\KickOAuth;
use MerchBoard\Modules\Alerts\Chatbot\TwitchOAuth;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeOAuth;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me/alerts/chatbot` (phase 25, steps 8 and 8.2). The creator is always
 * the one CreatorContext resolves (ADR-007).
 *
 * - `GET`: the chatbot on every platform (CreatorChatbot::describe).
 * - `PATCH` with JSON `{ template?, enabled?: { twitch?, kick? } }`: the
 *   shared template (plain text, at most 500 characters) and messages on or
 *   off per platform.
 * - `POST …/{platform}/connect`: `{ "url": … }`, the platform's page where
 *   the creator adds the bot to the own channel; 409 before the
 *   administrator has set the platform up.
 * - `DELETE …/{platform}/channel`: removes the bot from the channel.
 * - `POST …/{platform}/test`: sends a test message; `{ result, reason, message }`.
 * - YouTube (ADR-071): `connect` requires `{ "accept_terms": true }` (400
 *   `merchboard_terms_required` otherwise) and stores the acceptance;
 *   `POST …/youtube/terms` with `{ "accept": true }` accepts a new version.
 */
final class MeChatbotController extends RestController {

	private const ROUTE = '/me/alerts/chatbot';

	/**
	 * Route pattern of a platform.
	 */
	private const PLATFORM = '/(?P<platform>twitch|kick|youtube)';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext $context Creator access.
	 * @param CreatorChatbot $chatbot The creator's chatbot.
	 * @param TwitchOAuth    $twitch  Twitch authorizations.
	 * @param KickOAuth      $kick    Kick authorizations.
	 * @param YouTubeOAuth   $youtube YouTube authorizations.
	 * @param Logger         $logger  Technical log.
	 */
	public function __construct( private CreatorContext $context, private CreatorChatbot $chatbot, private TwitchOAuth $twitch, private KickOAuth $kick, private YouTubeOAuth $youtube, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$route = function ( string $path, string $method, string $callback ): void {
			register_rest_route(
				self::NAMESPACE,
				self::ROUTE . self::PLATFORM . $path,
				array(
					array(
						'methods'             => $method,
						'callback'            => array( $this, $callback ),
						'permission_callback' => array( $this, 'permission' ),
						'args'                => array(),
					),
				)
			);
		};

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_chatbot' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_chatbot' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);
		$route( '/connect', WP_REST_Server::CREATABLE, 'connect' );
		$route( '/channel', WP_REST_Server::DELETABLE, 'disconnect' );
		$route( '/test', WP_REST_Server::CREATABLE, 'test' );
		$route( '/terms', WP_REST_Server::CREATABLE, 'accept_terms' );
	}

	/**
	 * Only creators with dashboard access.
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/alerts/chatbot`.
	 */
	public function get_chatbot(): WP_REST_Response|WP_Error {
		return $this->with_creator( self::ROUTE, fn( int $creator_id ): WP_REST_Response => $this->answer( $creator_id ) );
	}

	/**
	 * `PATCH /me/alerts/chatbot`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_chatbot( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE,
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$body     = (array) $request->get_json_params();
				$template = $body['template'] ?? null;
				$enabled  = $body['enabled'] ?? array();

				if ( ( null !== $template && ! is_string( $template ) ) || ! is_array( $enabled ) ) {
					return self::invalid();
				}

				$switches = array();

				foreach ( $enabled as $platform => $on ) {
					if ( null === ChatPlatform::parse( $platform ) || ! is_bool( $on ) ) {
						return self::invalid();
					}

					$switches[ (string) $platform ] = $on;
				}

				$this->chatbot->save( $creator_id, $template, $switches );

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * `POST /me/alerts/chatbot/{platform}/connect`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function connect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = self::platform( $request );

		return $this->with_creator(
			self::ROUTE . '/connect',
			function ( int $creator_id ) use ( $platform, $request ): WP_REST_Response|WP_Error {
				$youtube = ChatPlatform::YouTube === $platform;

				// YouTube: the creator must accept the privacy policy and terms (ADR-071).
				if ( $youtube && ! self::accepted( $request, 'accept_terms' ) ) {
					return self::terms_required();
				}

				try {
					$url = match ( $platform ) {
						ChatPlatform::Twitch  => $this->twitch->start_channel( $creator_id ),
						ChatPlatform::Kick    => $this->kick->start_channel( $creator_id ),
						ChatPlatform::YouTube => $this->youtube->start_channel( $creator_id ),
					};

					// Stored only once the login can start.
					if ( $youtube ) {
						$this->chatbot->accept_youtube_terms( $creator_id );
					}

					return new WP_REST_Response( array( 'url' => $url ), 200 );
				} catch ( ChatbotException $e ) {
					return self::error( 'merchboard_chatbot_unavailable', __( 'The chatbot is not set up yet.', 'merchboard' ), 409, array( 'reason' => $e->reason() ) );
				}
			}
		);
	}

	/**
	 * `DELETE /me/alerts/chatbot/{platform}/channel`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function disconnect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = self::platform( $request );

		return $this->with_creator(
			self::ROUTE . '/channel',
			function ( int $creator_id ) use ( $platform ): WP_REST_Response {
				$this->chatbot->disconnect( $creator_id, $platform );

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * `POST /me/alerts/chatbot/{platform}/test`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = self::platform( $request );

		return $this->with_creator(
			self::ROUTE . '/test',
			function ( int $creator_id ) use ( $platform ): WP_REST_Response|WP_Error {
				try {
					return new WP_REST_Response( $this->chatbot->test( $creator_id, $platform ), 200 );
				} catch ( ChatbotException $e ) {
					if ( ChatbotException::TOO_MANY === $e->reason() ) {
						return self::error( 'merchboard_too_many_requests', __( 'Too many tests. Please wait a minute.', 'merchboard' ), 429 );
					}

					return self::error( 'merchboard_chatbot_unavailable', __( 'The test message could not be sent.', 'merchboard' ), 409, array( 'reason' => $e->reason() ) );
				}
			}
		);
	}

	/**
	 * `POST /me/alerts/chatbot/youtube/terms` with `{ "accept": true }`:
	 * accepts the current privacy policy and terms of the YouTube chatbot
	 * (e.g. a new version). Only for YouTube.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function accept_terms( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$platform = self::platform( $request );

		return $this->with_creator(
			self::ROUTE . '/terms',
			function ( int $creator_id ) use ( $platform, $request ): WP_REST_Response|WP_Error {
				if ( ChatPlatform::YouTube !== $platform ) {
					return self::invalid();
				}

				if ( ! self::accepted( $request, 'accept' ) ) {
					return self::terms_required();
				}

				try {
					$this->chatbot->accept_youtube_terms( $creator_id );
				} catch ( ChatbotException $e ) {
					return self::error( 'merchboard_chatbot_unavailable', __( 'The chatbot is not set up yet.', 'merchboard' ), 409, array( 'reason' => $e->reason() ) );
				}

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * Whether the JSON body has `true` in a field.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $field   Field.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function accepted( WP_REST_Request $request, string $field ): bool {
		return true === ( ( (array) $request->get_json_params() )[ $field ] ?? null );
	}

	/**
	 * 400 when the privacy policy and terms were not accepted.
	 */
	private static function terms_required(): WP_Error {
		return self::error( 'merchboard_terms_required', __( 'Accept the privacy policy and terms of the YouTube chatbot first.', 'merchboard' ), 400 );
	}

	/**
	 * The chatbot as the dashboard shows it.
	 *
	 * @param int $creator_id Creator.
	 */
	private function answer( int $creator_id ): WP_REST_Response {
		$response = new WP_REST_Response( $this->chatbot->describe( $creator_id ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The route's platform (the pattern allows only known ones).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function platform( WP_REST_Request $request ): ChatPlatform {
		return ChatPlatform::parse( $request->get_param( 'platform' ) ) ?? ChatPlatform::Twitch;
	}

	/**
	 * 400 for invalid settings.
	 */
	private static function invalid(): WP_Error {
		return self::error( 'merchboard_invalid_request', __( 'The chatbot settings are invalid.', 'merchboard' ), 400 );
	}

	/**
	 * Runs an endpoint for the current creator.
	 *
	 * @param string                                     $route    Route, for the log.
	 * @param callable(int): (WP_REST_Response|WP_Error) $callback Endpoint body.
	 */
	private function with_creator( string $route, callable $callback ): WP_REST_Response|WP_Error {
		return $this->run(
			$route,
			function () use ( $callback ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : $callback( $creator->id );
			}
		);
	}
}
