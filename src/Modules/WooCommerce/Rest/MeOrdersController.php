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
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfits;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitSummary;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLine;
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
 *
 * Profit (ADR-050): `profit` is the line's realized profit (ADR-049) over all
 * its ledger rows (sale, refunds, reversals and corrections with their
 * signs); `order_profit` sums every line of the creator in the same order
 * (also lines on other pages), and `order_lines` counts them. Both come from
 * one aggregation query per page (CreatorProfits) on the ledger's order and
 * order item ids. An unknown or incomplete profit has no amount.
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
	 * @param CreatorProfits    $profits  Realized profit of the creator's ledger rows.
	 */
	public function __construct( private CreatorContext $context, private SalesOrderLines $lines, private OrderLineEnricher $enricher, Logger $logger, private CreatorProfits $profits ) {
		parent::__construct( $logger );
	}

	/**
	 * The creator's profit and number of lines per order, from the lines.
	 *
	 * @param array<int, array{order_id: int, profit: ProfitSummary}> $lines Profit by order item id.
	 * @return array<int, array{profit: ProfitSummary, lines: int}> By order id.
	 */
	private static function orders( array $lines ): array {
		$orders = array();

		foreach ( $lines as $line ) {
			$id = $line['order_id'];

			$orders[ $id ] = isset( $orders[ $id ] )
				? array(
					'profit' => $orders[ $id ]['profit']->plus( $line['profit'] ),
					'lines'  => $orders[ $id ]['lines'] + 1,
				)
				: array(
					'profit' => $line['profit'],
					'lines'  => 1,
				);
		}

		return $orders;
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
					$page    = $this->lines->page( $creator->id, $pagination );
					$profits = $this->profits->by_order_line( $creator->id, array_values( array_unique( array_map( static fn( SalesOrderLine $line ): int => $line->order_id, $page ) ) ) );
					$orders  = self::orders( $profits );
					$order   = array();

					foreach ( $page as $line ) {
						$order[ $line->order_item_id ] = $line->order_id;
					}

					$items = array_map(
						static function ( OrderLineView $view ) use ( $profits, $orders, $order ): array {
							$order_id = $order[ $view->line_id ] ?? 0;

							return $view->to_array() + array(
								'profit'       => isset( $profits[ $view->line_id ] ) ? $profits[ $view->line_id ]['profit']->to_array() : null,
								'order_profit' => isset( $orders[ $order_id ] ) ? $orders[ $order_id ]['profit']->to_array() : null,
								'order_lines'  => $orders[ $order_id ]['lines'] ?? 0,
							);
						},
						$this->enricher->enrich( $page )
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
				'profit'       => array( 'type' => array( 'object', 'null' ) ) + ProfitSummary::schema(),
				'order_profit' => array( 'type' => array( 'object', 'null' ) ) + ProfitSummary::schema(),
				'order_lines'  => array(
					'type'     => 'integer',
					'readonly' => true,
				),
			),
		);
	}
}
