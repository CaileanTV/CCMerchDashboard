<?php
/**
 * The E3 frame with F-5's registry (E3 T4) on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use Closure;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\RepeatableReadTransaction;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Operations\Operation;
use MerchBoard\Modules\WooCommerce\Operations\OperationFrame;
use MerchBoard\Modules\WooCommerce\Operations\OperationResult;
use MerchBoard\Modules\WooCommerce\Payouts\CreatorRowLocks;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use wpdb;

/**
 * No flow calls the frame yet, so a test operation stands in for one: a
 * `g1_decision` that turns the order's `pending` G1 assessment into `paid`
 * with a guarded UPDATE (the expected prior state is `pending`). Failures
 * come from the database itself (triggers, a second connection holding a
 * lock, KILL of the own connection, statements replaced by a failing one
 * through the `query` filter). After every attempt the registry row, the
 * state change and the audit record exist together or not at all.
 * Synthetic data only.
 */
final class OperationFrameTest extends IntegrationTestCase {

	private const ORDER = 77;

	private const ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private const HASH = 'a3f5c0d2e1b4968700112233445566778899aabbccddeeff0011223344556677';

	private RecordingLogger $logger;

	private ?wpdb $second = null;

	private int $creator = 0;

	private int $mutations = 0;

	/**
	 * Active `query` filters.
	 *
	 * @var list<Closure>
	 */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->logger  = new RecordingLogger();
		$this->creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Operations', CreatorStatus::Active )->id;
		self::assertSame(
			1,
			$this->wpdb->insert(
				$this->table( Tables::SALES ),
				array(
					'event_key'     => 's:77:1',
					'creator_id'    => $this->creator,
					'order_id'      => self::ORDER,
					'order_item_id' => 1,
					'cycle'         => 1,
					'product_id'    => 5,
					'type'          => 'sale',
					'quantity'      => 1,
					'net_amount'    => '100.0000',
					'currency'      => 'DKK',
					'occurred_at'   => '2026-12-02 10:00:00',
					'created_at'    => '2026-12-02 10:00:00',
				)
			)
		);
		$this->execute( 'INSERT INTO %i (order_id, status, updated_at) VALUES (%d, %s, %s)', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), self::ORDER, 'pending', '2026-12-02 10:00:00' );
	}

	protected function tearDown(): void {
		$this->remove_filters();
		$this->wpdb->query( 'ROLLBACK' );
		$this->wpdb->query( 'SELECT RELEASE_ALL_LOCKS()' );
		$this->wpdb->query( 'DROP TRIGGER IF EXISTS merchboard_test_fail' );

		if ( null !== $this->second ) {
			$this->second->query( 'ROLLBACK' );
			$this->second->query( 'SELECT RELEASE_ALL_LOCKS()' );
			$this->second->close();
		}

		parent::tearDown();
	}

	public function test_an_operation_is_applied_once_with_its_registry_row_and_audit_record(): void {
		$result = $this->run_operation();

		self::assertSame( OperationResult::COMPLETED, $result->code );
		self::assertSame( 1, $this->mutations );
		self::assertSame( 'paid', $this->assessment_status() );
		self::assertSame(
			array(
				'operation_id'  => self::ID,
				'kind'          => 'g1_decision',
				'subject_id'    => (string) self::ORDER,
				'payload_hash'  => self::HASH,
				'actor_user_id' => '1',
			),
			array_diff_key( $this->registry()[0], array( 'created_at' => true ) )
		);
		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertCount( 1, $audit );
		self::assertSame( '{"order_id":77,"operation_id":"' . self::ID . '"}', $audit[0]['context'] );
		self::assertSame(
			array( 'order_history_deleted', '1', null ),
			array(
				$audit[0]['action'],
				$audit[0]['actor_user_id'],
				$audit[0]['creator_id'],
			),
			'The caller\'s action, the current user, no creator.'
		);

		// The same operation again: completed by the pre-check, nothing written.
		self::assertSame( OperationResult::COMPLETED, $this->run_operation()->code );
		self::assertSame( 1, $this->mutations, 'Not applied twice.' );
		self::assertCount( 1, $this->registry() );
		self::assertSame( 1, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_a_reused_id_with_other_content_gives_operation_mismatch(): void {
		$this->run_operation();
		$other_hash = new Operation( self::ID, 'g1_decision', self::ORDER, str_repeat( 'b', 64 ) );
		$other_kind = new Operation( self::ID, 'g1_finalize', self::ORDER, self::HASH );

		foreach ( array( $other_hash, $other_kind ) as $operation ) {
			self::assertSame( OperationResult::OPERATION_MISMATCH, $this->run_operation( $operation )->code );
		}

		self::assertSame( 1, $this->mutations );
		self::assertSame( 1, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_a_changed_prior_state_gives_conflict_without_writes(): void {
		$this->set_status( 'paid' );

		self::assertSame( OperationResult::CONFLICT, $this->run_operation()->code );
		$this->assert_nothing_written( 'paid' );
	}

	public function test_the_order_lock_held_elsewhere_gives_busy(): void {
		self::assertSame( '1', (string) $this->second()->get_var( (string) $this->second()->prepare( 'SELECT GET_LOCK(%s, 0)', $this->sales()->lock_name( self::ORDER ) ) ) );

		self::assertSame( OperationResult::BUSY, $this->run_operation()->code );
		$this->assert_nothing_written();
	}

	public function test_a_row_lock_held_elsewhere_gives_busy(): void {
		$second = $this->second();
		$second->query( 'START TRANSACTION' );
		self::assertNotNull( $second->get_var( (string) $second->prepare( 'SELECT order_id FROM %i WHERE order_id = %d FOR UPDATE', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), self::ORDER ) ) );
		$this->wpdb->suppress_errors( true );

		self::assertSame( OperationResult::BUSY, $this->run_operation()->code, '1205 (D-1).' );
		$second->query( 'ROLLBACK' );
		$this->assert_nothing_written();
	}

	public function test_the_creator_rows_are_locked_while_the_mutation_runs(): void {
		$second  = $this->second();
		$blocked = null;
		$result  = $this->run_operation(
			null,
			function () use ( $second, &$blocked ): void {
				$second->query( 'START TRANSACTION' );
				$second->get_var( (string) $second->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $this->table( Tables::CREATORS ), $this->creator ) );
				$blocked = DatabaseErrno::last( $second );
				$second->query( 'ROLLBACK' );
			}
		);

		self::assertSame( OperationResult::COMPLETED, $result->code );
		self::assertSame( 1205, $blocked, 'R1 holds the creator row.' );
	}

	public function test_a_duplicate_registry_id_is_reconciled(): void {
		// Another attempt registered the same operation; this one's pre-check missed it.
		$this->register( self::HASH );
		$this->hide_first_registry_read();
		$this->wpdb->suppress_errors( true );

		self::assertSame( OperationResult::COMPLETED, $this->run_operation()->code, 'The duplicate (1062) is reconciled: same content.' );
		$this->assert_nothing_written( 'pending', 1 );
	}

	public function test_a_duplicate_registry_id_with_other_content_gives_operation_mismatch(): void {
		$this->register( str_repeat( 'c', 64 ) );
		$this->hide_first_registry_read();
		$this->wpdb->suppress_errors( true );

		self::assertSame( OperationResult::OPERATION_MISMATCH, $this->run_operation()->code );
		$this->assert_nothing_written( 'pending', 1 );
	}

	public function test_a_failed_commit_is_unknown_and_reconciles_to_retry_safe_then_applies_once(): void {
		$this->replace( 'COMMIT', 'SELECT * FROM merchboard_t4_no_such_table' );
		$this->wpdb->suppress_errors( true );

		self::assertSame( OperationResult::OUTCOME_UNKNOWN, $this->run_operation()->code );
		$this->remove_filters();
		$this->assert_nothing_written();

		self::assertSame( OperationResult::RETRY_SAFE, $this->frame()->reconcile( $this->operation(), $this->admit() )->code );
		self::assertSame( OperationResult::COMPLETED, $this->run_operation()->code );
		self::assertCount( 1, $this->registry() );
		self::assertSame( 1, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_a_lost_connection_before_the_registry_row_is_unknown_and_writes_nothing(): void {
		$own = (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$this->kill_before( 'INSERT INTO `' . $this->table( Tables::OPERATIONS ) . '`', $own );

		self::assertSame( OperationResult::OUTCOME_UNKNOWN, $this->run_operation()->code, 'D-5.' );
		$this->remove_filters();
		$this->assert_nothing_written();
		self::assertSame( OperationResult::RETRY_SAFE, $this->frame()->reconcile( $this->operation(), $this->admit() )->code );
	}

	public function test_a_changed_connection_after_commit_is_unknown_and_reconciles_to_completed(): void {
		$own = (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$this->kill_before( 'SELECT CONNECTION_ID()', $own, 'COMMIT' );

		self::assertSame( OperationResult::OUTCOME_UNKNOWN, $this->run_operation()->code, 'E3.5(d).' );
		$this->remove_filters();
		self::assertSame( 'paid', $this->assessment_status(), 'The COMMIT happened.' );
		self::assertSame( OperationResult::COMPLETED, $this->frame()->reconcile( $this->operation(), $this->admit() )->code );
	}

	public function test_a_failed_rollback_is_unknown_logged_as_critical_and_runs_no_sql_that_can_write(): void {
		$this->execute( "CREATE TRIGGER merchboard_test_fail BEFORE INSERT ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'", $this->table( Tables::AUDIT_LOG ) );
		$this->wpdb->suppress_errors( true );
		$statements = $this->replace( 'ROLLBACK', 'SELECT * FROM merchboard_t4_no_such_table' );

		self::assertSame( OperationResult::OUTCOME_UNKNOWN, $this->run_operation()->code );
		self::assertSame(
			array(
				array(
					'level'   => 'error',
					'message' => 'An order operation could not be rolled back; its outcome is unknown.',
					'context' => array( 'severity' => 'critical' ),
				),
			),
			$this->logger->entries
		);

		$all   = $statements();
		$after = array_slice( $all, (int) array_search( 'ROLLBACK', $all, true ) + 1 );
		self::assertCount( 2, $after );
		self::assertStringStartsWith( 'SET SESSION innodb_lock_wait_timeout = ', $after[0], 'The timeout restore of T1.' );
		self::assertStringStartsWith( 'SELECT RELEASE_LOCK(', $after[1], 'The release of the order lock.' );

		// Still open and uncommitted: the second connection sees nothing.
		self::assertSame( '0', (string) $this->second()->get_var( (string) $this->second()->prepare( 'SELECT COUNT(*) FROM %i', $this->table( Tables::OPERATIONS ) ) ) );
		self::assertSame( 'pending', $this->second()->get_var( (string) $this->second()->prepare( 'SELECT status FROM %i WHERE order_id = %d', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), self::ORDER ) ) );

		$this->remove_filters();
		$this->wpdb->query( 'ROLLBACK' );
		$this->assert_nothing_written();
	}

	public function test_a_g1_status_that_changed_before_the_locks_gives_busy(): void {
		$second = $this->second();
		$filter = function ( string $sql ) use ( $second ): string {
			if ( 'START TRANSACTION' === $sql ) {
				$second->query( (string) $second->prepare( 'UPDATE %i SET cutoff_utc = %s, activated_at = %s, activated_by = 1 WHERE id = 1', $this->table( Tables::G1_STATE ), '2027-01-01 00:00:00', '2026-12-01 00:00:00' ) );
			}

			return $sql;
		};
		$this->add_filter( $filter );

		self::assertSame( OperationResult::BUSY, $this->run_operation()->code, 'Activated between step 2b and R0.' );
		$this->assert_nothing_written();
	}

	public function test_an_assessment_deleted_before_the_locks_is_an_error(): void {
		$second = $this->second();
		$filter = function ( string $sql ) use ( $second ): string {
			if ( 'START TRANSACTION' === $sql ) {
				$second->query( (string) $second->prepare( 'DELETE FROM %i WHERE order_id = %d', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), self::ORDER ) );
			}

			return $sql;
		};
		$this->add_filter( $filter );

		$result = $this->run_operation();

		self::assertSame( array( OperationResult::ERROR, 'assessment_missing' ), array( $result->code, $result->reason ) );
		self::assertSame( 'The G1 assessment disappeared under the order lock.', $this->logger->entries[0]['message'] ?? null );
		self::assertCount( 0, $this->registry() );
	}

	public function test_an_open_transaction_is_refused_on_mariadb(): void {
		if ( ! $this->is_mariadb() ) {
			self::markTestSkipped( 'E3.2 step 2a is MariaDB only; MySQL 8.0 has no verified check.' );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		$result = $this->run_operation();
		$this->wpdb->query( 'ROLLBACK' );

		self::assertSame( array( OperationResult::ERROR, 'open_transaction' ), array( $result->code, $result->reason ) );
		$this->assert_nothing_written();
	}

	public function test_every_statement_failing_alone_leaves_registry_state_and_audit_consistent(): void {
		$statements = $this->replace( null, '' );
		self::assertSame( OperationResult::COMPLETED, $this->run_operation()->code );
		$run = $statements();
		$this->remove_filters();
		$this->reset();
		self::assertGreaterThanOrEqual( 15, count( $run ) );

		foreach ( $run as $index => $sql ) {
			$this->fail_statement( $index );
			$this->wpdb->suppress_errors( true );
			$result = $this->run_operation();
			$this->remove_filters();
			$this->wpdb->query( 'ROLLBACK' );

			$registered = count( $this->registry() );
			$audited    = $this->count_rows( Tables::AUDIT_LOG );
			$changed    = 'paid' === $this->assessment_status();

			self::assertSame( $registered, $audited, "Statement {$index}: {$sql}" );
			self::assertSame( 1 === $registered, $changed, "Statement {$index}: {$sql}" );
			self::assertContains(
				$result->code,
				$changed ? array( OperationResult::COMPLETED, OperationResult::OUTCOME_UNKNOWN ) : array( OperationResult::ERROR, OperationResult::BUSY, OperationResult::OUTCOME_UNKNOWN, OperationResult::RETRY_SAFE ),
				"Statement {$index}: {$sql}"
			);

			$this->reset();
		}
	}

	/**
	 * Runs the test operation.
	 *
	 * @param Operation|null $operation Operation (default: the test operation).
	 * @param Closure|null   $during    Runs inside the mutation, after the UPDATE.
	 */
	private function run_operation( ?Operation $operation = null, ?Closure $during = null ): OperationResult {
		$wpdb     = $this->wpdb;
		$table    = $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS );
		$mutation = function ( array $state ) use ( $wpdb, $table, $during ): void {
			$sql = $wpdb->prepare( 'UPDATE %i SET status = %s WHERE order_id = %d AND status = %s AND CONNECTION_ID() = %d', $table, 'paid', $state['order_id'], 'pending', $state['connection_id'] );

			if ( 1 !== $wpdb->query( (string) $sql ) ) {
				throw new DatabaseException( 'Not applied.' );
			}

			++$this->mutations;

			if ( null !== $during ) {
				$during();
			}
		};

		return $this->frame()->execute( $operation ?? $this->operation(), $this->admit(), $mutation, AuditAction::OrderHistoryDeleted, null, array( 'order_id' => self::ORDER ) );
	}

	/**
	 * The test operation.
	 */
	private function operation(): Operation {
		return new Operation( self::ID, 'g1_decision', self::ORDER, self::HASH );
	}

	/**
	 * Expected prior state: the assessment is pending.
	 */
	private function admit(): Closure {
		return static fn( array $state ): bool => 'pending' === ( $state['assessment']['status'] ?? null );
	}

	/**
	 * The frame on the test's connection, with 1-second lock waits.
	 */
	private function frame(): OperationFrame {
		$support     = new TransactionSupport( $this->wpdb );
		$transaction = new Transaction( $this->wpdb, $support );

		return new OperationFrame(
			$this->wpdb,
			new SalesRepository( $this->wpdb, $transaction, 1 ),
			$transaction,
			new RepeatableReadTransaction( $this->wpdb, $transaction, $support, $this->logger ),
			new CreatorRowLocks( $this->wpdb ),
			new AuditTrail( new AuditRepository( $this->wpdb ) ),
			$this->logger,
			1
		);
	}

	/**
	 * A sales repository (for lock names).
	 */
	private function sales(): SalesRepository {
		return new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) );
	}

	/**
	 * Registers the test operation's id directly, with the given hash.
	 *
	 * @param string $hash Payload hash.
	 */
	private function register( string $hash ): void {
		self::assertSame(
			1,
			$this->wpdb->insert(
				$this->table( Tables::OPERATIONS ),
				array(
					'operation_id' => self::ID,
					'kind'         => 'g1_decision',
					'subject_id'   => self::ORDER,
					'payload_hash' => $hash,
					'created_at'   => '2026-12-02 10:00:00',
				)
			)
		);
	}

	/**
	 * Makes the first registry pre-check find nothing (a serialisation that failed).
	 */
	private function hide_first_registry_read(): void {
		$hidden = false;
		$this->add_filter(
			static function ( string $sql ) use ( &$hidden ): string {
				if ( ! $hidden && str_starts_with( $sql, 'SELECT kind, subject_id, payload_hash FROM' ) ) {
					$hidden = true;

					return str_replace( ' LOCK IN SHARE MODE', ' AND 1 = 0 LOCK IN SHARE MODE', $sql );
				}

				return $sql;
			}
		);
	}

	/**
	 * The assessment's status.
	 */
	private function assessment_status(): ?string {
		$status = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT status FROM %i WHERE order_id = %d', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), self::ORDER ) );

		return null === $status ? null : (string) $status;
	}

	/**
	 * Sets the assessment's status directly.
	 *
	 * @param string $status Status.
	 */
	private function set_status( string $status ): void {
		$this->execute( 'UPDATE %i SET status = %s WHERE order_id = %d', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), $status, self::ORDER );
	}

	/**
	 * The registry's rows.
	 *
	 * @return list<array<string, string|null>>
	 */
	private function registry(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY operation_id', $this->table( Tables::OPERATIONS ) ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * No state change and no audit record; the registry holds only $registered rows.
	 *
	 * @param string $status     Expected status.
	 * @param int    $registered Rows the test registered itself.
	 */
	private function assert_nothing_written( string $status = 'pending', int $registered = 0 ): void {
		self::assertSame( $status, $this->assessment_status() );
		self::assertCount( $registered, $this->registry() );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	/**
	 * Back to a pending assessment, an empty registry and audit log, and no locks.
	 */
	private function reset(): void {
		$this->wpdb->query( 'SELECT RELEASE_ALL_LOCKS()' );
		$this->set_status( 'pending' );
		$this->execute( 'DELETE FROM %i', $this->table( Tables::OPERATIONS ) );
		$this->execute( 'DELETE FROM %i', $this->table( Tables::AUDIT_LOG ) );
	}

	/**
	 * Adds a `query` filter that is removed again after the test.
	 *
	 * @param Closure $filter Filter.
	 */
	private function add_filter( Closure $filter ): void {
		add_filter( 'query', $filter );
		$this->filters[] = $filter;
	}

	/**
	 * Removes every filter this test added.
	 */
	private function remove_filters(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( 'query', $filter );
		}

		$this->filters = array();
	}

	/**
	 * Records every statement and replaces the ones starting with $prefix (null: none).
	 *
	 * @param string|null $prefix      Statement prefix.
	 * @param string      $replacement Statement run instead.
	 * @return Closure(): list<string> The statements seen so far.
	 */
	private function replace( ?string $prefix, string $replacement ): Closure {
		$seen = array();
		$this->add_filter(
			static function ( string $sql ) use ( $prefix, $replacement, &$seen ): string {
				$seen[] = $sql;

				return null !== $prefix && str_starts_with( $sql, $prefix ) ? $replacement : $sql;
			}
		);

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
		$count = 0;
		$this->add_filter(
			static function ( string $sql ) use ( $index, &$count ): string {
				return $index === $count++ ? 'SELECT * FROM merchboard_t4_no_such_table' : $sql;
			}
		);
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
		$this->add_filter(
			static function ( string $sql ) use ( $prefix, $own, $after, $second, &$armed, &$done ): string {
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
			}
		);
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
