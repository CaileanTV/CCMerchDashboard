<?php
/**
 * Which statements start a transaction's snapshot (E3 T1, G-1), on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Database\SchemaVersionRow;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use wpdb;

/**
 * E3.10 test 13 (G-1, E3.4): at REPEATABLE READ, CONNECTION_ID(),
 * IS_USED_LOCK() and a locking read (R0's shared read of the version row)
 * after START do not fix the snapshot, so a commit of another connection
 * after them is still seen by the first plain read. Control (mutation): a
 * plain read of an InnoDB table fixes it. Synthetic data.
 */
final class SnapshotStartTest extends IntegrationTestCase {

	private ?wpdb $second = null;

	private int $creator = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Before', CreatorStatus::Active )->id;
	}

	protected function tearDown(): void {
		$this->wpdb->query( 'ROLLBACK' );

		if ( null !== $this->second ) {
			$this->second->close();
		}

		parent::tearDown();
	}

	public function test_connection_id_lock_check_and_a_locking_read_do_not_fix_the_snapshot(): void {
		$this->wpdb->query( 'START TRANSACTION' );
		$this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$this->wpdb->get_var( "SELECT IS_USED_LOCK('merchboard_t1_snapshot')" );
		SchemaVersionRow::read_shared( $this->wpdb );

		$this->rename( 'After' );

		self::assertSame( 'After', $this->creator_name(), 'The first plain read sees the later commit.' );
		$this->wpdb->query( 'COMMIT' );
	}

	public function test_a_plain_read_fixes_the_snapshot(): void {
		$this->wpdb->query( 'START TRANSACTION' );
		self::assertSame( 'Before', $this->creator_name() );

		$this->rename( 'After' );

		self::assertSame( 'Before', $this->creator_name(), 'The snapshot of the first plain read.' );
		$this->wpdb->query( 'COMMIT' );
	}

	/**
	 * Renames the creator on a second connection and commits (autocommit).
	 *
	 * @param string $name New name.
	 */
	private function rename( string $name ): void {
		if ( null === $this->second ) {
			$this->second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$this->second->set_prefix( $this->wpdb->prefix );
		}

		$rename = $this->second->prepare( 'UPDATE %i SET display_name = %s WHERE id = %d', $this->table( Tables::CREATORS ), $name, $this->creator );
		self::assertIsString( $rename );
		self::assertSame( 1, $this->second->query( $rename ) );
	}

	/**
	 * The creator's name as the test's connection sees it.
	 */
	private function creator_name(): string {
		return (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT display_name FROM %i WHERE id = %d', $this->table( Tables::CREATORS ), $this->creator ) );
	}
}
