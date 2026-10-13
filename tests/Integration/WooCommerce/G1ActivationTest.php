<?php
/**
 * The G1 activation frame (E3 T3) on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\RepeatableReadTransaction;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\WooCommerce\Payouts\G1\G1Activation;
use MerchBoard\Modules\WooCommerce\Payouts\G1\G1ActivationResult;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use wpdb;

/**
 * Failures come from the database itself (triggers that SIGNAL, a second
 * connection holding the row, KILL of the own connection, statements
 * replaced by a failing one through the `query` filter), not from doubles.
 * After every failure the activation row is inactive and no audit record
 * exists, except where the COMMIT already happened. Synthetic data only.
 */
final class G1ActivationTest extends IntegrationTestCase {

	private RecordingLogger $logger;

	private ?wpdb $second = null;

	/**
	 * Active `query` filters.
	 *
	 * @var list<Closure>
	 */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->logger = new RecordingLogger();
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( 'query', $filter );
		}

		$this->wpdb->query( 'ROLLBACK' );
		$this->wpdb->query( 'DROP TRIGGER IF EXISTS merchboard_test_fail' );

		if ( null !== $this->second ) {
			$this->second->query( 'ROLLBACK' );
			$this->second->close();
		}

		parent::tearDown();
	}

	public function test_activation_stores_the_cutoff_once_with_its_audit_record(): void {
		$cutoff = $this->days( 30 );
		$result = $this->activation()->activate( $cutoff );

		self::assertSame( G1ActivationResult::COMPLETED, $result->code );
		self::assertSame( $cutoff->format( 'Y-m-d H:i:s' ), $result->cutoff_utc );
		self::assertSame( 1, $result->activated_by );
		self::assertSame(
			array(
				'id'           => '1',
				'cutoff_utc'   => $result->cutoff_utc,
				'activated_at' => $result->activated_at,
				'activated_by' => '1',
			),
			$this->state()
		);
		self::assertLessThanOrEqual( 5, abs( strtotime( (string) $result->activated_at . ' UTC' ) - time() ), 'The database clock.' );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertCount( 1, $audit );
		self::assertSame( array( 'g1_activated', '1', null, '{"cutoff_utc":"' . $result->cutoff_utc . '"}' ), array( $audit[0]['action'], $audit[0]['actor_user_id'], $audit[0]['creator_id'], $audit[0]['context'] ) );
	}

	public function test_a_cutoff_before_the_activation_is_refused(): void {
		$result = $this->activation()->activate( $this->days( -1 ) );

		self::assertSame( array( G1ActivationResult::ERROR, 'cutoff_before_activation' ), array( $result->code, $result->reason ) );
		$this->assert_inactive();
	}

	public function test_an_active_row_is_never_overwritten_and_gives_conflict_with_the_stored_state(): void {
		$first  = $this->activation()->activate( $this->days( 10 ) );
		$second = $this->activation()->activate( $this->days( 20 ) );

		self::assertSame( G1ActivationResult::CONFLICT, $second->code );
		self::assertSame( array( $first->cutoff_utc, $first->activated_at, 1 ), array( $second->cutoff_utc, $second->activated_at, $second->activated_by ) );
		self::assertSame( $first->cutoff_utc, $this->state()['cutoff_utc'] );
		self::assertSame( 1, $this->count_rows( Tables::AUDIT_LOG ), 'One audit record.' );
	}

	public function test_an_old_or_missing_schema_version_is_refused(): void {
		$this->execute( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $this->wpdb->options, '11', Migrator::VERSION_OPTION );
		$old = $this->activation()->activate( $this->days( 1 ) );

		$this->execute( 'DELETE FROM %i WHERE option_name = %s', $this->wpdb->options, Migrator::VERSION_OPTION );
		$missing = $this->activation()->activate( $this->days( 1 ) );

		foreach ( array( $old, $missing ) as $result ) {
			self::assertSame( array( G1ActivationResult::ERROR, 'schema_version' ), array( $result->code, $result->reason ) );
		}

		$this->assert_inactive();
	}

	public function test_a_missing_activation_row_is_refused(): void {
		$this->execute( 'DELETE FROM %i', $this->table( Tables::G1_STATE ) );

		$result = $this->activation()->activate( $this->days( 1 ) );

		self::assertSame( array( G1ActivationResult::ERROR, 'state_row_missing' ), array( $result->code, $result->reason ) );
		self::assertSame( 0, $this->count_rows( Tables::G1_STATE ), 'The row is not created here.' );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_an_options_table_that_is_not_innodb_is_refused_before_start(): void {
		$statements = $this->replace( 'SELECT ENGINE FROM information_schema.TABLES', "SELECT 'MyISAM'" );

		$result = $this->activation()->activate( $this->days( 1 ) );

		self::assertSame( array( G1ActivationResult::ERROR, 'options_not_innodb' ), array( $result->code, $result->reason ) );
		self::assertNotContains( 'START TRANSACTION', $statements() );
		$this->assert_inactive();
	}

	public function test_a_row_held_by_another_connection_gives_busy(): void {
		$second = $this->second();
		$second->query( 'START TRANSACTION' );
		self::assertNotNull( $second->get_row( (string) $second->prepare( 'SELECT id FROM %i WHERE id = 1 FOR UPDATE', $this->table( Tables::G1_STATE ) ) ) );
		$this->wpdb->suppress_errors( true );

		$result = $this->activation()->activate( $this->days( 1 ) );

		self::assertSame( G1ActivationResult::BUSY, $result->code, 'Lock wait timeout 1205 (D-1).' );
		$second->query( 'ROLLBACK' );
		$this->assert_inactive();
	}

	/**
	 * Error numbers raised by a trigger on the activation and their result.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function update_failures(): array {
		return array(
			'deadlock'      => array( 1213, G1ActivationResult::BUSY ),
			'other failure' => array( 1644, G1ActivationResult::ERROR ),
		);
	}

	#[DataProvider( 'update_failures' )]
	public function test_a_failing_update_is_classified_by_its_error_number( int $errno, string $code ): void {
		$this->execute( "CREATE TRIGGER merchboard_test_fail BEFORE UPDATE ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = %d, MESSAGE_TEXT = 'forced failure'", $this->table( Tables::G1_STATE ), $errno );
		$this->wpdb->suppress_errors( true );

		$result = $this->activation()->activate( $this->days( 1 ) );

		self::assertSame( $code, $result->code );
		$this->assert_inactive();
	}

	public function test_a_failing_audit_record_rolls_the_activation_back(): void {
		$this->execute( "CREATE TRIGGER merchboard_test_fail BEFORE INSERT ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'", $this->table( Tables::AUDIT_LOG ) );
		$this->wpdb->suppress_errors( true );

		$result = $this->activation()->activate( $this->days( 1 ) );

		self::assertSame( array( G1ActivationResult::ERROR, 'db_error' ), array( $result->code, $result->reason ) );
		$this->assert_inactive();
	}

	public function test_a_lost_connection_before_commit_gives_outcome_unknown_and_writes_nothing(): void {
		$own = (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$this->kill_before( 'UPDATE ', $own );

		$result = $this->activation()->activate( $this->days( 1 ) );

		self::assertSame( G1ActivationResult::OUTCOME_UNKNOWN, $result->code, 'The repeated UPDATE wrote nothing on the new connection (D-5).' );
		self::assertNotSame( $own, (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' ), 'wpdb reconnected.' );
		$this->assert_inactive();
	}

	public function test_a_changed_connection_after_commit_gives_outcome_unknown(): void {
		$own = (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$this->kill_before( 'SELECT CONNECTION_ID()', $own, 'COMMIT' );

		$result = $this->activation()->activate( $this->days( 1 ) );

		self::assertSame( G1ActivationResult::OUTCOME_UNKNOWN, $result->code, 'E3.5(d).' );
		self::assertNotNull( $this->state()['activated_at'], 'The COMMIT happened.' );
	}

	public function test_a_failed_rollback_gives_outcome_unknown_a_critical_log_and_no_sql_that_can_write(): void {
		$this->execute( "CREATE TRIGGER merchboard_test_fail BEFORE INSERT ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'", $this->table( Tables::AUDIT_LOG ) );
		$this->wpdb->suppress_errors( true );
		$timeout    = (int) $this->wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout AS seconds' );
		$statements = $this->replace( 'ROLLBACK', 'SELECT * FROM merchboard_t3_no_such_table' );

		$result = $this->activation()->activate( $this->days( 1 ) );

		self::assertSame( G1ActivationResult::OUTCOME_UNKNOWN, $result->code );
		self::assertSame(
			array(
				array(
					'level'   => 'error',
					'message' => 'The G1 activation could not be rolled back; its outcome is unknown.',
					'context' => array( 'severity' => 'critical' ),
				),
			),
			$this->logger->entries
		);

		$all   = $statements();
		$after = array_slice( $all, (int) array_search( 'ROLLBACK', $all, true ) + 1 );
		self::assertSame( array( 'SET SESSION innodb_lock_wait_timeout = ' . $timeout ), $after, 'Only the timeout restore of T1 follows.' );

		// The transaction is still open with the UPDATE; nothing was committed.
		self::assertNull( $this->second()->get_var( (string) $this->second()->prepare( 'SELECT activated_at FROM %i WHERE id = 1', $this->table( Tables::G1_STATE ) ) ) );

		if ( $this->is_mariadb() ) {
			self::assertSame( '1', $this->wpdb->get_var( 'SELECT @@in_transaction' ) );
		}

		foreach ( $this->filters as $filter ) {
			remove_filter( 'query', $filter );
		}

		$this->filters = array();
		$this->wpdb->query( 'ROLLBACK' );
		$this->assert_inactive();
	}

	public function test_a_failed_commit_gives_outcome_unknown(): void {
		$this->replace( 'COMMIT', 'SELECT * FROM merchboard_t3_no_such_table' );
		$this->wpdb->suppress_errors( true );

		$result = $this->activation()->activate( $this->days( 1 ) );

		self::assertSame( G1ActivationResult::OUTCOME_UNKNOWN, $result->code );
		$this->assert_inactive();
	}

	public function test_every_statement_failing_alone_is_classified_and_leaves_nothing_behind(): void {
		$statements = $this->replace( null, '' );
		self::assertSame( G1ActivationResult::COMPLETED, $this->activation()->activate( $this->days( 1 ) )->code );
		$run = $statements();
		$this->reset();
		self::assertGreaterThanOrEqual( 12, count( $run ) );

		foreach ( $run as $index => $sql ) {
			$this->fail_statement( $index );
			$this->wpdb->suppress_errors( true );
			$result = $this->activation()->activate( $this->days( 1 ) );
			$active = null !== $this->state()['activated_at'];

			[ $code, $expect_active ] = match ( true ) {
				'COMMIT' === $sql                                               => array( G1ActivationResult::OUTCOME_UNKNOWN, false ),
				'SELECT CONNECTION_ID()' === $sql && $index > array_search( 'COMMIT', $run, true ) => array( G1ActivationResult::OUTCOME_UNKNOWN, true ),
				str_starts_with( $sql, 'SET SESSION innodb_lock_wait_timeout' ) && $index > array_search( 'COMMIT', $run, true ) => array( G1ActivationResult::COMPLETED, true ),
				default                                                         => array( G1ActivationResult::ERROR, false ),
			};

			self::assertSame( $code, $result->code, "Statement {$index}: {$sql}" );
			self::assertSame( $expect_active, $active, "Statement {$index}: {$sql}" );
			self::assertSame( $expect_active ? 1 : 0, $this->count_rows( Tables::AUDIT_LOG ), "Statement {$index}: {$sql}" );

			foreach ( $this->filters as $filter ) {
				remove_filter( 'query', $filter );
			}

			$this->filters = array();
			$this->wpdb->query( 'ROLLBACK' );
			$this->reset();
		}
	}

	/**
	 * The activation on the test's connection, with a cold engine check and a 1-second lock wait.
	 */
	private function activation(): G1Activation {
		$support     = new TransactionSupport( $this->wpdb );
		$transaction = new Transaction( $this->wpdb, $support );

		return new G1Activation(
			$this->wpdb,
			$transaction,
			new RepeatableReadTransaction( $this->wpdb, $transaction, $support, $this->logger ),
			new AuditTrail( new AuditRepository( $this->wpdb ) ),
			$this->logger,
			1
		);
	}

	/**
	 * A UTC time some days from now, without seconds' fractions.
	 *
	 * @param int $days Days (negative: in the past).
	 */
	private function days( int $days ): DateTimeImmutable {
		return ( new DateTimeImmutable( '@' . ( time() + $days * DAY_IN_SECONDS ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The activation row as stored.
	 *
	 * @return array<string, string|null>
	 */
	private function state(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $this->table( Tables::G1_STATE ) ), ARRAY_A );
		self::assertIsArray( $rows );
		self::assertCount( 1, $rows );

		return array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $rows[0] );
	}

	/**
	 * The activation row is inactive and no audit record exists.
	 */
	private function assert_inactive(): void {
		self::assertSame(
			array(
				'id'           => '1',
				'cutoff_utc'   => null,
				'activated_at' => null,
				'activated_by' => null,
			),
			$this->state()
		);
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	/**
	 * Back to an inactive row and an empty audit log.
	 */
	private function reset(): void {
		$this->execute( 'UPDATE %i SET cutoff_utc = NULL, activated_at = NULL, activated_by = NULL WHERE id = 1', $this->table( Tables::G1_STATE ) );
		$this->execute( 'DELETE FROM %i', $this->table( Tables::AUDIT_LOG ) );
	}

	/**
	 * Records every statement of the test's connection and replaces the
	 * ones starting with $prefix (null: none).
	 *
	 * @param string|null $prefix      Statement prefix.
	 * @param string      $replacement Statement run instead.
	 * @return Closure(): list<string> The statements seen so far.
	 */
	private function replace( ?string $prefix, string $replacement ): Closure {
		$seen   = array();
		$filter = static function ( string $sql ) use ( $prefix, $replacement, &$seen ): string {
			$seen[] = $sql;

			return null !== $prefix && str_starts_with( $sql, $prefix ) ? $replacement : $sql;
		};
		add_filter( 'query', $filter );
		$this->filters[] = $filter;

		return static function () use ( &$seen ): array {
			return $seen;
		};
	}

	/**
	 * Replaces only the statement at $index of the next run with one that fails (1146).
	 *
	 * @param int $index Statement index.
	 */
	private function fail_statement( int $index ): void {
		$count  = 0;
		$filter = static function ( string $sql ) use ( $index, &$count ): string {
			return $index === $count++ ? 'SELECT * FROM merchboard_t3_no_such_table' : $sql;
		};
		add_filter( 'query', $filter );
		$this->filters[] = $filter;
	}

	/**
	 * Kills the test's connection from a second one right before the first
	 * statement starting with $prefix (after $after, when given).
	 *
	 * @param string      $prefix Statement prefix.
	 * @param int         $own    The test connection's id.
	 * @param string|null $after  Statement that must have run first.
	 */
	private function kill_before( string $prefix, int $own, ?string $after = null ): void {
		$second = $this->second();
		$armed  = null === $after;
		$done   = false;
		$filter = static function ( string $sql ) use ( $prefix, $own, $after, $second, &$armed, &$done ): string {
			if ( ! $armed && $sql === $after ) {
				$armed = true;

				return $sql;
			}

			if ( $armed && ! $done && str_starts_with( $sql, $prefix ) ) {
				$done = true;
				$second->query( (string) $second->prepare( 'KILL %d', $own ) );
				usleep( 200000 );
			}

			return $sql;
		};
		add_filter( 'query', $filter );
		$this->filters[] = $filter;
		$this->wpdb->suppress_errors( true );
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
