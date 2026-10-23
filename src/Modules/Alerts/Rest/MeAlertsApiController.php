<?php
/**
 * REST: the creator's API keys of the alert API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Api\AlertApiException;
use MerchBoard\Modules\Alerts\Api\AlertApiFeed;
use MerchBoard\Modules\Alerts\Api\AlertApiFeedController;
use MerchBoard\Modules\Alerts\Api\AlertApiKey;
use MerchBoard\Modules\Alerts\Api\AlertApiKeys;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me/alerts/api` (step 25.12.1, ADR-073). The creator is always the one
 * CreatorContext resolves (ADR-007); key ids only ever select among the
 * creator's own keys.
 * - `GET /me/alerts/api`: `{ enabled, endpoint, limit, max_keys, keys }`.
 * - `POST /me/alerts/api/keys` with `{ "name": "…" }`: 201 with `{ key }`
 *   (the whole key, shown once) and the list.
 * - `DELETE /me/alerts/api/keys/{id}`: revokes the key; the list.
 * - `POST /me/alerts/api/test`: a test event for the creator's bots,
 *   `{ "sent": keys }`.
 * While the administrator has the API off, making keys and tests answer
 * 403 `merchboard_alerts_api_disabled`; revoking always works.
 */
final class MeAlertsApiController extends RestController {

	private const ROUTE = '/me/alerts/api';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext $context Creator access.
	 * @param AlertApiKeys   $keys    Keys.
	 * @param AlertApiSwitch $api     The administrator's switch.
	 * @param AlertApiFeed   $feed    Test events.
	 * @param Logger         $logger  Technical log.
	 */
	public function __construct( private CreatorContext $context, private AlertApiKeys $keys, private AlertApiSwitch $api, private AlertApiFeed $feed, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$routes = array(
			self::ROUTE                                    => array( WP_REST_Server::READABLE, 'get_api' ),
			self::ROUTE . '/keys'                          => array( WP_REST_Server::CREATABLE, 'create_key' ),
			self::ROUTE . '/keys/(?P<id>[1-9][0-9]{0,18})' => array( WP_REST_Server::DELETABLE, 'revoke_key' ),
			self::ROUTE . '/test'                          => array( WP_REST_Server::CREATABLE, 'send_test' ),
		);

		foreach ( $routes as $route => [ $methods, $callback ] ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					array(
						'methods'             => $methods,
						'callback'            => array( $this, $callback ),
						'permission_callback' => array( $this, 'permission' ),
						'args'                => array(),
					),
				)
			);
		}
	}

	/**
	 * Permission: an active creator (CreatorContext).
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/alerts/api`.
	 */
	public function get_api(): WP_REST_Response|WP_Error {
		return $this->with_creator( self::ROUTE, fn( int $creator_id ): WP_REST_Response => $this->answer( $creator_id ) );
	}

	/**
	 * `POST /me/alerts/api/keys`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create_key( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/keys',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$name = ( (array) $request->get_json_params() )['name'] ?? null;

				try {
					$key = $this->keys->create( $creator_id, is_string( $name ) ? $name : '' );
				} catch ( AlertApiException $e ) {
					return self::refused( $e );
				}

				$response = $this->answer( $creator_id, $key );
				$response->set_status( 201 );

				return $response;
			}
		);
	}

	/**
	 * `DELETE /me/alerts/api/keys/{id}`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function revoke_key( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/keys/revoke',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$id = $request->get_param( 'id' );

				try {
					$this->keys->revoke( $creator_id, is_string( $id ) ? (int) $id : 0 );
				} catch ( AlertApiException $e ) {
					return self::refused( $e );
				}

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * `POST /me/alerts/api/test`.
	 */
	public function send_test(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/test',
			function ( int $creator_id ): WP_REST_Response|WP_Error {
				if ( ! $this->api->enabled() ) {
					return self::refused( new AlertApiException( AlertApiException::DISABLED ) );
				}

				$response = new WP_REST_Response( array( 'sent' => $this->feed->send_test( $creator_id ) ), 200 );
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}

	/**
	 * The creator's API page.
	 *
	 * @param int         $creator_id Creator.
	 * @param string|null $key        A key just made, shown once.
	 */
	private function answer( int $creator_id, ?string $key = null ): WP_REST_Response {
		$data = array(
			'enabled'  => $this->api->enabled(),
			'endpoint' => rest_url( self::NAMESPACE . '/alerts/api/events' ),
			'limit'    => AlertApiFeedController::LIMIT,
			'max_keys' => AlertApiKeys::MAX_KEYS,
			'keys'     => array_map( static fn( AlertApiKey $item ): array => $item->to_array(), $this->keys->for_creator( $creator_id ) ),
		);

		if ( null !== $key ) {
			$data['key'] = $key;
		}

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The answer to a refused change.
	 *
	 * @param AlertApiException $e Reason.
	 */
	private static function refused( AlertApiException $e ): WP_Error {
		return match ( $e->reason() ) {
			AlertApiException::DISABLED     => self::error( 'merchboard_alerts_api_disabled', __( 'The alert API is switched off. Ask the shop to switch it on.', 'merchboard' ), 403 ),
			AlertApiException::INVALID_NAME => self::error( 'merchboard_invalid_request', __( 'Give the key a name of at most 60 characters.', 'merchboard' ), 400, array( 'fields' => array( 'name' => 'invalid' ) ) ),
			/* translators: %d: most keys. */
			AlertApiException::KEY_LIMIT    => self::error( 'merchboard_alerts_api_key_limit', sprintf( __( 'You can have at most %d keys. Revoke one first.', 'merchboard' ), AlertApiKeys::MAX_KEYS ), 409 ),
			default                         => self::error( 'merchboard_not_found', __( 'The key was not found.', 'merchboard' ), 404 ),
		};
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
