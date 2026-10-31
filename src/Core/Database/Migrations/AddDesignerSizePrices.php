<?php
/**
 * Migration 42: a base price per size (phase 30, ADR-080).
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
 * A base product can have a base price per size (the owner's choice
 * 2026-10-31):
 *
 * - `merchboard_designer_bases.size_prices`: JSON, the price of a size where it
 *   has its own; NULL with one price for every size.
 * - `merchboard_designer_products.shop_base_prices`: JSON, the base price
 *   behind each size's price in the shop. The settlement of a sale uses it, as
 *   `shop_base_price` for a product with one price; NULL for those.
 *
 * Existing rows stay NULL and keep their prices. Idempotent: a column is added
 * only when missing.
 */
final class AddDesignerSizePrices implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		foreach ( array(
			'size_prices'      => Tables::name( $wpdb, Tables::DESIGNER_BASES ),
			'shop_base_prices' => Tables::name( $wpdb, Tables::DESIGNER_PRODUCTS ),
		) as $column => $table ) {
			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` longtext DEFAULT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table and column names.
			}

			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				throw new DatabaseException( 'Could not add the prices per size.' );
			}
		}
	}

	/**
	 * Whether a table has a column.
	 *
	 * @param wpdb   $wpdb   WordPress database object.
	 * @param string $table  Full table name.
	 * @param string $column Column.
	 */
	private static function has_column( wpdb $wpdb, string $table, string $column ): bool {
		return $column === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Checks the migration.
	}
}
