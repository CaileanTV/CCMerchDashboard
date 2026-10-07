<?php
/**
 * Benchmark of the sales statistics (ADR-040 pkt. 15, phase 20, step 8):
 * p95 of the read model and of the `/me/sales` report without cache, on a
 * documented, reproducible synthetic data set. Opt-in: the group `benchmark`
 * is excluded from the integration suite and CI. Run it with
 * `node bin/integration.mjs --group benchmark --exclude-group none --filter SalesBenchmarkTest`.
 *
 * Writes a results table to STDERR (and JSON to MERCHBOARD_BENCHMARK_OUT when
 * set). Fails when an uncached report has p95 >= 200 ms. The data lives only
 * in the test database of the run and is deleted afterwards.
 *
 * Data set (deterministic: a fixed linear congruential generator, so every
 * run inserts the same rows), 2025-10-01 to 2026-09-30 UTC:
 * - one large creator: 60-140 orders per day, 1-3 of its lines per order,
 *   250 parent products (40 % with variations), DKK 85 % / EUR 10 % / SEK 5 %,
 *   refunds on 6 % of the lines, cancellations (reversal) of 2 % of the
 *   orders, half of them reopened (sale of cycle 2), line corrections on
 *   0.5 % of the lines;
 * - 40 medium creators: 2-6 orders per day, the same mix (DKK/EUR);
 * - one small creator: 0-1 orders per day.
 * The numbers are a test fixture, not a forecast: the repo documents no
 * production volume (ADR-040 pkt. 15).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Benchmark;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\WooCommerce\Dashboard\CachedSalesReports;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReport;
use MerchBoard\Modules\WooCommerce\Rest\MeSalesController;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeSalesProductNames;
use PHPUnit\Framework\Attributes\Group;
use WP_REST_Request;

#[Group( 'benchmark' )]
final class SalesBenchmarkTest extends IntegrationTestCase {

	use SalesBenchmarkFixture;

	/**
	 * ADR-040 pkt. 15: p95 below this many milliseconds without cache.
	 */
	private const LIMIT_MS = 200.0;

	protected function tearDown(): void {
		// Users first (their deletion hooks read the creators table), then the
		// plugin's tables with every benchmark row.
		parent::tearDown();
		$this->drop_plugin_schema();
	}

	public function test_sales_statistics_stay_below_200_ms_p95_without_cache(): void {
		[ $large, $small ] = $this->seed_dataset();

		$timezone   = new DateTimeZone( 'Europe/Copenhagen' );
		$now        = new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) );
		$statistics = new LedgerSalesStatistics( $this->wpdb );
		$uncached   = new SalesReport( $statistics, new FakeSalesProductNames() );
		$cache      = new SalesCache();
		$cached     = new CachedSalesReports( $uncached, $cache );
		$scenarios  = array(
			'30 days by day'  => SalesQuery::resolve( null, null, SalesInterval::Day, $timezone, $now ),
			'1 year by day'   => SalesQuery::resolve( '2025-10-01', '2026-09-30', SalesInterval::Day, $timezone, $now ),
			'1 year by month' => SalesQuery::resolve( '2025-10-01', '2026-09-30', SalesInterval::Month, $timezone, $now ),
		);

		foreach ( array(
			'large'  => $large->id,
			'medium' => 100001,
			'small'  => $small->id,
		) as $size => $creator_id ) {
			foreach ( $scenarios as $label => $query ) {
				$scenario = $size . ' | ' . $label;
				$this->measure( $scenario, 'totals()', fn() => $statistics->totals( $creator_id, $query->start, $query->end ) );
				$this->measure( $scenario, 'series()', fn() => $statistics->series( $creator_id, $query->periods ) );
				$this->measure( $scenario, 'products()', fn() => $statistics->products( $creator_id, $query->start, $query->end ) );
				$this->measure( $scenario, 'report uncached', fn() => $uncached->build( $creator_id, $query ) );
				$this->measure(
					$scenario,
					'report cache miss',
					fn() => $cached->build( $creator_id, $query ),
					// Untimed: drop the earlier entries (one process keeps every
					// generation's report in the in-memory cache), then a new generation.
					static function () use ( $cache, $creator_id ): void {
						wp_cache_flush_group( SalesCache::GROUP );
						$cache->invalidate( array( $creator_id ) );
					}
				);
				$cached->build( $creator_id, $query );
				$this->measure( $scenario, 'report cache hit', fn() => $cached->build( $creator_id, $query ) );
			}
		}

		$this->measure_rest( $large->id, $uncached );
		$this->explain( $statistics, $large->id, $scenarios['1 year by day'] );
		$this->verify_cache( $cached, $uncached, $cache, $large->id, $small->id, $scenarios['30 days by day'] );
		$this->report();

		foreach ( $this->results as $result ) {
			if ( str_starts_with( (string) $result['method'], 'report uncached' ) || str_starts_with( (string) $result['method'], 'REST' ) ) {
				self::assertLessThan( self::LIMIT_MS, $result['p95'], $result['scenario'] . ' ' . $result['method'] );
			}
		}
	}

	/**
	 * `GET /me/sales` through the real REST server (permission callback,
	 * validation, SalesQuery, uncached SalesReport), for the large creator.
	 *
	 * @param int         $creator_id Large creator.
	 * @param SalesReport $uncached   Uncached report.
	 */
	private function measure_rest( int $creator_id, SalesReport $uncached ): void {
		update_option( 'timezone_string', 'Europe/Copenhagen' );
		$controller = new MeSalesController(
			$this->service( CreatorContext::class ),
			$uncached,
			$this->service( Logger::class ),
			static fn(): DateTimeImmutable => new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) )
		);
		$register   = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server for the benchmark.
		rest_get_server();
		$user = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT user_id FROM %i WHERE id = %d', $this->table( Tables::CREATORS ), $creator_id ) );
		wp_set_current_user( $user );

		foreach ( array(
			'30 days by day' => array(),
			'1 year by day'  => array(
				'from' => '2025-10-01',
				'to'   => '2026-09-30',
			),
		) as $label => $params ) {
			$this->measure(
				'large | ' . $label,
				'REST /me/sales uncached',
				static function () use ( $params ): void {
					$request = new WP_REST_Request( 'GET', '/merchboard/v1/me/sales' );
					$request->set_query_params( $params );
					$response = rest_do_request( $request );

					if ( 200 !== $response->get_status() ) {
						throw new \RuntimeException( 'GET /me/sales failed: ' . $response->get_status() );
					}

					wp_json_encode( $response->get_data() );
				}
			);
		}

		remove_action( 'rest_api_init', $register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server for the benchmark.
		delete_option( 'timezone_string' );
		wp_set_current_user( 1 );
	}

	/**
	 * EXPLAIN of the three read-model queries (large creator, one year by day).
	 *
	 * @param LedgerSalesStatistics $statistics Read model.
	 * @param int                   $creator_id Creator.
	 * @param SalesQuery            $query      Query.
	 */
	private function explain( LedgerSalesStatistics $statistics, int $creator_id, SalesQuery $query ): void {
		$queries = array();
		$record  = static function ( string $sql ) use ( &$queries ): string {
			$queries[] = $sql;

			return $sql;
		};
		add_filter( 'query', $record );
		$statistics->totals( $creator_id, $query->start, $query->end );
		$statistics->series( $creator_id, $query->periods );
		$statistics->products( $creator_id, $query->start, $query->end );
		remove_filter( 'query', $record );

		foreach ( array( 'totals', 'series', 'products' ) as $index => $label ) {
			fwrite( STDERR, "\nEXPLAIN {$label}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Benchmark output.

			foreach ( (array) $this->wpdb->get_results( 'EXPLAIN ' . $queries[ $index ], ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The recorded, already prepared query.
				$row = (array) $row;
				fwrite( STDERR, sprintf( "  select=%s table=%s type=%s key=%s rows=%s extra=%s\n", $row['select_type'] ?? '', $row['table'] ?? '', $row['type'] ?? '', $row['key'] ?? 'NULL', $row['rows'] ?? '', $row['Extra'] ?? '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Benchmark output.

				if ( str_contains( (string) ( $row['table'] ?? '' ), Tables::SALES ) && 'PRIMARY' !== ( $row['key'] ?? '' ) ) {
					self::assertSame( 'creator_occurred', $row['key'] ?? null, $label . ' filters by creator and period with the index.' );
				}
			}
		}

		self::assertStringContainsString( 'INTERVAL( TO_SECONDS( occurred_at )', $queries[1], 'The series still maps rows to periods with INTERVAL/TO_SECONDS.' );
		self::assertStringNotContainsStringIgnoringCase( 'CONVERT_TZ', implode( ' ', $queries ) );
	}

	/**
	 * The cache does not hide or skew the uncached figures.
	 *
	 * @param CachedSalesReports $cached     Cached reports.
	 * @param SalesReport        $uncached   Uncached reports.
	 * @param SalesCache         $cache      Cache.
	 * @param int                $large      Large creator.
	 * @param int                $small      Small creator.
	 * @param SalesQuery         $query      Query.
	 */
	private function verify_cache( CachedSalesReports $cached, SalesReport $uncached, SalesCache $cache, int $large, int $small, SalesQuery $query ): void {
		$cached->build( $large, $query );
		self::assertSame( 0, $this->ledger_queries( static fn() => $cached->build( $large, $query ) ), 'A hit reads no ledger rows.' );
		self::assertSame( 3, $this->ledger_queries( static fn() => $uncached->build( $large, $query ) ), 'The uncached report never uses the cache.' );
		self::assertSame( $uncached->build( $large, $query ), $cached->build( $large, $query ), 'The hit is the uncached report.' );

		$small_generation = $cache->generation( $small );
		$cache->invalidate( array( $large ) );
		self::assertSame( 3, $this->ledger_queries( static fn() => $cached->build( $large, $query ) ), 'After invalidation the next request reads the ledger.' );
		self::assertSame( $small_generation, $cache->generation( $small ), 'Another creator keeps its generation.' );
		self::assertNotSame( $cache->report_key( $large, '1', $query ), $cache->report_key( $small, '1', $query ) );
	}
}
