<?php
/**
 * Backfill order pages for tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillOrders;
use RuntimeException;

/**
 * Order ids by status, paged oldest id first like `wc_get_orders()` with an
 * offset; records every page request and can fail a request.
 */
final class FakeBackfillOrders implements SalesBackfillOrders {

	/**
	 * Status (without `wc-`) per order id.
	 *
	 * @var array<int, string>
	 */
	public array $orders = array();

	/**
	 * Page requests: [statuses, offset, limit].
	 *
	 * @var list<array{list<string>, int, int}>
	 */
	public array $requests = array();

	/**
	 * Number of the page request (1-based) that throws, if any.
	 *
	 * @var int|null
	 */
	public ?int $fail_on = null;

	/**
	 * Order ids of one page.
	 *
	 * @phpstan-param list<string> $statuses
	 *
	 * @param string[] $statuses Status slugs.
	 * @param int      $offset   Orders to skip.
	 * @param int      $limit    Orders per page.
	 * @return list<int>
	 * @throws RuntimeException On the configured request.
	 */
	public function page( array $statuses, int $offset, int $limit ): array {
		$this->requests[] = array( $statuses, $offset, $limit );

		if ( count( $this->requests ) === $this->fail_on ) {
			throw new RuntimeException( 'Orders could not be read.' );
		}

		$ids = array_keys( array_filter( $this->orders, static fn( string $status ): bool => in_array( $status, $statuses, true ) ) );
		sort( $ids );

		return array_slice( $ids, $offset, $limit );
	}
}
