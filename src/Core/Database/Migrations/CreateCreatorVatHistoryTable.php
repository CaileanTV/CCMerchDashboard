<?php
/**
 * Schema version 16: history of the creators' VAT status (ADR-062 K06).
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
 * `merchboard_creator_vat_history`: one row per registration of a creator's
 * VAT status, written once and never changed or deleted. The date the status
 * applies from (`effective_from`) is separate from the date it was checked
 * (`checked_on`) and the time it was recorded (`recorded_at`); a correction
 * is a new row. No row is made for existing creators: their current status
 * (`merchboard_creators.vat_registered`) was set before the history, and
 * earlier changes are in the audit log (`creator_vat_status_changed`).
 * Nothing existing is changed. Idempotent: dbDelta().
 */
final class CreateCreatorVatHistoryTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::CREATOR_VAT_HISTORY );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new DatabaseException( 'Could not create the creator VAT history table.' );
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
vat_registered tinyint(1) unsigned NOT NULL,
effective_from date NOT NULL,
checked_on date NOT NULL,
documentation_reference varchar(191) NOT NULL,
reason varchar(500) NOT NULL,
recorded_by bigint(20) unsigned DEFAULT NULL,
recorded_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY creator_effective (creator_id,effective_from,id)
) {$charset_collate};";
	}
}
