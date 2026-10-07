<?php
/**
 * The creator's sales report.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;

defined( 'ABSPATH' ) || exit;

/**
 * The response body of `GET /me/sales` (ADR-040 pkt. 11): SalesReport
 * computes it from the ledger, CachedSalesReports adds the object cache.
 */
interface SalesReports {

	/**
	 * The report of a creator for a query.
	 *
	 * @param int        $creator_id Creator id (from CreatorContext).
	 * @param SalesQuery $query      Validated period.
	 * @return array<string, mixed> `from`, `to`, `interval`, `timezone` and `currencies` (docs/API.md).
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function build( int $creator_id, SalesQuery $query ): array;
}
