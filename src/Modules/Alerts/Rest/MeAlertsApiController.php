<?php
/**
 * REST: the creator's API keys and webhooks of the alert API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Api\AlertApiAccess;
use MerchBoard\Modules\Alerts\Api\AlertApiException;
use MerchBoard\Modules\Alerts\Api\AlertApiFeed;
use MerchBoard\Modules\Alerts\Api\AlertApiFeedController;
use MerchBoard\Modules\Alerts\Api\AlertApiKey;
use MerchBoard\Modules\Alerts\Api\AlertApiKeys;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhook;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhooks;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDelivery;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDeliveryRepository;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDispatcher;
use MerchBoard\Modules\Alerts\Webhooks\WebhookException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me/alerts/api` (steps 25.12.1 and 25.12.2, ADR-073). The creator is
 * always the one CreatorContext resolves (ADR-007); ids only ever select
 * among the creator's own keys and webhooks.
 * - `GET /me/alerts/api`: `{ enabled, access: { keys, webhooks }, endpoint,
 *   limit, max_keys, keys, max_webhooks, webhooks, deliveries }` (the newest
 *   20 deliveries).
 * - `POST /me/alerts/api/keys` with `{ "name": "…" }`: 201 with `{ key }`
 *   (the whole key, shown once) and the page.
 * - `DELETE /me/alerts/api/keys/{id}`: revokes the key; the page.
 * - `POST /me/alerts/api/test`: a test event for the creator's keys,
 *   `{ "sent": keys }`.
 * - `POST /me/alerts/api/webhooks` with `{ name, format, url, secret,
 *   template }`: 201 with `{ secret }` when one was made (shown once).
 * - `PATCH /me/alerts/api/webhooks/{id}` with `{ name?, template?, enabled?,
 *   resume? }`; `DELETE` the same route.
 * - `POST /me/alerts/api/webhooks/{id}/test`: sends a test purchase at once;
 *   the page with `{ delivery }`.
 * - `POST /me/alerts/api/webhooks/test`: a test purchase to each switched-on
 *   webhook at once; the page with `{ tested: [ deliveries ] }`.
 * - `POST /me/alerts/api/webhooks/{id}/secret` with `{ secret }` ('' to get
 *   one): the page, with `{ secret }` when one was made.
 * While the administrator has the API off, making keys and webhooks and
 * tests answer 403 `merchboard_alerts_api_disabled`, and without the
 * administrator's access to keys or webhooks (step 25.12.4) 403
 * `merchboard_alerts_api_no_access`; revoking, switching off and deleting
 * always work.
 */
final class MeAlertsApiController extends RestController {

	private const ROUTE = '/me/alerts/api';

	private const ID = '(?P<id>[1-9][0-9]{0,18})';

	/**
	 * Deliveries in the log.
	 */
	public const DELIVERIES = 20;

	/**
	 * Constructor.
	 *
	 * @param CreatorContext            $context    Creator access.
	 * @param AlertApiKeys              $keys       Keys.
	 * @param AlertApiSwitch            $api        The administrator's switch.
	 * @param AlertApiFeed              $feed       Test events.
	 * @param AlertWebhooks             $webhooks   Webhooks.
	 * @param WebhookDispatcher         $dispatcher Webhook tests.
	 * @param WebhookDeliveryRepository $deliveries The delivery log.
	 * @param AlertApiAccess            $access     The creator's access (step 25.12.4).
	 * @param Logger                    $logger     Technical log.
	 */
	public function __construct(
		private CreatorContext $context,
		private AlertApiKeys $keys,
		private AlertApiSwitch $api,
		private AlertApiFeed $feed,
		private AlertWebhooks $webhooks,
		private WebhookDispatcher $dispatcher,
		private WebhookDeliveryRepository $deliveries,
		private AlertApiAccess $access,
		Logger $logger
	) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$routes = array(
			array( self::ROUTE, WP_REST_Server::READABLE, 'get_api' ),
			array( self::ROUTE . '/keys', WP_REST_Server::CREATABLE, 'create_key' ),
			array( self::ROUTE . '/keys/' . self::ID, WP_REST_Server::DELETABLE, 'revoke_key' ),
			array( self::ROUTE . '/test', WP_REST_Server::CREATABLE, 'send_test' ),
			array( self::ROUTE . '/webhooks', WP_REST_Server::CREATABLE, 'create_webhook' ),
			array( self::ROUTE . '/webhooks/test', WP_REST_Server::CREATABLE, 'test_webhooks' ),
			array( self::ROUTE . '/webhooks/' . self::ID, 'PATCH', 'update_webhook' ),
			array( self::ROUTE . '/webhooks/' . self::ID, WP_REST_Server::DELETABLE, 'delete_webhook' ),
			array( self::ROUTE . '/webhooks/' . self::ID . '/test', WP_REST_Server::CREATABLE, 'test_webhook' ),
			array( self::ROUTE . '/webhooks/' . self::ID . '/secret', WP_REST_Server::CREATABLE, 'webhook_secret' ),
		);

		foreach ( $routes as [ $route, $methods, $callback ] ) {
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
				try {
					$key = $this->keys->create( $creator_id, self::text( $request, 'name' ) );
				} catch ( AlertApiException $e ) {
					return self::refused( $e );
				}

				$response = $this->answer( $creator_id, array( 'key' => $key ) );
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
				try {
					$this->keys->revoke( $creator_id, self::id( $request ) );
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

				if ( ! $this->feed->allowed( $creator_id ) ) {
					return self::refused( new AlertApiException( AlertApiException::NO_ACCESS ) );
				}

				$response = new WP_REST_Response( array( 'sent' => $this->feed->send_test( $creator_id ) ), 200 );
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}

	/**
	 * `POST /me/alerts/api/webhooks`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/webhooks',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				try {
					$made = $this->webhooks->create( $creator_id, self::text( $request, 'name' ), self::text( $request, 'format' ), self::text( $request, 'url' ), self::text( $request, 'secret' ), self::text( $request, 'template' ) );
				} catch ( WebhookException $e ) {
					return self::webhook_refused( $e );
				}

				$response = $this->answer( $creator_id, null === $made['secret'] ? array() : array( 'secret' => $made['secret'] ) );
				$response->set_status( 201 );

				return $response;
			}
		);
	}

	/**
	 * `PATCH /me/alerts/api/webhooks/{id}`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/webhooks/update',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$body    = (array) $request->get_json_params();
				$changes = array();

				foreach ( array( 'name', 'template' ) as $field ) {
					if ( is_string( $body[ $field ] ?? null ) ) {
						$changes[ $field ] = $body[ $field ];
					}
				}

				foreach ( array( 'enabled', 'resume' ) as $field ) {
					if ( is_bool( $body[ $field ] ?? null ) ) {
						$changes[ $field ] = $body[ $field ];
					}
				}

				try {
					$this->webhooks->update( $creator_id, self::id( $request ), $changes );
				} catch ( WebhookException $e ) {
					return self::webhook_refused( $e );
				}

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * `DELETE /me/alerts/api/webhooks/{id}`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/webhooks/delete',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				try {
					$this->webhooks->delete( $creator_id, self::id( $request ) );
				} catch ( WebhookException $e ) {
					return self::webhook_refused( $e );
				}

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * `POST /me/alerts/api/webhooks/{id}/test`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function test_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/webhooks/test',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				try {
					$delivery = $this->dispatcher->send_test( $creator_id, self::id( $request ) );
				} catch ( WebhookException $e ) {
					return self::webhook_refused( $e );
				}

				return $this->answer( $creator_id, array( 'delivery' => $delivery->to_array() ) );
			}
		);
	}

	/**
	 * `POST /me/alerts/api/webhooks/test`.
	 */
	public function test_webhooks(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/webhooks/test-all',
			function ( int $creator_id ): WP_REST_Response|WP_Error {
				try {
					$tested = $this->dispatcher->send_test_all( $creator_id );
				} catch ( WebhookException $e ) {
					return self::webhook_refused( $e );
				}

				return $this->answer( $creator_id, array( 'tested' => array_map( static fn( WebhookDelivery $item ): array => $item->to_array(), $tested ) ) );
			}
		);
	}

	/**
	 * `POST /me/alerts/api/webhooks/{id}/secret`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function webhook_secret( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/webhooks/secret',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				try {
					$made = $this->webhooks->new_secret( $creator_id, self::id( $request ), self::text( $request, 'secret' ) );
				} catch ( WebhookException $e ) {
					return self::webhook_refused( $e );
				}

				return $this->answer( $creator_id, null === $made ? array() : array( 'secret' => $made ) );
			}
		);
	}

	/**
	 * The creator's API page.
	 *
	 * @param int                  $creator_id Creator.
	 * @param array<string, mixed> $extra      A key or secret just made (shown once), or a test's delivery.
	 */
	private function answer( int $creator_id, array $extra = array() ): WP_REST_Response {
		$data = array_merge(
			array(
				'enabled'      => $this->api->enabled(),
				'access'       => $this->access->for_creator( $creator_id ),
				'endpoint'     => rest_url( self::NAMESPACE . '/alerts/api/events' ),
				'limit'        => AlertApiFeedController::LIMIT,
				'max_keys'     => AlertApiKeys::MAX_KEYS,
				'keys'         => array_map( static fn( AlertApiKey $item ): array => $item->to_array(), $this->keys->for_creator( $creator_id ) ),
				'max_webhooks' => AlertWebhooks::MAX_WEBHOOKS,
				'webhooks'     => array_map( static fn( AlertWebhook $item ): array => $item->to_array(), $this->webhooks->for_creator( $creator_id ) ),
				'deliveries'   => array_map( static fn( WebhookDelivery $item ): array => $item->to_array(), $this->deliveries->recent( $creator_id, self::DELIVERIES ) ),
			),
			$extra
		);

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The answer to a refused key change.
	 *
	 * @param AlertApiException $e Reason.
	 */
	private static function refused( AlertApiException $e ): WP_Error {
		return match ( $e->reason() ) {
			AlertApiException::DISABLED     => self::disabled(),
			AlertApiException::NO_ACCESS    => self::no_access(),
			AlertApiException::INVALID_NAME => self::error( 'merchboard_invalid_request', __( 'Give the key a name of at most 60 characters.', 'merchboard' ), 400, array( 'fields' => array( 'name' => 'invalid' ) ) ),
			/* translators: %d: most keys. */
			AlertApiException::KEY_LIMIT    => self::error( 'merchboard_alerts_api_key_limit', sprintf( __( 'You can have at most %d keys. Revoke one first.', 'merchboard' ), AlertApiKeys::MAX_KEYS ), 409 ),
			default                         => self::error( 'merchboard_not_found', __( 'The key was not found.', 'merchboard' ), 404 ),
		};
	}

	/**
	 * The answer to a refused webhook change.
	 *
	 * @param WebhookException $e Reason.
	 */
	private static function webhook_refused( WebhookException $e ): WP_Error {
		$field = static fn( string $name, string $message ): WP_Error => self::error( 'merchboard_invalid_request', $message, 400, array( 'fields' => array( $name => 'invalid' ) ) );

		return match ( $e->reason() ) {
			WebhookException::DISABLED               => self::disabled(),
			WebhookException::NO_ACCESS              => self::no_access(),
			WebhookException::ENCRYPTION_UNAVAILABLE => self::error( 'merchboard_alerts_webhook_unavailable', __( 'Webhooks cannot be stored safely on this site. Ask the shop.', 'merchboard' ), 503 ),
			WebhookException::INVALID_NAME           => $field( 'name', __( 'Give the webhook a name of at most 60 characters.', 'merchboard' ) ),
			WebhookException::INVALID_FORMAT         => $field( 'format', __( 'Choose JSON or Discord.', 'merchboard' ) ),
			WebhookException::INVALID_URL            => $field( 'url', __( 'Enter a public https address. A Discord webhook must be a Discord webhook address.', 'merchboard' ) ),
			WebhookException::INVALID_SECRET         => $field( 'secret', __( 'The secret must be 16–200 characters without spaces.', 'merchboard' ) ),
			WebhookException::INVALID_TEMPLATE       => $field( 'template', __( 'The message can be at most 500 characters.', 'merchboard' ) ),
			/* translators: %d: most webhooks. */
			WebhookException::LIMIT                  => self::error( 'merchboard_alerts_webhook_limit', sprintf( __( 'You can have at most %d webhooks. Delete one first.', 'merchboard' ), AlertWebhooks::MAX_WEBHOOKS ), 409 ),
			default                                  => self::error( 'merchboard_not_found', __( 'The webhook was not found.', 'merchboard' ), 404 ),
		};
	}

	/**
	 * The answer while the API is off.
	 */
	private static function disabled(): WP_Error {
		return self::error( 'merchboard_alerts_api_disabled', __( 'The alert API is switched off. Ask the shop to switch it on.', 'merchboard' ), 403 );
	}

	/**
	 * The answer without the administrator's access (step 25.12.4).
	 */
	private static function no_access(): WP_Error {
		return self::error( 'merchboard_alerts_api_no_access', __( 'The shop has not given you access to this. Ask the shop.', 'merchboard' ), 403 );
	}

	/**
	 * A text field of the JSON body ('' when missing).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Field.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function text( WP_REST_Request $request, string $name ): string {
		$value = ( (array) $request->get_json_params() )[ $name ] ?? null;

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The id of a route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function id( WP_REST_Request $request ): int {
		$id = $request->get_param( 'id' );

		return is_string( $id ) ? (int) $id : 0;
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
