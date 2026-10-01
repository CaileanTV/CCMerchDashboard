<?php
/**
 * Order lines with their current WooCommerce data.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLine;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one page of ledger order lines (SalesOrderLines) into what
 * `GET /me/orders` shows, behind an interface so the endpoint can be tested
 * without WooCommerce. It never adds, removes or reorders lines: selection,
 * creator filtering and pagination belong to the read model.
 */
interface OrderLineEnricher {

	/**
	 * The page's lines, in the same order.
	 *
	 * @phpstan-param list<SalesOrderLine> $lines
	 * @phpstan-return list<OrderLineView>
	 *
	 * @param SalesOrderLine[] $lines One page of the creator's order lines.
	 * @return OrderLineView[]
	 */
	public function enrich( array $lines ): array;
}
