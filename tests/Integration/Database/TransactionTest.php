<?php
/**
 * Creator change + audit record in one transaction, on a real MySQL/MariaDB.
 *
 * Failures are produced by the database itself (BEFORE INSERT/UPDATE triggers
 * that SIGNAL an error, a real UNIQUE violation), not by test doubles.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\Creators\Admin\CreatorAdminActions;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\WordPressUserAccounts;
use MerchBoard\Tests\Integration\IntegrationTestCase;

final class TransactionTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install();
	}

	protected function tearDown(): void {
		$this->wpdb->query( 'DROP TRIGGER IF EXISTS merchboard_test_fail' );
		parent::tearDown();
	}

	public function test_creator_change_and_audit_record_are_committed_together(): void {
		$creator = $this->creator_service()->create( 'Committed', CreatorStatus::Active, null );

		self::assertSame( 1, $this->count_rows( Tables::CREATORS ) );
		self::assertSame(
			array( array( 'creator_created', '1', (string) $creator->id ) ),
			array_map( static fn( array $r ): array => array( $r['action'], $r['actor_user_id'], $r['creator_id'] ), $this->rows( Tables::AUDIT_LOG ) )
		);
	}

	public function test_failed_audit_write_rolls_back_the_creator_change(): void {
		$this->fail_writes( Tables::AUDIT_LOG, 'INSERT' );

		try {
			$this->creator_service()->create( 'Never stored', CreatorStatus::Active, null );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not write audit record.', $e->getMessage() );
		}

		self::assertSame( 0, $this->count_rows( Tables::CREATORS ), 'The creator insert was rolled back.' );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_failed_audit_write_rolls_back_an_update_of_several_fields(): void {
		$service = $this->creator_service();
		$creator = $service->create( 'Original', CreatorStatus::Active, null );
		$before  = $this->rows( Tables::CREATORS );
		$this->fail_writes( Tables::AUDIT_LOG, 'INSERT' );

		try {
			$service->update( $creator->id, 'Renamed', CreatorStatus::Inactive, null );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertSame( $before, $this->rows( Tables::CREATORS ), 'Name and status unchanged.' );
		}

		self::assertSame( 1, $this->count_rows( Tables::AUDIT_LOG ), 'Only the original creation record.' );
	}

	public function test_admin_request_with_failing_audit_shows_a_neutral_error_and_logs_it(): void {
		$this->fail_writes( Tables::AUDIT_LOG, 'INSERT' );
		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.

		$url = $this->service( CreatorAdminActions::class )->save(
			array(
				'creator_id'   => '0',
				'display_name' => 'Via admin',
				'status'       => 'active',
				'user_id'      => '',
				'_wpnonce'     => wp_create_nonce( CreatorAdminActions::save_nonce_action( 0 ) ),
			)
		);

		self::assertStringContainsString( 'merchboard_notice=error', $url );
		self::assertSame( 0, $this->count_rows( Tables::CREATORS ) );
		$log = (string) file_get_contents( MERCHBOARD_TEST_LOG ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.
		self::assertStringContainsString( 'ERROR:', $log );
		self::assertStringNotContainsString( 'Via admin', $log, 'No display name in the technical log.' );
	}

	public function test_failed_creator_write_leaves_no_audit_record(): void {
		$creators    = new CreatorRepository( $this->wpdb );
		$audit       = new AuditRepository( $this->wpdb );
		$transaction = $this->transaction();
		$user_id     = $this->create_user( 'taken_user' );
		$creators->create( 'Owner', CreatorStatus::Active, $user_id );
		$this->wpdb->suppress_errors( true );

		// Audit first, then a creator write that the UNIQUE index rejects: the
		// already written audit record must not survive.
		try {
			$transaction->run(
				static function () use ( $creators, $audit, $user_id ): void {
					$audit->append( AuditAction::CreatorCreated, 1, null, array(), current_time( 'mysql', true ) );
					$creators->create( 'Duplicate', CreatorStatus::Active, $user_id );
				}
			);
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not create creator.', $e->getMessage() );
		}

		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
		self::assertSame( 1, $this->count_rows( Tables::CREATORS ) );
	}

	public function test_failed_creator_update_through_the_service_writes_no_audit(): void {
		$service = $this->creator_service();
		$creator = $service->create( 'Stable', CreatorStatus::Active, null );
		$this->fail_writes( Tables::CREATORS, 'UPDATE' );

		try {
			$service->set_status( $creator->id, CreatorStatus::Inactive );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertSame( 'active', $this->rows( Tables::CREATORS )[0]['status'] );
		}

		self::assertSame( array( 'creator_created' ), array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );
	}

	public function test_non_transactional_engine_is_refused_before_anything_is_written(): void {
		$this->execute( 'ALTER TABLE %i ENGINE=MyISAM', $this->table( Tables::AUDIT_LOG ) );

		try {
			$this->creator_service()->create( 'Refused', CreatorStatus::Active, null );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Database tables do not support transactions; write refused.', $e->getMessage() );
			self::assertSame( array( 'tables' => $this->table( Tables::AUDIT_LOG ) . ' (MyISAM)' ), $e->context() );
		}

		self::assertSame( 0, $this->count_rows( Tables::CREATORS ) );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
		self::assertFalse( ( new TransactionSupport( $this->wpdb ) )->available() );

		$this->execute( 'ALTER TABLE %i ENGINE=InnoDB', $this->table( Tables::AUDIT_LOG ) );
		self::assertTrue( ( new TransactionSupport( $this->wpdb ) )->available(), 'Converting back re-enables writes.' );
	}

	public function test_myisam_really_ignores_rollback_which_is_why_writes_are_refused(): void {
		$this->execute( 'ALTER TABLE %i ENGINE=MyISAM', $this->table( Tables::AUDIT_LOG ) );
		$audit = new AuditRepository( $this->wpdb );

		$this->wpdb->query( 'START TRANSACTION' );
		$audit->append( AuditAction::CreatorCreated, 1, null, array(), current_time( 'mysql', true ) );
		$this->wpdb->query( 'ROLLBACK' );

		self::assertSame( 1, $this->count_rows( Tables::AUDIT_LOG ), 'MyISAM kept the "rolled back" row.' );
	}

	public function test_innodb_rollback_discards_both_writes(): void {
		$creators = new CreatorRepository( $this->wpdb );
		$audit    = new AuditRepository( $this->wpdb );

		try {
			$this->transaction()->run(
				static function () use ( $creators, $audit ): void {
					$creator = $creators->create( 'Probe', CreatorStatus::Active );
					$audit->append( AuditAction::CreatorCreated, 1, $creator->id, array(), current_time( 'mysql', true ) );
					throw new \RuntimeException( 'Simulated failure after both writes.' );
				}
			);
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Simulated failure after both writes.', $e->getMessage() );
		}

		self::assertSame( 0, $this->count_rows( Tables::CREATORS ) );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	/**
	 * A fresh service: the container's instances live for the whole test run
	 * (one "request"), and TransactionSupport caches a positive result.
	 */
	private function creator_service(): CreatorService {
		return new CreatorService(
			new CreatorRepository( $this->wpdb ),
			new AuditTrail( new AuditRepository( $this->wpdb ) ),
			$this->transaction(),
			new WordPressUserAccounts()
		);
	}

	private function transaction(): Transaction {
		return new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
	}

	/**
	 * Makes the database reject writes to a table with a real error.
	 *
	 * @param string $table Tables constant.
	 * @param string $event INSERT or UPDATE.
	 */
	private function fail_writes( string $table, string $event ): void {
		$event = 'UPDATE' === $event ? 'UPDATE' : 'INSERT';

		$this->execute(
			"CREATE TRIGGER merchboard_test_fail BEFORE {$event} ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'",
			$this->table( $table )
		);
		$this->wpdb->suppress_errors( true );
	}
}
