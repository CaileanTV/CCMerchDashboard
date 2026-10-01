<?php
/**
 * Creator-scope REST route: /me/orders.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Rest;

use MerchBoard\Core\Http\Pagination;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\WooCommerce\Dashboard\OrderLineEnricher;
use MerchBoard\Modules\WooCommerce\Dashboard\OrderLineView;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLines;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/me/orders` (ADR-039, docs/API.md#paginering): the
 * creator's order lines, a page at a time, as a plain JSON array with
 * `X-WP-Total` and `X-WP-TotalPages`.
 *
 * Flow: CreatorContext gives the creator (permission callback and again in
 * the endpoint); SalesOrderLines selects, filters by creator, aggregates and
 * paginates from the sales ledger alone; OrderLineEnricher adds order number,
 * status and product name for that page only. The route takes only `page` and
 * `per_page`; other parameters are ignored. Registered by the WooCommerce
 * module, so without WooCommerce it does not exist. No caching (P12).
 */
final class MeOrdersController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/orders';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext    $context  Creator access policy.
	 * @param SalesOrderLines   $lines    The creator's order lines from the ledger.
	 * @param OrderLineEnricher $enricher Order and product data of a page.
	 * @param Logger            $logger   Technical log.
	 */
	public function __construct( private CreatorContext $context, private SalesOrderLines $lines, private OrderLineEnricher $enricher, Logger $logger ) {
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
					'callback'            => array( $this, 'get_orders' ),
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
	 * `GET /me/orders`: the requested page of the creator's order lines.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_orders( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$pagination = Pagination::from_request( $request );
				$total      = $this->lines->count( $creator->id );
				$items      = array();

				if ( $pagination->offset() < $total ) {
					$items = array_map(
						static fn( OrderLineView $view ): array => $view->to_array(),
						$this->enricher->enrich( $this->lines->page( $creator->id, $pagination ) )
					);
				}

				return self::paginated( $items, $total, $pagination );
			}
		);
	}

	/**
	 * JSON schema of one order line in `/me/orders`.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array {
		$text = array(
			'type'     => 'string',
			'readonly' => true,
		);
		$flag = array(
			'type'     => 'boolean',
			'readonly' => true,
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'merchboard_order_line',
			'type'       => 'object',
			'properties' => array(
				'line_id'      => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'order_number' => $text,
				'date'         => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
				'status'       => $text,
				'status_label' => $text,
				'product_name' => $text,
				'quantity'     => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'refunded'     => $flag,
				'counted'      => $flag,
			),
		);
	}
}
