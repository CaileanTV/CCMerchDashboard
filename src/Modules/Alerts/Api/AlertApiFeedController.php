<?php
/**
 * REST route of the alert API for the creators' own bots.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Api;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET merchboard/v1/alerts/api/events?after={id}&limit={n}` (ADR-073).
 *
 * Public by design, like the overlay's feed: a bot cannot log in to
 * WordPress, so the creator's API key is the credential. It is read only
 * from the header `Authorization: Bearer mbk_…` (or `X-MerchBoard-Key` on
 * hosts that drop the Authorization header), never from the URL, so it does
 * not end up in access logs. Order of checks: the API is on and alerts are
 * sent (503) → a working key of an active creator (401, the same answer for
 * every reason) → at most LIMIT requests per key per minute (429). Answers
 * are never cached.
 */
final class AlertApiFeedController extends RestController {

	/**
	 * Requests per key per minute.
	 */
	public const LIMIT = 60;

	/**
	 * The fallback header.
	 */
	public const KEY_HEADER = 'x-merchboard-key';

	/**
	 * Constructor.
	 *
	 * @param AlertApiFeed       $feed    Feed.
	 * @param AlertApiKeys       $keys    Keys.
	 * @param OverlayRateLimiter $limiter Request limit (its own prefix).
	 * @param Logger             $logger  Technical log.
	 */
	public function __construct( private AlertApiFeed $feed, private AlertApiKeys $keys, private OverlayRateLimiter $limiter, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/alerts/api/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					// Public by design: the creator's API key in the header is the credential (see the class comment).
					'permission_callback' => '__return_true',
					'args'                => array(
						'after' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => AlertApiFeed::MAX_LIMIT,
							'default' => AlertApiFeed::LIMIT,
						),
					),
				),
			)
		);
	}

	/**
	 * The events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_events( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			'/alerts/api/events',
			function () use ( $request ): WP_REST_Response|WP_Error {
				if ( ! $this->feed->available() ) {
					return self::error( 'merchboard_alerts_api_unavailable', __( 'The alert API is switched off.', 'merchboard' ), 503 );
				}

				$header = (string) $request->get_header( 'authorization' );
				$header = '' !== $header ? $header : 'Bearer ' . (string) $request->get_header( self::KEY_HEADER );
				$key    = $this->keys->authenticate( $header );

				if ( null === $key || ! $this->feed->creator_active( $key ) ) {
					return self::error( 'merchboard_unauthorized', __( 'The API key is missing, wrong or revoked.', 'merchboard' ), 401 );
				}

				if ( ! $this->limiter->allow( 'key:' . $key->id ) ) {
					return self::error( 'merchboard_too_many_requests', __( 'Too many requests. Please try again later.', 'merchboard' ), 429 );
				}

				$after  = $request->get_param( 'after' );
				$limit  = $request->get_param( 'limit' );
				$answer = $this->feed->read( $key, is_numeric( $after ) ? (int) $after : null, is_numeric( $limit ) ? (int) $limit : AlertApiFeed::LIMIT );

				$response = new WP_REST_Response( $answer, 200 );
				$response->header( 'Cache-Control', 'no-store, max-age=0' );
				$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

				return $response;
			}
		);
	}
}
