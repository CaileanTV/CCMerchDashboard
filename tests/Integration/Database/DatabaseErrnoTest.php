<?php
/**
 * Error numbers per statement and the open-transaction flag (E3 T1), on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use mysqli;
use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use ReflectionProperty;
use wpdb;

/**
 * E3.10 test 32 (required by F-5 D-1 and D-3): the error number of a
 * failed statement is read right after it, for 1062, 1146, 1205 and 1213,
 * each provoked by the database itself (a duplicate key, a missing table, a
 * row lock held by a second connection, a real deadlock between two
 * connections); 0 after a successful statement; null when there is no
 * connection to read it from. Test 31: `@@in_transaction` on MariaDB
 * (E3.2 step 2a). Synthetic data.
 */
final class DatabaseErrnoTest extends IntegrationTestCase {

	private ?wpdb $second = null;

	private int $session_timeout = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->session_timeout = (int) $this->wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		$this->wpdb->suppress_errors( true );
	}

	protected function tearDown(): void {
		$this->wpdb->query( 'ROLLBACK' );
		$this->execute( 'SET SESSION innodb_lock_wait_timeout = %d', $this->session_timeout );

		if ( null !== $this->second ) {
			$this->second->query( 'ROLLBACK' );
			$this->second->close();
		}

		parent::tearDown();
	}

	public function test_a_successful_statement_reads_as_zero(): void {
		$this->wpdb->get_var( 'SELECT 1' );

		self::assertSame( 0, DatabaseErrno::last( $this->wpdb ) );
	}

	public function test_a_duplicate_key_reads_as_1062(): void {
		$sql = $this->wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $this->wpdb->options, 'siteurl', 'x', 'no' );

		self::assertFalse( $this->wpdb->query( (string) $sql ) );
		self::assertSame( 1062, DatabaseErrno::last( $this->wpdb ) );
	}

	public function test_a_missing_table_reads_as_1146(): void {
		self::assertNull( $this->wpdb->get_var( 'SELECT 1 FROM merchboard_t1_no_such_table' ) );
		self::assertSame( 1146, DatabaseErrno::last( $this->wpdb ) );
	}

	public function test_a_lock_wait_timeout_reads_as_1205(): void {
		$creator = $this->creator( 'Held' );
		$second  = $this->second();
		$second->query( 'START TRANSACTION' );
		self::assertSame( (string) $creator, $second->get_var( $this->lock_sql( $second, $creator ) ) );

		$this->wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$this->wpdb->query( 'START TRANSACTION' );
		self::assertNull( $this->wpdb->get_var( $this->lock_sql( $this->wpdb, $creator ) ) );
		self::assertSame( 1205, DatabaseErrno::last( $this->wpdb ) );
	}

	public function test_a_deadlock_reads_as_1213(): void {
		$own    = $this->creator( 'Own' );
		$others = array( $this->creator( 'Other 1' ), $this->creator( 'Other 2' ), $this->creator( 'Other 3' ) );
		$second = $this->second();

		// The second connection changes three rows (one by one, by primary
		// key, so only those rows are locked), so InnoDB rolls back the test's
		// lighter transaction (no changed row) when the cycle closes.
		$second->query( 'START TRANSACTION' );

		foreach ( $others as $other ) {
			$update = $second->prepare( 'UPDATE %i SET display_name = CONCAT(display_name, %s) WHERE id = %d', $this->table( Tables::CREATORS ), '+', $other );
			self::assertIsString( $update );
			self::assertSame( 1, $second->query( $update ) );
		}

		$this->wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 5' );
		$this->wpdb->query( 'START TRANSACTION' );
		self::assertSame( (string) $own, $this->wpdb->get_var( $this->lock_sql( $this->wpdb, $own ) ) );

		// The second connection now waits for the test's row (asynchronously).
		$dbh = $second->__get( 'dbh' );
		self::assertInstanceOf( mysqli::class, $dbh );
		$dbh->query( $this->lock_sql( $second, $own ), MYSQLI_ASYNC );

		// And the test's connection for one of the second's rows: a deadlock.
		self::assertNull( $this->wpdb->get_var( $this->lock_sql( $this->wpdb, $others[0] ) ) );
		self::assertSame( 1213, DatabaseErrno::last( $this->wpdb ) );

		// The victim's rollback released the row; the second connection got it.
		$result = $dbh->reap_async_query();
		self::assertNotFalse( $result, 'The second connection was not the victim.' );
	}

	public function test_a_connection_without_handle_reads_as_null(): void {
		$closed = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		( new ReflectionProperty( wpdb::class, 'dbh' ) )->setValue( $closed, null );

		self::assertNull( DatabaseErrno::last( $closed ) );
	}

	public function test_mariadb_reports_an_open_transaction(): void {
		if ( ! $this->is_mariadb() ) {
			self::markTestSkipped( 'E3.2 step 2a reads @@in_transaction on MariaDB only; MySQL 8.0 stays OPEN (ADR-038).' );
		}

		$flags   = array();
		$flags[] = $this->in_transaction();
		$this->wpdb->query( 'START TRANSACTION' );
		$flags[] = $this->in_transaction();
		$this->wpdb->query( 'COMMIT' );
		$flags[] = $this->in_transaction();
		$this->wpdb->query( 'START TRANSACTION' );
		$this->wpdb->query( 'ROLLBACK' );
		$flags[] = $this->in_transaction();

		self::assertSame( array( '0', '1', '0', '0' ), $flags, 'Outside, after START, after COMMIT, after ROLLBACK.' );
	}

	/**
	 * MariaDB's open-transaction flag of the test's connection.
	 */
	private function in_transaction(): string {
		return (string) $this->wpdb->get_var( 'SELECT @@in_transaction' );
	}

	/**
	 * Creates a creator.
	 *
	 * @param string $name Name.
	 */
	private function creator( string $name ): int {
		return ( new CreatorRepository( $this->wpdb ) )->create( $name, CreatorStatus::Active )->id;
	}

	/**
	 * Locks a creator's row FOR UPDATE.
	 *
	 * @param wpdb $connection Connection that prepares the statement.
	 * @param int  $creator    Creator.
	 */
	private function lock_sql( wpdb $connection, int $creator ): string {
		return (string) $connection->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $this->table( Tables::CREATORS ), $creator );
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
