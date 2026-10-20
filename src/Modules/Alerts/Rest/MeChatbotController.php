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
use MerchBoard\Modules\Alerts\Chatbot\CreatorChatbot;
use MerchBoard\Modules\Alerts\Chatbot\TwitchException;
use MerchBoard\Modules\Alerts\Chatbot\TwitchOAuth;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me/alerts/chatbot` (phase 25, step 8). The creator is always the one
 * CreatorContext resolves (ADR-007).
 *
 * - `GET`: the chatbot as the dashboard shows it (CreatorChatbot::describe).
 * - `PATCH` with JSON `{ enabled, template }`: messages on or off and the
 *   template (plain text, at most 500 characters).
 * - `POST …/connect`: `{ "url": … }`, Twitch's page where the creator adds
 *   the bot to the own channel (`channel:bot`); 409 before the administrator
 *   has set the bot up.
 * - `DELETE …/channel`: removes the bot from the channel.
 * - `POST …/test`: sends a test message; `{ result, reason, message }`.
 */
final class MeChatbotController extends RestController {

	private const ROUTE = '/me/alerts/chatbot';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext $context Creator access.
	 * @param CreatorChatbot $chatbot The creator's chatbot.
	 * @param TwitchOAuth    $oauth   Authorizations.
	 * @param Logger         $logger  Technical log.
	 */
	public function __construct( private CreatorContext $context, private CreatorChatbot $chatbot, private TwitchOAuth $oauth, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$route = function ( string $path, string $method, string $callback ): void {
			register_rest_route(
				self::NAMESPACE,
				self::ROUTE . $path,
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
	}

	/**
	 * Permission: an active creator (CreatorContext).
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
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_chatbot( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE,
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$body     = (array) $request->get_json_params();
				$current  = $this->chatbot->describe( $creator_id );
				$enabled  = $body['enabled'] ?? $current['enabled'];
				$template = $body['template'] ?? $current['template'];

				if ( ! is_bool( $enabled ) || ! is_string( $template ) ) {
					return self::error( 'merchboard_invalid_request', __( 'The chatbot settings are invalid.', 'merchboard' ), 400 );
				}

				$this->chatbot->save( $creator_id, $enabled, $template );

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * `POST /me/alerts/chatbot/connect`.
	 */
	public function connect(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/connect',
			function ( int $creator_id ): WP_REST_Response|WP_Error {
				try {
					return new WP_REST_Response( array( 'url' => $this->oauth->start_channel( $creator_id ) ), 200 );
				} catch ( TwitchException $e ) {
					return self::error( 'merchboard_chatbot_unavailable', __( 'The chatbot is not set up yet.', 'merchboard' ), 409, array( 'reason' => $e->reason() ) );
				}
			}
		);
	}

	/**
	 * `DELETE /me/alerts/chatbot/channel`.
	 */
	public function disconnect(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/channel',
			function ( int $creator_id ): WP_REST_Response {
				$this->chatbot->disconnect( $creator_id );

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * `POST /me/alerts/chatbot/test`.
	 */
	public function test(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/test',
			function ( int $creator_id ): WP_REST_Response|WP_Error {
				try {
					return new WP_REST_Response( $this->chatbot->test( $creator_id ), 200 );
				} catch ( TwitchException $e ) {
					return self::error( 'merchboard_chatbot_unavailable', __( 'The test message could not be sent.', 'merchboard' ), 409, array( 'reason' => $e->reason() ) );
				}
			}
		);
	}

	/**
	 * The answer of the chatbot routes.
	 *
	 * @param int $creator_id Creator.
	 */
	private function answer( int $creator_id ): WP_REST_Response {
		$response = new WP_REST_Response( $this->chatbot->describe( $creator_id ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
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
