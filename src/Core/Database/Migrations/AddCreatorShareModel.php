<?php
/**
 * Schema version 8: the creator share model (phase 22).
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
 * Switches creator pricing to ADR-049: the creator's profit is a share of
 * the WooCommerce sale price, and Merch Dashboard never writes a price.
 *
 * - `merchboard_sales_line_pricing` gets `share_bps`, `share_source` and
 *   `price_decimals` (all NULL for existing rows: their share is unknown and
 *   is never filled in afterwards). Existing rows and their columns of
 *   ADR-047's withdrawn model (`rule`, `markup_bps`, `base_unit`,
 *   `profit_unit`, `price_unit`) stay unchanged;
 * - `merchboard_pricing_overrides` (current configuration, no history) is
 *   recreated with a percentage (`markup_bps`) instead of a fixed amount. A
 *   fixed amount per unit cannot be turned into a share of the sale price
 *   without guessing, so existing fixed-profit rows are dropped (their
 *   history stays in the audit log);
 * - `merchboard_item_pricing` (base prices and the last applied pricing,
 *   current state of the withdrawn price writing) is dropped.
 *
 * WooCommerce prices, the sales ledger, the audit log and the snapshots'
 * existing values are not touched. Idempotent: the columns are added only
 * when missing; the overrides table is recreated only while it lacks
 * `markup_bps`; dropping a missing table is a no-op.
 */
final class AddCreatorShareModel implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column or a table is not as expected afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$lines = Tables::name( $wpdb, Tables::SALES_LINE_PRICING );

		if ( ! self::has_column( $wpdb, $lines, 'share_bps' ) ) {
			self::run( $wpdb, $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN share_bps int(10) unsigned DEFAULT NULL', $lines ) );
		}

		if ( ! self::has_column( $wpdb, $lines, 'share_source' ) ) {
			self::run( $wpdb, $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN share_source varchar(8) DEFAULT NULL', $lines ) );
		}

		if ( ! self::has_column( $wpdb, $lines, 'price_decimals' ) ) {
			self::run( $wpdb, $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN price_decimals tinyint(3) unsigned DEFAULT NULL', $lines ) );
		}

		foreach ( array( 'share_bps', 'share_source', 'price_decimals' ) as $column ) {
			if ( ! self::has_column( $wpdb, $lines, $column ) ) {
				throw new DatabaseException( 'Could not add the creator share columns.' );
			}
		}

		$overrides = Tables::name( $wpdb, Tables::PRICING_OVERRIDES );

		if ( ! self::has_column( $wpdb, $overrides, 'markup_bps' ) ) {
			self::run( $wpdb, $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $overrides ) );

			// Never let dbDelta() alter the old table: it would add
			// `markup_bps` with 0 to every fixed-profit row, i.e. a
			// fabricated 0 % product markup.
			if ( self::has_table( $wpdb, $overrides ) ) {
				throw new DatabaseException( 'Could not drop the fixed-profit overrides table.' );
			}

			dbDelta( self::product_markups_schema( $overrides, $wpdb->get_charset_collate() ) );
		}

		if ( ! self::has_column( $wpdb, $overrides, 'markup_bps' ) ) {
			throw new DatabaseException( 'Could not create the product markup table.' );
		}

		$items = Tables::name( $wpdb, Tables::ITEM_PRICING );
		self::run( $wpdb, $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $items ) );

		if ( self::has_table( $wpdb, $items ) ) {
			throw new DatabaseException( 'Could not drop the item pricing table.' );
		}
	}

	/**
	 * Whether a table exists.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_table( wpdb $wpdb, string $table ): bool {
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Runs a prepared schema statement.
	 *
	 * @param wpdb        $wpdb WordPress database object.
	 * @param string|null $sql  Result of $wpdb->prepare().
	 */
	private static function run( wpdb $wpdb, ?string $sql ): void {
		if ( is_string( $sql ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared by the caller; versioned migration.
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

	/**
	 * A parent product's own creator share (ADR-049). One row per product;
	 * the creator is the owner when the share was set.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function product_markups_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
product_id bigint(20) unsigned NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
markup_bps int(10) unsigned NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (product_id),
KEY creator_id (creator_id)
) {$charset_collate};";
	}
}
