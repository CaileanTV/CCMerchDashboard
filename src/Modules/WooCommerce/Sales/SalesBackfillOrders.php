<?php
/**
 * The orders a backfill looks at.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * One page of shop order ids in the given statuses, oldest id first
 * (ADR-038, status note "ROADMAP trin 7", D2).
 */
interface SalesBackfillOrders {

	/**
	 * Order ids of one page.
	 *
	 * @phpstan-param list<string> $statuses
	 *
	 * @param string[] $statuses Status slugs without `wc-`.
	 * @param int      $offset   Orders to skip.
	 * @param int      $limit    Orders per page.
	 * @return list<int>
	 * @throws \RuntimeException When the orders cannot be read (the page fails).
	 */
	public function page( array $statuses, int $offset, int $limit ): array;
}
