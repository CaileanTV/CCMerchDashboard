<?php
/**
 * Schema version 19: alert overlays, and no customer first name (ADR-065).
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
 * `merchboard_alert_overlays`: one overlay per creator, with the secret
 * overlay id of the creator's OBS browser source (32 lowercase hex
 * characters, the format MerchAlert uses, so imported ids keep working) and
 * the overlay settings as JSON.
 *
 * It also drops `customer_first_name` from `merchboard_alert_events`: the
 * owner decided on 2026-10-19 that alerts show only the customer's voluntary
 * username, so the first name is no longer stored (ADR-065, status note).
 *
 * Idempotent: dbDelta() and a column check before the drop.
 */
final class CreateAlertOverlaysTable implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the table does not exist or the column is still there afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table = Tables::name( $wpdb, Tables::ALERT_OVERLAYS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new DatabaseException( 'Could not create the alert overlays table.' );
		}

		$events = Tables::name( $wpdb, Tables::ALERT_EVENTS );

		if ( self::has_first_name( $wpdb, $events ) ) {
			$drop = $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN customer_first_name', $events );

			if ( null !== $drop ) {
				$wpdb->query( $drop ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; a migration.
			}

			if ( self::has_first_name( $wpdb, $events ) ) {
				throw new DatabaseException( 'Could not drop the customer first name from the alert events.' );
			}
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
overlay_id char(32) NOT NULL,
settings longtext NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY creator_id (creator_id),
UNIQUE KEY overlay_id (overlay_id)
) {$charset_collate};";
	}

	/**
	 * Whether the alert events still have the first name column.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full name of the alert events table.
	 * @phpstan-impure Reads the database.
	 */
	private static function has_first_name( wpdb $wpdb, string $table ): bool {
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'customer_first_name' ) );
	}
}
