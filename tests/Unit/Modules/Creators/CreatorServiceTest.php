<?php
/**
 * Tests for creator administration use cases and the audit trail.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Creators;

use Brain\Monkey\Actions;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorException;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( CreatorService::class )]
final class CreatorServiceTest extends TestCase {

	use CreatorAdminEnvironment;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
	}


	public function test_create_stores_creator_and_one_audit_record_atomically(): void {
		$creator = $this->service->create( 'Studio Nova', CreatorStatus::Inactive, null );

		self::assertSame( 1, $creator->id );
		self::assertNull( $creator->user_id );
		self::assertSame(
			array(
				array(
					'id'            => '1',
					'created_at'    => $this->now,
					'action'        => 'creator_created',
					'actor_user_id' => '7',
					'creator_id'    => '1',
					'context'       => array(
						'status'  => 'inactive',
						'user_id' => null,
					),
				),
			),
			$this->audit_rows()
		);
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->db->transaction_log );
	}

	public function test_create_with_user_links_it_and_grants_the_role_after_commit(): void {
		$creator = $this->service->create( 'Studio Nova', CreatorStatus::Active, 42 );

		self::assertSame( 42, $creator->user_id );
		self::assertSame( 42, $this->audit_rows()[0]['context']['user_id'] );
		self::assertSame( array( 'grant:42' ), $this->users->role_log );
	}

	public function test_creator_id_is_assigned_by_the_database_not_the_user_id(): void {
		$this->service->create( 'First', CreatorStatus::Active, null );
		$second = $this->service->create( 'Second', CreatorStatus::Active, 42 );

		self::assertSame( 2, $second->id );
		self::assertSame( 42, $second->user_id );
	}

	public function test_create_rejects_unknown_user_without_writing(): void {
		$this->expect_rejection( CreatorException::USER_NOT_FOUND, fn() => $this->service->create( 'Nova', CreatorStatus::Active, 999 ) );
	}

	public function test_create_rejects_user_linked_to_another_creator(): void {
		$this->service->create( 'First', CreatorStatus::Active, 42 );

		$this->expect_rejection( CreatorException::USER_ALREADY_LINKED, fn() => $this->service->create( 'Second', CreatorStatus::Active, 42 ), 1 );
	}

	/**
	 * Names outside the column constraints.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_names(): array {
		return array(
			'empty'      => array( '' ),
			'whitespace' => array( '   ' ),
			'too long'   => array( str_repeat( 'x', 192 ) ),
		);
	}

	#[DataProvider( 'invalid_names' )]
	public function test_create_rejects_invalid_display_names( string $name ): void {
		$this->expect_rejection( CreatorException::INVALID_DISPLAY_NAME, fn() => $this->service->create( $name, CreatorStatus::Active, null ) );
	}


	public function test_every_operation_requires_the_manage_creators_capability(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$this->caps = array( Capabilities::ACCESS_DASHBOARD, 'read' );

		$operations = array(
			fn() => $this->service->create( 'Other', CreatorStatus::Active, null ),
			fn() => $this->service->update( 1, 'Renamed', CreatorStatus::Inactive, null ),
			fn() => $this->service->set_status( 1, CreatorStatus::Inactive ),
		);

		foreach ( $operations as $operation ) {
			$this->expect_rejection( CreatorException::NOT_AUTHORIZED, $operation, 1 );
		}

		self::assertSame( 'Nova', $this->db->rows( 'merchboard_creators' )[0]['display_name'] );
		self::assertContains( Capabilities::MANAGE_CREATORS, $this->capability_checks );
	}

	/**
	 * Users that must not administer creators.
	 *
	 * @return array<string, array{list<string>}>
	 */
	public static function unauthorized_users(): array {
		return array(
			'creator'             => array( array( 'read', 'merchboard_access_dashboard' ) ),
			'subscriber'          => array( array( 'read' ) ),
			'plugin admin only'   => array( array( 'merchboard_manage', 'merchboard_manage_settings' ) ),
			'wp admin w/o plugin' => array( array( 'manage_options', 'edit_users' ) ),
		);
	}

	/**
	 * Only the plugin capability counts, not manage_options.
	 *
	 * @param string[] $caps Capabilities of the user.
	 */
	#[DataProvider( 'unauthorized_users' )]
	public function test_users_without_the_capability_are_rejected( array $caps ): void {
		$this->caps = $caps;

		$this->expect_rejection( CreatorException::NOT_AUTHORIZED, fn() => $this->service->create( 'Nova', CreatorStatus::Active, null ) );
	}


	public function test_rename_writes_creator_updated_with_field_names_only(): void {
		$this->service->create( 'Old Private Name', CreatorStatus::Active, null );
		$this->now = '2026-09-25 08:30:00';

		$changes = $this->service->update( 1, 'New Private Name', CreatorStatus::Active, null );

		self::assertSame( array( AuditAction::CreatorUpdated ), $changes );
		self::assertSame( array( 'changed_fields' => array( 'display_name' ) ), $this->audit_rows()[1]['context'] );
		$creator = $this->db->rows( 'merchboard_creators' )[0];
		self::assertSame( 'New Private Name', $creator['display_name'] );
		self::assertSame( '2026-09-24 12:00:00', $creator['created_at'] );
		self::assertSame( '2026-09-25 08:30:00', $creator['updated_at'] );

		$audit = (string) json_encode( $this->db->rows( 'merchboard_audit_log' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress.
		self::assertStringNotContainsString( 'Private Name', $audit, 'Display names must not be copied into the audit trail.' );
	}

	public function test_deactivate_and_activate_write_status_actions_with_previous_status(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, null );

		self::assertSame( array( AuditAction::CreatorDeactivated ), $this->service->set_status( 1, CreatorStatus::Inactive ) );
		self::assertSame( array( AuditAction::CreatorActivated ), $this->service->set_status( 1, CreatorStatus::Active ) );

		$rows = $this->audit_rows();
		self::assertSame( array( 'creator_created', 'creator_deactivated', 'creator_activated' ), $this->audit_actions() );
		self::assertSame( array( 'previous_status' => 'active' ), $rows[1]['context'] );
		self::assertSame( array( 'previous_status' => 'inactive' ), $rows[2]['context'] );
		self::assertSame( 'active', $this->db->rows( 'merchboard_creators' )[0]['status'] );
	}

	public function test_a_committed_status_change_fires_the_status_event_and_nothing_else_does(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, null );
		Actions\expectDone( CreatorService::STATUS_CHANGED_ACTION )->once()->with( 1, 'inactive', 'active' );
		Actions\expectDone( CreatorService::STATUS_CHANGED_ACTION )->once()->with( 1, 'active', 'inactive' );

		$this->service->set_status( 1, CreatorStatus::Inactive );
		$this->service->set_status( 1, CreatorStatus::Inactive );
		$this->service->update( 1, 'Nova Renamed', CreatorStatus::Inactive, null );
		$this->service->set_status( 1, CreatorStatus::Active );
	}

	public function test_link_and_unlink_write_audit_and_change_the_role_but_never_delete_the_user(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, null );

		$this->service->update( 1, 'Nova', CreatorStatus::Active, 42 );
		self::assertSame( '42', $this->db->rows( 'merchboard_creators' )[0]['user_id'] );

		$this->service->update( 1, 'Nova', CreatorStatus::Active, null );
		self::assertNull( $this->db->rows( 'merchboard_creators' )[0]['user_id'] );

		self::assertSame( array( 'creator_created', 'creator_user_linked', 'creator_user_unlinked' ), $this->audit_actions() );
		self::assertSame( array( 'user_id' => 42 ), $this->audit_rows()[1]['context'] );
		self::assertSame( array( 'user_id' => 42 ), $this->audit_rows()[2]['context'] );
		self::assertSame( array( 'grant:42', 'revoke:42' ), $this->users->role_log );
		self::assertTrue( $this->users->exists( 42 ), 'Unlinking must not delete the WordPress user.' );
		self::assertCount( 1, $this->db->rows( 'merchboard_creators' ), 'Unlinking must not delete the creator.' );
	}

	public function test_relinking_to_another_user_unlinks_then_links(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );

		$changes = $this->service->update( 1, 'Nova', CreatorStatus::Active, 43 );

		self::assertSame( array( AuditAction::CreatorUserUnlinked, AuditAction::CreatorUserLinked ), $changes );
		self::assertSame( array( 'grant:42', 'revoke:42', 'grant:43' ), $this->users->role_log );
	}

	public function test_combined_edit_writes_one_record_per_change_in_one_transaction(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, null );
		$this->db->transaction_log = array();

		$changes = $this->service->update( 1, 'Nova Studio', CreatorStatus::Inactive, 42 );

		self::assertSame( array( AuditAction::CreatorUpdated, AuditAction::CreatorDeactivated, AuditAction::CreatorUserLinked ), $changes );
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->db->transaction_log );
	}

	public function test_unchanged_edit_writes_nothing(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, 42 );
		$this->db->transaction_log = array();

		self::assertSame( array(), $this->service->update( 1, '  Nova  ', CreatorStatus::Active, 42 ) );
		self::assertSame( array(), $this->service->set_status( 1, CreatorStatus::Active ) );

		self::assertCount( 1, $this->db->rows( 'merchboard_audit_log' ) );
		self::assertSame( array(), $this->db->transaction_log );
	}

	/**
	 * Ids that do not identify an existing creator.
	 *
	 * @return array<string, array{int}>
	 */
	public static function unknown_ids(): array {
		return array(
			'unknown'  => array( 999 ),
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
		);
	}

	#[DataProvider( 'unknown_ids' )]
	public function test_update_of_unknown_creator_is_rejected( int $id ): void {
		$this->expect_rejection( CreatorException::NOT_FOUND, fn() => $this->service->update( $id, 'X', CreatorStatus::Active, null ) );
		$this->expect_rejection( CreatorException::NOT_FOUND, fn() => $this->service->set_status( $id, CreatorStatus::Active ) );
	}

	public function test_linking_a_user_of_another_creator_is_rejected_and_nothing_changes(): void {
		$this->service->create( 'First', CreatorStatus::Active, 42 );
		$this->service->create( 'Second', CreatorStatus::Active, null );

		$this->expect_rejection( CreatorException::USER_ALREADY_LINKED, fn() => $this->service->update( 2, 'Second renamed', CreatorStatus::Active, 42 ), 2 );

		self::assertSame( 'Second', $this->db->rows( 'merchboard_creators' )[1]['display_name'] );
	}


	public function test_failed_audit_write_rolls_back_the_creator_change(): void {
		$this->service->create( 'Nova', CreatorStatus::Active, null );
		$this->db->fail_writes_to = array( 'merchboard_audit_log' );

		try {
			$this->service->update( 1, 'Renamed', CreatorStatus::Inactive, 42 );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not write audit record.', $e->getMessage() );
		}

		$creator = $this->db->rows( 'merchboard_creators' )[0];
		self::assertSame( 'Nova', $creator['display_name'] );
		self::assertSame( 'active', $creator['status'] );
		self::assertNull( $creator['user_id'] );
		self::assertSame( array( 'creator_created' ), $this->audit_actions() );
		self::assertSame( array(), $this->users->role_log, 'No role change when nothing was committed.' );
		$log = $this->db->transaction_log;
		self::assertSame( 'ROLLBACK', end( $log ) );
	}

	public function test_failed_creator_write_writes_no_audit_record(): void {
		$this->db->fail_writes_to = array( 'merchboard_creators' );

		try {
			$this->service->create( 'Nova', CreatorStatus::Active, 42 );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
			self::assertSame( array(), $this->users->role_log );
		}
	}


	public function test_actor_is_the_current_user_and_null_without_one(): void {
		$this->current_user_id = 12;
		$this->service->create( 'By admin 12', CreatorStatus::Active, null );

		$this->current_user_id = 0;
		$this->service->create( 'Without user', CreatorStatus::Active, null );

		$rows = $this->db->rows( 'merchboard_audit_log' );
		self::assertSame( '12', $rows[0]['actor_user_id'] );
		self::assertNull( $rows[1]['actor_user_id'], 'No user id is invented for actions without a user.' );
	}

	/**
	 * Asserts a rejection with a reason and that no creator/audit rows were added.
	 *
	 * @param string   $reason         Expected reason.
	 * @param callable $operation      Operation.
	 * @param int      $creators_after Expected creator count afterwards.
	 */
	private function expect_rejection( string $reason, callable $operation, int $creators_after = 0 ): void {
		$audit_before = count( $this->db->rows( 'merchboard_audit_log' ) );

		try {
			$operation();
			self::fail( 'Expected CreatorException ' . $reason );
		} catch ( CreatorException $e ) {
			self::assertSame( $reason, $e->reason() );
		}

		self::assertCount( $creators_after, $this->db->rows( 'merchboard_creators' ) );
		self::assertCount( $audit_before, $this->db->rows( 'merchboard_audit_log' ) );
	}
}
