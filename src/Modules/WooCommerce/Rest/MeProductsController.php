<?php
/**
 * Creator-scope REST routes: /me/products and its removal requests.
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
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfits;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitSummary;
use WP_Error;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalException;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalRepository;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovals;
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
 *
 * Each product carries `profit` (ADR-050): the creator's realized profit
 * (ADR-049) from the ledger rows of the product and its variations, all
 * time, per currency, or null when the creator has no ledger rows for it.
 * One aggregation query per page (CreatorProfits), never one per product.
 * It also carries `designer` (a designer row names it) and `removal_pending`.
 *
 * `POST /merchboard/v1/me/products/{id}/removal-request` (ADR-083): asks to
 * have one of the creator's legacy products removed, and answers
 * `{ "removal_pending": true }`. An administrator handles it by hand; the
 * product is not touched. Asking again changes nothing. Another creator's
 * product is 404, a designer product 400 with `data.reason`.
 */
final class MeProductsController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/products';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext                $context  Creator access policy.
	 * @param CreatorProducts               $products Selection of the creator's products.
	 * @param ProductDetails                $details  Product data.
	 * @param Logger                        $logger   Technical log.
	 * @param CreatorProfits                $profits  Realized profit of the creator's ledger rows.
	 * @param ProductRemovalRepository|null $requests Removal requests (null: no such data).
	 * @param ProductRemovals|null          $removals Takes removal requests (null: no such route).
	 */
	public function __construct( private CreatorContext $context, private CreatorProducts $products, private ProductDetails $details, Logger $logger, private CreatorProfits $profits, private ?ProductRemovalRepository $requests = null, private ?ProductRemovals $removals = null ) {
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

		if ( null !== $this->removals ) {
			register_rest_route(
				self::NAMESPACE,
				self::ROUTE . '/(?P<id>\d+)/removal-request',
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'request_removal' ),
						'permission_callback' => array( $this, 'permission' ),
						'args'                => array(),
					),
				)
			);
		}
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
					$ids      = $this->products->ids( $creator->id, $pagination );
					$profits  = $this->profits->by_product( $creator->id, $ids );
					$designer = null === $this->requests ? array() : $this->requests->designer_among( $ids );
					$pending  = null === $this->requests ? array() : $this->requests->pending_among( $ids );

					foreach ( $ids as $product_id ) {
						$summary = $this->details->find( $product_id );

						if ( null !== $summary ) {
							$items[] = $summary->to_array() + array(
								'profit'          => isset( $profits[ $product_id ] ) ? array_map( static fn( ProfitSummary $profit ): array => $profit->to_array(), $profits[ $product_id ] ) : null,
								'designer'        => in_array( $product_id, $designer, true ),
								'removal_pending' => in_array( $product_id, $pending, true ),
							);
						}
					}
				}

				return self::paginated( $items, $total, $pagination );
			}
		);
	}

	/**
	 * `POST /me/products/{id}/removal-request`: asks to have a legacy product removed.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function request_removal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/removal-request',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				if ( null === $this->removals ) {
					return self::error( 'merchboard_not_found', __( 'Not found.', 'merchboard' ), 404 );
				}

				try {
					$this->removals->request( $creator, absint( $request->get_param( 'id' ) ) );
				} catch ( ProductRemovalException $e ) {
					return ProductRemovalException::NOT_FOUND === $e->reason()
						? self::error( 'merchboard_not_found', __( 'Not found.', 'merchboard' ), 404 )
						: self::error( 'merchboard_removal_refused', __( 'The request was not sent.', 'merchboard' ), 400, array( 'reason' => $e->reason() ) );
				}

				return new WP_REST_Response( array( 'removal_pending' => true ) );
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
				'id'              => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'name'            => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'status'          => array(
					'type'     => 'string',
					'enum'     => array( 'publish', 'private' ),
					'readonly' => true,
				),
				'price'           => array(
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
				'stock_status'    => array(
					'type'     => array( 'string', 'null' ),
					'enum'     => array( 'instock', 'outofstock', 'onbackorder', null ),
					'readonly' => true,
				),
				'profit'          => array(
					'type'     => array( 'array', 'null' ),
					'readonly' => true,
					'items'    => ProfitSummary::schema(),
				),
				'designer'        => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'removal_pending' => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'image'           => array(
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
