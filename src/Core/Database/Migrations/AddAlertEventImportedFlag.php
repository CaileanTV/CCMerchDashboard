<?php
/**
 * Schema version 29: imported alerts (phase 25, step 25.13).
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
 * Alerts imported from MerchAlert get new, higher ids than the live ones, so
 * an open overlay, the global overlay or an API bot would show them as new
 * purchases (25.13 review). `merchboard_alert_events` gets
 * `imported tinyint(1) NOT NULL DEFAULT 0`: 1 for rows the import added;
 * the live feeds skip them, the history keeps them.
 *
 * Rows from before this version stay 0 (an import that already ran is
 * behind every cursor). No other data changes. Idempotent: the column only
 * when missing.
 */
final class AddAlertEventImportedFlag implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the column does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::ALERT_EVENTS );

		if ( ! self::has_column( $wpdb, $table ) ) {
			$sql = $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN imported tinyint(1) NOT NULL DEFAULT 0 AFTER customer_username', $table );

			if ( is_string( $sql ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
			}
		}

		if ( ! self::has_column( $wpdb, $table ) ) {
			throw new DatabaseException( 'Could not add the imported flag of alerts.' );
		}
	}

	/**
	 * Whether the table has the column.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_column( wpdb $wpdb, string $table ): bool {
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'imported' ) );
	}
}
