<?php
/**
 * Schema version 32: the creators' category proposals.
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
 * Creates `{prefix}merchboard_category_proposals` with dbDelta() (phase 28,
 * ADR-077; idempotent).
 */
final class CreateCategoryProposalsTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::CATEGORY_PROPOSALS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $found !== $table ) {
			throw new DatabaseException( 'Could not create the category proposals table.' );
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format. `pending_key`
	 * ("{creator_id}:{field}") is set only while a proposal is pending, so the
	 * unique key allows one pending proposal per creator and field. Times are
	 * UTC. No foreign keys (ADR-019/020).
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
field varchar(32) NOT NULL,
value longtext NOT NULL,
status varchar(16) NOT NULL,
pending_key varchar(64) DEFAULT NULL,
reason varchar(500) DEFAULT NULL,
created_at datetime NOT NULL,
created_by bigint(20) unsigned DEFAULT NULL,
decided_at datetime DEFAULT NULL,
decided_by bigint(20) unsigned DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY pending_key (pending_key),
KEY creator_status (creator_id,status),
KEY status_created (status,created_at)
) {$charset_collate};";
	}
}
