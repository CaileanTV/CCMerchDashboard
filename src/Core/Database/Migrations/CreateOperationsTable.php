<?php
/**
 * Schema version 13: the operation registry (E3 T4, F-5).
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
 * `merchboard_operations`: one row per completed state mutation, written in
 * the same transaction as the mutation and its audit record (F-5). The
 * primary key is the operation id (a UUID), so an operation is never
 * applied twice; `kind`, `subject_id` and `payload_hash` (an opaque SHA-256
 * hex string from the caller) tell a repeat of the same operation from a
 * reused id. Rows are never deleted here; the opt-in uninstall keeps them
 * (B-2). Nothing existing is changed. Idempotent: dbDelta().
 */
final class CreateOperationsTable implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the table does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table = Tables::name( $wpdb, Tables::OPERATIONS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new DatabaseException( 'Could not create the operation registry.' );
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
operation_id char(36) NOT NULL,
kind varchar(32) NOT NULL,
subject_id bigint(20) unsigned NOT NULL,
payload_hash char(64) NOT NULL,
actor_user_id bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (operation_id),
KEY subject (kind,subject_id)
) {$charset_collate};";
	}
}
