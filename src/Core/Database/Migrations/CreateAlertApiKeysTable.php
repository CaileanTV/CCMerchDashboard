<?php
/**
 * Schema version 26: the creators' API keys of the alert API (ADR-073, step 25.12.1).
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
 * `merchboard_alert_api_keys`: one row per key a creator made for their own
 * bot: a name, the public lookup part of the key and an HMAC of its secret
 * part (never the key itself), when it was made, last used and revoked.
 *
 * Idempotent: dbDelta() and a table check afterwards.
 */
final class CreateAlertApiKeysTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::ALERT_API_KEYS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new DatabaseException( 'Could not create the alert API keys table.' );
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
name varchar(60) NOT NULL,
lookup char(12) NOT NULL,
secret_hash char(64) NOT NULL,
created_at datetime NOT NULL,
last_used_at datetime NULL,
revoked_at datetime NULL,
PRIMARY KEY  (id),
UNIQUE KEY lookup (lookup),
KEY creator_id (creator_id)
) {$charset_collate};";
	}
}
