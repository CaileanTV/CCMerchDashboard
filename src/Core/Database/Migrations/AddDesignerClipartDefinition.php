<?php
/**
 * Schema version 55: cliparts for chosen base products with coloured parts.
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
 * Adds two nullable columns to `{prefix}merchboard_designer_assets`
 * (ADR-121 pkt. 9; idempotent): `definition` holds a clipart's base
 * products, parts, palette and lock, and `retired_at` (UTC) when it was
 * removed. NULL in both is an asset as every row before version 55: a
 * clipart for all products. No data is changed.
 */
final class AddDesignerClipartDefinition implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::DESIGNER_ASSETS );

		$columns = array(
			'definition' => 'ALTER TABLE %i ADD COLUMN definition longtext NULL',
			'retired_at' => 'ALTER TABLE %i ADD COLUMN retired_at datetime NULL',
		);

		foreach ( $columns as $column => $statement ) {
			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				$sql = $wpdb->prepare( $statement, $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- One of the two constant statements above.

				if ( is_string( $sql ) ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
				}
			}

			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				throw new DatabaseException( 'Could not add the definition of cliparts.' );
			}
		}
	}

	/**
	 * Whether the table has a column.
	 *
	 * @param wpdb   $wpdb   WordPress database object.
	 * @param string $table  Full table name.
	 * @param string $column The column.
	 */
	private static function has_column( wpdb $wpdb, string $table, string $column ): bool {
		return $column === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	}
}
