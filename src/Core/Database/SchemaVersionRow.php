<?php
/**
 * Reads the schema version row.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The schema version straight from its `options` row (E3.3, version row
 * rules 1-2), never from the option cache.
 *
 * The plain read() is used before a transaction (E3.2 step 2b); read_shared()
 * is the first lock of R0 inside a transaction (E3.2 step 7, E3.3), a locking
 * read that waits for and blocks the migration's UPDATE of the row. A missing
 * row is an error, never "no version" (fail closed, E3.3).
 */
final class SchemaVersionRow {

	/**
	 * The stored schema version, read without a lock.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the read fails or the row is missing.
	 */
	public static function read( wpdb $wpdb ): string {
		return self::select( $wpdb, false );
	}

	/**
	 * The stored schema version, read with a shared lock on its row that is
	 * held until the caller's transaction ends.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the read fails or the row is missing.
	 */
	public static function read_shared( wpdb $wpdb ): string {
		return self::select( $wpdb, true );
	}

	/**
	 * Reads the row.
	 *
	 * @param wpdb $wpdb   WordPress database object.
	 * @param bool $shared Whether to take a shared lock on the row.
	 * @throws DatabaseException When the read fails or the row is missing.
	 */
	private static function select( wpdb $wpdb, bool $shared ): string {
		$sql = $shared
			? $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LOCK IN SHARE MODE', $wpdb->options, Migrator::VERSION_OPTION )
			: $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, Migrator::VERSION_OPTION );

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not read the schema version.' );
		}

		$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare().

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the schema version.' );
		}

		if ( null === $value ) {
			throw new DatabaseException( 'The schema version row is missing.' );
		}

		return (string) $value;
	}
}
