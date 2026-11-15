<?php
/**
 * The merch ad overlay's check for changes (public, the overlay id).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use MerchBoard\Modules\Community\MerchAd\MerchAdRepository;
use MerchBoard\Modules\Community\MerchAd\MerchAdView;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/merch-ad/{id}` (ADR-113 pkt. 1): the overlay asks
 * every 10 seconds whether the ad changed (`version`) or "show now" was
 * pressed (`show_at`). The secret id is the credential; at most 60 calls a
 * minute per id. 404 for an unknown id or while the shop has it off.
 */
final class MerchAdStatusController extends RestController {

	/**
	 * The route.
	 */
	public const ROUTE = '/merch-ad/(?P<id>[0-9a-f]{32})';

	/**
	 * Constructor.
	 *
	 * @param MerchAdRepository  $settings The creators' ads.
	 * @param MerchAdView        $view     What an ad shows.
	 * @param OverlayRateLimiter $limiter  Calls per id.
	 * @param Logger             $logger   Technical log.
	 */
	public function __construct( private MerchAdRepository $settings, private MerchAdView $view, private OverlayRateLimiter $limiter, Logger $logger ) {
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
					'callback'            => array( $this, 'status' ),
					// The secret overlay id is checked in status().
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * The ad's version and "show now".
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			'/merch-ad',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$id = (string) $request->get_param( 'id' );

				if ( ! $this->limiter->allow( $id ) ) {
					return self::error( 'merchboard_rate_limited', __( 'Too many requests.', 'merchboard' ), 429 );
				}

				$creator_id = Settings::merch_ad_enabled() ? $this->settings->creator_for( $id ) : null;

				if ( null === $creator_id ) {
					return self::error( 'merchboard_not_found', __( 'Not found.', 'merchboard' ), 404 );
				}

				$response = new WP_REST_Response(
					array(
						'version' => $this->view->version( $creator_id ),
						'show_at' => $this->settings->find( $creator_id )->show_at,
					),
					200
				);
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}
}
