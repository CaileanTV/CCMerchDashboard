<?php
/**
 * Unique orders of the sales ledger for the admin order history.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitSummary;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Read model of the admin order history (ADR-051): the ledger grouped by
 * `order_id`, so every order appears once however many events and lines it
 * has, a page at a time, with its event, line, tax and profit counts.
 * Merch Dashboard's own tables only (`merchboard_sales`, joined by key with
 * `merchboard_sales_line_pricing` and `merchboard_sales_tax`); no WooCommerce
 * table, so HPOS and legacy storage give the same result. Nothing is
 * written.
 *
 * A row's profit is known exactly under LedgerCreatorProfits::KNOWN, the
 * condition of RealizedProfit (ADR-049/050), so the status here is the one
 * the creator endpoints use. Filters: order id, creator (only that
 * creator's rows of each order) and profit status (complete: no unknown row;
 * incomplete: both; unknown: no known row). One count and one page query.
 */
final class OrderHistory {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Number of orders matching the filter.
	 *
	 * @param OrderHistoryFilter $filter Filter.
	 * @throws DatabaseException When the query fails.
	 */
	public function count( OrderHistoryFilter $filter ): int {
		[ $sql, $args ] = $this->grouped( $filter );
		$wpdb           = $this->wpdb;
		$query          = $wpdb->prepare( 'SELECT COUNT(*) FROM ( ' . $sql . ' ) o', ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant SQL with placeholders only.
		$total          = is_string( $query ) ? $wpdb->get_var( $query ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; admin read model of the ledger.

		if ( '' !== $wpdb->last_error || ! is_numeric( $total ) ) {
			throw new DatabaseException( 'Could not count the order history.' );
		}

		return (int) $total;
	}

	/**
	 * The orders on the requested page, newest first event first.
	 *
	 * @param OrderHistoryFilter $filter     Filter.
	 * @param Pagination         $pagination Page.
	 * @return list<OrderHistoryRow>
	 * @throws DatabaseException When the query fails or a row does not match the schema.
	 */
	public function page( OrderHistoryFilter $filter, Pagination $pagination ): array {
		[ $sql, $args ] = $this->grouped( $filter );
		$wpdb           = $this->wpdb;
		$query          = $wpdb->prepare( $sql . ' ORDER BY first_at DESC, r.order_id DESC LIMIT %d OFFSET %d', ...array_merge( $args, array( $pagination->per_page, $pagination->offset() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant SQL with placeholders only.
		$rows           = is_string( $query ) ? $wpdb->get_results( $query, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; admin read model of the ledger.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the order history.' );
		}

		return array_map( array( self::class, 'row' ), $rows );
	}

	/**
	 * The grouped query (without order and limit) and its arguments.
	 *
	 * @param OrderHistoryFilter $filter Filter.
	 * @return array{0: literal-string, 1: list<int|string>}
	 */
	private function grouped( OrderHistoryFilter $filter ): array {
		$wpdb  = $this->wpdb;
		$where = '1 = 1';
		$args  = array(
			Tables::name( $wpdb, Tables::SALES ),
			Tables::name( $wpdb, Tables::SALES_LINE_PRICING ),
			Tables::name( $wpdb, Tables::SALES_TAX ),
		);

		if ( null !== $filter->order_id ) {
			$where .= ' AND r.order_id = %d';
			$args[] = $filter->order_id;
		}

		if ( null !== $filter->creator_id ) {
			$where .= ' AND r.creator_id = %d';
			$args[] = $filter->creator_id;
		}

		$having = match ( $filter->status ) {
			OrderHistoryFilter::WITH_PROFIT_DATA => ' HAVING known_rows > 0',
			ProfitSummary::COMPLETE   => ' HAVING unknown_rows = 0',
			ProfitSummary::INCOMPLETE => ' HAVING unknown_rows > 0 AND known_rows > 0',
			ProfitSummary::UNKNOWN    => ' HAVING known_rows = 0',
			default                   => '',
		};

		$sql = 'SELECT r.order_id, MIN( r.occurred_at ) AS first_at, MAX( r.occurred_at ) AS last_at, MIN( r.currency ) AS currency,
				GROUP_CONCAT( DISTINCT r.creator_id ORDER BY r.creator_id ) AS creator_ids,
				COUNT(*) AS events, COUNT( DISTINCT r.order_item_id ) AS line_count,
				SUM( CASE WHEN r.type = \'sale\' THEN 1 ELSE 0 END ) AS sales,
				SUM( r.net_amount ) AS net_amount,
				SUM( CASE WHEN t.event_key IS NOT NULL THEN 1 ELSE 0 END ) AS taxed_rows,
				SUM( CASE WHEN ' . LedgerCreatorProfits::KNOWN . ' THEN ' . LedgerCreatorProfits::PROFIT . ' ELSE 0 END ) AS known_profit,
				SUM( CASE WHEN ' . LedgerCreatorProfits::KNOWN . ' THEN 1 ELSE 0 END ) AS known_rows,
				SUM( CASE WHEN ' . LedgerCreatorProfits::KNOWN . ' THEN 0 ELSE 1 END ) AS unknown_rows
			' . LedgerCreatorProfits::FROM . ' WHERE ' . $where . ' GROUP BY r.order_id' . $having;

		return array( $sql, $args );
	}

	/**
	 * An order from a result row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row does not match the schema.
	 */
	private static function row( array $row ): OrderHistoryRow {
		try {
			foreach ( array( 'order_id', 'events', 'line_count', 'sales', 'taxed_rows', 'known_rows', 'unknown_rows' ) as $column ) {
				if ( ! is_numeric( $row[ $column ] ?? null ) ) {
					throw new DatabaseException( 'An order history row does not match the schema.' );
				}
			}

			$utc   = new DateTimeZone( 'UTC' );
			$known = (int) $row['known_rows'];

			return new OrderHistoryRow(
				(int) $row['order_id'],
				new DateTimeImmutable( (string) $row['first_at'], $utc ),
				new DateTimeImmutable( (string) $row['last_at'], $utc ),
				(string) $row['currency'],
				array_values( array_map( 'intval', array_filter( explode( ',', (string) ( $row['creator_ids'] ?? '' ) ), 'is_numeric' ) ) ),
				(int) $row['events'],
				(int) $row['line_count'],
				(int) $row['sales'],
				SalesAmount::format( SalesAmount::parse( (string) $row['net_amount'] ) ),
				(int) $row['taxed_rows'],
				new ProfitSummary(
					(string) $row['currency'],
					0 === $known ? 0 : SalesAmount::parse( (string) $row['known_profit'] ),
					$known,
					(int) $row['unknown_rows']
				)
			);
		} catch ( DatabaseException $e ) {
			throw $e;
		} catch ( Throwable ) {
			throw new DatabaseException( 'An order history row does not match the schema.' );
		}
	}
}
