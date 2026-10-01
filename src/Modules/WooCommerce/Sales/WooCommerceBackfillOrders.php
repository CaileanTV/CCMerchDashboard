<?php
/**
 * Backfill order pages through WooCommerce CRUD.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Pages of shop order ids via `wc_get_orders()` (ADR-038, status note
 * "ROADMAP trin 7", D2; WOOCOMMERCE.md: CRUD only, never direct SQL against
 * order storage). Works the same with HPOS and legacy storage: `orderby` ID
 * ascending and `limit`/`offset` are supported by both data stores, while an
 * "id greater than" query is not (`field_query` is HPOS-only). The statuses
 * are passed with the `wc-` prefix, so a counted status that is no longer
 * registered still matches in both stores; `trash` is never included.
 */
final class WooCommerceBackfillOrders implements SalesBackfillOrders {

	/**
	 * Order ids of one page.
	 *
	 * @phpstan-param list<string> $statuses
	 *
	 * @param string[] $statuses Status slugs without `wc-`.
	 * @param int      $offset   Orders to skip.
	 * @param int      $limit    Orders per page.
	 * @return list<int>
	 * @throws RuntimeException When WooCommerce is not loaded or the query does not return ids.
	 */
	public function page( array $statuses, int $offset, int $limit ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			throw new RuntimeException( 'WooCommerce is not loaded.' );
		}

		$statuses = array_values( array_filter( $statuses, static fn( string $status ): bool => 'trash' !== $status ) );

		if ( array() === $statuses || $limit < 1 ) {
			return array();
		}

		$ids = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'status'   => array_map( static fn( string $status ): string => 'wc-' . $status, $statuses ),
				'return'   => 'ids',
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'limit'    => $limit,
				'offset'   => max( 0, $offset ),
				'paginate' => false,
			)
		);

		if ( ! is_array( $ids ) ) {
			throw new RuntimeException( 'The order query did not return ids.' );
		}

		$page = array();

		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$page[] = (int) $id;
			}
		}

		return $page;
	}
}
