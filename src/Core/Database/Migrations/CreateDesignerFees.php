<?php
/**
 * Migration: the digitization fees of embroidery files (ADR-086).
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
 * - `merchboard_designer_fees`: one row per creator and embroidery file
 *   (`UNIQUE(creator_id, file_hash)`), so a file costs once.
 * - `merchboard_designer_fee_versions`: the approved versions that use a file.
 *   The first sale of one of them charges the fee.
 * - `merchboard_designer_versions.fee_quote`: the fee the creator saw when the
 *   version was sent; NULL when it has no embroidery or there was no fee.
 * - `merchboard_payout_adjustments`: an adjustment comes from a recalculation
 *   or from a fee (`designer_fee_id`), so the recalculation columns may be
 *   NULL. `UNIQUE(recalculation_item_id)` still executes an item at most once.
 *
 * Existing rows do not change. Idempotent.
 */
final class CreateDesignerFees implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a table or column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$collate = $wpdb->get_charset_collate();
		$tables  = array(
			Tables::name( $wpdb, Tables::DESIGNER_FEES ) => self::fees_schema( Tables::name( $wpdb, Tables::DESIGNER_FEES ), $collate ),
			Tables::name( $wpdb, Tables::DESIGNER_FEE_VERSIONS ) => self::links_schema( Tables::name( $wpdb, Tables::DESIGNER_FEE_VERSIONS ), $collate ),
		);

		foreach ( $tables as $table => $schema ) {
			dbDelta( $schema );

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create the fee tables.' );
			}
		}

		$versions    = Tables::name( $wpdb, Tables::DESIGNER_VERSIONS );
		$adjustments = Tables::name( $wpdb, Tables::PAYOUT_ADJUSTMENTS );

		if ( ! self::has_column( $wpdb, $versions, 'fee_quote' ) ) {
			$wpdb->query( "ALTER TABLE `{$versions}` ADD COLUMN `fee_quote` decimal(19,4) DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables; versioned migration.
		}

		if ( ! self::has_column( $wpdb, $adjustments, 'designer_fee_id' ) ) {
			$wpdb->query( "ALTER TABLE `{$adjustments}` MODIFY `recalculation_id` bigint(20) unsigned DEFAULT NULL, MODIFY `recalculation_item_id` bigint(20) unsigned DEFAULT NULL, ADD COLUMN `designer_fee_id` bigint(20) unsigned DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables; versioned migration.
		}

		if ( ! self::has_column( $wpdb, $versions, 'fee_quote' ) || ! self::has_column( $wpdb, $adjustments, 'designer_fee_id' ) ) {
			throw new DatabaseException( 'Could not add the columns of the fees.' );
		}
	}

	/**
	 * CREATE TABLE statement for the fees in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function fees_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
file_hash char(64) NOT NULL,
amount decimal(19,4) NOT NULL,
currency char(3) NOT NULL,
side varchar(32) NOT NULL,
title varchar(191) NOT NULL,
status varchar(12) NOT NULL,
order_id bigint(20) unsigned DEFAULT NULL,
adjustment_id bigint(20) unsigned DEFAULT NULL,
charged_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY creator_file (creator_id,file_hash),
KEY status (status,id)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the versions of a file in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function links_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
fee_id bigint(20) unsigned NOT NULL,
version_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (fee_id,version_id),
KEY version_id (version_id)
) {$charset_collate};";
	}

	/**
	 * Whether a table has a column.
	 *
	 * @param wpdb   $wpdb   WordPress database object.
	 * @param string $table  Full table name.
	 * @param string $column Column.
	 */
	private static function has_column( wpdb $wpdb, string $table, string $column ): bool {
		return $column === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check in a migration.
	}
}
