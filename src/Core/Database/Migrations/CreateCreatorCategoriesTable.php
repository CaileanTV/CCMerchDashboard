<?php
/**
 * Schema version 4: creator ↔ product category mapping table.
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
 * Creates `{prefix}merchboard_creator_categories` with dbDelta() (idempotent).
 */
final class CreateCreatorCategoriesTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::CREATOR_CATEGORIES );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $found !== $table ) {
			throw new DatabaseException( 'Could not create the creator categories table.' );
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format (ADR-033). The primary key on
	 * `term_id` makes one `product_cat` term map to at most one creator; a
	 * creator can hold several rows. `created_at` is UTC. No foreign keys, also
	 * not to WordPress' term tables (ADR-019/020).
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
term_id bigint(20) unsigned NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
created_at datetime NOT NULL,
created_by bigint(20) unsigned DEFAULT NULL,
PRIMARY KEY  (term_id),
KEY creator_id (creator_id)
) {$charset_collate};";
	}
}
