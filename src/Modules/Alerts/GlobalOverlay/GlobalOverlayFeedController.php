<?php
/**
 * REST route of the global alert overlay's feed.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET merchboard/v1/alerts/global-overlay/{id}/feed` (step 25.9.4): public,
 * the secret id in the path is the credential, as for a creator's overlay
 * (OverlayFeedController), but on its own route, so a creator's id never
 * opens the global overlay and the global id never opens a creator's. Only
 * an active global overlay is rate limited, so guessing ids writes nothing.
 */
final class GlobalOverlayFeedController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param GlobalOverlayFeed  $feed    The feed.
	 * @param OverlayRateLimiter $limiter Rate limit per overlay id.
	 * @param Logger             $logger  Technical log.
	 */
	public function __construct( private GlobalOverlayFeed $feed, private OverlayRateLimiter $limiter, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/alerts/global-overlay/(?P<overlay_id>[0-9a-f]{32})/feed',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_feed' ),
					// Public by design: the secret overlay id in the path is the credential.
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
			'/alerts/global-overlay/{id}/feed',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$overlay_id = (string) $request->get_param( 'overlay_id' );

				if ( null === $this->feed->active( $overlay_id ) ) {
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
	 * The answer for an unknown, inactive or regenerated id.
	 */
	private static function not_found(): WP_Error {
		return self::error( 'merchboard_overlay_not_found', __( 'This overlay does not exist.', 'merchboard' ), 404 );
	}
}
