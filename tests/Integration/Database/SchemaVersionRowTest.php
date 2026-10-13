<?php
/**
 * The schema version row read for R0 (E3 T1), on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\SchemaVersionRow;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use wpdb;

/**
 * The version row is read from the table, never from the option cache; a
 * missing row is an error (fail closed, part of E3.10 test 20); its shared
 * lock blocks another connection's UPDATE of the row until the transaction
 * ends (part of test 15). The activation row follows in T2.
 */
final class SchemaVersionRowTest extends IntegrationTestCase {

	private ?wpdb $second = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
	}

	protected function tearDown(): void {
		$this->wpdb->query( 'ROLLBACK' );

		if ( null !== $this->second ) {
			$this->second->close();
		}

		parent::tearDown();
	}

	public function test_both_reads_return_the_stored_version(): void {
		$stored = (string) get_option( Migrator::VERSION_OPTION );
		self::assertNotSame( '', $stored );

		self::assertSame( $stored, SchemaVersionRow::read( $this->wpdb ) );

		$this->wpdb->query( 'START TRANSACTION' );
		self::assertSame( $stored, SchemaVersionRow::read_shared( $this->wpdb ) );
		$this->wpdb->query( 'COMMIT' );
	}

	public function test_the_row_is_read_and_not_the_option_cache(): void {
		get_option( Migrator::VERSION_OPTION );
		$this->execute( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $this->wpdb->options, '999', Migrator::VERSION_OPTION );

		self::assertSame( '999', SchemaVersionRow::read( $this->wpdb ) );
		self::assertSame( '999', SchemaVersionRow::read_shared( $this->wpdb ) );
	}

	public function test_a_missing_row_fails_closed(): void {
		$this->execute( 'DELETE FROM %i WHERE option_name = %s', $this->wpdb->options, Migrator::VERSION_OPTION );

		$reads = array(
			fn (): string => SchemaVersionRow::read( $this->wpdb ),
			fn (): string => SchemaVersionRow::read_shared( $this->wpdb ),
		);

		foreach ( $reads as $read ) {
			try {
				$read();
				self::fail( 'Expected DatabaseException.' );
			} catch ( DatabaseException $e ) {
				self::assertSame( 'The schema version row is missing.', $e->getMessage() );
			}
		}
	}

	public function test_the_shared_read_blocks_an_update_of_the_row_until_the_transaction_ends(): void {
		$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$second->suppress_errors( true );
		$second->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$this->second = $second;
		$update       = (string) $second->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $this->wpdb->options, '998', Migrator::VERSION_OPTION );

		$this->wpdb->query( 'START TRANSACTION' );
		SchemaVersionRow::read_shared( $this->wpdb );

		self::assertFalse( $second->query( $update ), 'The UPDATE waits for the shared lock.' );
		self::assertSame( 1205, DatabaseErrno::last( $second ) );

		$this->wpdb->query( 'COMMIT' );

		self::assertSame( 1, $second->query( $update ), 'After COMMIT the UPDATE goes through.' );
	}
}
