<?php
/**
 * Schema version 1: creators table.
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
 * Creates `{prefix}merchboard_creators` with dbDelta() (idempotent).
 */
final class CreateCreatorsTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::CREATORS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $found !== $table ) {
			throw new DatabaseException( 'Could not create the creators table.' );
		}
	}

	/**
	 * CREATE TABLE statement in the format dbDelta() requires: one column per
	 * line, two spaces after PRIMARY KEY, KEY instead of INDEX.
	 *
	 * - `user_id` is nullable: a creator (business entity) can exist without a
	 *   WordPress login. UNIQUE allows many NULLs but at most one creator per user.
	 * - Timestamps are UTC and set by the application, not by MySQL defaults,
	 *   so they do not depend on the database session time zone (ADR-019).
	 * - No foreign key to wp_users: WordPress does not use them and user
	 *   deletion must never cascade into creator data.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned DEFAULT NULL,
display_name varchar(191) NOT NULL,
status varchar(20) NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY user_id (user_id),
KEY status (status)
) {$charset_collate};";
	}
}
