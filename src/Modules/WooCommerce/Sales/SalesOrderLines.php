<?php
/**
 * A creator's order lines from the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Http\Pagination;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Read model of `GET /me/orders` (ADR-039 P1, P2, P9): the creator's order
 * lines, a page at a time, from `merchboard_sales` and
 * `merchboard_sales_cycles` alone. No WooCommerce table and no WooCommerce
 * function is used, so the result is the same under HPOS and legacy order
 * storage; the caller enriches the page (status, order number, product name).
 *
 * Selection: the ledger rows whose `creator_id` is the creator (the id comes
 * from the caller, i.e. CreatorContext on the server), grouped by
 * `order_item_id` (globally unique, so one group per `order_id` +
 * `order_item_id`). Every row of a line carries the same creator snapshot
 * (ADR-038 B2/B3). A line is listed when its first sale row exists: the row
 * `s:{order_item_id}:{cycle}` of the lowest cycle with a sale, which also
 * gives the date and the product snapshot. count() and page() share this
 * selection (LINES).
 *
 * Per line (P2):
 * - quantity: the sum of all its rows' `quantity` (the ledger balance).
 * - refunded: a `refund` row `r:{order_item_id}:{refund_id}` without the
 *   correction `c-rd:{order_item_id}:{refund_id}`. `c-ro` and `c-rdo`
 *   (closed_cycle_refund[_deleted]) do not matter.
 * - counted (per order): the order has an open cycle, i.e. more `open` than
 *   `close` rows in `merchboard_sales_cycles`. Why it does not count is not
 *   derived.
 *
 * Order: the first sale row's `occurred_at` descending, then
 * `order_item_id` descending.
 */
final class SalesOrderLines {

	/**
	 * The creator's lines with their first sale row. Placeholders: sales
	 * table, creator id, sales table.
	 */
	private const LINES = "FROM (
			SELECT order_item_id, MAX(order_id) AS order_id, SUM(quantity) AS quantity,
				MIN(CASE WHEN type = 'sale' THEN cycle END) AS first_cycle
			FROM %i
			WHERE creator_id = %d
			GROUP BY order_item_id
		) l
		INNER JOIN %i s ON s.event_key = CONCAT('s:', l.order_item_id, ':', l.first_cycle)";

	/**
	 * Columns of page(): the line, its first sale row and the refund marker
	 * (a `refund` row without its `c-rd` correction). Placeholders: sales
	 * table, sales table.
	 */
	private const PAGE_COLUMNS = "SELECT l.order_id, l.order_item_id, l.quantity, s.occurred_at, s.product_id, s.variation_id, s.item_name,
		EXISTS (
			SELECT 1 FROM %i r
			WHERE r.order_id = l.order_id AND r.order_item_id = l.order_item_id AND r.type = 'refund'
			AND NOT EXISTS ( SELECT 1 FROM %i d WHERE d.event_key = CONCAT( 'c-rd:', r.order_item_id, ':', r.refund_id ) )
		) AS refunded ";

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Number of the creator's order lines.
	 *
	 * @param int $creator_id Creator id.
	 * @throws DatabaseException When the query fails.
	 */
	public function count( int $creator_id ): int {
		if ( $creator_id < 1 ) {
			return 0;
		}

		$wpdb  = $this->wpdb;
		$sales = $this->table( Tables::SALES );
		$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read model of the ledger; no caching in phase 19 (ADR-039 P12).
			$wpdb->prepare( 'SELECT COUNT(*) ' . self::LINES, $sales, $creator_id, $sales ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- LINES is a constant with placeholders only.
		);

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not count the creator\'s order lines.' );
		}

		return is_numeric( $total ) ? (int) $total : 0;
	}

	/**
	 * The creator's order lines on the requested page.
	 *
	 * @param int        $creator_id Creator id.
	 * @param Pagination $pagination Requested page.
	 * @return list<SalesOrderLine>
	 * @throws DatabaseException When a query fails or a row does not match the schema.
	 */
	public function page( int $creator_id, Pagination $pagination ): array {
		if ( $creator_id < 1 ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$sales = $this->table( Tables::SALES );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read model of the ledger; no caching in phase 19 (ADR-039 P12).
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- PAGE_COLUMNS' two placeholders, LINES' three, then LIMIT and OFFSET.
				self::PAGE_COLUMNS . self::LINES . ' ORDER BY s.occurred_at DESC, l.order_item_id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constants with placeholders only.
				$sales,
				$sales,
				$sales,
				$creator_id,
				$sales,
				$pagination->per_page,
				$pagination->offset()
			),
			ARRAY_A
		);

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the creator\'s order lines.' );
		}

		if ( array() === $rows ) {
			return array();
		}

		$counted = $this->counted( array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['order_id'], $rows ) ) ) );
		$lines   = array();

		foreach ( $rows as $row ) {
			$lines[] = self::line( $row, $counted );
		}

		return $lines;
	}

	/**
	 * Whether each order has an open cycle.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int[] $order_ids Order ids.
	 * @return array<int, bool> By order id; orders without cycle rows are missing.
	 * @throws DatabaseException When the query fails.
	 */
	private function counted( array $order_ids ): array {
		$wpdb         = $this->wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read model of the ledger; no caching in phase 19 (ADR-039 P12).
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The table, then one %d per order id.
				"SELECT order_id, SUM( event = 'open' ) - SUM( event = 'close' ) AS open_cycles FROM %i WHERE order_id IN ( {$placeholders} ) GROUP BY order_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %d placeholders, one per id.
				$this->table( Tables::SALES_CYCLES ),
				...$order_ids
			),
			ARRAY_A
		);

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the cycles of the creator\'s orders.' );
		}

		$counted = array();

		foreach ( $rows as $row ) {
			if ( ! is_numeric( $row['order_id'] ?? null ) || ! is_numeric( $row['open_cycles'] ?? null ) ) {
				throw new DatabaseException( 'A cycle row does not match the schema.' );
			}

			$counted[ (int) $row['order_id'] ] = (int) $row['open_cycles'] > 0;
		}

		return $counted;
	}

	/**
	 * An order line from a result row, validating every column.
	 *
	 * @param array<string, mixed> $row     Row.
	 * @param array<int, bool>     $counted Open cycle by order id.
	 * @throws DatabaseException When the row does not match the schema.
	 */
	private static function line( array $row, array $counted ): SalesOrderLine {
		foreach ( array( 'order_id', 'order_item_id', 'quantity', 'product_id', 'variation_id', 'refunded' ) as $column ) {
			if ( ! is_numeric( $row[ $column ] ?? null ) ) {
				throw new DatabaseException( 'An order line does not match the schema.' );
			}
		}

		$occurred_at = $row['occurred_at'] ?? null;

		if ( ! is_string( $occurred_at ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $occurred_at ) ) {
			throw new DatabaseException( 'An order line does not match the schema.' );
		}

		try {
			$date = new DateTimeImmutable( $occurred_at, new DateTimeZone( 'UTC' ) );
		} catch ( Exception ) {
			throw new DatabaseException( 'An order line does not match the schema.' );
		}

		$order_id = (int) $row['order_id'];

		return new SalesOrderLine(
			$order_id,
			(int) $row['order_item_id'],
			$date,
			(int) $row['quantity'],
			1 === (int) $row['refunded'],
			$counted[ $order_id ] ?? false,
			(int) $row['product_id'],
			(int) $row['variation_id'],
			is_string( $row['item_name'] ?? null ) ? $row['item_name'] : ''
		);
	}

	/**
	 * Full table name.
	 *
	 * @param string $table Tables constant.
	 */
	private function table( string $table ): string {
		return Tables::name( $this->wpdb, $table );
	}
}
