<?php
/**
 * Migration 39: the price of a base product's extra design areas (phase 30, ADR-080).
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
 * One design area is included in a base product's price; every further used
 * area adds `merchboard_designer_bases.extra_side_price` (decimal incl. VAT,
 * 0 by default, so existing base products keep their prices; the owner's
 * choice 2026-10-30).
 *
 * Idempotent: the column is added only when missing.
 */
final class AddDesignerExtraSidePrice implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::DESIGNER_BASES );

		if ( ! self::has_column( $wpdb, $table ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `extra_side_price` decimal(19,4) NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables; versioned migration.
		}

		if ( ! self::has_column( $wpdb, $table ) ) {
			throw new DatabaseException( 'Could not add the price of extra design areas.' );
		}
	}

	/**
	 * Whether the table has the column.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_column( wpdb $wpdb, string $table ): bool {
		return 'extra_side_price' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'extra_side_price' ) );
	}
}
