<?php
/**
 * Creator access and user relation with real WordPress users, roles and
 * database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Access;

use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorException;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\DeletedUserHandler;
use MerchBoard\Modules\Creators\WordPressUserAccounts;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use WP_User;

final class CreatorAccessTest extends IntegrationTestCase {

	private CreatorService $creators;

	private CreatorContext $access;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->creators = $this->service( CreatorService::class );
		$this->access   = $this->service( CreatorContext::class );
	}

	protected function tearDown(): void {
		$this->wpdb->query( 'DROP TRIGGER IF EXISTS merchboard_test_fail' );
		parent::tearDown();
	}

	public function test_access_matrix_with_the_real_permission_model(): void {
		$subscriber = $this->create_user( 'plain_subscriber' );

		$cap_only = $this->create_user( 'cap_only' );
		$this->user( $cap_only )->add_role( Capabilities::CREATOR_ROLE );

		$record_only = $this->create_user( 'record_only' );
		$this->creators->create( 'Record only', CreatorStatus::Active, $record_only );
		$this->user( $record_only )->remove_role( Capabilities::CREATOR_ROLE );

		$inactive = $this->create_user( 'inactive_creator' );
		$this->creators->create( 'Inactive', CreatorStatus::Inactive, $inactive );

		$active = $this->create_user( 'active_creator' );
		$this->creators->create( 'Active', CreatorStatus::Active, $active );

		$matrix = array(
			'administrator without creator'    => array( 1, false ),
			'subscriber without creator'       => array( $subscriber, false ),
			'capability without record'        => array( $cap_only, false ),
			'active record without capability' => array( $record_only, false ),
			'inactive creator'                 => array( $inactive, false ),
			'active creator'                   => array( $active, true ),
		);

		foreach ( $matrix as $case => [ $user_id, $expected ] ) {
			self::assertSame( $expected, $this->access->user_has_access( $user_id ), $case );

			wp_set_current_user( $user_id );
			self::assertSame( $expected, $this->access->current_user_has_access(), $case . ' (current user)' );
		}

		self::assertTrue( user_can( $cap_only, Capabilities::ACCESS_DASHBOARD ), 'The role gives the capability …' );
		self::assertFalse( $this->access->user_has_access( $cap_only ), '… but the role alone is not access.' );
	}

	public function test_full_lifecycle_including_user_deletion(): void {
		$user_id = $this->create_user( 'lifecycle' );
		$creator = $this->creators->create( 'Lifecycle', CreatorStatus::Active, null );

		$this->creators->update( $creator->id, 'Lifecycle', CreatorStatus::Active, $user_id );
		self::assertContains( Capabilities::CREATOR_ROLE, get_userdata( $user_id )->roles ?? array() );
		self::assertTrue( user_can( $user_id, Capabilities::ACCESS_DASHBOARD ) );
		self::assertTrue( $this->access->user_has_access( $user_id ) );

		$this->creators->set_status( $creator->id, CreatorStatus::Inactive );
		self::assertFalse( $this->access->user_has_access( $user_id ) );
		self::assertContains( Capabilities::CREATOR_ROLE, get_userdata( $user_id )->roles ?? array(), 'Deactivation keeps the role.' );

		$this->creators->set_status( $creator->id, CreatorStatus::Active );
		self::assertTrue( $this->access->user_has_access( $user_id ) );

		$this->creators->update( $creator->id, 'Lifecycle', CreatorStatus::Active, null );
		self::assertFalse( $this->access->user_has_access( $user_id ) );
		self::assertSame( array( 'subscriber' ), array_values( get_userdata( $user_id )->roles ?? array() ) );

		$this->creators->update( $creator->id, 'Lifecycle', CreatorStatus::Active, $user_id );
		self::assertTrue( $this->access->user_has_access( $user_id ) );

		$audit_before = $this->count_rows( Tables::AUDIT_LOG );
		self::assertTrue( wp_delete_user( $user_id ) );

		$row = $this->rows( Tables::CREATORS )[0];
		self::assertSame( (string) $creator->id, $row['id'], 'Creator record survives.' );
		self::assertSame( 'inactive', $row['status'] );
		self::assertNull( $row['user_id'] );
		self::assertFalse( $this->access->user_has_access( $user_id ) );

		$audit = array_slice( $this->rows( Tables::AUDIT_LOG ), $audit_before );
		self::assertSame( array( 'creator_user_unlinked', 'creator_deactivated' ), array_column( $audit, 'action' ) );
		self::assertSame( array( null, null ), array_column( $audit, 'actor_user_id' ), 'System action, although admin 1 deleted the user.' );
		self::assertSame(
			array( '{"user_id":' . $user_id . ',"reason":"user_deleted"}', '{"previous_status":"active","reason":"user_deleted"}' ),
			array_column( $audit, 'context' )
		);
		self::assertStringNotContainsString( 'lifecycle', (string) wp_json_encode( $audit ), 'No login or e-mail in the audit trail.' );
	}

	public function test_deleting_a_user_without_creator_changes_nothing(): void {
		$this->creators->create( 'Unrelated', CreatorStatus::Active, $this->create_user( 'kept' ) );
		$before = $this->rows( Tables::CREATORS );
		$audit  = $this->count_rows( Tables::AUDIT_LOG );

		wp_delete_user( $this->create_user( 'no_creator' ) );

		self::assertSame( $before, $this->rows( Tables::CREATORS ) );
		self::assertSame( $audit, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_failed_audit_during_deletion_rolls_back_and_is_logged(): void {
		$user_id = $this->create_user( 'doomed' );
		$this->creators->create( 'Doomed', CreatorStatus::Active, $user_id );
		$before = $this->rows( Tables::CREATORS );
		$this->execute( "CREATE TRIGGER merchboard_test_fail BEFORE INSERT ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'", $this->table( Tables::AUDIT_LOG ) );
		$this->wpdb->suppress_errors( true );
		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.

		self::assertTrue( wp_delete_user( $user_id ), 'The WordPress deletion itself is not broken.' );

		self::assertSame( $before, $this->rows( Tables::CREATORS ), 'Relation change rolled back with the failed audit write.' );
		self::assertSame( 1, $this->count_rows( Tables::AUDIT_LOG ) );
		$log = (string) file_get_contents( MERCHBOARD_TEST_LOG ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.
		self::assertStringContainsString( 'ERROR: Could not release the creator of a deleted WordPress user.', $log );
		self::assertStringNotContainsString( 'Doomed', $log );
		self::assertFalse( $this->access->user_has_access( $user_id ), 'The dangling link grants nothing.' );
	}

	public function test_failed_release_leaves_no_audit_record(): void {
		$user_id = $this->create_user( 'stuck' );
		$this->creators->create( 'Stuck', CreatorStatus::Active, $user_id );
		$this->execute( "CREATE TRIGGER merchboard_test_fail BEFORE UPDATE ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'", $this->table( Tables::CREATORS ) );
		$this->wpdb->suppress_errors( true );

		wp_delete_user( $user_id );

		self::assertSame( array( 'creator_created' ), array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );
		self::assertSame( (string) $user_id, $this->rows( Tables::CREATORS )[0]['user_id'] );
	}

	public function test_deletion_fails_closed_on_a_non_transactional_engine(): void {
		$user_id = $this->create_user( 'myisam' );
		$this->creators->create( 'MyISAM', CreatorStatus::Active, $user_id );
		$this->execute( 'ALTER TABLE %i ENGINE=MyISAM', $this->table( Tables::CREATORS ) );
		$logger = $this->service( Logger::class );
		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.

		// Fresh instances: the engine check caches per request.
		$handler = new DeletedUserHandler(
			new CreatorService(
				new CreatorRepository( $this->wpdb ),
				new AuditTrail( new AuditRepository( $this->wpdb ) ),
				new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ),
				new WordPressUserAccounts()
			),
			$logger
		);
		// Remove the user without firing the hooks (the container's listener is
		// bound to this run's cached engine check), then run the fresh handler.
		$user = get_userdata( $user_id );
		$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->users, $user_id );
		self::assertNotFalse( $user );
		clean_user_cache( $user );

		$handler->handle( $user_id );

		self::assertSame( (string) $user_id, $this->rows( Tables::CREATORS )[0]['user_id'] );
		self::assertSame( 1, $this->count_rows( Tables::AUDIT_LOG ) );
		self::assertStringContainsString( '(MyISAM)', (string) file_get_contents( MERCHBOARD_TEST_LOG ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.

		$this->execute( 'ALTER TABLE %i ENGINE=InnoDB', $this->table( Tables::CREATORS ) );
	}

	public function test_unlinking_removes_only_the_creator_role(): void {
		$user_id = $this->create_user( 'multi_role' );
		$this->user( $user_id )->add_role( 'editor' );
		$creator = $this->creators->create( 'Multi', CreatorStatus::Active, $user_id );

		$this->creators->update( $creator->id, 'Multi', CreatorStatus::Active, null );

		self::assertSame( array( 'subscriber', 'editor' ), array_values( get_userdata( $user_id )->roles ?? array() ) );
		self::assertCount( 1, $this->rows( Tables::CREATORS ), 'Creator kept.' );
	}

	public function test_creator_access_grants_no_administration(): void {
		$user_id = $this->create_user( 'no_escalation' );
		$this->creators->create( 'Plain', CreatorStatus::Active, $user_id );
		self::assertTrue( $this->access->user_has_access( $user_id ) );

		foreach ( array( Capabilities::MANAGE, Capabilities::MANAGE_CREATORS, Capabilities::MANAGE_SETTINGS, 'manage_options', 'edit_posts', 'list_users', 'manage_woocommerce' ) as $capability ) {
			self::assertFalse( user_can( $user_id, $capability ), $capability );
		}

		wp_set_current_user( $user_id );

		try {
			$this->creators->create( 'Escalation', CreatorStatus::Active, null );
			self::fail( 'Expected CreatorException.' );
		} catch ( CreatorException $e ) {
			self::assertSame( CreatorException::NOT_AUTHORIZED, $e->reason() );
		}
	}

	/**
	 * An existing WordPress user.
	 *
	 * @param int $user_id User id.
	 */
	private function user( int $user_id ): WP_User {
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		return $user;
	}

	public function test_a_dangling_link_to_a_recycled_user_id_grants_nothing(): void {
		// Even if a new user got the id of a deleted, still-linked user, access
		// needs the capability, which only linking through the admin grants.
		$user_id = $this->create_user( 'recycled' );
		$this->execute( 'INSERT INTO %i (user_id, display_name, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s)', $this->table( Tables::CREATORS ), $user_id, 'Dangling', 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00' );

		self::assertFalse( $this->access->user_has_access( $user_id ) );
	}
}
