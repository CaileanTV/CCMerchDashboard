<?php
/**
 * The creator's sales report through the object cache.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Read-through cache around SalesReports (ADR-040 pkt. 10, SalesCache):
 *
 * 1. read the creator's generation and look the report up under it; a hit
 *    is returned without any ledger query;
 * 2. on a miss, build the report from the ledger;
 * 3. store it only when the generation is still the one read in step 1. A
 *    write committed meanwhile has given the creator a new generation, so a
 *    report that may predate it is not stored (and, if a write lands just
 *    after this check, it is stored under the old generation, which no
 *    reader uses any more).
 *
 * The report is the same array as without the cache; nothing else changes.
 */
final class CachedSalesReports implements SalesReports {

	/**
	 * Constructor.
	 *
	 * @param SalesReports $reports Uncached reports.
	 * @param SalesCache   $cache   Object cache of the sales statistics.
	 */
	public function __construct( private SalesReports $reports, private SalesCache $cache ) {
	}

	/**
	 * The report of a creator for a query, from the cache when possible.
	 *
	 * @param int        $creator_id Creator id (from CreatorContext).
	 * @param SalesQuery $query      Validated period.
	 * @return array<string, mixed>
	 */
	public function build( int $creator_id, SalesQuery $query ): array {
		$generation = $this->cache->generation( $creator_id );
		$key        = $this->cache->report_key( $creator_id, $generation, $query );
		$cached     = $this->cache->report( $key, $query );

		if ( null !== $cached ) {
			return $cached;
		}

		$report = $this->reports->build( $creator_id, $query );

		if ( $this->cache->generation( $creator_id ) === $generation ) {
			$this->cache->store( $key, $report );
		}

		return $report;
	}
}
