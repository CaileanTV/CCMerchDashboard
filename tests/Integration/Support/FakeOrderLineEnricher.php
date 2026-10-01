<?php
/**
 * Order line enrichment without WooCommerce (integration tests).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\WooCommerce\Dashboard\OrderLineEnricher;
use MerchBoard\Modules\WooCommerce\Dashboard\OrderLineView;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLine;

/**
 * Stands in for WooCommerceOrderLineEnricher (tested on its own with the
 * WooCommerce stand-ins): the order id as order number, status `processing`
 * (or `deleted` for ids in $deleted), the ledger's item name. Records the
 * order item ids of every call.
 */
final class FakeOrderLineEnricher implements OrderLineEnricher {

	/**
	 * Order item ids per enrich() call.
	 *
	 * @var list<list<int>>
	 */
	public array $calls = array();

	/**
	 * Order ids reported as permanently deleted.
	 *
	 * @var list<int>
	 */
	public array $deleted = array();

	/**
	 * The page's lines with stand-in order data.
	 *
	 * @phpstan-param list<SalesOrderLine> $lines
	 * @phpstan-return list<OrderLineView>
	 *
	 * @param SalesOrderLine[] $lines Lines.
	 * @return OrderLineView[]
	 */
	public function enrich( array $lines ): array {
		$this->calls[] = array_map( static fn( SalesOrderLine $line ): int => $line->order_item_id, $lines );

		return array_map(
			fn( SalesOrderLine $line ): OrderLineView => new OrderLineView(
				$line->order_item_id,
				(string) $line->order_id,
				$line->date,
				in_array( $line->order_id, $this->deleted, true ) ? 'deleted' : 'processing',
				in_array( $line->order_id, $this->deleted, true ) ? 'Deleted' : 'Processing',
				$line->item_name,
				$line->quantity,
				$line->refunded,
				$line->counted
			),
			$lines
		);
	}
}
