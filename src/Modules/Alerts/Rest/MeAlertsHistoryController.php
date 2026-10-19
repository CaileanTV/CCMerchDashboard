<?php
/**
 * REST: the creator's alert history.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Http\Pagination;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\History\AlertHistory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me/alerts/history` (phase 25, step 7). The creator is always the one
 * CreatorContext resolves (ADR-007); ids in routes only ever select among
 * the creator's own alerts.
 *
 * - `GET /me/alerts/history`: orders with alerts, newest first, paginated
 *   (array body, `X-WP-Total`, `X-WP-TotalPages`; 10 per page by default).
 * - `POST /me/alerts/history/alerts/{id}/resend` and
 *   `POST /me/alerts/history/orders/{id}/resend`: `{ "sent": n }`; 404 when
 *   the alert or order is not the creator's or before the overlay is set up.
 * - `POST /me/alerts/stop`: "Stop all alerts", `{ "stopped": true }`.
 * - `GET`/`PATCH /me/alerts/preferences`: `{ "anonymize": bool }`, whether
 *   the history shortens usernames: a personal UI preference of the
 *   logged-in user (user meta ANONYMIZE_META, like the dashboard theme,
 *   ADR-044; the dashboard keeps nothing in browser storage).
 */
final class MeAlertsHistoryController extends RestController {

	private const ROUTE = '/me/alerts/history';

	/**
	 * User meta: "Anonymize usernames" in the history ('1'; absent when off).
	 * Removed by the opt-in uninstall (Core\Lifecycle\Uninstaller::USER_META_KEYS).
	 */
	public const ANONYMIZE_META = 'merchboard_alerts_anonymize';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext $context Creator access.
	 * @param AlertHistory   $history History.
	 * @param Logger         $logger  Technical log.
	 */
	public function __construct( private CreatorContext $context, private AlertHistory $history, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$args = self::pagination_args();

		$args['per_page']['default'] = AlertHistory::PER_PAGE;

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_history' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => $args,
				),
			)
		);

		foreach ( array( 'alerts', 'orders' ) as $kind ) {
			register_rest_route(
				self::NAMESPACE,
				self::ROUTE . '/' . $kind . '/(?P<id>[1-9][0-9]{0,18})/resend',
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'alerts' === $kind ? 'resend_alert' : 'resend_order' ),
						'permission_callback' => array( $this, 'permission' ),
						'args'                => array(),
					),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/me/alerts/preferences',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_preferences' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_preferences' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/me/alerts/stop',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'stop' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Permission: an active creator (CreatorContext).
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/alerts/history`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_history( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE,
			function ( int $creator_id ) use ( $request ): WP_REST_Response {
				$pagination = Pagination::from_request( $request );
				$total      = $this->history->count( $creator_id );
				$orders     = $pagination->offset() < $total ? $this->history->page( $creator_id, $pagination ) : array();
				$response   = self::paginated( $orders, $total, $pagination );
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}

	/**
	 * `POST /me/alerts/history/alerts/{id}/resend`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function resend_alert( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/alerts/resend',
			fn( int $creator_id ): WP_REST_Response|WP_Error => self::sent( $this->history->resend_alert( $creator_id, self::id( $request ) ) )
		);
	}

	/**
	 * `POST /me/alerts/history/orders/{id}/resend`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function resend_order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/orders/resend',
			fn( int $creator_id ): WP_REST_Response|WP_Error => self::sent( $this->history->resend_order( $creator_id, self::id( $request ) ) )
		);
	}

	/**
	 * `POST /me/alerts/stop`.
	 */
	public function stop(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			'/me/alerts/stop',
			fn( int $creator_id ): WP_REST_Response|WP_Error => $this->history->stop( $creator_id ) ? new WP_REST_Response( array( 'stopped' => true ), 200 ) : self::not_set_up()
		);
	}

	/**
	 * `GET /me/alerts/preferences`: the logged-in user's history preferences.
	 */
	public function get_preferences(): WP_REST_Response|WP_Error {
		return $this->with_creator( '/me/alerts/preferences', fn(): WP_REST_Response => self::preferences() );
	}

	/**
	 * `PATCH /me/alerts/preferences` with JSON `{ "anonymize": bool }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_preferences( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			'/me/alerts/preferences',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$anonymize = ( (array) $request->get_json_params() )['anonymize'] ?? null;

				if ( ! is_bool( $anonymize ) ) {
					return self::error( 'merchboard_invalid_request', __( 'The preference is not valid.', 'merchboard' ), 400, array( 'fields' => array( 'anonymize' => 'invalid' ) ) );
				}

				// Only ever the logged-in user (ADR-007).
				if ( $anonymize ) {
					update_user_meta( get_current_user_id(), self::ANONYMIZE_META, '1' );
				} else {
					delete_user_meta( get_current_user_id(), self::ANONYMIZE_META );
				}

				return self::preferences();
			}
		);
	}

	/**
	 * The logged-in user's history preferences.
	 */
	private static function preferences(): WP_REST_Response {
		$response = new WP_REST_Response( array( 'anonymize' => '1' === get_user_meta( get_current_user_id(), self::ANONYMIZE_META, true ) ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The answer of a resend.
	 *
	 * @param int|null $sent Alerts sent; null before the overlay is set up.
	 */
	private static function sent( ?int $sent ): WP_REST_Response|WP_Error {
		if ( null === $sent ) {
			return self::not_set_up();
		}

		if ( 0 === $sent ) {
			return self::error( 'merchboard_not_found', __( 'The alert was not found.', 'merchboard' ), 404 );
		}

		return new WP_REST_Response( array( 'sent' => $sent ), 200 );
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

	/**
	 * The answer before the overlay is set up.
	 */
	private static function not_set_up(): WP_Error {
		return self::error( 'merchboard_not_found', __( 'Set up your overlay first.', 'merchboard' ), 404 );
	}
}
