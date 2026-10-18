<?php
/**
 * Storage of the order exclusions and the shared ledger filter.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\WooCommerce\Sales\SalesConnectionChangedException;
use ValueError;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_order_exclusions` (ADR-068, M2): one decision per order and
 * creator whose own discount code the order used, inserted once by the sales
 * ledger synchronization (on the order lock's connection, inside its
 * transaction) and never deleted automatically.
 *
 * The rule which ledger rows an exclusion takes out is in one place, the
 * constants below, used by every read model of the ledger (sales
 * statistics, profit, payouts and settlement documents, the creator's order
 * lines and the admin order history), so they cannot apply it differently:
 * a row counts unless its order is listed with a decision for the row's
 * creator. Other creators' rows of the same order count normally. Settled
 * rows keep counting in the payout balance (LedgerCreatorPayouts), because
 * issued documents are never changed (K2).
 */
final class OrderExclusionRepository {

	/**
	 * Joins the decision for the row's order and creator to ledger rows
	 * aliased `r` as `x`. Placeholder: the exclusions table.
	 */
	public const JOIN = " LEFT JOIN %i x ON x.order_id = r.order_id AND x.creator_id = r.creator_id AND x.status IN ( 'excluded', 'open' )";

	/**
	 * With JOIN: the row is not excluded.
	 */
	public const COUNTS = 'x.order_id IS NULL';

	/**
	 * For a subquery on the sales table aliased `sales`, without the join: the
	 * row is not excluded. Placeholder: the exclusions table.
	 */
	public const SALES_NOT_EXCLUDED = "NOT EXISTS ( SELECT 1 FROM %i xo WHERE xo.order_id = sales.order_id AND xo.creator_id = sales.creator_id AND xo.status IN ( 'excluded', 'open' ) )";

	private const COLUMNS = 'order_id, status, code_id, creator_id, code, coupon_deviation, order_created_at, assessed_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The full name of the exclusions table, for JOIN and SALES_NOT_EXCLUDED.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public static function table_name( wpdb $wpdb ): string {
		return Tables::name( $wpdb, Tables::ORDER_EXCLUSIONS );
	}

	/**
	 * The decisions for an order, by creator ascending.
	 *
	 * @param int $order_id WooCommerce order.
	 * @return list<OrderExclusion>
	 * @throws DatabaseException When the read fails.
	 */
	public function for_order( int $order_id ): array {
		$wpdb = $this->wpdb;

		return $this->rows( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE order_id = %d ORDER BY creator_id', $this->table(), $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The decisions for the given orders, by order id, each by creator ascending.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int[] $order_ids Orders.
	 * @return array<int, list<OrderExclusion>>
	 * @throws DatabaseException When the read fails.
	 */
	public function for_orders( array $order_ids ): array {
		$ids = array_slice( array_values( array_unique( array_filter( $order_ids, static fn( int $id ): bool => $id > 0 ) ) ), 0, 1000 );

		if ( array() === $ids ) {
			return array();
		}

		$wpdb   = $this->wpdb;
		$marks  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$result = array();

		foreach ( $this->rows( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE order_id IN ( {$marks} ) ORDER BY order_id, creator_id", $this->table(), ...$ids ) ) as $exclusion ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant columns; $marks holds one %d per id.
			$result[ $exclusion->order_id ][] = $exclusion;
		}

		return $result;
	}

	/**
	 * Number of decisions, optionally of one status.
	 *
	 * @param OrderExclusionStatus|null $status Status filter.
	 * @throws DatabaseException When the read fails.
	 */
	public function count( ?OrderExclusionStatus $status = null ): int {
		$wpdb  = $this->wpdb;
		$total = null === $status
			? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table() ) )
			: $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $this->table(), $status->value ) );

		if ( '' !== $wpdb->last_error || ! is_numeric( $total ) ) {
			throw new DatabaseException( 'Could not count the order exclusions.' );
		}

		return (int) $total;
	}

	/**
	 * A page of decisions, newest first, optionally of one status.
	 *
	 * @param OrderExclusionStatus|null $status     Status filter.
	 * @param Pagination                $pagination Page.
	 * @return list<OrderExclusion>
	 * @throws DatabaseException When the read fails.
	 */
	public function page( ?OrderExclusionStatus $status, Pagination $pagination ): array {
		$wpdb = $this->wpdb;

		return $this->rows(
			null === $status
				? $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i ORDER BY assessed_at DESC, order_id DESC, creator_id ASC LIMIT %d OFFSET %d', $this->table(), $pagination->per_page, $pagination->offset() ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
				: $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE status = %s ORDER BY assessed_at DESC, order_id DESC, creator_id ASC LIMIT %d OFFSET %d', $this->table(), $status->value, $pagination->per_page, $pagination->offset() ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
		);
	}

	/**
	 * Inserts a decision on the connection that holds the order's ledger lock,
	 * inside the synchronization's transaction (like the ledger rows, so a
	 * reconnect writes nothing outside the transaction).
	 *
	 * @param OrderExclusion $exclusion     Decision.
	 * @param int            $connection_id Connection that holds the order's lock.
	 * @return bool True when inserted, false when the order already had a decision for the creator.
	 * @throws DatabaseException When the statement fails.
	 * @throws SalesConnectionChangedException When it ran in another session than the lock's.
	 */
	public function insert_on_connection( OrderExclusion $exclusion, int $connection_id ): bool {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare(
			'INSERT IGNORE INTO %i (order_id, status, reason, code_id, creator_id, code, coupon_deviation, order_created_at, assessed_at) SELECT %d, %s, %s, %d, %d, %s, %d, %s, %s FROM DUAL WHERE CONNECTION_ID() = %d',
			$this->table(),
			$exclusion->order_id,
			$exclusion->status->value,
			OrderExclusion::REASON_CREATOR_CODE,
			$exclusion->code_id,
			$exclusion->creator_id,
			$exclusion->code,
			$exclusion->coupon_deviation ? 1 : 0,
			self::utc( $exclusion->order_created_at ),
			self::utc( $exclusion->assessed_at ),
			$connection_id
		);

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the order exclusion.', array( 'order_id' => $exclusion->order_id ) );
		}

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the result of $wpdb->prepare(); a guarded write.

		if ( '' !== $wpdb->last_error || ! is_int( $result ) ) {
			throw new DatabaseException( 'Could not store the order exclusion.', array( 'order_id' => $exclusion->order_id ) );
		}

		if ( 1 === $result ) {
			return true;
		}

		// 0 rows: a duplicate (a decision for the creator exists) or a changed connection.
		foreach ( $this->for_order( $exclusion->order_id ) as $stored ) {
			if ( $stored->creator_id === $exclusion->creator_id ) {
				return false;
			}
		}

		throw new SalesConnectionChangedException( 'The database connection changed while the sales ledger lock was held.' );
	}

	/**
	 * Whether any of the creator's ledger rows of the order is in an issued
	 * settlement document, read LOCK IN SHARE MODE (the latest committed
	 * state, not the transaction's snapshot), inside the caller's transaction.
	 *
	 * @param int $order_id   WooCommerce order.
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the read fails.
	 */
	public function has_settled_rows( int $order_id, int $creator_id ): bool {
		$wpdb  = $this->wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT r.id FROM %i r INNER JOIN %i d ON d.event_key = r.event_key WHERE r.order_id = %d AND r.creator_id = %d LIMIT 1 LOCK IN SHARE MODE',
				Tables::name( $wpdb, Tables::SALES ),
				Tables::name( $wpdb, Tables::SETTLEMENT_ROWS ),
				$order_id,
				$creator_id
			)
		);

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the order\'s settled rows.', array( 'order_id' => $order_id ) );
		}

		return null !== $found;
	}

	/**
	 * Rows of a prepared query as decisions.
	 *
	 * @param string|null $sql Prepared query.
	 * @return list<OrderExclusion>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	private function rows( ?string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = null === $sql ? null : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; decisions are never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the order exclusions.' );
		}

		$result = array();
		$utc    = new DateTimeZone( 'UTC' );

		foreach ( $rows as $row ) {
			try {
				$result[] = new OrderExclusion(
					(int) $row['order_id'],
					OrderExclusionStatus::from( (string) $row['status'] ),
					(int) $row['code_id'],
					(int) $row['creator_id'],
					(string) $row['code'],
					'1' === (string) $row['coupon_deviation'],
					new DateTimeImmutable( (string) $row['order_created_at'], $utc ),
					new DateTimeImmutable( (string) $row['assessed_at'], $utc )
				);
			} catch ( Exception | ValueError ) {
				throw new DatabaseException( 'Invalid order exclusion.' );
			}
		}

		return $result;
	}

	/**
	 * A time as stored (UTC).
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return self::table_name( $this->wpdb );
	}
}
