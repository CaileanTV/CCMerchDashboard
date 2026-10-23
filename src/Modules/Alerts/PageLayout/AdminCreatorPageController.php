<?php
/**
 * REST routes of the creator page's setup for the admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\PageLayout;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard/v1/admin/alerts/creator-page` (step 25.9.6), only with
 * `merchboard_manage_alerts`: `GET` the setup, `POST` saves one (normalized,
 * CreatorPageLayout::from_array()), `DELETE` goes back to the default. Every
 * answer is the complete setup.
 */
final class AdminCreatorPageController extends RestController {

	/**
	 * The route.
	 */
	public const ROUTE = '/admin/alerts/creator-page';

	/**
	 * Constructor.
	 *
	 * @param CreatorPageLayoutStore $store  The setup.
	 * @param Logger                 $logger Technical log.
	 */
	public function __construct( private CreatorPageLayoutStore $store, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		$one = fn( string $method, string $callback ): array => array(
			'methods'             => $method,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'permission' ),
			'args'                => array(),
		);

		register_rest_route( self::NAMESPACE, self::ROUTE, array( $one( WP_REST_Server::READABLE, 'get_layout' ), $one( WP_REST_Server::CREATABLE, 'save_layout' ), $one( WP_REST_Server::DELETABLE, 'reset_layout' ) ) );
	}

	/**
	 * Only users who manage alerts.
	 */
	public function permission(): bool|WP_Error {
		return $this->require_capability( Capabilities::MANAGE_ALERTS );
	}

	/**
	 * The setup.
	 */
	public function get_layout(): WP_REST_Response|WP_Error {
		return $this->run( self::ROUTE, fn(): WP_REST_Response => self::answer( $this->store->get() ) );
	}

	/**
	 * Saves a setup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function save_layout( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run( self::ROUTE, fn(): WP_REST_Response => self::answer( $this->store->save( $request->get_json_params() ) ) );
	}

	/**
	 * Goes back to the default setup.
	 */
	public function reset_layout(): WP_REST_Response|WP_Error {
		return $this->run( self::ROUTE, fn(): WP_REST_Response => self::answer( $this->store->reset() ) );
	}

	/**
	 * The answer of every route.
	 *
	 * @param CreatorPageLayout $layout The setup.
	 */
	private static function answer( CreatorPageLayout $layout ): WP_REST_Response {
		$response = new WP_REST_Response( $layout->to_array(), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
