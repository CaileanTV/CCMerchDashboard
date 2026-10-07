<?php
/**
 * Current product names for the sales statistics.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * The current names of parent products (ADR-040 pkt. 4, ADR-039 P6), behind
 * an interface so `GET /me/sales` can be tested without WooCommerce. Only
 * names: it never changes which products are shown or their sums.
 */
interface SalesProductNames {

	/**
	 * The current name of every given product that still exists, as plain
	 * text. Products that no longer exist, have no name or have id 0 are left
	 * out; the caller falls back to the ledger's item name.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[] $product_ids Distinct parent product ids.
	 * @return array<int, string> Name by product id.
	 */
	public function current( array $product_ids ): array;
}
