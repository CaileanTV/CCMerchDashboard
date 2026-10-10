<?php
/**
 * Decision analysis of phase 20, step 8b: can fewer ledger scans bring the
 * uncached `/me/sales` report below p95 200 ms (ADR-040 pkt. 15)? Opt-in like
 * SalesBenchmarkTest (group `benchmark`), on the same data set with the same
 * WARMUP + RUNS. Benchmark code only; production is unchanged. Run it with
 * `node bin/integration.mjs --group benchmark --exclude-group none --filter SalesQueryStrategyExperimentTest`.
 *
 * Compares, for the large creator:
 * - A: today (totals, series, products: three scans);
 * - B1: totals + series in one scan (ROLLUP), products separate;
 * - B2: totals + products in one scan (ROLLUP), series separate;
 * first with the existing indexes, then with an experimental covering index
 * that exists only in this run's throwaway test database. Before timing,
 * every strategy must return exactly the report of A for every creator and
 * period, and for an edge-case creator (negative and zero periods, period
 * boundaries, several currencies, product 0).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Benchmark;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReport;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeSalesProductNames;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'benchmark' )]
final class SalesQueryStrategyExperimentTest extends IntegrationTestCase {

	use SalesBenchmarkFixture;

	/**
	 * Creator with hand-placed edge cases.
	 */
	private const EDGE = 900001;

	protected function tearDown(): void {
		parent::tearDown();
		$this->drop_plugin_schema();
	}

	public function test_fewer_scans_against_the_current_three_queries(): void {
		[ $large, $small ] = $this->seed_dataset();
		$this->seed_edges();
		$this->flush_rows();

		$timezone  = new DateTimeZone( 'Europe/Copenhagen' );
		$now       = new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) );
		$ledger    = new LedgerSalesStatistics( $this->wpdb );
		$names     = new FakeSalesProductNames();
		$baseline  = new SalesReport( $ledger, $names, new LedgerCreatorProfits( $this->wpdb ) );
		$scenarios = array(
			'30 days by day'  => SalesQuery::resolve( null, null, SalesInterval::Day, $timezone, $now ),
			'1 year by day'   => SalesQuery::resolve( '2025-10-01', '2026-09-30', SalesInterval::Day, $timezone, $now ),
			'1 year by month' => SalesQuery::resolve( '2025-10-01', '2026-09-30', SalesInterval::Month, $timezone, $now ),
			'edge week'       => SalesQuery::resolve( '2026-08-24', '2026-09-13', SalesInterval::Week, $timezone, $now ),
		);
		$report    = fn( SalesQuery $query, string $strategy, int $creator_id ): array => ( new SalesReport( new ExperimentalSalesStatistics( $this->wpdb, $ledger, $query, $strategy ), $names, new LedgerCreatorProfits( $this->wpdb ) ) )->build( $creator_id, $query );

		// Correctness first: identical reports (totals, series, products, currencies, periods).
		foreach ( array( $large->id, 100001, $small->id, self::EDGE ) as $creator_id ) {
			foreach ( $scenarios as $label => $query ) {
				$expected = $baseline->build( $creator_id, $query );

				foreach ( array( ExperimentalSalesStatistics::STRATEGY_SERIES, ExperimentalSalesStatistics::STRATEGY_PRODUCTS ) as $strategy ) {
					self::assertSame( $expected, $report( $query, $strategy, $creator_id ), "{$strategy}, creator {$creator_id}, {$label}" );
				}
			}
		}

		$edge = $baseline->build( self::EDGE, $scenarios['edge week'] );
		self::assertSame( array( 'DKK', 'EUR' ), array_column( $edge['currencies'], 'currency' ), 'The edge creator covers several currencies.' );
		self::assertContains( '-50.0000', array_column( $edge['currencies'][0]['series'], 'net_sales' ), 'A negative week.' );
		self::assertContains( '0.0000', array_column( $edge['currencies'][0]['series'], 'net_sales' ), 'A zero week.' );
		self::assertContains( 0, array_column( $edge['currencies'][0]['products'], 'product_id' ), 'Product 0.' );

		foreach ( array( 'existing indexes', 'experimental covering index' ) as $phase ) {
			if ( 'experimental covering index' === $phase ) {
				// Throwaway test database only; never part of the product.
				$this->execute( 'ALTER TABLE %i ADD INDEX experiment_covering (creator_id, occurred_at, currency, type, order_id, product_id, quantity, net_amount)', $this->table( Tables::SALES ) );
				$this->execute( 'ANALYZE TABLE %i', $this->table( Tables::SALES ) );
			}

			foreach ( array( '30 days by day', '1 year by day', '1 year by month' ) as $label ) {
				$query    = $scenarios[ $label ];
				$scenario = $phase . ' | ' . $label;
				$creator  = $large->id;
				$series   = new ExperimentalSalesStatistics( $this->wpdb, $ledger, $query, ExperimentalSalesStatistics::STRATEGY_SERIES );
				$products = new ExperimentalSalesStatistics( $this->wpdb, $ledger, $query, ExperimentalSalesStatistics::STRATEGY_PRODUCTS );
				$sql_b1   = $series->sql( $creator );
				$sql_b2   = $products->sql( $creator );

				$this->measure( $scenario, 'A totals()', fn() => $ledger->totals( $creator, $query->start, $query->end ) );
				$this->measure( $scenario, 'A series()', fn() => $ledger->series( $creator, $query->periods ) );
				$this->measure( $scenario, 'A products()', fn() => $ledger->products( $creator, $query->start, $query->end ) );
				$this->measure( $scenario, 'A report (3 scans)', fn() => $baseline->build( $creator, $query ) );
				$this->measure( $scenario, 'B1 totals+series query', fn() => $this->wpdb->get_results( $sql_b1, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by sql().
				$this->measure( $scenario, 'B1 report (2 scans)', fn() => $report( $query, ExperimentalSalesStatistics::STRATEGY_SERIES, $creator ) );
				$this->measure( $scenario, 'B2 totals+products query', fn() => $this->wpdb->get_results( $sql_b2, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by sql().
				$this->measure( $scenario, 'B2 report (2 scans)', fn() => $report( $query, ExperimentalSalesStatistics::STRATEGY_PRODUCTS, $creator ) );

				if ( '1 year by day' === $label ) {
					$this->explain_sql( $phase . ' B1 totals+series', $sql_b1 );
					$this->explain_sql( $phase . ' B2 totals+products', $sql_b2 );
				}
			}
		}

		$this->report();
	}

	/**
	 * Prints the EXPLAIN of a query and requires the creator filter on the index.
	 *
	 * @param string $label Label.
	 * @param string $sql   Prepared query.
	 */
	private function explain_sql( string $label, string $sql ): void {
		fwrite( STDERR, "\nEXPLAIN {$label}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Benchmark output.
		self::assertStringNotContainsStringIgnoringCase( 'CONVERT_TZ', $sql );
		self::assertMatchesRegularExpression( '/WHERE creator_id = \d+ AND occurred_at >= \'[^\']+\' AND occurred_at < \'[^\']+\'/', $sql, 'Same creator and period filter.' );

		foreach ( (array) $this->wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by sql().
			$row = (array) $row;
			fwrite( STDERR, sprintf( "  select=%s table=%s type=%s key=%s rows=%s extra=%s\n", $row['select_type'] ?? '', $row['table'] ?? '', $row['type'] ?? '', $row['key'] ?? 'NULL', $row['rows'] ?? '', $row['Extra'] ?? '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Benchmark output.

			if ( str_contains( (string) ( $row['table'] ?? '' ), Tables::SALES ) && 'PRIMARY' !== ( $row['key'] ?? '' ) ) {
				self::assertContains( $row['key'] ?? null, array( 'creator_occurred', 'experiment_covering' ), $label . ': the creator filter uses an index.' );
			}
		}
	}

	/**
	 * Edge cases for the identity check, in Copenhagen weeks of 2026-08-24,
	 * 2026-08-31 and 2026-09-07: a week with only a refund (negative), a week
	 * whose sale and refund cancel out (zero), rows exactly at local midnight
	 * on both sides of a week boundary, product 0 and a second currency.
	 */
	private function seed_edges(): void {
		$utc = new DateTimeZone( 'UTC' );
		$at  = static fn( string $time ): int => ( new DateTimeImmutable( $time, $utc ) )->getTimestamp();

		// Week of 2026-08-24: only a refund of an earlier sale (-50).
		$this->row( self::EDGE, 1, 11, 1, 91, 20, 0, 'refund', null, -1, -5000, 'DKK', $at( '2026-08-25 10:00:00' ) );
		// Week of 2026-08-31: a sale exactly at the week's first instant and its full refund (zero).
		$this->row( self::EDGE, 2, 21, 1, 0, 20, 0, 'sale', null, 1, 1000, 'DKK', $at( '2026-08-30 22:00:00' ) ); // Monday 2026-08-31 00:00 local.
		$this->row( self::EDGE, 2, 21, 1, 92, 20, 0, 'refund', null, -1, -1000, 'DKK', $at( '2026-09-03 10:00:00' ) ); // Zero week.
		// Week of 2026-09-07: product 0, a second currency, a reversal and a reopened sale.
		$this->row( self::EDGE, 3, 31, 1, 0, 0, 0, 'sale', null, 2, 2500, 'DKK', $at( '2026-09-06 22:00:00' ) ); // Monday 00:00 local.
		$this->row( self::EDGE, 4, 41, 1, 0, 30, 31, 'sale', null, 1, 1999, 'EUR', $at( '2026-09-08 10:00:00' ) );
		$this->row( self::EDGE, 4, 41, 1, 0, 30, 31, 'reversal', null, -1, -1999, 'EUR', $at( '2026-09-09 10:00:00' ) );
		$this->row( self::EDGE, 4, 41, 2, 0, 30, 31, 'sale', null, 1, 1999, 'EUR', $at( '2026-09-10 10:00:00' ) );
		// Just outside the period on both sides.
		$this->row( self::EDGE, 5, 51, 1, 0, 20, 0, 'sale', null, 1, 777, 'DKK', $at( '2026-08-23 21:59:59' ) );
		$this->row( self::EDGE, 6, 61, 1, 0, 20, 0, 'sale', null, 1, 888, 'DKK', $at( '2026-09-13 22:00:00' ) );
	}
}
