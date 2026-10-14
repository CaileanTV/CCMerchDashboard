<?php
/**
 * The synchronization's G1 `pending` (E3 T5b) against a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use wpdb;

/**
 * T5b contract: with G1 active in R0, an order is covered when the earliest
 * `occurred_at` of its cycle-1 sale rows (stored and planned) is on or after
 * R0's cut-off. Only then is its assessment read (plain, after R0, before
 * the ledger rows) and, when missing, inserted as `pending` after the ledger
 * rows in the same transaction: status `pending`, every other column NULL,
 * no audit. A null plan (planner failure, backfill over history, D1) writes
 * nothing. A failed insert (1062) is db_error, zero rows connection_changed,
 * both with a full rollback. Synthetic data only; the reader stands in for
 * WooCommerce (`date_created` 2026-09-01 10:00:00, `date_paid` per order,
 * so the cycle-1 sale time is `date_paid`).
 */
final class SalesSyncPendingTest extends IntegrationTestCase {

	private const ORDER = 7;

	private FakeOrderReader $reader;

	private RecordingFailureMarker $marker;

	private RecordingLogger $logger;

	private SalesSynchronizer $sync;

	private int $clock = 0;

	private ?wpdb $second = null;

	/**
	 * Filters added by the test, removed in tearDown().
	 *
	 * @var list<Closure>
	 */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->reader = new FakeOrderReader();
		$this->marker = new RecordingFailureMarker();
		$this->logger = new RecordingLogger();
		$this->sync   = $this->synchronizer();
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( 'query', $filter );
		}

		$this->wpdb->query( 'SET autocommit = 1' );

		if ( null !== $this->second ) {
			$this->second->close();
			$this->second = null;
		}

		parent::tearDown();
	}

	public function test_a_covered_new_order_gets_pending_after_its_ledger_rows_live(): void {
		$this->activate( '2026-09-01 10:05:00' );
		$this->order( self::ORDER );
		$audit = $this->count_rows( Tables::AUDIT_LOG );

		$statements = $this->recorded( fn() => $this->sync->sync( self::ORDER ) );

		$r0     = $this->index( $statements, 'FROM ' . $this->quoted( Tables::G1_STATE ) );
		$state  = $this->index( $statements, 'SELECT id, event_key' );
		$read   = $this->index( $statements, 'SELECT order_id FROM ' . $this->quoted( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) );
		$cycle  = $this->index( $statements, 'INSERT INTO ' . $this->quoted( Tables::SALES_CYCLES ) );
		$ledger = $this->index( $statements, 'INSERT INTO ' . $this->quoted( Tables::SALES ) );
		$insert = $this->index( $statements, 'INSERT INTO ' . $this->quoted( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) );

		self::assertSame( array( $r0, $state, $read, $cycle, $ledger, $insert ), array_values( array_filter( array( $r0, $state, $read, $cycle, $ledger, $insert ), static fn( int $i ): bool => $i >= 0 ) ), 'All six statements ran.' );
		self::assertTrue( $r0 < $state && $state < $read && $read < $cycle && $cycle < $ledger && $ledger < $insert, 'R0, state(), assessment read, ledger rows, then pending.' );
		self::assertStringNotContainsString( 'FOR UPDATE', $statements[ $read ] );
		self::assertStringNotContainsString( 'LOCK IN SHARE MODE', $statements[ $read ] );
		self::assertStringContainsString( 'FROM DUAL WHERE CONNECTION_ID() = ', $statements[ $insert ] );
		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( '7:pending' ), $this->assessments() );

		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), self::ORDER ), ARRAY_A );
		self::assertIsArray( $row );
		self::assertSame( array( null, null, null, null, null ), array( $row['reason_code'], $row['source'], $row['assessed_at'], $row['assessed_by'], $row['decision_reason'] ) );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['updated_at'] );
		self::assertSame( $audit, $this->count_rows( Tables::AUDIT_LOG ), 'pending is not audited.' );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
		self::assertSame( array(), $this->logger->at( 'error' ) );
	}

	public function test_one_second_before_the_cutoff_is_not_covered_and_not_read(): void {
		$this->activate( '2026-09-01 10:05:01' );
		$this->order( self::ORDER );

		$statements = $this->recorded( fn() => $this->sync->sync( self::ORDER ) );

		self::assertSame( -1, $this->index( $statements, $this->quoted( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) ), 'No assessment read.' );
		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array(), $this->assessments() );
	}

	public function test_an_order_created_before_and_paid_after_the_cutoff_is_covered(): void {
		$this->activate( '2026-09-01 10:02:00' );
		$this->order( self::ORDER );

		$this->sync->sync( self::ORDER );

		self::assertSame( array( '7:pending' ), $this->assessments(), 'date_created 10:00 < cut-off <= date_paid 10:05.' );
	}

	public function test_the_earliest_cycle_1_sale_decides(): void {
		$this->activate( '2026-09-01 10:10:00' );
		$this->order( self::ORDER, '2026-09-01 10:05:00', array( 11 ) );
		$this->sync->sync( self::ORDER );

		// A line added later in the open cycle 1, now with a later payment date.
		$this->order( self::ORDER, '2026-09-01 10:20:00', array( 11, 12 ) );
		$this->sync->sync( self::ORDER );

		self::assertSame(
			array(
				's:11:1' => '2026-09-01 10:05:00',
				's:12:1' => '2026-09-01 10:20:00',
			),
			$this->sale_times()
		);
		self::assertSame( array(), $this->assessments(), 'The earliest cycle-1 sale (10:05) lies before the cut-off.' );
	}

	public function test_backfill_registers_a_covered_order_with_pending(): void {
		$this->activate( '2026-09-01 00:00:00' );
		$this->order( 9 );

		$outcome = $this->sync->backfill( 9 );

		self::assertSame( SalesBackfillStatus::Registered, $outcome->status );
		self::assertSame( array( '9:pending' ), $this->assessments() );
	}

	public function test_deletion_adds_pending_to_a_covered_order_without_assessment(): void {
		$this->order( self::ORDER );
		$this->sync->sync( self::ORDER );
		$this->activate( '2026-09-01 00:00:00' );

		$this->sync->order_deleting( self::ORDER );

		self::assertSame( array( 's:11:1', 'v:11:1' ), $this->keys() );
		self::assertSame( array( '7:pending' ), $this->assessments() );
		self::assertSame( array( 'clear:7' ), $this->marker->calls, 'No marker for the order being deleted.' );
	}

	public function test_a_valid_plan_without_new_rows_writes_a_missing_pending(): void {
		$this->order( self::ORDER );
		$this->sync->sync( self::ORDER );
		$this->activate( '2026-09-01 00:00:00' );

		$this->sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1' ), $this->keys(), 'No new ledger rows.' );
		self::assertSame( array( '7:pending' ), $this->assessments() );
	}

	public function test_a_planner_failure_writes_no_pending(): void {
		$this->order( self::ORDER );
		$this->sync->sync( self::ORDER );
		$this->activate( '2026-09-01 00:00:00' );

		$this->order( self::ORDER, currency: 'EUR' );
		$this->sync->sync( self::ORDER );

		self::assertSame( array( 'clear:7', 'record:7:currency_mismatch' ), $this->marker->calls );
		self::assertSame( array(), $this->assessments(), 'A planning failure writes nothing (ADR-038 pkt. 23).' );

		$this->order( self::ORDER );
		$this->sync->sync( self::ORDER );

		self::assertSame( array( '7:pending' ), $this->assessments() );
	}

	public function test_backfill_over_history_writes_no_pending(): void {
		$this->order( self::ORDER );
		$this->sync->sync( self::ORDER );
		$this->activate( '2026-09-01 00:00:00' );

		$outcome    = null;
		$statements = $this->recorded(
			function () use ( &$outcome ): void {
				$outcome = $this->sync->backfill( self::ORDER );
			}
		);

		self::assertNotNull( $outcome );
		self::assertSame( SalesBackfillStatus::HasHistory, $outcome->status );
		self::assertSame( -1, $this->index( $statements, $this->quoted( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) ) );
		self::assertSame( array(), $this->assessments(), 'D1: an order with history is never touched.' );

		$this->sync->sync( self::ORDER );

		self::assertSame( array( '7:pending' ), $this->assessments() );
	}

	public function test_backfill_rejected_inside_the_lock_writes_no_pending(): void {
		$this->order( self::ORDER );
		$live = $this->synchronizer();
		$done = false;

		// Between the backfill's check and its lock, a live hook registers the
		// order while G1 is inactive; then G1 is activated. The backfill's admit
		// check (D1) rejects it under the lock: its null plan writes nothing,
		// although the order is covered and has no assessment.
		$this->reader->on_read = function ( int $order_id ) use ( $live, &$done ): void {
			if ( ! $done ) {
				$done = true;
				$live->sync( $order_id );
				$this->activate( '2026-09-01 00:00:00' );
			}
		};

		$outcome = $this->sync->backfill( self::ORDER );

		self::assertTrue( $done );
		self::assertSame( SalesBackfillStatus::HasHistory, $outcome->status );
		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array(), $this->assessments(), 'D1: the null plan wrote no pending.' );
	}

	public function test_an_existing_assessment_is_read_once_and_left_alone(): void {
		$this->activate( '2026-09-01 00:00:00' );
		$this->execute( "INSERT INTO %i (order_id, status, reason_code, source, updated_at) VALUES (%d, 'unknown', 'card_missing', 'automatic', '2026-09-02 00:00:00')", $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), self::ORDER );
		$this->order( self::ORDER );

		$statements = $this->recorded( fn() => $this->sync->sync( self::ORDER ) );

		self::assertSame( -1, $this->index( $statements, 'INSERT INTO ' . $this->quoted( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) ) );
		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( '7:unknown' ), $this->assessments() );
		self::assertSame( '2026-09-02 00:00:00', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT updated_at FROM %i WHERE order_id = %d', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), self::ORDER ) ) );
	}

	public function test_e3_2_start_ends_an_implicit_transaction_and_its_old_snapshot(): void {
		$this->activate( '2026-09-01 00:00:00' );
		$this->order( self::ORDER );
		$this->wpdb->query( 'SET autocommit = 0' );

		// An implicit transaction with a snapshot that has no assessment.
		self::assertSame( '0', $this->assessment_count_here() );
		$this->commit_pending_under_the_order_lock( self::ORDER );
		self::assertSame( '0', $this->assessment_count_here(), 'The old snapshot does not see it.' );

		$statements = $this->recorded( fn() => $this->sync->sync( self::ORDER ) );
		$this->wpdb->query( 'SET autocommit = 1' );

		self::assertSame( -1, $this->index( $statements, 'INSERT INTO ' . $this->quoted( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) ), 'START ended the old snapshot; the row was seen.' );
		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( '7:pending' ), $this->assessments() );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
	}

	public function test_e3_1_the_synchronization_sees_a_pending_committed_by_another_order_lock_holder(): void {
		$this->activate( '2026-09-01 00:00:00' );
		$this->order( self::ORDER );
		$this->order( 8 );
		$this->wpdb->suppress_errors( true );

		// Control (S0's failure path): an old snapshot misses the committed row
		// and an insert there is a duplicate.
		$this->wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		self::assertSame( '0', $this->assessment_count_here( 8 ) );
		$this->commit_pending_under_the_order_lock( 8 );
		self::assertSame( '0', $this->assessment_count_here( 8 ) );
		$this->execute( "INSERT INTO %i (order_id, status, updated_at) VALUES (%d, 'pending', '2026-09-02 00:00:00')", $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), 8 );
		self::assertSame( 1062, DatabaseErrno::last( $this->wpdb ) );
		$this->wpdb->query( 'ROLLBACK' );

		// S2: the same situation for order 7, with an old snapshot still open.
		$this->wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		self::assertSame( '0', $this->assessment_count_here() );
		$this->commit_pending_under_the_order_lock( self::ORDER );

		$statements = $this->recorded( fn() => $this->sync->sync( self::ORDER ) );

		self::assertSame( -1, $this->index( $statements, 'INSERT INTO ' . $this->quoted( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) ), 'No insert, no duplicate.' );
		self::assertSame( array( 's:11:1' ), $this->keys(), 'No ledger loss.' );
		self::assertSame( array( '7:pending', '8:pending' ), $this->assessments() );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
	}

	public function test_e3_5_a_broken_i5_is_db_error_and_rolls_everything_back(): void {
		$this->activate( '2026-09-01 00:00:00' );
		$this->order( self::ORDER );
		$this->wpdb->suppress_errors( true );
		$second = $this->second();
		$insert = 'INSERT INTO ' . $this->quoted( Tables::ORDER_GIFT_CARD_ASSESSMENTS );
		$row    = $this->prepared( $second, "INSERT INTO %i (order_id, status, updated_at) VALUES (%d, 'unknown', '2026-09-02 00:00:00')", $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), self::ORDER );
		$done   = false;

		// Another connection writes the row without the order lock (I5 broken)
		// between the existence read and the insert.
		$this->filter(
			static function ( string $sql ) use ( $second, $insert, $row, &$done ): string {
				if ( ! $done && str_starts_with( $sql, $insert ) ) {
					$done = true;
					$second->query( $row );
				}

				return $sql;
			}
		);
		$errno = $this->errno_before_rollback();

		$this->sync->sync( self::ORDER );

		self::assertTrue( $done );
		self::assertSame( 1062, $errno() );
		self::assertSame( 0, $this->count_rows( Tables::SALES ), 'The ledger rows were rolled back too.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
		self::assertSame( array( '7:unknown' ), $this->assessments(), 'No duplicate; the other row stands.' );
		self::assertSame( array( 'record:7:db_error' ), $this->marker->calls );
		self::assertSame( array( 'db_error' ), $this->error_codes() );
	}

	public function test_a_changed_connection_before_pending_is_connection_changed(): void {
		$this->activate( '2026-09-01 00:00:00' );
		$this->order( self::ORDER );
		$this->wpdb->suppress_errors( true );
		$second = $this->second();
		$own    = (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$kill   = $this->prepared( $second, 'KILL %d', $own );
		$insert = 'INSERT INTO ' . $this->quoted( Tables::ORDER_GIFT_CARD_ASSESSMENTS );
		$done   = false;

		$this->filter(
			static function ( string $sql ) use ( $second, $kill, $insert, &$done ): string {
				if ( ! $done && str_starts_with( $sql, $insert ) ) {
					$done = true;
					$second->query( $kill );
					usleep( 200000 );
				}

				return $sql;
			}
		);

		$this->sync->sync( self::ORDER );

		self::assertTrue( $done );
		self::assertSame( array( 'record:7:connection_changed' ), $this->marker->calls );
		self::assertSame( 0, $this->count_rows( Tables::SALES ), 'Nothing committed.' );
		self::assertSame( array(), $this->assessments(), 'The repeated insert wrote nothing on the new connection.' );
	}

	public function test_a_failed_assessment_read_is_db_error(): void {
		$this->activate( '2026-09-01 00:00:00' );
		$this->order( 8 );
		$this->sync->sync( 8 );
		$this->execute( 'DROP TABLE %i', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) );
		$this->wpdb->suppress_errors( true );
		$this->order( self::ORDER );
		$errno = $this->errno_before_rollback();

		$this->sync->sync( self::ORDER );

		self::assertSame( 1146, $errno(), 'The existence read failed inside the transaction.' );
		self::assertSame( array( 's:21:1' ), $this->keys(), 'Order 7 wrote nothing.' );
		self::assertSame( array( 'clear:8', 'record:7:db_error' ), $this->marker->calls );
	}

	public function test_an_active_g1_without_a_valid_cutoff_fails_closed(): void {
		// Active, but cut-off NULL: allowed by the schema, never written by the activation frame.
		self::assertSame( 1, $this->execute( "UPDATE %i SET cutoff_utc = NULL, activated_at = '2026-08-31 00:00:00', activated_by = 1 WHERE id = 1", $this->table( Tables::G1_STATE ) ) );
		$this->order( self::ORDER );
		$errno = $this->errno_before_rollback();

		$statements = $this->recorded( fn() => $this->sync->sync( self::ORDER ) );

		$start    = array_search( 'START TRANSACTION', $statements, true );
		$rollback = array_search( 'ROLLBACK', $statements, true );
		self::assertIsInt( $start );
		self::assertIsInt( $rollback );
		$inside = array_slice( $statements, $start + 1, $rollback - $start - 1 );

		self::assertCount( 2, $inside, 'Only R0 ran before the rollback: no state(), no assessment read, no write.' );
		self::assertStringContainsString( 'LOCK IN SHARE MODE', $inside[0] );
		self::assertStringContainsString( $this->quoted( Tables::G1_STATE ), $inside[1] );
		self::assertSame( 0, $errno(), 'The activation row was read; the decision failed closed, not the statement.' );
		self::assertFalse( in_array( 'COMMIT', $statements, true ), 'Nothing committed.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
		self::assertSame( array(), $this->assessments(), 'No pending; no cut-off was guessed.' );
		self::assertSame( array( 'record:7:db_error' ), $this->marker->calls );
		self::assertSame( array( 'db_error' ), $this->error_codes() );
		self::assertSame( array( null, '2026-08-31 00:00:00' ), array_values( (array) $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT cutoff_utc, activated_at FROM %i WHERE id = 1', $this->table( Tables::G1_STATE ) ), ARRAY_A ) ), 'The activation row is unchanged.' );
	}

	/**
	 * Activates G1 directly on its row (test setup; the activation frame is T3).
	 *
	 * @param string $cutoff Cut-off (UTC).
	 */
	private function activate( string $cutoff ): void {
		self::assertSame( 1, $this->execute( "UPDATE %i SET cutoff_utc = %s, activated_at = '2026-08-31 00:00:00', activated_by = 1 WHERE id = 1", $this->table( Tables::G1_STATE ), $cutoff ) );
	}

	/**
	 * Commits a `pending` row on the second connection while it holds the
	 * order's lock (another N1 holder), then releases the lock.
	 *
	 * @param int $order_id Order.
	 */
	private function commit_pending_under_the_order_lock( int $order_id ): void {
		$second = $this->second();
		$name   = $this->synchronizer_repository()->lock_name( $order_id );
		self::assertSame( '1', (string) $second->get_var( $this->prepared( $second, 'SELECT GET_LOCK(%s, 0)', $name ) ) );
		self::assertSame( 1, $second->query( $this->prepared( $second, "INSERT INTO %i (order_id, status, updated_at) VALUES (%d, 'pending', '2026-09-02 00:00:00')", $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), $order_id ) ) );
		$second->get_var( $this->prepared( $second, 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Assessment rows of an order, read on the test connection (in its
	 * current snapshot).
	 *
	 * @param int $order_id Order.
	 */
	private function assessment_count_here( int $order_id = self::ORDER ): string {
		return (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), $order_id ) );
	}

	/**
	 * Assessments as "order:status", by order.
	 *
	 * @return list<string>
	 */
	private function assessments(): array {
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT CONCAT(order_id, ':', status) FROM %i ORDER BY order_id", $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) ) );

		return array_map( 'strval', $rows );
	}

	/**
	 * `occurred_at` of the sale rows by event key.
	 *
	 * @return array<string, string>
	 */
	private function sale_times(): array {
		$times = array();

		foreach ( $this->rows( Tables::SALES ) as $row ) {
			if ( 'sale' === $row['type'] ) {
				$times[ (string) $row['event_key'] ] = (string) $row['occurred_at'];
			}
		}

		return $times;
	}

	/**
	 * Index of the first statement that contains $needle, or -1.
	 *
	 * @phpstan-param list<string> $statements
	 *
	 * @param string[] $statements Statements.
	 * @param string   $needle     Text.
	 */
	private function index( array $statements, string $needle ): int {
		foreach ( $statements as $i => $statement ) {
			if ( str_contains( $statement, $needle ) ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * A table name as `%i` quotes it.
	 *
	 * @param string $table Tables constant.
	 */
	private function quoted( string $table ): string {
		return '`' . $this->table( $table ) . '`';
	}

	/**
	 * The statements run while $work runs.
	 *
	 * @param Closure $work Work.
	 * @return list<string>
	 */
	private function recorded( Closure $work ): array {
		$statements = array();
		$this->filter(
			static function ( string $sql ) use ( &$statements ): string {
				$statements[] = $sql;

				return $sql;
			}
		);

		$work();

		return $statements;
	}

	/**
	 * Adds a `query` filter, removed in tearDown().
	 *
	 * @param Closure $filter Filter.
	 */
	private function filter( Closure $filter ): void {
		add_filter( 'query', $filter );
		$this->filters[] = $filter;
	}

	/**
	 * The error number of the statement before the first ROLLBACK.
	 *
	 * @return Closure(): (int|null|false) False while no ROLLBACK ran.
	 */
	private function errno_before_rollback(): Closure {
		$errno = false;
		$wpdb  = $this->wpdb;
		$this->filter(
			static function ( string $sql ) use ( $wpdb, &$errno ): string {
				if ( 'ROLLBACK' === $sql && false === $errno ) {
					$errno = DatabaseErrno::last( $wpdb );
				}

				return $sql;
			}
		);

		return static function () use ( &$errno ): int|null|false {
			return $errno;
		};
	}

	/**
	 * Prepares a statement on a connection.
	 *
	 * @param wpdb   $connection Connection.
	 * @param string $query      Query.
	 * @param mixed  ...$args    Arguments.
	 * @phpstan-param literal-string $query
	 */
	private function prepared( wpdb $connection, string $query, mixed ...$args ): string {
		$sql = $connection->prepare( $query, ...$args );
		self::assertIsString( $sql );

		return $sql;
	}

	/**
	 * A second, real connection with a 1-second InnoDB lock wait.
	 */
	private function second(): wpdb {
		if ( null === $this->second ) {
			$this->second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$this->second->set_prefix( $this->wpdb->prefix );
			$this->second->suppress_errors( true );
			$this->second->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		}

		return $this->second;
	}

	/**
	 * The codes of the logged synchronization failures.
	 *
	 * @return list<mixed>
	 */
	private function error_codes(): array {
		return array_map( static fn( array $entry ): mixed => $entry['context']['code'] ?? null, $this->logger->at( 'error' ) );
	}

	/**
	 * A repository on the test connection (for lock names).
	 */
	private function synchronizer_repository(): SalesRepository {
		return new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) );
	}

	/**
	 * A synchronizer on the test connection with a stepping clock.
	 */
	private function synchronizer(): SalesSynchronizer {
		return new SalesSynchronizer(
			$this->synchronizer_repository(),
			new SalesPlanner(),
			$this->reader,
			$this->marker,
			$this->logger,
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-29 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Sets a completed order with one line per item id (product 20, creator 3).
	 *
	 * @phpstan-param list<int> $items
	 *
	 * @param int    $id        Order id.
	 * @param string $date_paid Payment date (UTC), the cycle-1 sale time.
	 * @param int[]  $items     Item ids.
	 * @param string $currency  Currency.
	 */
	private function order( int $id, string $date_paid = '2026-09-01 10:05:00', array $items = array(), string $currency = 'DKK' ): void {
		$items = array() === $items ? array( 7 === $id ? 11 : ( 8 === $id ? 21 : 31 ) ) : $items;

		$this->reader->orders[ $id ] = array(
			'status'    => 'completed',
			'currency'  => $currency,
			'lines'     => array_map( static fn( int $item ): OrderLineSnapshot => new OrderLineSnapshot( $item, 20, 0, 'Line ' . $item, 1, SalesAmount::parse( '100' ), 3 ), $items ),
			'refunds'   => array(),
			'date_paid' => $date_paid,
		);
	}

	/**
	 * Event keys in insertion order.
	 *
	 * @return list<string|null>
	 */
	private function keys(): array {
		return array_column( $this->rows( Tables::SALES ), 'event_key' );
	}
}
