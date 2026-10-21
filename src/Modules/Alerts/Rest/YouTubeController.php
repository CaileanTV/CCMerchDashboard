<?php
/**
 * REST: Google's redirect for the YouTube chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeOAuth;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /alerts/youtube/callback` (ADR-070): Google's redirect after the bot
 * or a creator authorized the app. Public, because Google cannot log in; the
 * single-use state (YouTubeOAuth) is the proof. The browser is then sent to
 * the admin page (bot) or the dashboard (channel) with a 302. YouTube has no
 * chat webhooks, so this is the only route Google calls.
 */
final class YouTubeController extends RestController {

	private const CALLBACK = '/alerts/youtube/callback';

	/**
	 * Constructor.
	 *
	 * @param YouTubeOAuth $oauth  Authorizations.
	 * @param Logger       $logger Technical log.
	 */
	public function __construct( private YouTubeOAuth $oauth, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::CALLBACK,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'callback' ),
					'permission_callback' => '__return_true',
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * `GET /alerts/youtube/callback`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function callback( WP_REST_Request $request ): WP_REST_Response {
		$text     = static fn( string $name ): string => is_string( $request->get_param( $name ) ) ? sanitize_text_field( (string) $request->get_param( $name ) ) : '';
		$location = $this->oauth->finish( $text( 'state' ), $text( 'code' ), $text( 'error' ) );
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', wp_validate_redirect( $location, home_url( '/' ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}
}
