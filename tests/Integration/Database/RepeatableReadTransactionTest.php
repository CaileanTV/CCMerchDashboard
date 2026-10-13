<?php
/**
 * REPEATABLE READ for the next transaction and its lock wait timeout (E3 T1), on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use Closure;
use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\RepeatableReadTransaction;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use wpdb;

/**
 * E3.10 tests 9-12. Isolation is observed by behaviour: the test's
 * connection reads a creator's name, a second, real connection renames it
 * and commits, and the test's connection reads again. Still the first name:
 * REPEATABLE READ; the new name: READ COMMITTED or READ UNCOMMITTED; the
 * rename waiting for the first read's lock (1-second InnoDB wait on the
 * second connection): SERIALIZABLE. Session isolation and lock wait timeout
 * of the test's connection are restored after every test. Synthetic data.
 */
final class RepeatableReadTransactionTest extends IntegrationTestCase {

	private ?wpdb $second = null;

	private int $creator = 0;

	private int $session_timeout = 0;

	private RecordingLogger $logger;

	/**
	 * The statements seen by the `query` filter while recording.
	 *
	 * @var list<string>
	 */
	private array $statements = array();

	/**
	 * The active `query` filter, if any.
	 *
	 * @var Closure|null
	 */
	private ?Closure $filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->session_timeout = $this->timeout();
		$this->logger          = new RecordingLogger();
		$this->creator         = ( new CreatorRepository( $this->wpdb ) )->create( 'Before', CreatorStatus::Active )->id;
	}

	protected function tearDown(): void {
		if ( null !== $this->filter ) {
			remove_filter( 'query', $this->filter );
		}

		$this->wpdb->query( 'ROLLBACK' );
		$this->wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->execute( 'SET SESSION innodb_lock_wait_timeout = %d', $this->session_timeout );

		if ( null !== $this->second ) {
			$this->second->query( 'ROLLBACK' );
			$this->second->close();
		}

		parent::tearDown();
	}

	public function test_set_transaction_applies_to_the_next_transaction_only(): void {
		$this->set_session_level( 'READ COMMITTED' );

		$this->wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->wpdb->query( 'START TRANSACTION' );
		self::assertSame( 'repeatable', $this->probe() );
		$this->wpdb->query( 'COMMIT' );

		$this->wpdb->query( 'START TRANSACTION' );
		self::assertSame( 'committed', $this->probe(), 'The next transaction runs at the session level again.' );
		$this->wpdb->query( 'COMMIT' );
	}

	public function test_an_autocommit_statement_in_between_uses_up_set_transaction(): void {
		$this->set_session_level( 'READ COMMITTED' );

		$this->wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->execute( 'SELECT COUNT(*) FROM %i', $this->table( Tables::CREATORS ) );
		$this->wpdb->query( 'START TRANSACTION' );
		self::assertSame( 'committed', $this->probe(), 'The statement in between was the "next transaction".' );
		$this->wpdb->query( 'COMMIT' );
	}

	public function test_the_session_isolation_variable_has_the_expected_name(): void {
		$this->set_session_level( 'READ COMMITTED' );

		self::assertSame( 'READ-COMMITTED', $this->session_level() );
	}

	/**
	 * Session levels and their variable values.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function levels(): array {
		return array(
			'read uncommitted' => array( 'READ UNCOMMITTED', 'READ-UNCOMMITTED' ),
			'read committed'   => array( 'READ COMMITTED', 'READ-COMMITTED' ),
			'repeatable read'  => array( 'REPEATABLE READ', 'REPEATABLE-READ' ),
			'serializable'     => array( 'SERIALIZABLE', 'SERIALIZABLE' ),
		);
	}

	#[DataProvider( 'levels' )]
	public function test_the_transaction_runs_at_repeatable_read_whatever_the_session_level( string $level, string $value ): void {
		$this->set_session_level( $level );

		self::assertSame( 'repeatable', $this->helper()->run( fn (): string => $this->probe(), 5 ) );
		self::assertSame( $value, $this->session_level(), 'The session level is unchanged.' );
	}

	public function test_set_transaction_is_the_last_statement_before_start_also_with_a_cold_engine_check(): void {
		$helper = $this->helper();
		$this->record_queries();

		$helper->run( static fn (): bool => true, 5 );

		$start = array_search( 'START TRANSACTION', $this->statements, true );
		self::assertIsInt( $start );
		self::assertSame( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $this->statements[ $start - 1 ] );
		self::assertNotEmpty(
			array_filter( array_slice( $this->statements, 0, $start - 1 ), static fn( string $sql ): bool => str_contains( $sql, 'information_schema' ) ),
			'The cold engine check ran before SET TRANSACTION.'
		);
	}

	public function test_a_failing_first_engine_check_refuses_before_set_transaction_and_start(): void {
		$helper       = $this->helper();
		$checks       = 0;
		$this->filter = function ( string $sql ) use ( &$checks ): string {
			$this->statements[] = $sql;

			// Only the first engine check fails; a later one would pass.
			if ( str_contains( $sql, 'information_schema.TABLES t LEFT JOIN' ) && 1 === ++$checks ) {
				return 'SELECT * FROM merchboard_t1_no_such_table';
			}

			return $sql;
		};
		add_filter( 'query', $this->filter );
		$this->wpdb->suppress_errors( true );
		$ran = false;

		try {
			$helper->run(
				static function () use ( &$ran ): void {
					$ran = true;
				},
				7
			);
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Database tables do not support transactions; write refused.', $e->getMessage() );
		}

		self::assertFalse( $ran, 'The work did not run.' );
		self::assertSame( 1, $checks, 'Refused at the first check; run() did not check again.' );
		self::assertNotContains( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $this->statements );
		self::assertNotContains( 'START TRANSACTION', $this->statements );
		self::assertSame( $this->session_timeout, $this->timeout(), 'The timeout is restored.' );
	}

	public function test_an_open_transaction_refuses_set_transaction_and_stays_open(): void {
		$this->wpdb->suppress_errors( true );
		$this->wpdb->query( 'START TRANSACTION' );
		$this->execute( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $this->wpdb->options, 'merchboard_t1_open', '1', 'no' );

		self::assertFalse( $this->wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) );
		self::assertSame( 1568, DatabaseErrno::last( $this->wpdb ), 'Read right after the statement.' );

		$helper = $this->helper();
		$this->record_queries();
		$ran = false;

		try {
			$helper->run(
				static function () use ( &$ran ): void {
					$ran = true;
				},
				7
			);
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not set the transaction isolation level.', $e->getMessage() );
		}

		self::assertFalse( $ran, 'The work did not run.' );
		self::assertNotContains( 'START TRANSACTION', $this->statements );
		self::assertSame( $this->session_timeout, $this->timeout(), 'The timeout is restored.' );
		self::assertNull(
			$this->second()->get_var( $this->second()->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->wpdb->options, 'merchboard_t1_open' ) ),
			'The open transaction was not committed.'
		);

		if ( $this->is_mariadb() ) {
			self::assertSame( '1', $this->wpdb->get_var( 'SELECT @@in_transaction' ) );
		}

		$this->wpdb->query( 'ROLLBACK' );
	}

	public function test_the_timeout_is_set_for_the_transaction_and_restored_after_commit_and_rollback(): void {
		self::assertNotSame( 7, $this->session_timeout );

		self::assertSame( 7, $this->helper()->run( fn (): int => $this->timeout(), 7 ) );
		self::assertSame( $this->session_timeout, $this->timeout(), 'Restored after COMMIT.' );

		try {
			$this->helper()->run(
				self::failing_work(),
				7
			);
			self::fail( 'Expected RuntimeException.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'Work failed.', $e->getMessage() );
		}

		self::assertSame( $this->session_timeout, $this->timeout(), 'Restored after ROLLBACK.' );
	}

	/**
	 * Statements of the timeout setup and the error they cause.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function setup_statements(): array {
		return array(
			'read' => array( 'SELECT @@SESSION.innodb_lock_wait_timeout', 'Could not read the lock wait timeout.' ),
			'set'  => array( 'SET SESSION innodb_lock_wait_timeout = 7', 'Could not set the lock wait timeout.' ),
		);
	}

	#[DataProvider( 'setup_statements' )]
	public function test_a_failing_timeout_setup_ends_before_start_without_work( string $statement, string $message ): void {
		$helper = $this->helper();
		$this->break_statement( $statement );
		$ran = false;

		try {
			$helper->run(
				static function () use ( &$ran ): void {
					$ran = true;
				},
				7
			);
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( $message, $e->getMessage() );
		}

		self::assertFalse( $ran, 'The work did not run.' );
		self::assertNotContains( 'START TRANSACTION', $this->statements );
		self::assertSame( $this->session_timeout, $this->timeout() );
	}

	public function test_a_timeout_below_one_second_is_refused_before_any_statement(): void {
		$helper = $this->helper();
		$this->record_queries();

		try {
			$helper->run( static fn (): bool => true, 0 );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'The lock wait timeout must be at least one second.', $e->getMessage() );
		}

		self::assertSame( array(), $this->statements );
	}

	public function test_a_failing_restore_is_logged_and_keeps_the_result_or_the_original_error(): void {
		$helper = $this->helper();
		$this->break_statement( 'SET SESSION innodb_lock_wait_timeout = ' . $this->session_timeout );

		self::assertSame( 'done', $helper->run( static fn (): string => 'done', 7 ) );
		self::assertSame( array( 'Could not restore the lock wait timeout.' ), array_column( $this->logger->at( 'error' ), 'message' ) );
		self::assertSame( 7, $this->timeout(), 'Not restored.' );

		// Back to the session's value (worded differently from the broken statement).
		$this->wpdb->query( 'SET @@SESSION.innodb_lock_wait_timeout = ' . $this->session_timeout );

		try {
			$helper->run(
				self::failing_work(),
				7
			);
			self::fail( 'Expected RuntimeException.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'Work failed.', $e->getMessage(), 'The original error is kept.' );
		}

		self::assertCount( 2, $this->logger->at( 'error' ) );
	}

	/**
	 * Work that throws.
	 *
	 * @return Closure(): void
	 */
	private static function failing_work(): Closure {
		return static function (): void {
			throw new RuntimeException( 'Work failed.' );
		};
	}

	/**
	 * The helper with a cold engine check (fresh TransactionSupport, shared with its Transaction).
	 */
	private function helper(): RepeatableReadTransaction {
		$support = new TransactionSupport( $this->wpdb );

		return new RepeatableReadTransaction( $this->wpdb, new Transaction( $this->wpdb, $support ), $support, $this->logger );
	}

	/**
	 * Reads the creator's name, lets the second connection rename it and
	 * commit, and reads it again.
	 *
	 * @return string 'repeatable', 'committed' or 'blocked'.
	 */
	private function probe(): string {
		$first  = $this->creator_name();
		$second = $this->second();
		$rename = $second->prepare( 'UPDATE %i SET display_name = %s WHERE id = %d', $this->table( Tables::CREATORS ), $first . '+', $this->creator );
		self::assertIsString( $rename );
		$renamed = $second->query( $rename );

		if ( false === $renamed ) {
			self::assertSame( 1205, DatabaseErrno::last( $second ), 'The rename waited for a lock.' );

			return 'blocked';
		}

		return $first === $this->creator_name() ? 'repeatable' : 'committed';
	}

	/**
	 * The creator's name as the test's connection sees it.
	 */
	private function creator_name(): string {
		return (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT display_name FROM %i WHERE id = %d', $this->table( Tables::CREATORS ), $this->creator ) );
	}

	/**
	 * Sets the session isolation level of the test's connection.
	 *
	 * @param string $level Level.
	 */
	private function set_session_level( string $level ): void {
		$sql = match ( $level ) {
			'READ UNCOMMITTED' => 'SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
			'READ COMMITTED'   => 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
			'REPEATABLE READ'  => 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ',
			'SERIALIZABLE'     => 'SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE',
			default            => throw new RuntimeException( 'Unknown isolation level.' ),
		};

		self::assertNotFalse( $this->wpdb->query( $sql ) );
	}

	/**
	 * The session isolation level: `tx_isolation` on MariaDB 10.11,
	 * `transaction_isolation` on MySQL 8.0 (E3.10 test 9).
	 */
	private function session_level(): string {
		$level = $this->is_mariadb()
			? $this->wpdb->get_var( 'SELECT @@SESSION.tx_isolation' )
			: $this->wpdb->get_var( 'SELECT @@SESSION.transaction_isolation' );

		self::assertSame( '', $this->wpdb->last_error );

		return (string) $level;
	}

	/**
	 * The session's InnoDB lock wait timeout on the test's connection (worded
	 * differently from the helper's statements, so break_statement() never
	 * hits it).
	 */
	private function timeout(): int {
		return (int) $this->wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout AS seconds' );
	}

	/**
	 * Records every statement of the test's connection from now on.
	 */
	private function record_queries(): void {
		$this->break_statement( null );
	}

	/**
	 * Records every statement and replaces $statement (exactly) with one
	 * that fails.
	 *
	 * @param string|null $statement Statement to break, or null to only record.
	 */
	private function break_statement( ?string $statement ): void {
		$this->wpdb->suppress_errors( true );
		$this->filter = function ( string $sql ) use ( $statement ): string {
			$this->statements[] = $sql;

			return $sql === $statement ? 'SELECT * FROM merchboard_t1_no_such_table' : $sql;
		};
		add_filter( 'query', $this->filter );
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
}
