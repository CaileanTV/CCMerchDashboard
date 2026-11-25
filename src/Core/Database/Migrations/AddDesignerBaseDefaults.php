<?php
/**
 * Migration 56: a base product's fixed default colour and size (phase 59).
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
 * `merchboard_designer_bases.default_color` (a colour key) and
 * `default_size` (a size name): the variant a designer product on the base
 * product shows first in the shop when the administrator fixes one
 * (phase 59). Empty, as for every existing base product, means no fixed
 * default: the colour then follows the creator's main image, and a size is
 * chosen only when there is one.
 *
 * Idempotent: a column is added only when missing.
 */
final class AddDesignerBaseDefaults implements Migration {

	/**
	 * The columns and their definitions.
	 */
	private const COLUMNS = array(
		'default_color' => "varchar(32) NOT NULL DEFAULT ''",
		'default_size'  => "varchar(60) NOT NULL DEFAULT ''",
	);

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::DESIGNER_BASES );

		foreach ( self::COLUMNS as $column => $definition ) {
			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table, column and definition; versioned migration.
			}

			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				throw new DatabaseException( 'Could not add the default variant of base products.' );
			}
		}
	}

	/**
	 * Whether the table has a column.
	 *
	 * @param wpdb   $wpdb   WordPress database object.
	 * @param string $table  Full table name.
	 * @param string $column Column.
	 */
	private static function has_column( wpdb $wpdb, string $table, string $column ): bool {
		return $column === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	}
}
