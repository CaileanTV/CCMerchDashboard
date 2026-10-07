<?php
/**
 * Sales cache invalidation that records its calls (integration tests).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Sales\SalesCacheInvalidator;

/**
 * Records the creator ids of every invalidate() call and passes them on to
 * the real SalesCache, so the generations change as in production.
 */
final class RecordingSalesCacheInvalidator implements SalesCacheInvalidator {

	/**
	 * Creator ids per call.
	 *
	 * @var list<list<int>>
	 */
	public array $calls = array();

	/**
	 * Constructor.
	 *
	 * @param SalesCache $cache The real cache.
	 */
	public function __construct( private SalesCache $cache = new SalesCache() ) {
	}

	/**
	 * Records and invalidates.
	 *
	 * @phpstan-param list<int> $creator_ids
	 *
	 * @param int[] $creator_ids Creator ids.
	 */
	public function invalidate( array $creator_ids ): void {
		$this->calls[] = $creator_ids;
		$this->cache->invalidate( $creator_ids );
	}
}
