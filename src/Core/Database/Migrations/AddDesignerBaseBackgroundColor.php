<?php
/**
 * Migration 46: base products that offer a background colour (phase 33).
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
 * ADR-095: `merchboard_designer_bases.background_color` (0 or 1, 0 by
 * default) marks the base products, e.g. all-over jerseys, on which the
 * designer offers a background colour. Existing base products offer none.
 *
 * It also repairs the dates of base products written by 1.5.0: its format
 * lists were one short after migration 44, so `created_at` and `updated_at`
 * were stored as zero dates (review 33.5). A zero date becomes the time of
 * the migration; real dates are not touched.
 *
 * Idempotent: the column is added only when missing.
 */
final class AddDesignerBaseBackgroundColor implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::DESIGNER_BASES );

		if ( ! self::has_column( $wpdb, $table ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `background_color` tinyint(1) NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables; versioned migration.
		}

		if ( ! self::has_column( $wpdb, $table ) ) {
			throw new DatabaseException( 'Could not add the background colour of base products.' );
		}

		foreach ( array( 'created_at', 'updated_at' ) as $column ) {
			// No zero date literal: strict SQL modes refuse one even in a comparison.
			$wpdb->query( "UPDATE `{$table}` SET `{$column}` = UTC_TIMESTAMP() WHERE `{$column}` < '1000-01-01'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant table and column names; versioned migration.
		}
	}

	/**
	 * Whether the table has the column.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_column( wpdb $wpdb, string $table ): bool {
		return 'background_color' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'background_color' ) );
	}
}
