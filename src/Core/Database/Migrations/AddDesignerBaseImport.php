<?php
/**
 * Migration 38: a base product's import from Printful or Gelato (phase 30, ADR-081, ADR-082).
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
 * A base product picked from Printful's or Gelato's catalog is imported in
 * the background (review 30.15): the provider renders the blank product in
 * every colour and with a marker per side, from which the photos and the
 * print areas are taken. On `merchboard_designer_bases`:
 *
 * - `provider_template` becomes `longtext`: the provider's product, its
 *   variant per colour and size, the placement per side and (Gelato) the
 *   template and the image index per side no longer fit 191 characters.
 * - `import_state`: `none` (an own base product), `running`, `ready` or
 *   `failed`.
 * - `import_data`: the import's tasks or products, attempts and failure
 *   (JSON), NULL when there is none.
 *
 * Idempotent: a column is added or widened only when needed.
 */
final class AddDesignerBaseImport implements Migration {

	/**
	 * New columns and their definitions.
	 */
	private const COLUMNS = array(
		'import_state' => "varchar(10) NOT NULL DEFAULT 'none'",
		'import_data'  => 'longtext NULL',
	);

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column is missing or not widened afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::DESIGNER_BASES );

		if ( 'longtext' !== self::column_type( $wpdb, $table, 'provider_template' ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` MODIFY `provider_template` longtext NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables; versioned migration.
		}

		if ( 'longtext' !== self::column_type( $wpdb, $table, 'provider_template' ) ) {
			throw new DatabaseException( 'Could not widen the provider template of the base products.' );
		}

		foreach ( self::COLUMNS as $column => $definition ) {
			if ( null === self::column_type( $wpdb, $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables and constant column definitions; versioned migration.
			}

			if ( null === self::column_type( $wpdb, $table, $column ) ) {
				throw new DatabaseException( 'Could not add the base product import columns.', array( 'column' => $column ) );
			}
		}
	}

	/**
	 * A column's type in lower case, or null when the table has no such column.
	 *
	 * @param wpdb   $wpdb   WordPress database object.
	 * @param string $table  Full table name.
	 * @param string $column Column.
	 */
	private static function column_type( wpdb $wpdb, string $table, string $column ): ?string {
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ), ARRAY_A );

		return is_array( $row ) && is_string( $row['Type'] ?? null ) ? strtolower( $row['Type'] ) : null;
	}
}
