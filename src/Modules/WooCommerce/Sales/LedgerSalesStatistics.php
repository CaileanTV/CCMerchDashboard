<?php
/**
 * Sales statistics from the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionRepository;
use OverflowException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * SalesStatistics read from `merchboard_sales` alone (ADR-040): no WooCommerce
 * table or function, so HPOS or legacy order storage does not matter. Every
 * query filters `creator_id = %d AND occurred_at >= from AND occurred_at < to`
 * (index `creator_occurred`), groups by currency and never converts or sums
 * across currencies.
 *
 * - sales = SUM(net_amount) of `sale` rows; adjustments = SUM(net_amount) of
 *   every other type; net_sales = sales + adjustments (added as integers of
 *   1/10000, never as floats).
 * - units = SUM(quantity) of all rows (signed).
 * - Incl. VAT (ADR-053): the same sums of `net_amount` plus the row's stored
 *   actual tax (`merchboard_sales_tax`, ADR-048, joined by `event_key`), but
 *   only when every row of the group has its stored tax in the row's
 *   currency; otherwise null and the number of rows without tax is counted.
 *   Missing tax is never treated as 0.
 * - orders = COUNT(DISTINCT order_id) of `sale` rows (not the current cycle
 *   state).
 * - Products are grouped by parent `product_id` (variations included). Rows
 *   without a product (`product_id = 0`) are kept as their own group with
 *   product id 0, so the product sums add up to the totals.
 * - The series maps each row to its period with `INTERVAL( TO_SECONDS(
 *   occurred_at ), <period starts> )`: one indexed range scan, no
 *   `CONVERT_TZ()` (TO_SECONDS works on the stored UTC value without any time
 *   zone).
 */
final class LedgerSalesStatistics implements SalesStatistics {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Totals per currency.
	 *
	 * @phpstan-return list<SalesTotals>
	 *
	 * @param int               $creator_id Creator id.
	 * @param DateTimeImmutable $from       Start (UTC, inclusive).
	 * @param DateTimeImmutable $to         End (UTC, exclusive).
	 * @return SalesTotals[]
	 * @throws DatabaseException When the query fails or a row does not match the schema.
	 */
	public function totals( int $creator_id, DateTimeImmutable $from, DateTimeImmutable $to ): array {
		if ( ! self::readable( $creator_id, $from, $to ) ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read model of the ledger; caching is a separate layer (ADR-040 pkt. 10).
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant SQL fragments with placeholders only (the exclusion join, ADR-068): the tables, then the creator and interval.
			$wpdb->prepare(
				"SELECT r.currency,
					COALESCE( SUM( CASE WHEN r.type = 'sale' THEN r.net_amount END ), 0 ) AS sales,
					COALESCE( SUM( CASE WHEN r.type <> 'sale' THEN r.net_amount END ), 0 ) AS adjustments,
					COALESCE( SUM( CASE WHEN r.type = 'sale' AND ( t.event_key IS NOT NULL AND t.currency = r.currency ) THEN r.net_amount + t.tax_amount END ), 0 ) AS sales_incl_vat,
					COALESCE( SUM( CASE WHEN r.type <> 'sale' AND ( t.event_key IS NOT NULL AND t.currency = r.currency ) THEN r.net_amount + t.tax_amount END ), 0 ) AS adjustments_incl_vat,
					SUM( CASE WHEN ( t.event_key IS NOT NULL AND t.currency = r.currency ) THEN 0 ELSE 1 END ) AS missing_vat_rows,
					SUM( r.quantity ) AS units,
					COUNT( DISTINCT CASE WHEN r.type = 'sale' THEN r.order_id END ) AS orders
				FROM %i r LEFT JOIN %i t ON t.event_key = r.event_key" . OrderExclusionRepository::JOIN . '
				WHERE ' . OrderExclusionRepository::COUNTS . ' AND r.creator_id = %d AND r.occurred_at >= %s AND r.occurred_at < %s
				GROUP BY r.currency
				ORDER BY r.currency ASC',
				$this->table(),
				$this->tax_table(),
				$this->exclusions_table(),
				$creator_id,
				self::utc( $from ),
				self::utc( $to )
			),
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			ARRAY_A
		);

		$totals = array();

		foreach ( $this->checked( $rows ) as $row ) {
			$sales       = self::amount( $row['sales'] ?? null );
			$adjustments = self::amount( $row['adjustments'] ?? null );
			$missing     = self::integer( $row['missing_vat_rows'] ?? null );
			$sales_vat   = self::amount( $row['sales_incl_vat'] ?? null );
			$adjust_vat  = self::amount( $row['adjustments_incl_vat'] ?? null );
			$totals[]    = new SalesTotals(
				self::currency( $row['currency'] ?? null ),
				SalesAmount::format( $sales ),
				SalesAmount::format( $adjustments ),
				SalesAmount::format( self::sum( $sales, $adjustments ) ),
				self::integer( $row['units'] ?? null ),
				self::integer( $row['orders'] ?? null ),
				0 === $missing ? SalesAmount::format( $sales_vat ) : null,
				0 === $missing ? SalesAmount::format( $adjust_vat ) : null,
				0 === $missing ? SalesAmount::format( self::sum( $sales_vat, $adjust_vat ) ) : null,
				$missing
			);
		}

		return $totals;
	}

	/**
	 * Sums per period and currency, only for periods with rows.
	 *
	 * @phpstan-param list<SalesPeriod> $periods
	 * @phpstan-return list<SalesSeriesPoint>
	 *
	 * @param int           $creator_id Creator id.
	 * @param SalesPeriod[] $periods    Contiguous periods, in order.
	 * @return SalesSeriesPoint[]
	 * @throws DatabaseException When the query fails or a row does not match the schema.
	 */
	public function series( int $creator_id, array $periods ): array {
		if ( array() === $periods ) {
			return array();
		}

		$from = $periods[0]->start;
		$to   = $periods[ count( $periods ) - 1 ]->end;

		if ( ! self::readable( $creator_id, $from, $to ) ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$starts = array_map( static fn( SalesPeriod $period ): string => self::utc( $period->start ), $periods );
		$marks  = implode( ', ', array_fill( 0, count( $starts ), 'TO_SECONDS( %s )' ) );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read model of the ledger; caching is a separate layer (ADR-040 pkt. 10).
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Constant SQL fragments with placeholders only (the exclusion join, ADR-068).
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %s per period start, then the tables, creator and interval.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $marks holds only %s placeholders, one per period.
				"SELECT INTERVAL( TO_SECONDS( r.occurred_at ), {$marks} ) - 1 AS period, r.currency,
					SUM( r.net_amount ) AS net_sales,
					COALESCE( SUM( CASE WHEN ( t.event_key IS NOT NULL AND t.currency = r.currency ) THEN r.net_amount + t.tax_amount END ), 0 ) AS net_sales_incl_vat,
					SUM( CASE WHEN ( t.event_key IS NOT NULL AND t.currency = r.currency ) THEN 0 ELSE 1 END ) AS missing_vat_rows,
					SUM( r.quantity ) AS units,
					COUNT( DISTINCT CASE WHEN r.type = 'sale' THEN r.order_id END ) AS orders
				FROM %i r LEFT JOIN %i t ON t.event_key = r.event_key" . OrderExclusionRepository::JOIN . '
				WHERE ' . OrderExclusionRepository::COUNTS . ' AND r.creator_id = %d AND r.occurred_at >= %s AND r.occurred_at < %s
				GROUP BY period, r.currency
				ORDER BY period ASC, r.currency ASC',
				...array_merge( $starts, array( $this->table(), $this->tax_table(), $this->exclusions_table(), $creator_id, self::utc( $from ), self::utc( $to ) ) )
			),
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$points = array();

		foreach ( $this->checked( $rows ) as $row ) {
			$index = self::integer( $row['period'] ?? null );

			if ( $index < 0 || $index >= count( $periods ) ) {
				throw new DatabaseException( 'A sales period does not match the requested periods.' );
			}

			$points[] = new SalesSeriesPoint(
				$index,
				self::currency( $row['currency'] ?? null ),
				SalesAmount::format( self::amount( $row['net_sales'] ?? null ) ),
				self::integer( $row['units'] ?? null ),
				self::integer( $row['orders'] ?? null ),
				0 === self::integer( $row['missing_vat_rows'] ?? null ) ? SalesAmount::format( self::amount( $row['net_sales_incl_vat'] ?? null ) ) : null
			);
		}

		return $points;
	}

	/**
	 * Sums per parent product and currency.
	 *
	 * @phpstan-return list<SalesProductTotals>
	 *
	 * @param int               $creator_id Creator id.
	 * @param DateTimeImmutable $from       Start (UTC, inclusive).
	 * @param DateTimeImmutable $to         End (UTC, exclusive).
	 * @return SalesProductTotals[]
	 * @throws DatabaseException When the query fails or a row does not match the schema.
	 */
	public function products( int $creator_id, DateTimeImmutable $from, DateTimeImmutable $to ): array {
		if ( ! self::readable( $creator_id, $from, $to ) ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read model of the ledger; caching is a separate layer (ADR-040 pkt. 10).
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant SQL fragments with placeholders only (the exclusion join, ADR-068): the tables, the creator and interval, then the sales table.
			$wpdb->prepare(
				'SELECT g.currency, g.product_id, g.net_sales, g.net_sales_incl_vat, g.missing_vat_rows, g.units, s.item_name
				FROM (
					SELECT r.currency, r.product_id, SUM( r.net_amount ) AS net_sales,
						COALESCE( SUM( CASE WHEN ( t.event_key IS NOT NULL AND t.currency = r.currency ) THEN r.net_amount + t.tax_amount END ), 0 ) AS net_sales_incl_vat,
						SUM( CASE WHEN ( t.event_key IS NOT NULL AND t.currency = r.currency ) THEN 0 ELSE 1 END ) AS missing_vat_rows,
						SUM( r.quantity ) AS units, MAX( r.id ) AS last_id
					FROM %i r LEFT JOIN %i t ON t.event_key = r.event_key' . OrderExclusionRepository::JOIN . '
					WHERE ' . OrderExclusionRepository::COUNTS . ' AND r.creator_id = %d AND r.occurred_at >= %s AND r.occurred_at < %s
					GROUP BY r.currency, r.product_id
				) g
				INNER JOIN %i s ON s.id = g.last_id
				ORDER BY g.currency ASC, g.net_sales DESC, g.product_id ASC',
				$table,
				$this->tax_table(),
				$this->exclusions_table(),
				$creator_id,
				self::utc( $from ),
				self::utc( $to ),
				$table
			),
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			ARRAY_A
		);

		$products = array();

		foreach ( $this->checked( $rows ) as $row ) {
			$product_id = self::integer( $row['product_id'] ?? null );

			if ( $product_id < 0 ) {
				throw new DatabaseException( 'A product row does not match the schema.' );
			}

			$products[] = new SalesProductTotals(
				self::currency( $row['currency'] ?? null ),
				$product_id,
				SalesAmount::format( self::amount( $row['net_sales'] ?? null ) ),
				self::integer( $row['units'] ?? null ),
				is_string( $row['item_name'] ?? null ) ? $row['item_name'] : '',
				0 === self::integer( $row['missing_vat_rows'] ?? null ) ? SalesAmount::format( self::amount( $row['net_sales_incl_vat'] ?? null ) ) : null
			);
		}

		return $products;
	}

	/**
	 * Whether there is anything to read: a creator and a non-empty interval.
	 *
	 * @param int               $creator_id Creator id.
	 * @param DateTimeImmutable $from       Start.
	 * @param DateTimeImmutable $to         End.
	 */
	private static function readable( int $creator_id, DateTimeImmutable $from, DateTimeImmutable $to ): bool {
		return $creator_id > 0 && $from < $to;
	}

	/**
	 * The result rows, or an exception after a failed query.
	 *
	 * @param mixed $rows Result of get_results().
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException When the query failed.
	 */
	private function checked( mixed $rows ): array {
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the sales statistics.' );
		}

		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * A decimal from the database in 1/10000 units.
	 *
	 * @param mixed $value Value.
	 * @throws DatabaseException When it is not a plain decimal.
	 */
	private static function amount( mixed $value ): int {
		try {
			return SalesAmount::parse( is_string( $value ) || is_int( $value ) ? (string) $value : '' );
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new DatabaseException( 'A sales amount does not match the schema.' );
		}
	}

	/**
	 * Checked sum of two amounts.
	 *
	 * @param int $a Units.
	 * @param int $b Units.
	 * @throws DatabaseException When the sum is out of range.
	 */
	private static function sum( int $a, int $b ): int {
		try {
			return SalesAmount::add( $a, $b );
		} catch ( OverflowException ) {
			throw new DatabaseException( 'A sales sum is out of range.' );
		}
	}

	/**
	 * An integer from the database.
	 *
	 * @param mixed $value Value.
	 * @throws DatabaseException When it is not an integer.
	 */
	private static function integer( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^-?\d{1,18}$/', $value ) ) {
			throw new DatabaseException( 'A sales count does not match the schema.' );
		}

		return (int) $value;
	}

	/**
	 * A currency code from the database.
	 *
	 * @param mixed $value Value.
	 * @throws DatabaseException When it is not three upper-case letters.
	 */
	private static function currency( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Z]{3}$/', $value ) ) {
			throw new DatabaseException( 'A currency does not match the schema.' );
		}

		return $value;
	}

	/**
	 * A UTC timestamp as the ledger stores it.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( SalesPeriods::SQL_FORMAT );
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::SALES );
	}

	/**
	 * Full name of the stored tax table.
	 */
	private function tax_table(): string {
		return Tables::name( $this->wpdb, Tables::SALES_TAX );
	}

	/**
	 * Full name of the order exclusions table (M2, ADR-068).
	 */
	private function exclusions_table(): string {
		return OrderExclusionRepository::table_name( $this->wpdb );
	}
}
