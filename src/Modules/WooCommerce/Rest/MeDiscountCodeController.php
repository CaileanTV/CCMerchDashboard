<?php
/**
 * Creator-scope REST route: /me/discount-code.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/me/discount-code` (ADR-068): the creator's own active
 * discount code, `{ "code": "…" }`, or `{ "code": null }` when there is none.
 * The dashboard asks for it only when the creator chooses to show it, so the
 * code is not part of any other response or of the page.
 *
 * The creator is always the one CreatorContext resolves for the logged-in
 * user (ADR-007); the route takes no parameters. The response is never
 * cached by the browser. Registered by the WooCommerce module.
 */
final class MeDiscountCodeController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/discount-code';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext        $context Creator access policy.
	 * @param CreatorCodeRepository $codes   Registrations.
	 * @param Logger                $logger  Technical log.
	 */
	public function __construct( private CreatorContext $context, private CreatorCodeRepository $codes, Logger $logger ) {
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
					'callback'            => array( $this, 'get_code' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				'schema' => array( $this, 'schema' ),
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
	 * `GET /me/discount-code`: the creator's active code, or null.
	 */
	public function get_code(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$active   = $this->codes->active_for_creator( $creator->id );
				$response = new WP_REST_Response( array( 'code' => null === $active ? null : $active->code ) );
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}

	/**
	 * JSON schema of the response.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'merchboard_discount_code',
			'type'       => 'object',
			'properties' => array(
				'code' => array(
					'type'     => array( 'string', 'null' ),
					'readonly' => true,
				),
			),
		);
	}
}
