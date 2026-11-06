<?php
/**
 * The creator's request to go live.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\GoLive\GoLiveException;
use MerchBoard\Modules\Creators\GoLive\GoLiveRequests;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/me/go-live` (ADR-091):
 * `{ live, agreement, request: { status, note, requested_at } | null }`;
 * `note` is the administrator's note of a declined request.
 *
 * `POST /merchboard/v1/me/go-live` asks to go live and answers like `GET`;
 * 400 `merchboard_go_live_refused` with `data.reason` (`already_live`,
 * `request_pending`, `agreement_required`).
 *
 * The creator is the one CreatorContext resolves (ADR-007).
 */
final class MeGoLiveController extends RestController {

	public const ROUTE = '/me/go-live';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext $context  Creator access policy.
	 * @param GoLiveRequests $requests Requests to go live.
	 * @param Logger         $logger   Technical log.
	 */
	public function __construct( private CreatorContext $context, private GoLiveRequests $requests, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ask' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Permission callback: an active creator for the current user.
	 *
	 * @return true|WP_Error
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/go-live`.
	 */
	public function show(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$status  = $this->requests->status( $creator );
				$request = $status['request'];

				$response = new WP_REST_Response(
					array(
						'live'      => $status['live'],
						'agreement' => $status['agreement'],
						'request'   => null === $request ? null : array(
							'status'       => $request['status'],
							'note'         => $request['note'],
							'requested_at' => mysql_to_rfc3339( $request['requested_at'] ),
						),
					)
				);
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}

	/**
	 * `POST /me/go-live`.
	 */
	public function ask(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				try {
					$user = get_current_user_id();
					$this->requests->request( $creator, $user > 0 ? $user : null );
				} catch ( GoLiveException $e ) {
					return self::error( 'merchboard_go_live_refused', __( 'The request to go live was not sent.', 'merchboard' ), 400, array( 'reason' => $e->reason() ) );
				}

				return $this->show();
			}
		);
	}
}
