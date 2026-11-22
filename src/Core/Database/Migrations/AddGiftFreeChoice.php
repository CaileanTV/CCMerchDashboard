<?php
/**
 * Schema version 54: gifts and giveaways with free choice of design.
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
 * Adds the nullable column `free_choice` to `{prefix}merchboard_gifts` and
 * `{prefix}merchboard_giveaways` (ADR-120, status note 2026-11-22;
 * idempotent). It holds the designs and prices as locked at the purchase;
 * NULL is a gift of one product, as every row before version 54. No data is
 * changed.
 */
final class AddGiftFreeChoice implements Migration {

	/**
	 * The column.
	 */
	private const COLUMN = 'free_choice';

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		foreach ( array( Tables::GIFTS, Tables::GIVEAWAYS ) as $name ) {
			$table = Tables::name( $wpdb, $name );

			if ( ! self::has_column( $wpdb, $table ) ) {
				$sql = $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN free_choice longtext NULL AFTER product_id', $table );

				if ( is_string( $sql ) ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
				}
			}

			if ( ! self::has_column( $wpdb, $table ) ) {
				throw new DatabaseException( 'Could not add the free choice of design.' );
			}
		}
	}

	/**
	 * Whether a table has the column.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_column( wpdb $wpdb, string $table ): bool {
		return self::COLUMN === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, self::COLUMN ) );
	}
}
