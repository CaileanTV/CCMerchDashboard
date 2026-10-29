<?php
/**
 * Migration 37: the price a designer product has in the shop (phase 30, ADR-080).
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
 * Adds to `merchboard_designer_products` the price the product has in
 * WooCommerce and the base price it was computed from (step 30.11, ADR-080
 * pkt. 10), both incl. VAT:
 *
 * - `shop_price`: the customer's price the writer last wrote.
 * - `shop_base_price`: the base price behind it. The settlement of a sale
 *   uses it (DesignerPrices), so a sale between a new base price and the
 *   repricing of the product is settled at the price the customer saw.
 *
 * NULL until a version is applied. Idempotent: a column is added only when
 * missing.
 */
final class AddDesignerShopPrice implements Migration {

	/**
	 * Columns and their definitions.
	 */
	private const COLUMNS = array(
		'shop_price'      => 'decimal(19,4) DEFAULT NULL',
		'shop_base_price' => 'decimal(19,4) DEFAULT NULL',
	);

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::DESIGNER_PRODUCTS );

		foreach ( self::COLUMNS as $column => $definition ) {
			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables and constant column definitions; versioned migration.
			}

			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				throw new DatabaseException( 'Could not add the designer shop price columns.', array( 'column' => $column ) );
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
		return $column === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	}
}
