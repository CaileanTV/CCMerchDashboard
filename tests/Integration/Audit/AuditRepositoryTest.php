<?php
/**
 * Audit storage on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Audit;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Tests\Integration\IntegrationTestCase;

final class AuditRepositoryTest extends IntegrationTestCase {

	private AuditRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->repository = new AuditRepository( $this->wpdb );
	}

	public function test_record_stores_server_side_actor_utc_time_and_integer_ids(): void {
		$id = ( new AuditTrail( $this->repository ) )->record( AuditAction::CreatorActivated, 5, array( 'previous_status' => 'inactive' ) );

		$row = $this->rows( Tables::AUDIT_LOG )[0];
		self::assertSame( (string) $id, $row['id'] );
		self::assertSame( '1', $row['actor_user_id'] );
		self::assertSame( '5', $row['creator_id'] );
		self::assertSame( 'creator_activated', $row['action'] );
		self::assertEqualsWithDelta( time(), strtotime( (string) $row['created_at'] . ' UTC' ), 2 );

		$entry = $this->repository->for_creator( 5 )[0];
		self::assertSame( $id, $entry->id );
		self::assertSame( 1, $entry->actor_user_id );
		self::assertSame( 5, $entry->creator_id );
		self::assertSame( array( 'previous_status' => 'inactive' ), $entry->context );
	}

	public function test_system_action_and_empty_context_are_stored_as_null_not_zero(): void {
		wp_set_current_user( 0 );

		( new AuditTrail( $this->repository ) )->record( AuditAction::CreatorCreated, null );

		$row = $this->rows( Tables::AUDIT_LOG )[0];
		self::assertNull( $row['actor_user_id'] );
		self::assertNull( $row['creator_id'] );
		self::assertNull( $row['context'] );
	}

	public function test_unicode_context_survives_a_legacy_utf8mb3_table(): void {
		$this->execute( 'ALTER TABLE %i CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci', $this->table( Tables::AUDIT_LOG ) );
		$this->forget_table_metadata();
		$context = array( 'note' => 'Blåbær 🎸 東京' );

		$this->repository->append( AuditAction::CreatorUpdated, 1, 3, $context, current_time( 'mysql', true ) );

		self::assertSame( $context, $this->repository->for_creator( 3 )[0]->context, 'ASCII JSON cannot hit the charset limit.' );
	}

	public function test_malformed_context_is_stored_without_failing_the_write(): void {
		$this->repository->append( AuditAction::CreatorUpdated, 1, 3, array( 'bad' => "x\xB1", 'n' => NAN ), current_time( 'mysql', true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( 1, $this->count_rows( Tables::AUDIT_LOG ) );
		self::assertSame( array( 'bad' => "x\u{FFFD}", 'n' => 0 ), $this->repository->for_creator( 3 )[0]->context ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_tampered_rows_are_handled_on_read(): void {
		$this->repository->append( AuditAction::CreatorUpdated, 1, 3, array( 'a' => 1 ), current_time( 'mysql', true ) );
		$this->wpdb->update( $this->table( Tables::AUDIT_LOG ), array( 'context' => '{"broken' ), array( 'creator_id' => 3 ) );

		self::assertSame( array(), $this->repository->for_creator( 3 )[0]->context, 'Malformed JSON reads as empty context.' );

		$this->wpdb->update( $this->table( Tables::AUDIT_LOG ), array( 'action' => 'creator_deleted' ), array( 'creator_id' => 3 ) );

		$this->expectException( DatabaseException::class );
		$this->repository->for_creator( 3 );
	}

	public function test_invalid_ids_are_rejected_before_writing(): void {
		foreach ( array( array( 0, 1 ), array( 1, -5 ) ) as [ $actor, $creator ] ) {
			try {
				$this->repository->append( AuditAction::CreatorUpdated, $actor, $creator, array(), current_time( 'mysql', true ) );
				self::fail( 'Expected InvalidArgumentException.' );
			} catch ( InvalidArgumentException ) {
				self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
			}
		}
	}
}
