<?php
/**
 * Migration 52: several gifts in one gift order (phase 48, ADR-111).
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
 * A viewer can give 1–10 gifts in one purchase, each its own gift with its
 * own draw. Adds `order_unit` (1–10) to `merchboard_gifts` and replaces the
 * unique key on `order_id` with one on (`order_id`, `order_unit`), so the
 * gifts of an order are stored once per unit. Existing gifts get unit 1 and
 * are unchanged; a giveaway's gift (order_id NULL) is not affected.
 */
final class AddGiftOrderUnits implements Migration {

	/**
	 * The new unique key.
	 */
	private const UNIT_KEY = 'order_unit';

	/**
	 * The old unique key (one gift per order).
	 */
	private const ORDER_KEY = 'order_id';

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the change is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::GIFTS );

		if ( ! self::has_column( $wpdb, $table ) ) {
			self::run( $wpdb, $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN order_unit tinyint(3) unsigned NOT NULL DEFAULT 1 AFTER order_id', $table ) );
		}

		if ( ! self::has_index( $wpdb, $table, self::UNIT_KEY ) ) {
			self::run( $wpdb, $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY %i (order_id, order_unit)', $table, self::UNIT_KEY ) );
		}

		// The old key allowed one gift per order; the new one is in place before it goes.
		if ( self::has_index( $wpdb, $table, self::UNIT_KEY ) && self::has_index( $wpdb, $table, self::ORDER_KEY ) ) {
			self::run( $wpdb, $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, self::ORDER_KEY ) );
		}

		if ( ! self::has_column( $wpdb, $table ) || ! self::has_index( $wpdb, $table, self::UNIT_KEY ) || self::has_index( $wpdb, $table, self::ORDER_KEY ) ) {
			throw new DatabaseException( 'Could not let a gift order hold several gifts.' );
		}
	}

	/**
	 * Runs a prepared schema change.
	 *
	 * @param wpdb        $wpdb WordPress database object.
	 * @param string|null $sql  Prepared statement.
	 */
	private static function run( wpdb $wpdb, ?string $sql ): void {
		if ( is_string( $sql ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared by the caller; versioned migration.
		}
	}

	/**
	 * Whether the table has `order_unit`.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_column( wpdb $wpdb, string $table ): bool {
		return 'order_unit' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'order_unit' ) );
	}

	/**
	 * Whether the table has an index of that name.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 * @param string $name  Index name.
	 */
	private static function has_index( wpdb $wpdb, string $table, string $name ): bool {
		return null !== $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $name ) );
	}
}
