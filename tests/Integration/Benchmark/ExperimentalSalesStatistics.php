<?php
/**
 * Experimental SalesStatistics with fewer ledger scans (phase 20, step 8b).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Benchmark;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPeriod;
use MerchBoard\Modules\WooCommerce\Sales\SalesPeriods;
use MerchBoard\Modules\WooCommerce\Sales\SalesProductTotals;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use MerchBoard\Modules\WooCommerce\Sales\SalesSeriesPoint;
use MerchBoard\Modules\WooCommerce\Sales\SalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesTotals;
use RuntimeException;
use wpdb;

/**
 * Benchmark code only, never production: the decision analysis of step 8b
 * ("fewer ledger scans"). Same filter as LedgerSalesStatistics (creator,
 * `occurred_at >= from AND < to`, index `creator_occurred`), same amounts
 * (decimal strings, SalesAmount, no floats) and no CONVERT_TZ(). One query
 * answers two of the three questions with `GROUP BY … WITH ROLLUP`: the
 * per-currency super-aggregate rows are the totals, and `COUNT(DISTINCT …)`
 * on them counts distinct orders over the whole period (not the sum of the
 * periods).
 *
 * - STRATEGY_SERIES: totals and series in one scan
 *   (`GROUP BY currency, period WITH ROLLUP`), products as today.
 * - STRATEGY_PRODUCTS: totals and products in one scan
 *   (`GROUP BY currency, product_id WITH ROLLUP`), series as today.
 *
 * The interface asks for totals() before series() and without the periods,
 * so the instance is built for one SalesQuery and remembers the combined
 * result; a production version would need a combined method instead.
 */
final class ExperimentalSalesStatistics implements SalesStatistics {

	public const STRATEGY_SERIES = 'totals+series';

	public const STRATEGY_PRODUCTS = 'totals+products';

	/**
	 * Whether the combined query ran.
	 *
	 * @var bool
	 */
	private bool $ran = false;

	/**
	 * Series from the combined query (STRATEGY_SERIES).
	 *
	 * @var list<SalesSeriesPoint>
	 */
	private array $series = array();

	/**
	 * Products from the combined query (STRATEGY_PRODUCTS).
	 *
	 * @var list<SalesProductTotals>
	 */
	private array $products = array();

	/**
	 * Constructor.
	 *
	 * @param wpdb                  $wpdb     Database.
	 * @param LedgerSalesStatistics $ledger   Production read model for the separate query.
	 * @param SalesQuery            $query    The one query this instance answers.
	 * @param string                $strategy STRATEGY_SERIES or STRATEGY_PRODUCTS.
	 */
	public function __construct( private wpdb $wpdb, private LedgerSalesStatistics $ledger, private SalesQuery $query, private string $strategy ) {
	}

	/**
	 * Totals per currency (from the combined query).
	 *
	 * @param int               $creator_id Creator.
	 * @param DateTimeImmutable $from       Start.
	 * @param DateTimeImmutable $to         End.
	 * @return list<SalesTotals>
	 */
	public function totals( int $creator_id, DateTimeImmutable $from, DateTimeImmutable $to ): array {
		$this->assert_query( $from, $to );
		$this->ran = true;

		return self::STRATEGY_SERIES === $this->strategy ? $this->totals_and_series( $creator_id ) : $this->totals_and_products( $creator_id );
	}

	/**
	 * Series (remembered, or the production query).
	 *
	 * @param int           $creator_id Creator.
	 * @param SalesPeriod[] $periods    Periods.
	 * @return list<SalesSeriesPoint>
	 */
	public function series( int $creator_id, array $periods ): array {
		if ( self::STRATEGY_SERIES !== $this->strategy ) {
			return $this->ledger->series( $creator_id, array_values( $periods ) );
		}

		if ( ! $this->ran ) {
			throw new RuntimeException( 'totals() first.' );
		}

		return $this->series;
	}

	/**
	 * Products (remembered, or the production query).
	 *
	 * @param int               $creator_id Creator.
	 * @param DateTimeImmutable $from       Start.
	 * @param DateTimeImmutable $to         End.
	 * @return list<SalesProductTotals>
	 */
	public function products( int $creator_id, DateTimeImmutable $from, DateTimeImmutable $to ): array {
		if ( self::STRATEGY_PRODUCTS !== $this->strategy ) {
			return $this->ledger->products( $creator_id, $from, $to );
		}

		if ( ! $this->ran ) {
			throw new RuntimeException( 'totals() first.' );
		}

		return $this->products;
	}

	/**
	 * The SQL of the combined query of the strategy (for EXPLAIN and timing).
	 *
	 * @param int $creator_id Creator.
	 */
	public function sql( int $creator_id ): string {
		$wpdb  = $this->wpdb;
		$table = Tables::name( $wpdb, Tables::SALES );
		$from  = self::utc( $this->query->start );
		$to    = self::utc( $this->query->end );
		$sums  = "COALESCE( SUM( CASE WHEN type = 'sale' THEN net_amount END ), 0 ) AS sales,
			COALESCE( SUM( CASE WHEN type <> 'sale' THEN net_amount END ), 0 ) AS adjustments,
			SUM( net_amount ) AS net_sales, SUM( quantity ) AS units,
			COUNT( DISTINCT CASE WHEN type = 'sale' THEN order_id END ) AS orders";

		if ( self::STRATEGY_SERIES === $this->strategy ) {
			$starts = array_map( static fn( SalesPeriod $period ): string => self::utc( $period->start ), $this->query->periods );
			$marks  = implode( ', ', array_fill( 0, count( $starts ), 'TO_SECONDS( %s )' ) );

			return (string) $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %s per period start, then table, creator and interval.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $marks holds only %s placeholders; $sums is constant SQL.
				"SELECT currency, INTERVAL( TO_SECONDS( occurred_at ), {$marks} ) - 1 AS period, {$sums}
				FROM %i
				WHERE creator_id = %d AND occurred_at >= %s AND occurred_at < %s
				GROUP BY currency, period WITH ROLLUP",
				...array_merge( $starts, array( $table, $creator_id, $from, $to ) )
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sums is constant SQL.
		return (string) $wpdb->prepare(
			"SELECT g.currency, g.product_id, g.sales, g.adjustments, g.net_sales, g.units, g.orders, s.item_name
			FROM (
				SELECT currency, product_id, {$sums}, MAX( id ) AS last_id
				FROM %i
				WHERE creator_id = %d AND occurred_at >= %s AND occurred_at < %s
				GROUP BY currency, product_id WITH ROLLUP
			) g
			LEFT JOIN %i s ON s.id = g.last_id",
			$table,
			$creator_id,
			$from,
			$to,
			$table
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Totals from one scan that also yields the series (kept in $series).
	 *
	 * @param int $creator_id Creator.
	 * @return list<SalesTotals>
	 */
	private function totals_and_series( int $creator_id ): array {
		$totals = array();
		$points = array();

		foreach ( $this->rows( $creator_id ) as $row ) {
			if ( null === $row['currency'] ) {
				continue; // Grand total across currencies: never used.
			}

			if ( null === $row['period'] ) {
				$totals[] = self::totals_row( $row );
				continue;
			}

			$points[] = new SalesSeriesPoint( (int) $row['period'], (string) $row['currency'], SalesAmount::format( SalesAmount::parse( (string) $row['net_sales'] ) ), (int) $row['units'], (int) $row['orders'] );
		}

		usort( $totals, static fn( SalesTotals $a, SalesTotals $b ): int => strcmp( $a->currency, $b->currency ) );
		usort( $points, static fn( SalesSeriesPoint $a, SalesSeriesPoint $b ): int => array( $a->index, $a->currency ) <=> array( $b->index, $b->currency ) );

		$this->series = $points;

		return $totals;
	}

	/**
	 * Totals from one scan that also yields the products (kept in $products).
	 *
	 * @param int $creator_id Creator.
	 * @return list<SalesTotals>
	 */
	private function totals_and_products( int $creator_id ): array {
		$totals   = array();
		$products = array();

		foreach ( $this->rows( $creator_id ) as $row ) {
			if ( null === $row['currency'] ) {
				continue;
			}

			if ( null === $row['product_id'] ) {
				$totals[] = self::totals_row( $row );
				continue;
			}

			$products[] = new SalesProductTotals( (string) $row['currency'], (int) $row['product_id'], SalesAmount::format( SalesAmount::parse( (string) $row['net_sales'] ) ), (int) $row['units'], (string) $row['item_name'] );
		}

		usort( $totals, static fn( SalesTotals $a, SalesTotals $b ): int => strcmp( $a->currency, $b->currency ) );
		usort(
			$products,
			static fn( SalesProductTotals $a, SalesProductTotals $b ): int => array( $a->currency, SalesAmount::parse( $b->net_sales ), $a->product_id ) <=> array( $b->currency, SalesAmount::parse( $a->net_sales ), $b->product_id )
		);

		$this->products = $products;

		return $totals;
	}

	/**
	 * Rows of the combined query.
	 *
	 * @param int $creator_id Creator.
	 * @return list<array<string, mixed>>
	 */
	private function rows( int $creator_id ): array {
		$rows = $this->wpdb->get_results( $this->sql( $creator_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sql() returns the result of $wpdb->prepare(); benchmark only.

		if ( ! is_array( $rows ) || '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Combined query failed: ' . $this->wpdb->last_error );
		}

		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * A super-aggregate row as totals (net sales = sales + adjustments, as in
	 * LedgerSalesStatistics).
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function totals_row( array $row ): SalesTotals {
		$sales       = SalesAmount::parse( (string) $row['sales'] );
		$adjustments = SalesAmount::parse( (string) $row['adjustments'] );

		return new SalesTotals(
			(string) $row['currency'],
			SalesAmount::format( $sales ),
			SalesAmount::format( $adjustments ),
			SalesAmount::format( SalesAmount::add( $sales, $adjustments ) ),
			(int) $row['units'],
			(int) $row['orders']
		);
	}

	/**
	 * Requires the interval of the query this instance was built for.
	 *
	 * @param DateTimeImmutable $from Start.
	 * @param DateTimeImmutable $to   End.
	 */
	private function assert_query( DateTimeImmutable $from, DateTimeImmutable $to ): void {
		if ( $from != $this->query->start || $to != $this->query->end ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- DateTimeImmutable compares by value.
			throw new RuntimeException( 'Built for another period.' );
		}
	}

	/**
	 * UTC time as stored.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( SalesPeriods::SQL_FORMAT );
	}
}
