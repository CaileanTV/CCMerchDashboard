<?php
/**
 * Tests for the audit trail.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Audit;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditEntry;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass( AuditTrail::class )]
#[CoversClass( AuditRepository::class )]
#[CoversClass( AuditEntry::class )]
#[CoversClass( AuditAction::class )]
final class AuditTrailTest extends TestCase {

	private FakeDatabase $db;

	private AuditRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$this->db         = new FakeDatabase();
		$this->repository = new AuditRepository( $this->db );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-09-24 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
	}

	public function test_action_names_are_a_closed_set(): void {
		self::assertSame(
			array( 'creator_created', 'creator_updated', 'creator_activated', 'creator_deactivated', 'creator_user_linked', 'creator_user_unlinked' ),
			array_map( static fn( AuditAction $a ): string => $a->value, AuditAction::cases() )
		);
	}

	public function test_record_uses_server_side_actor_and_utc_time(): void {
		( new AuditTrail( $this->repository ) )->record( AuditAction::CreatorCreated, 3, array( 'status' => 'active' ) );

		self::assertSame(
			array(
				'id'            => '1',
				'created_at'    => '2026-09-24 12:00:00',
				'action'        => 'creator_created',
				'actor_user_id' => '7',
				'creator_id'    => '3',
				'context'       => '{"status":"active"}',
			),
			$this->db->rows( 'merchboard_audit_log' )[0]
		);
	}

	public function test_system_actions_have_no_actor(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		( new AuditTrail( $this->repository ) )->record( AuditAction::CreatorDeactivated, 3 );

		$row = $this->db->rows( 'merchboard_audit_log' )[0];
		self::assertNull( $row['actor_user_id'] );
		self::assertNull( $row['context'], 'Empty context is stored as NULL.' );
	}

	public function test_sensitive_context_values_are_never_stored(): void {
		( new AuditTrail( $this->repository ) )->record(
			AuditAction::CreatorUpdated,
			3,
			array(
				'changed_fields' => array( 'display_name' ),
				'password'       => 'hunter2',
				'user_email'     => 'jane@example.com',
				'api_key'        => 'sk_live_x',
				'session_token'  => 'abc',
			)
		);

		$stored = (string) $this->db->rows( 'merchboard_audit_log' )[0]['context'];
		foreach ( array( 'hunter2', 'jane@example.com', 'sk_live_x', 'abc' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $stored );
		}
		self::assertStringContainsString( 'display_name', $stored );
	}

	public function test_append_rejects_invalid_ids_before_writing(): void {
		foreach ( array( array( 0, 1 ), array( 1, -1 ) ) as [ $actor, $creator ] ) {
			try {
				$this->repository->append( AuditAction::CreatorCreated, $actor, $creator, array(), '2026-09-24 12:00:00' );
				self::fail( 'Expected InvalidArgumentException.' );
			} catch ( InvalidArgumentException ) {
				self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
			}
		}
	}

	public function test_write_failure_raises_generic_error(): void {
		$this->db->fail_writes_to = array( 'merchboard_audit_log' );

		try {
			$this->repository->append( AuditAction::CreatorCreated, 7, 1, array(), '2026-09-24 12:00:00' );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not write audit record.', $e->getMessage() );
			self::assertSame( array( 'action' => 'creator_created' ), $e->context() );
		}
	}

	public function test_for_creator_returns_that_creators_records_newest_first(): void {
		$trail = new AuditTrail( $this->repository );
		$trail->record( AuditAction::CreatorCreated, 1 );
		$trail->record( AuditAction::CreatorCreated, 2 );
		$trail->record( AuditAction::CreatorDeactivated, 1, array( 'previous_status' => 'active' ) );

		$entries = $this->repository->for_creator( 1 );

		self::assertSame( array( AuditAction::CreatorDeactivated, AuditAction::CreatorCreated ), array_map( static fn( AuditEntry $e ): AuditAction => $e->action, $entries ) );
		self::assertSame( array( 'previous_status' => 'active' ), $entries[0]->context );
		self::assertSame( 7, $entries[0]->actor_user_id );
		self::assertSame( 'UTC', $entries[0]->created_at->getTimezone()->getName() );
	}

	public function test_repository_is_append_only(): void {
		$public = array_map(
			static fn( ReflectionMethod $m ): string => $m->getName(),
			( new ReflectionClass( AuditRepository::class ) )->getMethods( ReflectionMethod::IS_PUBLIC )
		);

		self::assertSame( array( '__construct', 'append', 'for_creator' ), $public );
	}
}
