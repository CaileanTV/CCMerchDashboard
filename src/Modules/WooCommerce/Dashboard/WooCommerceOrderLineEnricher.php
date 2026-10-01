<?php
/**
 * WooCommerce implementation of the order line enrichment.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLine;
use WC_Order;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Enriches one page of ledger order lines through WooCommerce CRUD
 * (`wc_get_order()`, `wc_get_product()`), never with SQL, so it works the same
 * with HPOS and legacy order storage (ADR-039 P4, P6):
 * - Order (looked up once per order id in this call): an existing order shows
 *   its WooCommerce status, status label and order number; an order in the
 *   trash shows `trashed`/"Trashed" with its order number; an order that no
 *   longer exists shows `deleted`/"Deleted" with the order id as its number.
 *   Lines of deleted orders are kept (the ledger cannot tell them apart).
 * - Product name (looked up once per product id): the variation's current
 *   name when the line has a variation, otherwise the product's; when that
 *   product or variation no longer exists, the ledger's `item_name` snapshot.
 *   A missing variation never falls back to its parent.
 * Nothing else is read from the order: no customer data, no amounts.
 */
final class WooCommerceOrderLineEnricher implements OrderLineEnricher {

	/**
	 * The page's lines with order and product data, in the same order.
	 *
	 * @phpstan-param list<SalesOrderLine> $lines
	 * @phpstan-return list<OrderLineView>
	 *
	 * @param SalesOrderLine[] $lines One page of the creator's order lines.
	 * @return OrderLineView[]
	 */
	public function enrich( array $lines ): array {
		$orders   = array();
		$products = array();
		$views    = array();

		foreach ( $lines as $line ) {
			if ( ! array_key_exists( $line->order_id, $orders ) ) {
				$orders[ $line->order_id ] = self::order( $line->order_id );
			}

			$product_id = $line->variation_id > 0 ? $line->variation_id : $line->product_id;

			if ( ! array_key_exists( $product_id, $products ) ) {
				$products[ $product_id ] = self::product_name( $product_id );
			}

			[ $number, $status, $label ] = $orders[ $line->order_id ];

			$views[] = new OrderLineView(
				$line->order_item_id,
				$number,
				$line->date,
				$status,
				$label,
				$products[ $product_id ] ?? PlainText::of( $line->item_name ),
				$line->quantity,
				$line->refunded,
				$line->counted
			);
		}

		return $views;
	}

	/**
	 * Order number, status and status label of an order.
	 *
	 * @param int $order_id Order id.
	 * @return array{string, string, string}
	 */
	private static function order( int $order_id ): array {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return array( (string) $order_id, 'deleted', __( 'Deleted', 'merchboard' ) );
		}

		$number = PlainText::of( (string) $order->get_order_number() );
		$number = '' === $number ? (string) $order_id : $number;
		$status = $order->get_status( 'edit' );
		$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		if ( 'trash' === $status ) {
			return array( $number, 'trashed', __( 'Trashed', 'merchboard' ) );
		}

		return array( $number, $status, PlainText::of( wc_get_order_status_name( $status ) ) );
	}

	/**
	 * The current name of a product or variation, or null when it no longer
	 * exists.
	 *
	 * @param int $product_id Product or variation id.
	 */
	private static function product_name( int $product_id ): ?string {
		$product = $product_id > 0 ? wc_get_product( $product_id ) : false;

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$name = PlainText::of( $product->get_name() );

		return '' === $name ? null : $name;
	}
}
