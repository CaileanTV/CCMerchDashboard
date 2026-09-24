<?php
/**
 * Schema version 2: audit trail table.
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
 * Creates `{prefix}merchboard_audit_log` with dbDelta() (idempotent).
 */
final class CreateAuditLogTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::AUDIT_LOG );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $found !== $table ) {
			throw new DatabaseException( 'Could not create the audit log table.' );
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format.
	 *
	 * - Append-only business audit trail (ADR-023); technical logs are not stored here.
	 * - `actor_user_id` is NULL for system actions (no invented user id).
	 * - `creator_id` is NULL for events that do not concern a creator (e.g. settings).
	 * - `action` holds an AuditAction value; never free text.
	 * - `context` is a small, sanitized JSON object; never request data.
	 * - UTC timestamp set by the application; no foreign keys (ADR-019/020).
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
action varchar(50) NOT NULL,
actor_user_id bigint(20) unsigned DEFAULT NULL,
creator_id bigint(20) unsigned DEFAULT NULL,
context longtext DEFAULT NULL,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY action (action),
KEY creator_id (creator_id)
) {$charset_collate};";
	}
}
