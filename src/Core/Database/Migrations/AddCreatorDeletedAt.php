<?php
/**
 * Schema version 31: deleted creators (phase 27, step 27.3).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database\Migrations;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migration;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_creators` gets `deleted_at datetime NULL` (ADR-076): a creator
 * with financial history is never removed, only anonymized and marked
 * deleted (UTC time of the deletion); the admin list hides it by default.
 * NULL for every existing creator. Idempotent: the column and its index only
 * when missing.
 */
final class AddCreatorDeletedAt implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the column does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::CREATORS );

		if ( ! self::has_column( $wpdb, $table ) ) {
			$sql = $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN deleted_at datetime NULL DEFAULT NULL AFTER updated_at, ADD KEY deleted_at (deleted_at)', $table );

			if ( is_string( $sql ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
			}
		}

		if ( ! self::has_column( $wpdb, $table ) ) {
			throw new DatabaseException( 'Could not add the deletion mark of creators.' );
		}
	}

	/**
	 * Whether the table has the column.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_column( wpdb $wpdb, string $table ): bool {
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'deleted_at' ) );
	}
}
