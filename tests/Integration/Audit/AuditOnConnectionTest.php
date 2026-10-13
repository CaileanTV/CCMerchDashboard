<?php
/**
 * Audit records written only on the lock's connection (E3 T1), on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Audit;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\ConnectionChangedException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Tests\Integration\IntegrationTestCase;

/**
 * Part of E3.10 test 29 (E3.5(b)/(c)): insert_on_connection() stores the
 * same row as append() for the same input, NULL for a missing actor,
 * creator or context included, writes exactly one row and returns its id;
 * on another connection id it writes nothing and throws.
 * record_on_connection() records like record(). Rejection outside E3's
 * frames (E3.5(i)) belongs to T4. Synthetic data.
 */
final class AuditOnConnectionTest extends IntegrationTestCase {

	private const TIME = '2026-10-13 12:00:00';

	protected function setUp(): void {
		parent::setUp();
		$this->install();
	}

	public function test_the_same_row_as_append_with_and_without_optional_fields(): void {
		$repository = new AuditRepository( $this->wpdb );
		$connection = $this->connection();
		$inputs     = array(
			array( null, null, array() ),
			array(
				1,
				3,
				array(
					'field' => 'name',
					'note'  => 'Ærø/ü "x"',
				),
			),
		);

		foreach ( $inputs as [ $actor, $creator, $context ] ) {
			$appended = $repository->append( AuditAction::CreatorUpdated, $actor, $creator, $context, self::TIME );
			$inserted = $repository->insert_on_connection( AuditAction::CreatorUpdated, $actor, $creator, $context, self::TIME, $connection );
			$rows     = $this->rows( Tables::AUDIT_LOG );
			$last     = array_pop( $rows );
			$previous = array_pop( $rows );

			self::assertNotNull( $last );
			self::assertNotNull( $previous );
			self::assertSame( (string) $inserted, $last['id'], 'Returns the id of the one row written.' );
			self::assertSame( (string) $appended, $previous['id'] );
			unset( $last['id'], $previous['id'] );
			self::assertSame( $previous, $last );
		}

		$first = $this->rows( Tables::AUDIT_LOG )[1];
		self::assertSame( array( null, null, null ), array( $first['actor_user_id'], $first['creator_id'], $first['context'] ), 'NULL for a missing actor, creator and context.' );
		self::assertSame( 4, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_another_connection_writes_nothing(): void {
		$repository = new AuditRepository( $this->wpdb );

		try {
			$repository->insert_on_connection( AuditAction::CreatorUpdated, 1, 3, array( 'field' => 'name' ), self::TIME, $this->connection() + 1000000 );
			self::fail( 'Expected ConnectionChangedException.' );
		} catch ( ConnectionChangedException $e ) {
			self::assertSame( 'The database connection changed before the audit record was written.', $e->getMessage() );
		}

		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_record_on_connection_records_like_record(): void {
		$trail   = new AuditTrail( new AuditRepository( $this->wpdb ) );
		$context = array(
			'field' => 'name',
			'email' => 'someone@example.org',
		);

		$trail->record( AuditAction::CreatorUpdated, 3, $context );
		$trail->record_on_connection( AuditAction::CreatorUpdated, 3, $context, $this->connection() );

		[ $recorded, $on_connection ] = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( '1', $on_connection['actor_user_id'], 'The current user.' );

		foreach ( array( 'action', 'actor_user_id', 'creator_id', 'context' ) as $column ) {
			self::assertSame( $recorded[ $column ], $on_connection[ $column ], $column );
		}

		self::assertLessThanOrEqual( 5, abs( strtotime( (string) $on_connection['created_at'] . ' UTC' ) - time() ), 'UTC time of the write.' );
	}

	/**
	 * The test connection's id.
	 */
	private function connection(): int {
		return (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
	}
}
