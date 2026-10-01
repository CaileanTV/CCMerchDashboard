<?php
/**
 * Creator-scope REST route: /me/products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Rest;

use MerchBoard\Core\Http\Pagination;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\Dashboard\ProductDetails;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/me/products` (ADR-039, docs/API.md#paginering): the
 * creator's `publish` and `private` products, a page at a time, as a plain
 * JSON array with `X-WP-Total` and `X-WP-TotalPages`.
 *
 * The creator is always the one CreatorContext resolves for the logged-in
 * user, in the permission callback and again in the endpoint; the route
 * takes only `page` and `per_page`, and other parameters are ignored. It is
 * registered by the WooCommerce module, so without WooCommerce it does not
 * exist. No caching (ADR-039 P12).
 */
final class MeProductsController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/products';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext  $context  Creator access policy.
	 * @param CreatorProducts $products Selection of the creator's products.
	 * @param ProductDetails  $details  Product data.
	 * @param Logger          $logger   Technical log.
	 */
	public function __construct( private CreatorContext $context, private CreatorProducts $products, private ProductDetails $details, Logger $logger ) {
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
					'callback'            => array( $this, 'get_products' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => self::pagination_args(),
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
	 * `GET /me/products`: the requested page of the creator's products.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_products( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$pagination = Pagination::from_request( $request );
				$total      = $this->products->count( $creator->id );
				$items      = array();

				if ( $pagination->offset() < $total ) {
					foreach ( $this->products->ids( $creator->id, $pagination ) as $product_id ) {
						$summary = $this->details->find( $product_id );

						if ( null !== $summary ) {
							$items[] = $summary->to_array();
						}
					}
				}

				return self::paginated( $items, $total, $pagination );
			}
		);
	}

	/**
	 * JSON schema of one product in `/me/products`.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array {
		$amount = array(
			'type'     => 'string',
			'readonly' => true,
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'merchboard_product',
			'type'       => 'object',
			'properties' => array(
				'id'           => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'name'         => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'status'       => array(
					'type'     => 'string',
					'enum'     => array( 'publish', 'private' ),
					'readonly' => true,
				),
				'price'        => array(
					'type'       => array( 'object', 'null' ),
					'readonly'   => true,
					'properties' => array(
						'min'      => $amount,
						'max'      => $amount,
						'currency' => $amount,
						'tax'      => array(
							'type'     => 'string',
							'enum'     => array( 'store_setting' ),
							'readonly' => true,
						),
					),
				),
				'stock_status' => array(
					'type'     => array( 'string', 'null' ),
					'enum'     => array( 'instock', 'outofstock', 'onbackorder', null ),
					'readonly' => true,
				),
				'image'        => array(
					'type'       => array( 'object', 'null' ),
					'readonly'   => true,
					'properties' => array(
						'url' => array(
							'type'     => 'string',
							'format'   => 'uri',
							'readonly' => true,
						),
						'alt' => array(
							'type'     => 'string',
							'readonly' => true,
						),
					),
				),
			),
		);
	}
}
