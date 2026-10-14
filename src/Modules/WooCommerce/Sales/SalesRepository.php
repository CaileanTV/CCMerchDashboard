<?php
/**
 * Storage of the sales ledger and its cycles.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\SchemaVersionRow;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\WooCommerce\Payouts\G1\G1AssessmentRow;
use MerchBoard\Modules\WooCommerce\Payouts\G1\G1StateRow;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and appends `merchboard_sales` and `merchboard_sales_cycles`
 * (ADR-037, ADR-038, docs/DATABASE.md). Storage only: it never loads
 * WooCommerce data and never decides which rows a synchronization needs;
 * that is the planner passed to write() (the later SalesRecorder).
 *
 * Concurrency (ADR-038 pkt. 18), for one order:
 *
 * 1. with_order_lock() takes a named lock with GET_LOCK() and notes the
 *    connection that holds it; the caller may then read WooCommerce data
 *    outside any transaction.
 * 2. write() runs in the project's Transaction: it first takes R0 (E3.3, S2:
 *    the schema version row and, from the G1 schema on, the G1 activation
 *    row, both with a shared lock; a missing or invalid one fails closed),
 *    then reads the order's ledger
 *    state, hands it to the planner, appends the planned rows and, before
 *    COMMIT, checks that the connection and lock are still the ones taken in
 *    step 1 (wpdb silently reconnects after "server has gone away", which
 *    loses both the lock and the open transaction). Every INSERT is guarded
 *    by `WHERE CONNECTION_ID() = <lock connection>`, because wpdb repeats
 *    the statement in the new session in autocommit mode; there it writes
 *    nothing, so no row is committed outside the transaction.
 * 3. The lock is released in `finally`.
 *
 * Rows are only ever inserted, never updated; the one exception is
 * erase_order(), which deletes an erased test order's whole history
 * (ADR-052). Inserting a row
 * whose identity is already stored (`event_key`; order/cycle/event) is a
 * recorded no-op; any other database error is a DatabaseException.
 *
 * The repository does not use `@@in_transaction` (verified on MariaDB only;
 * MySQL 8.0 is OPEN, ADR-038).
 */
final class SalesRepository {

	/**
	 * Default seconds to wait for the order lock. Short on purpose: a
	 * synchronization of one order holds it briefly, and there is no queue of
	 * waiting requests (ADR-038).
	 */
	public const LOCK_TIMEOUT = 5;

	/**
	 * Prefix of the lock names.
	 */
	private const LOCK_PREFIX = 'merchboard_sales:';

	/**
	 * Schema version that created the G1 tables and the activation row.
	 */
	private const G1_SCHEMA_VERSION = 12;

	/**
	 * Most digits a valid stored schema version has (T5a).
	 */
	private const MAX_VERSION_DIGITS = 9;

	/**
	 * Columns of `merchboard_sales` read into a SalesEntry.
	 */
	private const ENTRY_COLUMNS = 'id, event_key, creator_id, order_id, order_item_id, cycle, refund_id, product_id, variation_id, item_name, type, correction_reason, quantity, net_amount, currency, occurred_at, created_at';

	/**
	 * Order whose lock this instance holds, or null.
	 *
	 * @var int|null
	 */
	private ?int $locked_order = null;

	/**
	 * Connection (CONNECTION_ID()) that took the lock.
	 *
	 * @var int
	 */
	private int $lock_connection = 0;

	/**
	 * Invalidation of the cached sales statistics.
	 *
	 * @var SalesCacheInvalidator
	 */
	private SalesCacheInvalidator $cache;

	/**
	 * Constructor.
	 *
	 * @param wpdb                       $wpdb         WordPress database object.
	 * @param Transaction                $transaction  The project's transaction helper.
	 * @param int                        $lock_timeout Seconds to wait for an order lock (≥ 0).
	 * @param SalesCacheInvalidator|null $cache        Invalidation after a committed write (default: SalesCache, so every repository invalidates).
	 * @throws InvalidArgumentException When the timeout is negative.
	 */
	public function __construct( private wpdb $wpdb, private Transaction $transaction, private int $lock_timeout = self::LOCK_TIMEOUT, ?SalesCacheInvalidator $cache = null ) {
		if ( $lock_timeout < 0 ) {
			throw new InvalidArgumentException( 'The lock timeout must not be negative.' );
		}

		$this->cache = $cache ?? new SalesCache();
	}

	/**
	 * Runs $work while holding the order's ledger lock, and releases it
	 * afterwards, also when $work throws (its exception is passed on).
	 *
	 * @template T
	 * @param int           $order_id WooCommerce order.
	 * @param callable(): T $work     Read, plan and write for the order.
	 * @return T Result of $work.
	 * @throws SalesLockTimeoutException When another connection holds the lock beyond the timeout.
	 * @throws DatabaseException When the lock cannot be taken, or a lock is already held by this instance.
	 */
	public function with_order_lock( int $order_id, callable $work ): mixed {
		self::assert_positive_id( $order_id, 'Order id' );

		if ( null !== $this->locked_order ) {
			throw new DatabaseException( 'Nested sales ledger locks are not supported.' );
		}

		$wpdb = $this->wpdb;
		$name = $this->lock_name( $order_id );
		// One statement, so the lock and the connection id come from the same session.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d) AS acquired, CONNECTION_ID() AS connection_id', $name, $this->lock_timeout ),
			ARRAY_A
		);

		if ( ! is_array( $row ) || ! is_numeric( $row['acquired'] ?? null ) || ! is_numeric( $row['connection_id'] ?? null ) ) {
			throw new DatabaseException( 'Could not take the sales ledger lock.', array( 'order_id' => $order_id ) );
		}

		if ( 1 !== (int) $row['acquired'] ) {
			throw new SalesLockTimeoutException( 'The sales ledger lock of the order is held by another request.' );
		}

		$this->locked_order    = $order_id;
		$this->lock_connection = (int) $row['connection_id'];

		try {
			return $work();
		} finally {
			// NULL (not held, e.g. after a reconnect) and 0 need no handling: the
			// lock of a lost connection was already released by the server.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$this->locked_order    = null;
			$this->lock_connection = 0;
		}
	}

	/**
	 * The creators of the order's ledger rows, ascending, read without a lock
	 * before the transaction (E3.2 step 2c); stable while the order's lock is
	 * held (G-3).
	 *
	 * @param int $order_id WooCommerce order.
	 * @return list<int>
	 * @throws DatabaseException When the order's lock is not held by this instance, or the read fails.
	 */
	public function creator_ids_for_order( int $order_id ): array {
		$this->assert_locked( $order_id );

		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT creator_id FROM %i WHERE order_id = %d ORDER BY creator_id', $this->table( Tables::SALES ), $order_id ) );

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the order\'s creators.', array( 'order_id' => $order_id ) );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * The connection that holds the order's lock, for connection-guarded
	 * writes of other data in the same transaction (E3.5(a)).
	 *
	 * @param int $order_id WooCommerce order.
	 * @throws DatabaseException When the order's lock is not held by this instance.
	 */
	public function lock_connection( int $order_id ): int {
		$this->assert_locked( $order_id );

		return $this->lock_connection;
	}

	/**
	 * Requires the order's lock to be held by this instance, on the current
	 * connection, right before COMMIT (E3.2 step 12).
	 *
	 * @param int $order_id WooCommerce order.
	 * @throws DatabaseException When the order's lock is not held by this instance.
	 * @throws SalesConnectionChangedException When the connection changed or the lock is lost.
	 */
	public function assert_order_lock( int $order_id ): void {
		$this->assert_locked( $order_id );
		$this->assert_same_connection( $order_id );
	}

	/**
	 * Reads the order's ledger state, lets $plan decide the new rows and
	 * appends them, all in one transaction. Must run inside with_order_lock()
	 * for the same order.
	 *
	 * The transaction first takes R0 (E3.3, S2), before any plain read: the
	 * schema version row, and from the G1 schema on the G1 activation row,
	 * both LOCK IN SHARE MODE. A missing version row, a stored version that is
	 * not a plain number of at most 9 digits, or (from the G1 schema on) a
	 * missing or unreadable activation row is a DatabaseException (db_error),
	 * so nothing is written. Isolation level, lock wait timeout and the
	 * handling of 1205 and 1213 are unchanged.
	 *
	 * When G1 is active in R0 and the plan is not null, the order is G1-covered
	 * when the earliest `occurred_at` of its cycle-1 sale rows (stored and
	 * planned) is on or after R0's cut-off. Only then is its assessment read
	 * (a plain read) and, when missing, inserted as `pending` after the ledger
	 * rows, in the same transaction (E3.3 steps 4b and 6). No such sale row
	 * means no date is guessed and nothing is assessed. A null plan (planner
	 * failure, backfill over history) writes nothing at all.
	 *
	 * After COMMIT, the creators of the rows actually inserted (not skipped
	 * duplicates, not cycle events, which the statistics do not read) get a
	 * new cache generation, once per creator and write (ADR-040 pkt. 10). A
	 * rolled-back write invalidates nothing. When the connection check after
	 * COMMIT fails, the outcome is unknown; the invalidation has already run,
	 * which at worst costs a recomputation.
	 *
	 * @param int                                                 $order_id WooCommerce order.
	 * @param callable(SalesLedgerState): (SalesLedgerWrite|null) $plan     Decides the new rows from the stored state; null writes nothing, not even `pending`.
	 * @throws DatabaseException When the lock is not held, R0 fails closed, or a read or write fails (rolled back).
	 * @throws SalesConnectionChangedException When the connection changed (rolled back; after COMMIT the outcome is unknown).
	 * @throws InvalidArgumentException When a planned row belongs to another order (rolled back).
	 * @throws Throwable Whatever $plan throws (rolled back).
	 */
	public function write( int $order_id, callable $plan ): SalesWriteResult {
		$this->assert_locked( $order_id );

		$creators = array();
		$result   = $this->transaction->run(
			function () use ( $order_id, $plan, &$creators ): SalesWriteResult {
				$cutoff  = $this->take_r0();
				$state   = $this->state( $order_id );
				$planned = $plan( $state );
				$write   = $planned ?? new SalesLedgerWrite();

				foreach ( array_merge( $write->entries, $write->cycle_events ) as $row ) {
					if ( $row->order_id !== $order_id ) {
						throw new InvalidArgumentException( 'A planned sales ledger row belongs to another order.' );
					}
				}

				// After the plan, before the ledger rows (E3.3 step 4b): a plain read, only for a covered order.
				$pending = null !== $planned
					&& null !== $cutoff
					&& self::covered( $state, $write, $cutoff )
					&& ! G1AssessmentRow::exists( $this->wpdb, $order_id );

				$inserted_entries = array();
				$skipped_entries  = array();
				$inserted_cycles  = 0;
				$skipped_cycles   = 0;

				foreach ( $write->cycle_events as $event ) {
					if ( $this->insert_cycle_event( $event ) ) {
						++$inserted_cycles;
					} else {
						++$skipped_cycles;
					}
				}

				foreach ( $write->entries as $entry ) {
					if ( $this->insert_entry( $entry ) ) {
						$inserted_entries[]             = $entry->event_key;
						$creators[ $entry->creator_id ] = $entry->creator_id;

						// The row's actual tax (ADR-048), only for a row written now.
						if ( isset( $write->taxes[ $entry->event_key ] ) ) {
							$this->insert_tax( $entry, $write->taxes[ $entry->event_key ] );
						}
					} else {
						$skipped_entries[] = $entry->event_key;
					}
				}

				// E3.3 step 6: the ledger rows first, then `pending`.
				if ( $pending ) {
					$this->insert_pending( $order_id );
				}

				// Before COMMIT: a changed connection means the transaction is gone.
				$this->assert_same_connection( $order_id );

				return new SalesWriteResult( $inserted_entries, $skipped_entries, $inserted_cycles, $skipped_cycles );
			}
		);

		// Committed (Transaction rejects nesting, so run() returning means COMMIT).
		if ( array() !== $creators ) {
			$this->cache->invalidate( array_values( $creators ) );
		}

		// After COMMIT: a reconnect during COMMIT leaves the outcome unknown.
		$this->assert_same_connection( $order_id );

		return $result;
	}

	/**
	 * Permanently deletes Merch Dashboard's stored history of one order: its
	 * ledger rows, cycle events, stored tax and profit snapshots, in one
	 * transaction under the order's lock (ADR-052, the only exception to the
	 * append-only rule; the caller decides whether the order may be erased).
	 * $audit runs in the same transaction after the deletes, so the deletion
	 * and its audit record are committed together or not at all. Every DELETE
	 * is guarded by the lock's connection, like the inserts, so a reconnect
	 * deletes nothing outside the transaction. After COMMIT, the creators of
	 * the deleted rows get a new cache generation.
	 *
	 * @phpstan-param callable(array{ledger_rows: int, cycle_events: int, tax_rows: int, snapshots: int}, list<int>): void $audit
	 *
	 * @param int      $order_id WooCommerce order.
	 * @param callable $audit    Records the deletion (deleted rows per table, creator ids).
	 * @return array{ledger_rows: int, cycle_events: int, tax_rows: int, snapshots: int, creator_ids: list<int>}
	 * @throws DatabaseException When the lock is not held or a statement fails (rolled back).
	 * @throws SalesConnectionChangedException When the connection changed (rolled back; after COMMIT the outcome is unknown).
	 * @throws SalesOrderSettledException When a settlement document settled a row of the order (D5; rolled back).
	 * @throws Throwable Whatever $audit throws (rolled back).
	 */
	public function erase_order( int $order_id, callable $audit ): array {
		$this->assert_locked( $order_id );

		$result = $this->transaction->run(
			function () use ( $order_id, $audit ): array {
				$wpdb     = $this->wpdb;
				$creators = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT creator_id FROM %i WHERE order_id = %d ORDER BY creator_id FOR UPDATE', $this->table( Tables::SALES ), $order_id ) );

				if ( '' !== $wpdb->last_error ) {
					throw new DatabaseException( 'Could not read the order\'s sales ledger.', array( 'order_id' => $order_id ) );
				}

				// D5 (ADR-060): never when a settlement document settled a row of the order. A
				// locking read, so a document being issued now is waited for and then seen.
				$settled = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d LOCK IN SHARE MODE', $this->table( Tables::SETTLEMENT_ROWS ), $order_id ) );

				if ( ! is_numeric( $settled ) ) {
					throw new DatabaseException( 'Could not read the order\'s settlement rows.', array( 'order_id' => $order_id ) );
				}

				if ( (int) $settled > 0 ) {
					throw new SalesOrderSettledException();
				}

				$counts = array(
					'ledger_rows'  => $this->guarded_delete( Tables::SALES, $order_id ),
					'cycle_events' => $this->guarded_delete( Tables::SALES_CYCLES, $order_id ),
					'tax_rows'     => $this->guarded_delete( Tables::SALES_TAX, $order_id ),
					'snapshots'    => $this->guarded_delete( Tables::SALES_LINE_PRICING, $order_id ),
				);
				$ids    = array_map( 'intval', $creators );

				// Before the audit record and before COMMIT: a changed connection means the transaction is gone.
				$this->assert_same_connection( $order_id );
				$audit( $counts, $ids );
				$this->assert_same_connection( $order_id );

				return $counts + array( 'creator_ids' => $ids );
			}
		);

		if ( array() !== $result['creator_ids'] ) {
			$this->cache->invalidate( $result['creator_ids'] );
		}

		$this->assert_same_connection( $order_id );

		return $result;
	}

	/**
	 * Deletes an order's rows of one ledger table on the lock's connection.
	 *
	 * @param string $table    Tables constant with an `order_id` column.
	 * @param int    $order_id WooCommerce order.
	 * @return int Deleted rows.
	 * @throws DatabaseException When the statement fails.
	 */
	private function guarded_delete( string $table, int $order_id ): int {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d AND CONNECTION_ID() = %d', $this->table( $table ), $order_id, $this->lock_connection );

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the deletion of the order\'s stored history.', array( 'order_id' => $order_id ) );
		}

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare().

		if ( false === $result || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not delete the order\'s stored history.', array( 'order_id' => $order_id ) );
		}

		return (int) $result;
	}

	/**
	 * Appends a ledger row. Requires the order's lock; use it through write(),
	 * so it is part of the order's transaction.
	 *
	 * @param SalesEntry $entry New row.
	 * @return bool True when inserted, false when its `event_key` was already stored.
	 * @throws DatabaseException When the lock is not held or the row cannot be stored for another reason.
	 * @throws InvalidArgumentException When the row is already stored (has an id).
	 */
	public function insert_entry( SalesEntry $entry ): bool {
		$this->assert_locked( $entry->order_id );

		if ( null !== $entry->id ) {
			throw new InvalidArgumentException( 'A stored sales entry cannot be inserted again.' );
		}

		$wpdb  = $this->wpdb;
		$table = $this->table( Tables::SALES );
		// correction_reason: NULLIF turns the empty string of a non-correction into NULL.
		$inserted = $this->guarded_insert(
			$wpdb->prepare(
				"INSERT INTO %i (event_key, creator_id, order_id, order_item_id, cycle, refund_id, product_id, variation_id, item_name, type, correction_reason, quantity, net_amount, currency, occurred_at, created_at) SELECT %s, %d, %d, %d, %d, %d, %d, %d, %s, %s, NULLIF(%s, ''), %d, %s, %s, %s, %s FROM DUAL WHERE CONNECTION_ID() = %d",
				$table,
				$entry->event_key,
				$entry->creator_id,
				$entry->order_id,
				$entry->order_item_id,
				$entry->cycle,
				$entry->refund_id,
				$entry->product_id,
				$entry->variation_id,
				$entry->item_name,
				$entry->type->value,
				$entry->correction_reason->value ?? '',
				$entry->quantity,
				$entry->net_amount,
				$entry->currency,
				self::to_utc( $entry->occurred_at ),
				current_time( 'mysql', true ),
				$this->lock_connection
			),
			$entry->order_id
		);

		if ( $inserted ) {
			return true;
		}

		// Locking read: sees rows committed by other connections after this
		// transaction's snapshot, so a lost race is recognised as a duplicate.
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE event_key = %s FOR UPDATE', $table, $entry->event_key ) ) ) {
			return false;
		}

		throw new DatabaseException( 'Could not store the sales entry.', array( 'order_id' => $entry->order_id ) );
	}

	/**
	 * Appends the actual tax of a ledger row (ADR-048), in the same session
	 * guard as the row. Requires the order's lock; used by write() right after
	 * the row was inserted. Append-only: an existing tax row is kept.
	 *
	 * @param SalesEntry $entry The row just inserted.
	 * @param int        $tax   Its signed tax in 1/10000 units.
	 * @throws DatabaseException When the lock is not held or the tax cannot be stored.
	 */
	private function insert_tax( SalesEntry $entry, int $tax ): void {
		$this->assert_locked( $entry->order_id );

		$wpdb     = $this->wpdb;
		$table    = $this->table( Tables::SALES_TAX );
		$inserted = $this->guarded_insert(
			$wpdb->prepare(
				'INSERT INTO %i (event_key, order_id, order_item_id, tax_amount, currency, created_at) SELECT %s, %d, %d, %s, %s, %s FROM DUAL WHERE CONNECTION_ID() = %d',
				$table,
				$entry->event_key,
				$entry->order_id,
				$entry->order_item_id,
				SalesAmount::format( $tax ),
				$entry->currency,
				current_time( 'mysql', true ),
				$this->lock_connection
			),
			$entry->order_id
		);

		if ( ! $inserted && null === $wpdb->get_var( $wpdb->prepare( 'SELECT event_key FROM %i WHERE event_key = %s FOR UPDATE', $table, $entry->event_key ) ) ) {
			throw new DatabaseException( 'Could not store the sales tax.', array( 'order_id' => $entry->order_id ) );
		}
	}

	/**
	 * Appends a cycle event. Requires the order's lock; use it through write().
	 *
	 * @param SalesCycleEvent $event New event.
	 * @return bool True when inserted, false when (order, cycle, event) was already stored.
	 * @throws DatabaseException When the lock is not held or the event cannot be stored for another reason.
	 * @throws InvalidArgumentException When the event is already stored (has an id).
	 */
	public function insert_cycle_event( SalesCycleEvent $event ): bool {
		$this->assert_locked( $event->order_id );

		if ( null !== $event->id ) {
			throw new InvalidArgumentException( 'A stored sales cycle event cannot be inserted again.' );
		}

		$wpdb     = $this->wpdb;
		$table    = $this->table( Tables::SALES_CYCLES );
		$inserted = $this->guarded_insert(
			$wpdb->prepare(
				'INSERT INTO %i (order_id, cycle, event, occurred_at, created_at) SELECT %d, %d, %s, %s, %s FROM DUAL WHERE CONNECTION_ID() = %d',
				$table,
				$event->order_id,
				$event->cycle,
				$event->event->value,
				self::to_utc( $event->occurred_at ),
				current_time( 'mysql', true ),
				$this->lock_connection
			),
			$event->order_id
		);

		if ( $inserted ) {
			return true;
		}

		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE order_id = %d AND cycle = %d AND event = %s FOR UPDATE', $table, $event->order_id, $event->cycle, $event->event->value ) ) ) {
			return false;
		}

		throw new DatabaseException( 'Could not store the sales cycle event.', array( 'order_id' => $event->order_id ) );
	}

	/**
	 * Ledger rows and cycle events of an order.
	 *
	 * @param int $order_id WooCommerce order.
	 * @throws DatabaseException When a query fails or a stored row is invalid (InvalidArgumentException for a non-positive id).
	 */
	public function state( int $order_id ): SalesLedgerState {
		return new SalesLedgerState( $order_id, $this->entries_for_order( $order_id ), $this->cycle_state( $order_id ), $this->taxes_for_order( $order_id ) );
	}

	/**
	 * Stored actual tax of an order's ledger rows by event key (ADR-048);
	 * rows without a tax row are missing (unknown).
	 *
	 * @param int $order_id WooCommerce order.
	 * @return array<string, int>
	 * @throws DatabaseException When the query fails or a stored amount is invalid.
	 */
	public function taxes_for_order( int $order_id ): array {
		self::assert_positive_id( $order_id, 'Order id' );

		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_key, tax_amount FROM %i WHERE order_id = %d', $this->table( Tables::SALES_TAX ), $order_id ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the sales tax.' );
		}

		$taxes = array();

		foreach ( $rows as $row ) {
			$amount = (string) ( $row['tax_amount'] ?? '' );

			if ( 1 !== preg_match( '/^-?\d{1,15}(?:\.\d{1,4})?$/', $amount ) ) {
				throw new DatabaseException( 'Invalid sales tax row.' );
			}

			$taxes[ (string) ( $row['event_key'] ?? '' ) ] = SalesAmount::parse( $amount );
		}

		return $taxes;
	}

	/**
	 * Ledger rows of an order, by id.
	 *
	 * @param int $order_id WooCommerce order.
	 * @return list<SalesEntry>
	 * @throws DatabaseException When the query fails or a stored row is invalid (InvalidArgumentException for a non-positive id).
	 */
	public function entries_for_order( int $order_id ): array {
		self::assert_positive_id( $order_id, 'Order id' );

		$wpdb = $this->wpdb;

		return $this->entries(
			$wpdb->prepare( 'SELECT ' . self::ENTRY_COLUMNS . ' FROM %i WHERE order_id = %d ORDER BY id ASC', $this->table( Tables::SALES ), $order_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column list is a constant.
		);
	}

	/**
	 * Ledger rows of an order line (sales, refunds and corrections of all
	 * cycles), by id. Takes the order too, so the lookup uses KEY(order_id).
	 *
	 * @param int $order_id      WooCommerce order.
	 * @param int $order_item_id Original order line.
	 * @return list<SalesEntry>
	 * @throws DatabaseException When the query fails or a stored row is invalid (InvalidArgumentException for a non-positive id).
	 */
	public function entries_for_order_item( int $order_id, int $order_item_id ): array {
		self::assert_positive_id( $order_id, 'Order id' );
		self::assert_positive_id( $order_item_id, 'Order item id' );

		$wpdb = $this->wpdb;

		return $this->entries(
			$wpdb->prepare( 'SELECT ' . self::ENTRY_COLUMNS . ' FROM %i WHERE order_id = %d AND order_item_id = %d ORDER BY id ASC', $this->table( Tables::SALES ), $order_id, $order_item_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column list is a constant.
		);
	}

	/**
	 * Ledger rows of a refund (the refund and its corrections), by id. Finds
	 * the order of a refund that no longer exists in WooCommerce.
	 *
	 * @param int $refund_id WooCommerce refund.
	 * @return list<SalesEntry>
	 * @throws DatabaseException When the query fails or a stored row is invalid (InvalidArgumentException for a non-positive id).
	 */
	public function entries_for_refund( int $refund_id ): array {
		self::assert_positive_id( $refund_id, 'Refund id' );

		$wpdb = $this->wpdb;

		return $this->entries(
			$wpdb->prepare( 'SELECT ' . self::ENTRY_COLUMNS . ' FROM %i WHERE refund_id = %d ORDER BY id ASC', $this->table( Tables::SALES ), $refund_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column list is a constant.
		);
	}

	/**
	 * Whether a row with the event key is stored.
	 *
	 * @param string $event_key Event key.
	 * @throws DatabaseException When the query fails.
	 */
	public function event_exists( string $event_key ): bool {
		$wpdb  = $this->wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE event_key = %s', $this->table( Tables::SALES ), $event_key ) );

		if ( null === $found && '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the sales ledger.' );
		}

		return null !== $found;
	}

	/**
	 * Cycle events of an order.
	 *
	 * @param int $order_id WooCommerce order.
	 * @throws DatabaseException When the query fails or a stored row is invalid (InvalidArgumentException for a non-positive id).
	 */
	public function cycle_state( int $order_id ): SalesCycleState {
		self::assert_positive_id( $order_id, 'Order id' );

		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, order_id, cycle, event, occurred_at, created_at FROM %i WHERE order_id = %d ORDER BY cycle ASC, id ASC', $this->table( Tables::SALES_CYCLES ), $order_id ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the sales cycles.' );
		}

		return new SalesCycleState( $order_id, array_map( array( self::class, 'hydrate_cycle_event' ), $rows ) );
	}

	/**
	 * Name of the order's lock: deterministic, at most 64 characters (MySQL's
	 * limit). User-level locks are server-wide, so the name includes a hash of
	 * the database and table prefix; another site or installation on the same
	 * server never shares a lock with this one.
	 *
	 * @param int $order_id WooCommerce order.
	 */
	public function lock_name( int $order_id ): string {
		$database = defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '';

		return self::LOCK_PREFIX . substr( md5( $database . '|' . $this->wpdb->prefix ), 0, 12 ) . ':' . $order_id;
	}

	/**
	 * Runs a SELECT of ledger rows.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @return list<SalesEntry>
	 * @throws DatabaseException When the query fails or a stored row is invalid.
	 */
	private function entries( ?string $sql ): array {
		$rows = null === $sql ? null : $this->wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass the result of $wpdb->prepare().

		if ( ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the sales ledger.' );
		}

		return array_map( array( self::class, 'hydrate_entry' ), $rows );
	}

	/**
	 * Runs an `INSERT … SELECT … WHERE CONNECTION_ID() = <lock connection>`.
	 *
	 * The condition is what keeps a write atomic across a silent reconnect:
	 * when wpdb loses the connection it reconnects and repeats the statement
	 * in a new session, outside the transaction and in autocommit mode. There
	 * the condition is false, so nothing is written (0 rows) instead of a row
	 * that would be committed on its own.
	 *
	 * Runs without wpdb's error output: a duplicate is an expected outcome
	 * here, and other failures surface as DatabaseException.
	 *
	 * @param string|null $sql      Result of $wpdb->prepare().
	 * @param int         $order_id WooCommerce order (for the log).
	 * @return bool True when the row was inserted, false when the statement failed (e.g. a duplicate).
	 * @throws DatabaseException When the statement could not be prepared.
	 * @throws SalesConnectionChangedException When the statement ran in another session than the lock's.
	 */
	private function guarded_insert( ?string $sql, int $order_id ): bool {
		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the sales ledger write.', array( 'order_id' => $order_id ) );
		}

		$suppressed = $this->wpdb->suppress_errors( true );

		try {
			$result = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare().
		} finally {
			$this->wpdb->suppress_errors( $suppressed );
		}

		if ( 0 === $result ) {
			throw new SalesConnectionChangedException( 'The database connection changed while the sales ledger lock was held.' );
		}

		return 1 === $result;
	}

	/**
	 * R0 of the synchronization (E3.3, S2), inside its transaction and before
	 * any plain read: the schema version row LOCK IN SHARE MODE and, when the
	 * version is at least the G1 schema, the G1 activation row LOCK IN SHARE
	 * MODE. Below the G1 schema, no G1 table is read.
	 *
	 * @return DateTimeImmutable|null The cut-off (UTC) when G1 is active, else null.
	 * @throws DatabaseException When the version row is missing or unreadable, its value is not a plain number of at most 9 digits, or (from the G1 schema on) the activation row is missing or unreadable, or an active row has no valid cut-off.
	 */
	private function take_r0(): ?DateTimeImmutable {
		$version = SchemaVersionRow::read_shared( $this->wpdb );

		// Only digits, at most 9 of them, checked before the conversion: no silent 0.
		if ( ! ctype_digit( $version ) || strlen( $version ) > self::MAX_VERSION_DIGITS ) {
			throw new DatabaseException( 'The stored schema version is not valid.' );
		}

		if ( (int) $version < self::G1_SCHEMA_VERSION ) {
			return null;
		}

		$state = G1StateRow::read_shared( $this->wpdb );

		if ( null === $state ) {
			throw new DatabaseException( 'The G1 activation row is missing.' );
		}

		if ( null === $state['activated_at'] ) {
			return null;
		}

		// An active row always has its cut-off; without a valid one no date is guessed (fail closed).
		$cutoff = null === $state['cutoff_utc'] ? false : DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $state['cutoff_utc'], new DateTimeZone( 'UTC' ) );

		if ( false === $cutoff || $cutoff->format( 'Y-m-d H:i:s' ) !== $state['cutoff_utc'] ) {
			throw new DatabaseException( 'The G1 cut-off is not valid.' );
		}

		return $cutoff;
	}

	/**
	 * Whether the order is G1-covered: the earliest `occurred_at` of its
	 * cycle-1 sale rows, stored or planned, is on or after the cut-off.
	 * Without such a row it is not covered (no date is guessed).
	 *
	 * @param SalesLedgerState  $state  Stored ledger state.
	 * @param SalesLedgerWrite  $write  Planned rows.
	 * @param DateTimeImmutable $cutoff Cut-off (UTC) from R0.
	 */
	private static function covered( SalesLedgerState $state, SalesLedgerWrite $write, DateTimeImmutable $cutoff ): bool {
		$first = null;

		foreach ( array_merge( $state->entries, $write->entries ) as $entry ) {
			if ( SalesEntryType::Sale === $entry->type && 1 === $entry->cycle && ( null === $first || $entry->occurred_at < $first ) ) {
				$first = $entry->occurred_at;
			}
		}

		return null !== $first && $first >= $cutoff;
	}

	/**
	 * Inserts the order's `pending` assessment on the lock's connection.
	 *
	 * @param int $order_id WooCommerce order.
	 * @throws DatabaseException When the statement fails (e.g. 1062).
	 * @throws SalesConnectionChangedException When it ran on another connection (nothing written).
	 */
	private function insert_pending( int $order_id ): void {
		$inserted = G1AssessmentRow::insert_pending( $this->wpdb, $order_id, current_time( 'mysql', true ), $this->lock_connection );

		if ( 0 === $inserted ) {
			throw new SalesConnectionChangedException( 'The database connection changed while the sales ledger lock was held.' );
		}

		if ( 1 !== $inserted ) {
			throw new DatabaseException( 'Could not store the pending G1 assessment.', array( 'order_id' => $order_id ) );
		}
	}

	/**
	 * Requires the order's lock to be held by this instance.
	 *
	 * @param int $order_id WooCommerce order.
	 * @throws DatabaseException When it is not.
	 */
	private function assert_locked( int $order_id ): void {
		if ( $this->locked_order !== $order_id ) {
			throw new DatabaseException( 'The sales ledger lock of the order is not held.', array( 'order_id' => $order_id ) );
		}
	}

	/**
	 * Requires the connection that took the lock to still be the current one
	 * and to still hold the lock.
	 *
	 * @param int $order_id WooCommerce order.
	 * @throws SalesConnectionChangedException When the connection changed or the lock is lost.
	 */
	private function assert_same_connection( int $order_id ): void {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(%s) AS holder', $this->lock_name( $order_id ) ),
			ARRAY_A
		);

		if (
			! is_array( $row )
			|| (string) ( $row['connection_id'] ?? '' ) !== (string) $this->lock_connection
			|| (string) ( $row['holder'] ?? '' ) !== (string) $this->lock_connection
		) {
			throw new SalesConnectionChangedException( 'The database connection changed while the sales ledger lock was held.' );
		}
	}

	/**
	 * Full table name.
	 *
	 * @param string $table Tables constant.
	 */
	private function table( string $table ): string {
		return Tables::name( $this->wpdb, $table );
	}

	/**
	 * Builds a ledger row from the database, validating every column.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row does not match the schema.
	 */
	private static function hydrate_entry( array $row ): SalesEntry {
		$type   = SalesEntryType::tryFrom( (string) ( $row['type'] ?? '' ) );
		$reason = null === ( $row['correction_reason'] ?? null ) ? null : SalesCorrectionReason::tryFrom( (string) $row['correction_reason'] );
		$ints   = array( 'id', 'creator_id', 'order_id', 'order_item_id', 'cycle', 'refund_id', 'product_id', 'variation_id', 'quantity' );

		foreach ( $ints as $column ) {
			if ( ! is_numeric( $row[ $column ] ?? null ) ) {
				throw new DatabaseException( 'Invalid sales entry row.' );
			}
		}

		if ( null === $type || ( null !== $row['correction_reason'] && null === $reason ) ) {
			throw new DatabaseException( 'Invalid sales entry row.' );
		}

		try {
			return new SalesEntry(
				(string) $row['event_key'],
				(int) $row['creator_id'],
				(int) $row['order_id'],
				(int) $row['order_item_id'],
				(int) $row['cycle'],
				(int) $row['refund_id'],
				(int) $row['product_id'],
				(int) $row['variation_id'],
				(string) $row['item_name'],
				$type,
				$reason,
				(int) $row['quantity'],
				(string) $row['net_amount'],
				(string) $row['currency'],
				self::from_utc( $row['occurred_at'] ?? null ),
				(int) $row['id'],
				self::from_utc( $row['created_at'] ?? null )
			);
		} catch ( InvalidArgumentException $e ) {
			throw new DatabaseException( 'Invalid sales entry row.', array(), $e );
		}
	}

	/**
	 * Builds a cycle event from the database, validating every column.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row does not match the schema.
	 */
	private static function hydrate_cycle_event( array $row ): SalesCycleEvent {
		$event = SalesCycleEventType::tryFrom( (string) ( $row['event'] ?? '' ) );

		if ( null === $event || ! is_numeric( $row['id'] ?? null ) || ! is_numeric( $row['order_id'] ?? null ) || ! is_numeric( $row['cycle'] ?? null ) ) {
			throw new DatabaseException( 'Invalid sales cycle row.' );
		}

		try {
			return new SalesCycleEvent(
				(int) $row['order_id'],
				(int) $row['cycle'],
				$event,
				self::from_utc( $row['occurred_at'] ?? null ),
				(int) $row['id'],
				self::from_utc( $row['created_at'] ?? null )
			);
		} catch ( InvalidArgumentException $e ) {
			throw new DatabaseException( 'Invalid sales cycle row.', array(), $e );
		}
	}

	/**
	 * A time as stored: UTC in MySQL DATETIME format.
	 *
	 * @param DateTimeImmutable $time Time in any time zone.
	 */
	private static function to_utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A stored UTC DATETIME.
	 *
	 * @param mixed $value Column value.
	 * @throws DatabaseException When it is not a valid DATETIME.
	 */
	private static function from_utc( mixed $value ): DateTimeImmutable {
		$time = is_string( $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) ) : false;

		if ( false === $time ) {
			throw new DatabaseException( 'Invalid sales ledger time.' );
		}

		return $time;
	}

	/**
	 * Rejects ids that cannot exist.
	 *
	 * @param int    $id    Id to check.
	 * @param string $label Name for the message.
	 * @throws InvalidArgumentException When not positive.
	 */
	private static function assert_positive_id( int $id, string $label ): void {
		if ( $id < 1 ) {
			throw new InvalidArgumentException( $label . ' must be a positive integer.' );
		}
	}
}
