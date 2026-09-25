<?php
/**
 * Schema version 3: creator meta table.
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
 * Creates `{prefix}merchboard_creatormeta` with dbDelta() (idempotent).
 */
final class CreateCreatorMetaTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::CREATOR_META );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $found !== $table ) {
			throw new DatabaseException( 'Could not create the creator meta table.' );
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format: WordPress' standard meta
	 * table layout, so the metadata API (`*_metadata( 'merchboard_creator', … )`)
	 * and its cache work. The object column is `merchboard_creator_id` because
	 * the meta type is `merchboard_creator`. No foreign key (ADR-019/020).
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
merchboard_creator_id bigint(20) unsigned NOT NULL DEFAULT 0,
meta_key varchar(255) DEFAULT NULL,
meta_value longtext DEFAULT NULL,
PRIMARY KEY  (meta_id),
KEY merchboard_creator_id (merchboard_creator_id),
KEY meta_key (meta_key(191))
) {$charset_collate};";
	}
}
