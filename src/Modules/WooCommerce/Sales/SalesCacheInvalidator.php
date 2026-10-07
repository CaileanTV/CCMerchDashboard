<?php
/**
 * Invalidation of cached sales statistics.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * Called by SalesRepository::write() after a committed write with the
 * creators of the ledger rows it inserted (ADR-040 pkt. 10). Internal: no
 * public hook (`merchboard_sale_recorded` stays deferred, ADR-037 pkt. 11).
 */
interface SalesCacheInvalidator {

	/**
	 * Makes every cached statistic of the creators unreachable.
	 *
	 * @phpstan-param list<int> $creator_ids
	 *
	 * @param int[] $creator_ids Distinct creator ids.
	 */
	public function invalidate( array $creator_ids ): void;
}
