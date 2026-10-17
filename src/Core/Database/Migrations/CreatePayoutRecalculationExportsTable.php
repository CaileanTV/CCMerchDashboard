<?php
/**
 * Schema version 15: exports of payout recalculations (ADR-062 pkt. 9, ADR-063).
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
 * `merchboard_payout_recalculation_exports`: an export of a recalculation,
 * its files with manifest and checksums, written once and never changed or
 * deleted, so a new export never replaces an earlier one. A migration of its
 * own, because schema version 14 had already run without it. Nothing
 * existing is changed. Idempotent: dbDelta().
 */
final class CreatePayoutRecalculationExportsTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::RECALCULATION_EXPORTS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new DatabaseException( 'Could not create the recalculation exports table.' );
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
recalculation_id bigint(20) unsigned NOT NULL,
filters varchar(191) NOT NULL DEFAULT '',
files longtext NOT NULL,
manifest_sha256 char(64) NOT NULL DEFAULT '',
sums_sha256 char(64) NOT NULL DEFAULT '',
created_by bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY recalculation (recalculation_id,id)
) {$charset_collate};";
	}
}
