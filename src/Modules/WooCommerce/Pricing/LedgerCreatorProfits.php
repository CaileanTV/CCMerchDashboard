<?php
/**
 * Aggregated realized profit from the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * CreatorProfits on Merch Dashboard's own tables only (ADR-050):
 * `merchboard_sales` joined by key with `merchboard_sales_line_pricing`
 * (order item) and `merchboard_sales_tax` (event key). No WooCommerce table or
 * function, so HPOS and legacy storage give the same result, and no N+1: one
 * query per call, whatever the number of rows.
 *
 * The row profit is ADR-049's formula, evaluated per row in SQL so that the
 * sums scale like the sales statistics (ADR-040), and then summed per group:
 *
 *   ROUND( (net_amount + tax_amount) × share_bps × 0.0001, price_decimals )
 *
 * DECIMAL arithmetic is exact and ROUND() on exact values rounds half away
 * from zero, the same as CreatorShare::profit() (PricingMath::share()); the
 * decimals are constants per CASE branch, and the division by 10 000 is a
 * multiplication by 0.0001, so no session setting (div_precision_increment)
 * can shorten the scale. Integration tests check that every sum equals the
 * sum of RealizedProfit::for_entry() over the same rows.
 *
 * A row is known exactly when RealizedProfit would give a profit: a snapshot
 * with a share (ADR-049) that matches the row's order, creator, currency,
 * product and variation, and the row's stored tax in the row's currency. The
 * known rows are summed; the others are only counted (ProfitSummary).
 *
 * A designer product's row (snapshot source `designer`, ADR-080 pkt. 8)
 * reads the profit stored with its tax instead (SalesRepository computes it
 * once with DesignerRowAmounts when the row is written), so the base price
 * rule needs no division in SQL. The snapshot is captured when the order line
 * is created, before any ledger row of it, so every designer row has it.
 */
final class LedgerCreatorProfits implements CreatorProfits {

	/**
	 * The ledger rows with their snapshot and tax. Placeholders: sales,
	 * snapshot and tax table.
	 */
	public const FROM = 'FROM %i r
		LEFT JOIN %i p ON p.order_item_id = r.order_item_id
		LEFT JOIN %i t ON t.event_key = r.event_key';

	/**
	 * When a row's profit is known (RealizedProfit::for_entry()).
	 */
	public const KNOWN = "( p.share_bps IS NOT NULL AND p.share_bps <= 10000
		AND ( p.share_source IN ( 'creator', 'product' ) OR ( p.share_source = 'designer' AND t.designer_profit IS NOT NULL ) )
		AND p.price_decimals IS NOT NULL AND p.price_decimals <= 4
		AND p.order_id = r.order_id AND p.creator_id = r.creator_id AND p.currency = r.currency
		AND p.product_id = r.product_id AND p.variation_id = r.variation_id
		AND t.event_key IS NOT NULL AND t.currency = r.currency )";

	/**
	 * A row's profit (ADR-049), rounded to the snapshot's store precision;
	 * the stored profit of a designer product's row (ADR-080 pkt. 8).
	 */
	public const PROFIT = "CASE WHEN p.share_source = 'designer' THEN t.designer_profit ELSE CASE p.price_decimals
		WHEN 0 THEN ROUND( ( r.net_amount + t.tax_amount ) * p.share_bps * 0.0001, 0 )
		WHEN 1 THEN ROUND( ( r.net_amount + t.tax_amount ) * p.share_bps * 0.0001, 1 )
		WHEN 2 THEN ROUND( ( r.net_amount + t.tax_amount ) * p.share_bps * 0.0001, 2 )
		WHEN 3 THEN ROUND( ( r.net_amount + t.tax_amount ) * p.share_bps * 0.0001, 3 )
		WHEN 4 THEN ROUND( ( r.net_amount + t.tax_amount ) * p.share_bps * 0.0001, 4 )
	END END";

	/**
	 * Most ids per query (the REST pages have at most 100).
	 */
	private const MAX_IDS = 1000;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Profit per parent product, all time, per currency.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int   $creator_id  Creator id.
	 * @param int[] $product_ids Parent product ids.
	 * @return array<int, list<ProfitSummary>>
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function by_product( int $creator_id, array $product_ids ): array {
		$ids = self::ids( $product_ids );

		if ( $creator_id < 1 || array() === $ids ) {
			return array();
		}

		$result = array();

		foreach ( $this->groups( 'r.product_id', 'r.creator_id = %d AND r.product_id IN (' . self::marks( $ids ) . ')', array( $creator_id, ...$ids ) ) as $row ) {
			$result[ (int) $row['group_id'] ][] = $row['profit'];
		}

		return $result;
	}

	/**
	 * Profit of every order line of the creator in the given orders.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int   $creator_id Creator id.
	 * @param int[] $order_ids  Order ids.
	 * @return array<int, array{order_id: int, profit: ProfitSummary}>
	 * @throws DatabaseException When the ledger cannot be read or a line mixes currencies.
	 */
	public function by_order_line( int $creator_id, array $order_ids ): array {
		$ids = self::ids( $order_ids );

		if ( $creator_id < 1 || array() === $ids ) {
			return array();
		}

		$result = array();

		foreach ( $this->groups( 'r.order_item_id', 'r.creator_id = %d AND r.order_id IN (' . self::marks( $ids ) . ')', array( $creator_id, ...$ids ), 'r.order_id' ) as $row ) {
			$item = (int) $row['group_id'];

			if ( isset( $result[ $item ] ) ) {
				// The rows of one order line are always in the order's currency.
				throw new DatabaseException( 'An order line has ledger rows in more than one currency.' );
			}

			$result[ $item ] = array(
				'order_id' => (int) $row['order_id'],
				'profit'   => $row['profit'],
			);
		}

		return $result;
	}

	/**
	 * Profit of the rows in [from, to), per currency.
	 *
	 * @param int               $creator_id Creator id.
	 * @param DateTimeImmutable $from       Start (inclusive).
	 * @param DateTimeImmutable $to         End (exclusive).
	 * @return array<string, ProfitSummary>
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function totals( int $creator_id, DateTimeImmutable $from, DateTimeImmutable $to ): array {
		if ( $creator_id < 1 || $from >= $to ) {
			return array();
		}

		$result = array();

		foreach ( $this->groups( 'r.creator_id', 'r.creator_id = %d AND r.occurred_at >= %s AND r.occurred_at < %s', array( $creator_id, self::utc( $from ), self::utc( $to ) ) ) as $row ) {
			$result[ $row['profit']->currency ] = $row['profit'];
		}

		return $result;
	}

	/**
	 * Runs the aggregation for a grouping and a filter.
	 *
	 * @phpstan-param literal-string $group
	 * @phpstan-param literal-string $where
	 * @phpstan-param list<int|string> $args
	 * @phpstan-param literal-string|null $extra
	 *
	 * @param string      $group  Constant group column.
	 * @param string      $where  Constant filter with placeholders.
	 * @param array       $args   Values of the filter's placeholders.
	 * @param string|null $extra  Constant extra column (grouped as well), returned as `order_id`.
	 * @return list<array{group_id: string, order_id: string, profit: ProfitSummary}>
	 * @throws DatabaseException When the query fails or a row does not match the schema.
	 */
	private function groups( string $group, string $where, array $args, ?string $extra = null ): array {
		$wpdb    = $this->wpdb;
		$columns = $group . ' AS group_id, ' . ( null === $extra ? '0' : $extra ) . ' AS order_id, r.currency,
			SUM( CASE WHEN ' . self::KNOWN . ' THEN ' . self::PROFIT . ' ELSE 0 END ) AS known_profit,
			SUM( CASE WHEN ' . self::KNOWN . ' THEN 1 ELSE 0 END ) AS known_rows,
			SUM( CASE WHEN ' . self::KNOWN . ' THEN 0 ELSE 1 END ) AS unknown_rows';
		$by      = $group . ( null === $extra ? '' : ', ' . $extra ) . ', r.currency';
		$sql     = $wpdb->prepare(
			"SELECT {$columns} " . self::FROM . OrderExclusionRepository::JOIN . ' WHERE ' . OrderExclusionRepository::COUNTS . " AND {$where} GROUP BY {$by} ORDER BY {$by}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant SQL fragments with placeholders only; the table names, then the filter's values.
			Tables::name( $wpdb, Tables::SALES ),
			Tables::name( $wpdb, Tables::SALES_LINE_PRICING ),
			Tables::name( $wpdb, Tables::SALES_TAX ),
			OrderExclusionRepository::table_name( $wpdb ),
			...$args
		);
		$rows    = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; read model of the ledger, cached by its callers where they cache (ADR-040 pkt. 10).

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the creator\'s profit.' );
		}

		$result = array();

		foreach ( $rows as $row ) {
			try {
				foreach ( array( 'group_id', 'order_id', 'known_rows', 'unknown_rows' ) as $column ) {
					if ( ! is_numeric( $row[ $column ] ?? null ) ) {
						throw new DatabaseException( 'A profit row does not match the schema.' );
					}
				}

				$known    = (int) $row['known_rows'];
				$result[] = array(
					'group_id' => (string) $row['group_id'],
					'order_id' => (string) $row['order_id'],
					'profit'   => new ProfitSummary(
						(string) ( $row['currency'] ?? '' ),
						0 === $known ? 0 : SalesAmount::parse( is_string( $row['known_profit'] ?? null ) ? $row['known_profit'] : '' ),
						$known,
						(int) $row['unknown_rows']
					),
				);
			} catch ( DatabaseException $e ) {
				throw $e;
			} catch ( Throwable ) {
				throw new DatabaseException( 'A profit row does not match the schema.' );
			}
		}

		return $result;
	}

	/**
	 * Positive, unique ids (at most MAX_IDS).
	 *
	 * @param int[] $ids Ids.
	 * @return list<int>
	 */
	private static function ids( array $ids ): array {
		return array_slice( array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) ), 0, self::MAX_IDS );
	}

	/**
	 * One %d placeholder per id.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @param int[] $ids Ids.
	 * @return literal-string
	 */
	private static function marks( array $ids ): string {
		return implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	}

	/**
	 * A time as stored in `occurred_at` (UTC).
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
