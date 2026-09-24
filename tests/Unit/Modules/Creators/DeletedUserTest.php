<?php
/**
 * Tests for the reaction to deleted WordPress users.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\DeletedUserHandler;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreatorService::class )]
#[CoversClass( DeletedUserHandler::class )]
#[CoversClass( CreatorRepository::class )]
final class DeletedUserTest extends TestCase {

	use CreatorAdminEnvironment;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
	}

	public function test_deleted_user_releases_and_deactivates_the_creator_and_keeps_the_record(): void {
		$creator = $this->service->create( 'Nova Æ', CreatorStatus::Active, 42 );
		$this->delete_user( 42 );

		$changes = $this->service->release_deleted_user( 42 );

		self::assertSame( array( AuditAction::CreatorUserUnlinked, AuditAction::CreatorDeactivated ), $changes );
		$row = $this->db->rows( 'merchboard_creators' )[0];
		self::assertSame( (string) $creator->id, $row['id'], 'Record survives.' );
		self::assertNull( $row['user_id'] );
		self::assertSame( 'inactive', $row['status'] );
		self::assertSame( 'Nova Æ', $row['display_name'], 'Creator data untouched.' );
	}

	public function test_audit_records_are_system_actions_without_personal_data(): void {
		$this->service->create( 'Nova Æ', CreatorStatus::Active, 42 );
		$this->delete_user( 42 );

		$this->service->release_deleted_user( 42 );

		$records = array_slice( $this->audit_rows(), 1 );
		self::assertSame( array( 'creator_user_unlinked', 'creator_deactivated' ), array_column( $records, 'action' ) );
		self::assertSame( array( null, null ), array_column( $records, 'actor_user_id' ), 'Actor is NULL even though an admin is logged in.' );
		self::assertSame(
			array(
				array(
					'user_id' => 42,
					'reason'  => 'user_deleted',
				),
				array(
					'previous_status' => 'active',
					'reason'          => 'user_deleted',
				),
			),
			array_column( $records, 'context' )
		);
		self::assertStringNotContainsString( 'Nova', (string) wp_json_encode( $records ) );
	}

	public function test_inactive_creator_is_only_unlinked(): void {
		$this->service->create( 'Dormant', CreatorStatus::Inactive, 42 );
		$this->delete_user( 42 );

		self::assertSame( array( AuditAction::CreatorUserUnlinked ), $this->service->release_deleted_user( 42 ) );
	}

	public function test_needs_no_capability_because_it_is_a_system_reaction(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$this->delete_user( 42 );
		$this->caps            = array();
		$this->current_user_id = 0;

		self::assertCount( 2, $this->service->release_deleted_user( 42 ) );
	}

	public function test_does_nothing_for_a_user_that_still_exists(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$before = $this->db->rows( 'merchboard_creators' );

		self::assertSame( array(), $this->service->release_deleted_user( 42 ), 'Cannot be abused to unlink a living user.' );
		self::assertSame( $before, $this->db->rows( 'merchboard_creators' ) );
		self::assertCount( 1, $this->audit_rows() );
	}

	public function test_does_nothing_for_unlinked_or_invalid_users(): void {
		$this->service->create( 'Unrelated', CreatorStatus::Active, 43 );
		$this->delete_user( 42 );

		self::assertSame( array(), $this->service->release_deleted_user( 42 ) );
		self::assertSame( array(), $this->service->release_deleted_user( 0 ) );
		self::assertSame( array(), $this->service->release_deleted_user( -1 ) );
		self::assertSame( '43', $this->db->rows( 'merchboard_creators' )[0]['user_id'] );
		self::assertCount( 1, $this->audit_rows() );
	}

	public function test_never_changes_roles(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$this->users->role_log = array();
		$this->delete_user( 42 );

		$this->service->release_deleted_user( 42 );

		self::assertSame( array(), $this->users->role_log );
	}

	public function test_failed_audit_write_rolls_back_the_release(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$before = $this->db->rows( 'merchboard_creators' );
		$this->delete_user( 42 );
		$this->db->fail_writes_to = array( 'merchboard_audit_log' );

		try {
			$this->service->release_deleted_user( 42 );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertSame( $before, $this->db->rows( 'merchboard_creators' ) );
		}

		self::assertCount( 1, $this->audit_rows() );
	}

	public function test_failed_release_writes_no_audit_record(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$this->delete_user( 42 );
		$this->db->fail_writes_to = array( 'merchboard_creators' );

		try {
			$this->service->release_deleted_user( 42 );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not release creator user.', $e->getMessage() );
		}

		self::assertCount( 1, $this->audit_rows() );
		self::assertSame( '42', $this->db->rows( 'merchboard_creators' )[0]['user_id'] );
	}

	public function test_non_transactional_engine_is_refused(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$this->delete_user( 42 );
		$this->db->engines['merchboard_creators'] = 'MyISAM';
		// A new request: the engine check caches only within one request.
		$service = new CreatorService(
			new CreatorRepository( $this->db ),
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) ),
			$this->users
		);

		$this->expectException( DatabaseException::class );

		try {
			$service->release_deleted_user( 42 );
		} finally {
			self::assertSame( '42', $this->db->rows( 'merchboard_creators' )[0]['user_id'] );
		}
	}

	public function test_release_only_applies_while_still_linked_to_that_user(): void {
		$repository = new CreatorRepository( $this->db );
		$creator    = $repository->create( 'Relinked', CreatorStatus::Active, 43 );

		self::assertFalse( $repository->release_user( $creator->id, 42 ), 'Re-linked to another user in the meantime.' );
		self::assertSame( array( '43', 'active' ), array( $this->db->rows( 'merchboard_creators' )[0]['user_id'], $this->db->rows( 'merchboard_creators' )[0]['status'] ) );
		self::assertTrue( $repository->release_user( $creator->id, 43 ) );
		self::assertSame( array( null, 'inactive' ), array( $this->db->rows( 'merchboard_creators' )[0]['user_id'], $this->db->rows( 'merchboard_creators' )[0]['status'] ) );
		self::assertSame( '2026-09-24 12:00:00', $this->db->rows( 'merchboard_creators' )[0]['updated_at'] );
	}

	public function test_handler_logs_database_failures_instead_of_breaking_the_deletion(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$this->delete_user( 42 );
		$this->db->fail_writes_to = array( 'merchboard_audit_log' );
		$logger                   = new RecordingLogger();

		( new DeletedUserHandler( $this->service, $logger ) )->handle( '42' );

		self::assertSame( array( 'error' ), array_column( $logger->entries, 'level' ) );
		self::assertSame( 42, $logger->entries[0]['context']['user_id'] );
		self::assertStringNotContainsString( 'Nova', (string) wp_json_encode( $logger->entries ) );
	}

	public function test_handler_ignores_invalid_hook_arguments(): void {
		$logger = new RecordingLogger();

		( new DeletedUserHandler( $this->service, $logger ) )->handle( 'not-a-number' );
		( new DeletedUserHandler( $this->service, $logger ) )->handle( null );

		self::assertSame( array(), $logger->entries );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	/**
	 * Simulates WordPress having deleted the user.
	 *
	 * @param int $user_id User id.
	 */
	private function delete_user( int $user_id ): void {
		unset( $this->users->users[ $user_id ] );
	}
}
