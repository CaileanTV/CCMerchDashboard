<?php
/**
 * Creator-scope REST routes: /me/collections.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\WooCommerce\Collections\CollectionOverview;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequestException;
use MerchBoard\Modules\WooCommerce\Collections\CollectionRequests;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 1. The creator comes only from CreatorContext.
 *
 * - `GET /merchboard/v1/me/collections`: whether requests can be sent, the
 *   products that can be chosen, the creator's collections and requests.
 * - `POST /merchboard/v1/me/collections`: `product_ids` with `name` (a new
 *   collection) or `collection_id` (an addition); 201 with the same body as
 *   GET. An administrator approves; nothing in the shop changes by it.
 */
final class MeCollectionsController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/collections';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext     $context  Creator access policy.
	 * @param CollectionRequests $requests The rules.
	 * @param CollectionOverview $overview What the creator sees.
	 * @param Logger             $logger   Technical log.
	 */
	public function __construct( private CreatorContext $context, private CollectionRequests $requests, private CollectionOverview $overview, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_collections' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'request_collection' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'name'          => array(
							'type'      => 'string',
							'maxLength' => CollectionRequests::NAME_MAX_LENGTH,
						),
						'collection_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'product_ids'   => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => CollectionRequests::MAX_DESIGNS,
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
					),
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
	 * `GET /me/collections`.
	 */
	public function get_collections(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : new WP_REST_Response( $this->overview->for_creator( $creator ) );
			}
		);
	}

	/**
	 * `POST /me/collections`.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function request_collection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				if ( ! $this->overview->available() ) {
					return self::error( 'merchboard_collections_unavailable', __( 'Collections cannot be requested right now.', 'merchboard' ), 409 );
				}

				$products   = $request->get_param( 'product_ids' );
				$ids        = array_values( array_map( 'absint', array_filter( is_array( $products ) ? $products : array(), 'is_numeric' ) ) );
				$collection = $request->get_param( 'collection_id' );
				$name       = $request->get_param( 'name' );

				try {
					if ( is_numeric( $collection ) ) {
						$this->requests->request_add( $creator, (int) $collection, $ids );
					} else {
						$this->requests->request_new( $creator, is_string( $name ) ? $name : '', $ids );
					}
				} catch ( CollectionRequestException $e ) {
					return self::error( 'merchboard_collection_refused', __( 'The request was not sent.', 'merchboard' ), 400, array( 'reason' => $e->reason() ) );
				}

				return new WP_REST_Response( $this->overview->for_creator( $creator ), 201 );
			}
		);
	}
}
