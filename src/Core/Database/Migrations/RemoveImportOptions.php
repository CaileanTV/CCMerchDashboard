<?php
/**
 * Schema version 30: the import tools' options (phase 27, step 27.1).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database\Migrations;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migration;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The import from MerchAlert (phase 25) and the migration from WooCommerce
 * Product Vendors (phase 26) ran on production and were removed in step
 * 27.1. Their options (the last run and the migration's state) have no
 * reader any more and are deleted (the project owner's choice 2026-10-25;
 * the numbers are in PROJECT-STATUS). The audit records of the runs stay.
 *
 * No schema change. Idempotent: deleting a missing option does nothing.
 */
final class RemoveImportOptions implements Migration {

	/**
	 * The options of the removed tools (also deleted on opt-in uninstall).
	 */
	public const OPTIONS = array( 'merchboard_alert_import', 'merchboard_vendor_import' );

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object (unused; options API).
	 * @throws DatabaseException When an option is still there afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
			wp_cache_delete( $option, 'options' );

			if ( false !== get_option( $option, false ) ) {
				throw new DatabaseException( 'Could not delete the option of a removed import tool.' );
			}
		}
	}
}
