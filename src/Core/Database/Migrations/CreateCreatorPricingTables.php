<?php
/**
 * Schema version 6: creator pricing (phase 22).
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
 * Adds creator pricing (ADR-045–047):
 *
 * - `merchboard_creators.default_markup_bps`: the creator's default markup in
 *   basis points (0–10 000, 10 000 = 100 %). Existing and new creators get 0;
 * - `merchboard_pricing_overrides`: one fixed profit per parent product
 *   (current state);
 * - `merchboard_item_pricing`: base price and the last applied pricing per
 *   sellable unit (simple product or variation; current state, not a cache of
 *   the expected price);
 * - `merchboard_sales_line_pricing`: an append-only pricing snapshot per order
 *   line.
 *
 * Amounts are DECIMAL(19,4), read and written as integer units (never
 * floats). No foreign keys (ADR-019/020): the snapshot must survive deleted
 * orders, products and pricing rules. Value ranges (markup ≤ 10 000, amounts
 * ≥ 0, rule/markup coherence) are enforced by the pricing repositories, like
 * every other invariant of this schema, not by CHECK constraints (dbDelta).
 * Idempotent: the column is added only when it is missing, and dbDelta()
 * leaves existing tables unchanged.
 *
 * Schema version 8 (AddCreatorShareModel, ADR-049) replaces the price
 * writing of this version: it recreates `merchboard_pricing_overrides` with
 * a percentage, drops `merchboard_item_pricing` and adds the creator share to
 * the snapshot. This migration stays as it was, so upgrades replay history.
 */
final class CreateCreatorPricingTables implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the column or a table does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$creators = Tables::name( $wpdb, Tables::CREATORS );

		if ( ! self::has_markup_column( $wpdb, $creators ) ) {
			$alter = $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN default_markup_bps int(10) unsigned NOT NULL DEFAULT 0', $creators );

			if ( is_string( $alter ) ) {
				$wpdb->query( $alter ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
			}
		}

		if ( ! self::has_markup_column( $wpdb, $creators ) ) {
			throw new DatabaseException( 'Could not add the creator markup column.' );
		}

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = array(
			Tables::PRICING_OVERRIDES  => self::overrides_schema( Tables::name( $wpdb, Tables::PRICING_OVERRIDES ), $charset_collate ),
			Tables::ITEM_PRICING       => self::item_pricing_schema( Tables::name( $wpdb, Tables::ITEM_PRICING ), $charset_collate ),
			Tables::SALES_LINE_PRICING => self::sales_line_pricing_schema( Tables::name( $wpdb, Tables::SALES_LINE_PRICING ), $charset_collate ),
		);

		foreach ( $tables as $schema ) {
			dbDelta( $schema );
		}

		foreach ( array_keys( $tables ) as $table ) {
			$name  = Tables::name( $wpdb, $table );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );

			if ( $found !== $name ) {
				throw new DatabaseException( 'Could not create the creator pricing tables.' );
			}
		}
	}

	/**
	 * Whether the creators table already has `default_markup_bps`.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full creators table name.
	 */
	private static function has_markup_column( wpdb $wpdb, string $table ): bool {
		$column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'default_markup_bps' ) );

		return 'default_markup_bps' === $column;
	}

	/**
	 * Fixed profit per parent product (ADR-046). One row per product; the
	 * creator is the owner when the override was set. There is deliberately
	 * no percentage column: a product has either this override or the
	 * creator's default markup, never both.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function overrides_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
product_id bigint(20) unsigned NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
fixed_profit decimal(19,4) NOT NULL,
currency char(3) NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (product_id),
KEY creator_id (creator_id)
) {$charset_collate};";
	}

	/**
	 * Base price and last applied pricing per sellable unit (ADR-045): a
	 * simple product (`item_id` = `product_id`) or a variation (`product_id` =
	 * its parent). The `applied_*` columns record what Merch Dashboard last
	 * wrote to WooCommerce and for whom; they are all NULL until pricing is
	 * applied. The expected price is never stored.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function item_pricing_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
item_id bigint(20) unsigned NOT NULL,
product_id bigint(20) unsigned NOT NULL,
base_price decimal(19,4) NOT NULL,
base_currency char(3) NOT NULL,
base_updated_at datetime NOT NULL,
applied_at datetime DEFAULT NULL,
applied_creator_id bigint(20) unsigned DEFAULT NULL,
applied_rule varchar(7) DEFAULT NULL,
applied_markup_bps int(10) unsigned DEFAULT NULL,
applied_base decimal(19,4) DEFAULT NULL,
applied_profit decimal(19,4) DEFAULT NULL,
applied_price decimal(19,4) DEFAULT NULL,
applied_currency char(3) DEFAULT NULL,
PRIMARY KEY  (item_id),
KEY product_id (product_id)
) {$charset_collate};";
	}

	/**
	 * Pricing snapshot per order line (ADR-047). Append-only: rows are only
	 * inserted, never updated or deleted (except opt-in uninstall). References
	 * are plain ids without foreign keys, so the snapshot outlives the order,
	 * the order item, the product and every pricing rule.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function sales_line_pricing_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
order_item_id bigint(20) unsigned NOT NULL,
order_id bigint(20) unsigned NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
product_id bigint(20) unsigned NOT NULL,
variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
status varchar(8) NOT NULL,
reason varchar(20) DEFAULT NULL,
currency char(3) NOT NULL,
rule varchar(7) DEFAULT NULL,
markup_bps int(10) unsigned DEFAULT NULL,
base_unit decimal(19,4) DEFAULT NULL,
profit_unit decimal(19,4) DEFAULT NULL,
price_unit decimal(19,4) DEFAULT NULL,
captured_at datetime NOT NULL,
PRIMARY KEY  (order_item_id),
KEY order_id (order_id),
KEY creator_id (creator_id)
) {$charset_collate};";
	}
}
