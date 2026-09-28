<?php
/**
 * TEMPORARY CI probe (never merged): what `@@in_transaction` reports on the
 * CI database. Prints the observations; asserts nothing about them.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Tests\Integration\IntegrationTestCase;

final class InTransactionProbeTest extends IntegrationTestCase {

	private const TABLE = 'merchboard_probe_in_transaction';

	public function test_probe_in_transaction(): void {
		$wpdb  = $this->wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$out   = array( 'server=' . (string) $wpdb->get_var( 'SELECT VERSION()' ) );

		$wpdb->suppress_errors( true );
		$this->execute( 'DROP TABLE IF EXISTS %i', $table );
		$this->execute( 'CREATE TABLE %i (id INT NOT NULL) ENGINE=InnoDB', $table );

		try {
			$out[] = '1 idle=' . $this->in_transaction();
			$wpdb->query( 'START TRANSACTION' );
			$out[] = '2 after START TRANSACTION=' . $this->in_transaction();
			$this->execute( 'INSERT INTO %i (id) VALUES (1)', $table );
			$out[] = '3 after INSERT=' . $this->in_transaction();
			$wpdb->query( 'COMMIT' );
			$out[] = '4 after COMMIT=' . $this->in_transaction();
			$wpdb->query( 'START TRANSACTION' );
			$this->execute( 'INSERT INTO %i (id) VALUES (2)', $table );
			$wpdb->query( 'ROLLBACK' );
			$out[] = '5 after ROLLBACK=' . $this->in_transaction();
			$wpdb->query( 'SET autocommit = 0' );
			$out[] = '6a autocommit=0, before INSERT=' . $this->in_transaction();
			$this->execute( 'INSERT INTO %i (id) VALUES (3)', $table );
			$out[] = '6b autocommit=0, after INSERT=' . $this->in_transaction();
			$wpdb->query( 'ROLLBACK' );
			$out[] = '6c autocommit=0, after ROLLBACK=' . $this->in_transaction();
		} finally {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->query( 'SET autocommit = 1' );
			$this->execute( 'DROP TABLE IF EXISTS %i', $table );
			$wpdb->suppress_errors( false );
		}

		fwrite( STDERR, "\nIN_TRANSACTION_PROBE\n" . implode( "\n", $out ) . "\nEND_PROBE\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CI probe output.
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Probe table removed.' );
	}

	/**
	 * `@@in_transaction` as text, or the error when the variable is unknown.
	 */
	private function in_transaction(): string {
		$value = $this->wpdb->get_var( 'SELECT @@in_transaction' );

		return null === $value ? 'NULL error=[' . $this->wpdb->last_error . ']' : (string) $value;
	}
}
