<?php
/**
 * R0 of the sales synchronization (E3 T5a) against a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesFailureCode;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use mysqli;
use PHPUnit\Framework\Attributes\DataProvider;
use wpdb;

/**
 * Inside the synchronization's own transaction, before any plain read: the
 * schema version row LOCK IN SHARE MODE and, from schema version 12 on, the
 * G1 activation row LOCK IN SHARE MODE; then state(). A missing version row,
 * an invalid stored version (only digits, at most 9) or, from version 12 on,
 * a missing or unreadable activation row is db_error with the existing
 * marker rule (live and backfill: marker and log; deletion: log only), and
 * nothing is written. No G1 assessment is read (that is T5b). 1205 and 1213
 * stay db_error. Synthetic data only; the reader stands in for WooCommerce.
 */
final class SalesSyncR0Test extends IntegrationTestCase {

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

		if ( null !== $this->second ) {
			$this->second->query( 'ROLLBACK' );
			$this->second->close();
			$this->second = null;
		}

		parent::tearDown();
	}

	public function test_r0_comes_first_in_the_transaction_then_state(): void {
		$this->order( self::ORDER, 'processing', array( self::line( 11, 2, '100' ) ) );

		$statements = $this->recorded( fn() => $this->sync->sync( self::ORDER ) );
		$start      = array_search( 'START TRANSACTION', $statements, true );
		self::assertIsInt( $start );

		self::assertStringContainsString( "option_name = '" . Migrator::VERSION_OPTION . "' LOCK IN SHARE MODE", $statements[ $start + 1 ], 'R0: the version row first.' );
		self::assertStringContainsString( '`' . $this->table( Tables::G1_STATE ) . '`', $statements[ $start + 2 ], 'R0: then the activation row.' );
		self::assertStringEndsWith( 'LOCK IN SHARE MODE', $statements[ $start + 2 ] );
		self::assertStringStartsWith( 'SELECT', $statements[ $start + 3 ], 'Then state().' );
		self::assertStringContainsString( '`' . $this->table( Tables::SALES ) . '`', $statements[ $start + 3 ] );
		self::assertStringNotContainsString( 'FOR UPDATE', $statements[ $start + 3 ], 'state() is a plain read.' );
		self::assertStringNotContainsString( 'LOCK IN SHARE MODE', $statements[ $start + 3 ] );
		$this->assert_no_assessment_read( $statements );

		// Isolation level and lock wait timeout are untouched (only whole statements count).
		self::assertTrue( self::sets_isolation_or_lock_wait( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ), 'The check recognises T1\'s statements.' );
		self::assertTrue( self::sets_isolation_or_lock_wait( 'SET SESSION innodb_lock_wait_timeout = 5' ) );

		foreach ( $statements as $statement ) {
			self::assertFalse( self::sets_isolation_or_lock_wait( $statement ), $statement );
		}

		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( '1:open' ), $this->cycles() );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
		self::assertSame( array(), $this->logger->at( 'error' ) );
	}

	public function test_below_version_12_no_g1_table_is_read(): void {
		$this->set_version( '11' );
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );

		$statements = $this->recorded( fn() => $this->sync->sync( self::ORDER ) );

		foreach ( $statements as $statement ) {
			self::assertStringNotContainsString( $this->table( Tables::G1_STATE ), $statement );
			self::assertStringNotContainsString( 'gift_card', $statement );
		}

		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
	}

	public function test_inactive_g1_leaves_the_ledger_as_before(): void {
		$lines = array( self::line( 11, 2, '100' ) );
		$this->order( self::ORDER, 'completed', $lines );
		$this->sync->sync( self::ORDER );
		$this->order( self::ORDER, 'cancelled', $lines );
		$this->sync->sync( self::ORDER );
		$this->order( self::ORDER, 'processing', $lines );
		$this->sync->sync( self::ORDER );

		$items   = array( self::line( 21, 3, '150' ) );
		$partial = self::refund( 100, array( new RefundLineSnapshot( 21, -1, SalesAmount::parse( '-50' ) ) ) );
		$rest    = self::refund( 101, array( new RefundLineSnapshot( 21, -2, SalesAmount::parse( '-100' ) ) ) );
		$this->order( 8, 'completed', $items );
		$this->sync->sync( 8 );
		$this->order( 8, 'completed', $items, array( $partial ) );
		$this->sync->sync( 8 );
		$this->order( 8, 'refunded', $items, array( $partial, $rest ) );
		$this->sync->sync( 8 );
		$this->sync->sync( 8, 100 );

		// The same rows as SalesSynchronizerTest expects for these flows.
		self::assertSame( array( 's:11:1', 'v:11:1', 's:11:2', 's:21:1', 'r:21:100', 'r:21:101', 'c-rd:21:100', 'c-rdo:21:100' ), $this->keys() );
		self::assertSame( array( '1:open', '1:close', '2:open', '1:open', '1:close' ), $this->cycles() );
		self::assertSame( 0, $this->count_rows( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) );
		self::assertSame(
			array(
				array(
					'id'           => '1',
					'cutoff_utc'   => null,
					'activated_at' => null,
					'activated_by' => null,
				),
			),
			$this->rows( Tables::G1_STATE ),
			'Still inactive.'
		);
		self::assertSame( array(), $this->logger->at( 'error' ) );
	}

	public function test_active_g1_reads_the_assessment_once_and_writes_pending_for_a_covered_order(): void {
		// The order's sale (2026-09-01 10:05:00) lies after the cut-off: covered (T5b).
		$this->execute( "UPDATE %i SET cutoff_utc = '2026-09-01 00:00:00', activated_at = '2026-09-01 00:00:00', activated_by = 1 WHERE id = 1", $this->table( Tables::G1_STATE ) );
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );

		$statements  = $this->recorded( fn() => $this->sync->sync( self::ORDER ) );
		$assessments = '`' . $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) . '`';
		$touching    = array_values( array_filter( $statements, static fn( string $sql ): bool => str_contains( $sql, $assessments ) ) );

		self::assertCount( 2, $touching, 'One existence read and one insert.' );
		self::assertStringStartsWith( 'SELECT order_id FROM ' . $assessments, $touching[0] );
		self::assertStringNotContainsString( 'FOR UPDATE', $touching[0], 'A plain read.' );
		self::assertStringNotContainsString( 'LOCK IN SHARE MODE', $touching[0] );
		self::assertStringStartsWith( 'INSERT INTO ' . $assessments, $touching[1] );
		self::assertStringContainsString( 'FROM DUAL WHERE CONNECTION_ID() = ', $touching[1] );
		self::assertSame( array( 's:11:1' ), $this->keys() );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT order_id, status, reason_code, source, assessed_at, assessed_by, decision_reason FROM %i', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) ), ARRAY_A );
		self::assertIsArray( $rows );
		self::assertSame( array( array( '7', 'pending', null, null, null, null, null ) ), array_map( 'array_values', $rows ) );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
	}

	public function test_a_missing_version_row_fails_closed_on_every_path(): void {
		// The version read itself succeeds (errno 0) and finds no row.
		$this->assert_fails_closed_on_every_path(
			function (): void {
				$this->execute( 'DELETE FROM %i WHERE option_name = %s', $this->wpdb->options, Migrator::VERSION_OPTION );
			},
			false,
			0
		);
	}

	/**
	 * Stored versions that are not a plain number of at most 9 digits.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_versions(): array {
		return array(
			'empty'          => array( '' ),
			'letters'        => array( 'abc' ),
			'negative'       => array( '-1' ),
			'plus sign'      => array( '+13' ),
			'decimal'        => array( '13.0' ),
			'leading space'  => array( ' 13' ),
			'trailing space' => array( '13 ' ),
			'exponent'       => array( '1e1' ),
			'hexadecimal'    => array( '0x0d' ),
			'10 digits'      => array( '1234567890' ),
			'10 with zeros'  => array( '0000000013' ),
		);
	}

	#[DataProvider( 'invalid_versions' )]
	public function test_an_invalid_stored_version_fails_closed( string $version ): void {
		$this->set_version( $version );
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$transactions = $this->rolled_back_transactions();

		$this->sync->sync( self::ORDER );

		self::assertCount( 1, $transactions() );
		$this->assert_failed_in_r0( $transactions()[0], false, 0 );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
		self::assertSame( array( 'record:7:db_error' ), $this->marker->calls );
		self::assertSame( array( 'db_error' ), $this->error_codes() );
	}

	public function test_nine_digits_are_a_valid_version(): void {
		$this->set_version( '000000013' );
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );

		$this->sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
	}

	public function test_a_missing_activation_row_fails_closed_on_every_path(): void {
		// The activation read itself succeeds (errno 0) and finds no row.
		$this->assert_fails_closed_on_every_path(
			function (): void {
				$this->execute( 'DELETE FROM %i WHERE id = 1', $this->table( Tables::G1_STATE ) );
			},
			true,
			0
		);
	}

	public function test_a_missing_activation_table_fails_closed_on_every_path(): void {
		// The repository's TransactionSupport check passed on the first sync, so
		// the missing table is found by R0 itself (1146), not before START.
		$this->assert_fails_closed_on_every_path(
			function (): void {
				$this->execute( 'DROP TABLE %i', $this->table( Tables::G1_STATE ) );
			},
			true,
			1146
		);
	}

	public function test_a_migration_update_waits_for_the_synchronization(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$second = $this->second();
		$sales  = '`' . $this->table( Tables::SALES ) . '`';
		$update = $this->version_update( $second, '29' );
		$result = null;
		$errno  = null;

		// While the synchronization holds R0 (its first state() read is next),
		// the migration's UPDATE of the version row runs on another connection
		// (the UPDATE itself does not match the condition).
		$this->filter(
			static function ( string $sql ) use ( $second, $sales, $update, &$result, &$errno ): string {
				if ( null === $result && str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, $sales ) ) {
					$result = $second->query( $update );
					$errno  = DatabaseErrno::last( $second );
				}

				return $sql;
			}
		);

		$this->sync->sync( self::ORDER );

		self::assertFalse( $result, 'The UPDATE waited for the shared lock and gave up.' );
		self::assertSame( 1205, $errno );
		self::assertSame( '28', $this->stored_version(), 'The version is unchanged.' );
		self::assertSame( array( 's:11:1' ), $this->keys(), 'The synchronization committed.' );
		self::assertSame( array( 'clear:7' ), $this->marker->calls );
	}

	public function test_a_synchronization_waiting_for_a_migration_update_is_db_error(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$second = $this->second();
		$second->query( 'START TRANSACTION' );
		self::assertSame( 1, $second->query( $this->version_update( $second, '29' ) ) );
		$errno = $this->errno_before_rollback();
		$this->wpdb->suppress_errors( true );

		$this->with_lock_wait( 1, fn() => $this->sync->sync( self::ORDER ) );

		self::assertSame( 1205, $errno(), 'The version row read timed out.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
		self::assertSame( array( 'record:7:db_error' ), $this->marker->calls );
		self::assertSame( array( 'db_error' ), $this->error_codes() );
	}

	public function test_a_deadlock_in_r0_is_db_error(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$second = $this->second();
		$dbh    = $second->__get( 'dbh' );
		self::assertInstanceOf( mysqli::class, $dbh );

		// The second connection holds the activation row and changes three
		// more rows, so the synchronization is the lighter deadlock victim.
		$second->query( 'START TRANSACTION' );
		self::assertSame( 1, $this->on( $second, "UPDATE %i SET cutoff_utc = '2026-01-01 00:00:00' WHERE id = 1", $this->table( Tables::G1_STATE ) ) );

		foreach ( array( 1, 2, 3 ) as $n ) {
			self::assertSame( 1, $this->on( $second, "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, 'x', 'no')", $second->options, 'mbtest_t5a_' . $n ) );
		}

		$state  = '`' . $this->table( Tables::G1_STATE ) . '`';
		$update = $this->version_update( $second, '13' );
		$sent   = false;

		// The synchronization holds S on the version row; the second connection
		// now asks for X on it, then the synchronization asks for S on the
		// activation row the second holds: a deadlock.
		$this->filter(
			static function ( string $sql ) use ( $dbh, $state, $update, &$sent ): string {
				if ( ! $sent && str_contains( $sql, $state ) && str_ends_with( $sql, 'LOCK IN SHARE MODE' ) ) {
					$sent = true;
					$dbh->query( $update, MYSQLI_ASYNC );
					usleep( 300000 );
				}

				return $sql;
			}
		);
		$errno = $this->errno_before_rollback();
		$this->wpdb->suppress_errors( true );

		$this->sync->sync( self::ORDER );

		self::assertTrue( $sent );
		self::assertSame( 1213, $errno(), 'The synchronization was the victim.' );
		self::assertNotFalse( $dbh->reap_async_query(), 'The second connection got the row after the victim\'s rollback.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( array( 'record:7:db_error' ), $this->marker->calls );
		self::assertSame( array( 'db_error' ), $this->error_codes() );
	}

	/**
	 * Breaks R0 after a first, successful synchronization of order 8, then
	 * runs the live path (order 7), the backfill (order 9) and the deletion
	 * (order 8): each is db_error and writes nothing; live and backfill set
	 * the marker and log, the deletion only logs. Each path started its
	 * transaction, ran R0 alone and rolled back after it.
	 *
	 * @param Closure $breaks     Breaks R0.
	 * @param bool    $activation Whether R0 got as far as the activation read.
	 * @param int     $errno      Error number of the last R0 read.
	 */
	private function assert_fails_closed_on_every_path( Closure $breaks, bool $activation, int $errno ): void {
		$this->order( 8, 'completed', array( self::line( 21, 1, '40' ) ) );
		$this->sync->sync( 8 );
		self::assertSame( array( 's:21:1' ), $this->keys() );

		$breaks();
		$this->wpdb->suppress_errors( true );
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$this->order( 9, 'completed', array( self::line( 31, 1, '10' ) ) );
		$transactions = $this->rolled_back_transactions();

		$this->sync->sync( self::ORDER );
		$backfill = $this->sync->backfill( 9 );
		$this->sync->order_deleting( 8 );

		self::assertCount( 3, $transactions(), 'Live, backfill and deletion each rolled back.' );

		foreach ( $transactions() as $transaction ) {
			$this->assert_failed_in_r0( $transaction, $activation, $errno );
		}

		self::assertSame( SalesBackfillStatus::Failed, $backfill->status );
		self::assertSame( SalesFailureCode::DbError, $backfill->failure );
		self::assertSame( array( 's:21:1' ), $this->keys(), 'Nothing written, also no closing row for the deleted order.' );
		self::assertSame( array( '1:open' ), $this->cycles() );
		self::assertSame( array( 'clear:8', 'record:7:db_error', 'record:9:db_error' ), $this->marker->calls, 'No marker for the order being deleted.' );
		self::assertSame(
			array(
				array(
					'order_id' => self::ORDER,
					'code'     => 'db_error',
				),
				array(
					'order_id' => 9,
					'code'     => 'db_error',
				),
				array(
					'order_id' => 8,
					'code'     => 'db_error',
				),
			),
			array_column( $this->logger->at( 'error' ), 'context' )
		);
	}

	/**
	 * A rolled-back transaction ran only R0, and its last read reported $errno.
	 *
	 * @param array{statements: list<string>, errno: int|null} $transaction Statements between START TRANSACTION and ROLLBACK.
	 * @param bool                                             $activation  Whether the activation read ran after the version read.
	 * @param int                                              $errno       Expected error number of the last R0 read.
	 */
	private function assert_failed_in_r0( array $transaction, bool $activation, int $errno ): void {
		self::assertSame( $errno, $transaction['errno'], 'Error number of the last R0 read, read before ROLLBACK.' );
		self::assertCount( $activation ? 2 : 1, $transaction['statements'], 'Only R0 ran between START TRANSACTION and ROLLBACK.' );
		self::assertSame( $this->version_read(), $transaction['statements'][0] );

		if ( $activation ) {
			self::assertSame( $this->activation_read(), $transaction['statements'][1] );
		}
	}

	/**
	 * R0's version read, as SchemaVersionRow::read_shared() runs it.
	 */
	private function version_read(): string {
		$sql = $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LOCK IN SHARE MODE', $this->wpdb->options, Migrator::VERSION_OPTION );
		self::assertIsString( $sql );

		return $sql;
	}

	/**
	 * R0's activation read, as G1StateRow::read_shared() runs it.
	 */
	private function activation_read(): string {
		$sql = $this->wpdb->prepare( 'SELECT cutoff_utc, activated_at, activated_by FROM %i WHERE id = %d LOCK IN SHARE MODE', $this->table( Tables::G1_STATE ), 1 );
		self::assertIsString( $sql );

		return $sql;
	}

	/**
	 * The statements of every transaction that ended with ROLLBACK, with the
	 * error number of its last statement (read when ROLLBACK is about to run).
	 *
	 * @return Closure(): list<array{statements: list<string>, errno: int|null}>
	 */
	private function rolled_back_transactions(): Closure {
		$wpdb    = $this->wpdb;
		$open    = null;
		$results = array();
		$this->filter(
			static function ( string $sql ) use ( $wpdb, &$open, &$results ): string {
				if ( 'START TRANSACTION' === $sql ) {
					$open = array();
				} elseif ( 'COMMIT' === $sql ) {
					$open = null;
				} elseif ( 'ROLLBACK' === $sql && null !== $open ) {
					$results[] = array(
						'statements' => $open,
						'errno'      => DatabaseErrno::last( $wpdb ),
					);
					$open      = null;
				} elseif ( null !== $open ) {
					$open[] = $sql;
				}

				return $sql;
			}
		);

		return static function () use ( &$results ): array {
			return $results;
		};
	}

	/**
	 * Whether a whole statement sets the isolation level or the InnoDB lock
	 * wait (SET [SESSION|LOCAL|@@[SESSION.]] TRANSACTION / innodb_lock_wait_timeout
	 * / transaction_isolation / tx_isolation).
	 *
	 * @param string $sql Statement.
	 */
	private static function sets_isolation_or_lock_wait( string $sql ): bool {
		return 1 === preg_match( '/^\s*SET\s+(?:SESSION\s+|LOCAL\s+|@@(?:SESSION\.)?)?(?:TRANSACTION|innodb_lock_wait_timeout|transaction_isolation|tx_isolation)\b/i', $sql );
	}

	/**
	 * No statement touches the G1 assessment, payout or attempt tables.
	 *
	 * @phpstan-param list<string> $statements
	 *
	 * @param string[] $statements Statements.
	 */
	private function assert_no_assessment_read( array $statements ): void {
		foreach ( array( Tables::ORDER_GIFT_CARD_ASSESSMENTS, Tables::ORDER_GIFT_CARD_PAYOUTS, Tables::ORDER_GIFT_CARD_ATTEMPTS ) as $table ) {
			foreach ( $statements as $statement ) {
				self::assertStringNotContainsString( $this->table( $table ), $statement );
			}
		}
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
	 * The error number of the statement before the synchronization's ROLLBACK.
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
	 * Runs $work with the test connection's InnoDB lock wait set to $seconds.
	 *
	 * @param int     $seconds Lock wait.
	 * @param Closure $work    Work.
	 */
	private function with_lock_wait( int $seconds, Closure $work ): void {
		$previous = (int) $this->wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		$this->execute( 'SET SESSION innodb_lock_wait_timeout = %d', $seconds );

		try {
			$work();
		} finally {
			$this->execute( 'SET SESSION innodb_lock_wait_timeout = %d', $previous );
		}
	}

	/**
	 * The migration's UPDATE of the version row, prepared on $connection.
	 *
	 * @param wpdb   $connection Connection.
	 * @param string $version    New value.
	 */
	private function version_update( wpdb $connection, string $version ): string {
		$sql = $connection->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $connection->options, $version, Migrator::VERSION_OPTION );
		self::assertIsString( $sql );

		return $sql;
	}

	/**
	 * Runs a prepared statement on another connection.
	 *
	 * @param wpdb   $connection Connection.
	 * @param string $query      Query.
	 * @param mixed  ...$args    Arguments.
	 * @phpstan-param literal-string $query
	 */
	private function on( wpdb $connection, string $query, mixed ...$args ): int|bool {
		$sql = $connection->prepare( $query, ...$args );
		self::assertIsString( $sql );

		return $connection->query( $sql );
	}

	/**
	 * Sets the stored schema version (directly on its row).
	 *
	 * @param string $version Value.
	 */
	private function set_version( string $version ): void {
		$this->execute( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $this->wpdb->options, $version, Migrator::VERSION_OPTION );
		self::assertSame( $version, $this->stored_version() );
	}

	/**
	 * The stored schema version, read directly and byte-exact (wpdb::get_var()
	 * would return null for an empty value).
	 */
	private function stored_version(): ?string {
		$value = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT CONCAT('v', option_value) FROM %i WHERE option_name = %s", $this->wpdb->options, Migrator::VERSION_OPTION ) );

		return null === $value ? null : substr( (string) $value, 1 );
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
	 * A synchronizer on the test connection with a stepping clock.
	 */
	private function synchronizer(): SalesSynchronizer {
		return new SalesSynchronizer(
			new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) ),
			new SalesPlanner(),
			$this->reader,
			$this->marker,
			$this->logger,
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-29 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Sets an order in the test reader.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param int                 $id      Order id.
	 * @param string              $status  Status.
	 * @param OrderLineSnapshot[] $lines   Lines.
	 * @param RefundSnapshot[]    $refunds Refunds.
	 */
	private function order( int $id, string $status, array $lines, array $refunds = array() ): void {
		$this->reader->orders[ $id ] = array(
			'status'   => $status,
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => $refunds,
		);
	}

	/**
	 * A line of product 20 by creator 3.
	 *
	 * @param int    $item     Item id.
	 * @param int    $quantity Quantity.
	 * @param string $net      Net amount.
	 */
	private static function line( int $item, int $quantity, string $net ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, 20, 0, 'Line ' . $item, $quantity, SalesAmount::parse( $net ), 3 );
	}

	/**
	 * A refund created 2026-09-02.
	 *
	 * @phpstan-param list<RefundLineSnapshot> $lines
	 *
	 * @param int                  $id    Refund id.
	 * @param RefundLineSnapshot[] $lines Lines.
	 */
	private static function refund( int $id, array $lines ): RefundSnapshot {
		return new RefundSnapshot( $id, new DateTimeImmutable( '2026-09-02 09:00:00', new DateTimeZone( 'UTC' ) ), $lines );
	}

	/**
	 * Event keys in insertion order.
	 *
	 * @return list<string|null>
	 */
	private function keys(): array {
		return array_column( $this->rows( Tables::SALES ), 'event_key' );
	}

	/**
	 * Cycle events as "cycle:event" in insertion order.
	 *
	 * @return list<string>
	 */
	private function cycles(): array {
		return array_map( static fn( array $row ): string => $row['cycle'] . ':' . $row['event'], $this->rows( Tables::SALES_CYCLES ) );
	}
}
