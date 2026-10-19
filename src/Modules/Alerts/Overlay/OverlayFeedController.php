<?php
/**
 * REST route of an overlay's public feed.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET merchboard/v1/alerts/overlay/{overlay_id}/feed` (ADR-065 pkt. 4).
 *
 * The one public route of the plugin: OBS cannot log in, so the secret
 * overlay id in the path is the credential (128 bits, compared with
 * `hash_equals()`), like the overlay page itself. It returns only what the
 * overlay shows anyway (alert texts with the customer's voluntary username,
 * product images), never ids of customers or orders. Requests are limited
 * per overlay id (429), an unknown id or an inactive creator gives 404
 * without saying which, and answers are never cached.
 */
final class OverlayFeedController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param OverlayFeed        $feed    Feed.
	 * @param OverlayRateLimiter $limiter Request limit.
	 * @param Logger             $logger  Technical log.
	 */
	public function __construct( private OverlayFeed $feed, private OverlayRateLimiter $limiter, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/alerts/overlay/(?P<overlay_id>[0-9a-f]{32})/feed',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_feed' ),
					// Public by design: the secret overlay id in the path is the credential (see the class comment).
					'permission_callback' => '__return_true',
					'args'                => array(
						'after' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'wait'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * The feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_feed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			'/alerts/overlay/{id}/feed',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$overlay_id = (string) $request->get_param( 'overlay_id' );

				// Only existing overlays are counted, so guessing ids writes nothing.
				if ( ! OverlayId::valid( $overlay_id ) || null === $this->feed->active_overlay( $overlay_id ) ) {
					return self::not_found();
				}

				if ( ! $this->limiter->allow( $overlay_id ) ) {
					return self::error( 'merchboard_too_many_requests', __( 'Too many requests. Please try again later.', 'merchboard' ), 429 );
				}

				$after  = $request->get_param( 'after' );
				$answer = $this->feed->read( $overlay_id, is_numeric( $after ) ? (int) $after : null, true === $request->get_param( 'wait' ) );

				if ( null === $answer ) {
					return self::not_found();
				}

				$response = new WP_REST_Response( $answer, 200 );
				$response->header( 'Cache-Control', 'no-store, max-age=0' );
				$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

				return $response;
			}
		);
	}

	/**
	 * The answer for an unknown overlay.
	 */
	private static function not_found(): WP_Error {
		return self::error( 'merchboard_overlay_not_found', __( 'This overlay does not exist.', 'merchboard' ), 404 );
	}
}
