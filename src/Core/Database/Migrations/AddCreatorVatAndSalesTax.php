<?php
/**
 * Schema version 7: creator VAT status and the tax of sales ledger rows (phase 22).
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
 * Adds what the payout of ADR-048 needs:
 *
 * - `merchboard_creators.vat_registered`: whether the creator is VAT
 *   registered (1) or not (0). Existing and new creators get 0, which pays
 *   out the lower amount (excl. VAT) until an admin sets it explicitly;
 * - `merchboard_sales_line_pricing.vat_registered`: the creator's status
 *   snapshotted with the order line's pricing; NULL for snapshots taken
 *   before this migration (unknown, never filled in afterwards);
 * - `merchboard_sales_tax`: the actual WooCommerce tax of each sales ledger
 *   row, keyed by the row's `event_key`. Append-only and written in the same
 *   transaction as the row; rows registered before this migration have none
 *   (unknown). `merchboard_sales` itself is unchanged (no column added).
 *
 * Amounts are DECIMAL(19,4), read and written as integer units (never
 * floats). No foreign keys (ADR-019). Idempotent: columns are added only
 * when missing; dbDelta() leaves an existing table unchanged.
 */
final class AddCreatorVatAndSalesTax implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column or the table does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$creators = Tables::name( $wpdb, Tables::CREATORS );
		$lines    = Tables::name( $wpdb, Tables::SALES_LINE_PRICING );

		if ( ! self::has_column( $wpdb, $creators ) ) {
			self::alter( $wpdb, $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN vat_registered tinyint(1) unsigned NOT NULL DEFAULT 0', $creators ) );
		}

		if ( ! self::has_column( $wpdb, $lines ) ) {
			self::alter( $wpdb, $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN vat_registered tinyint(1) unsigned DEFAULT NULL', $lines ) );
		}

		if ( ! self::has_column( $wpdb, $creators ) || ! self::has_column( $wpdb, $lines ) ) {
			throw new DatabaseException( 'Could not add the VAT status columns.' );
		}

		$name = Tables::name( $wpdb, Tables::SALES_TAX );
		dbDelta( self::sales_tax_schema( $name, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
			throw new DatabaseException( 'Could not create the sales tax table.' );
		}
	}

	/**
	 * Runs a prepared ALTER statement.
	 *
	 * @param wpdb        $wpdb WordPress database object.
	 * @param string|null $sql  Result of $wpdb->prepare().
	 */
	private static function alter( wpdb $wpdb, ?string $sql ): void {
		if ( is_string( $sql ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared by the caller; versioned migration.
		}
	}

	/**
	 * Whether a table has `vat_registered`.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_column( wpdb $wpdb, string $table ): bool {
		return 'vat_registered' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'vat_registered' ) );
	}

	/**
	 * Actual tax per ledger row (ADR-048): the signed tax WooCommerce has for
	 * the row (sale: the line's tax; refund: the refund line's tax; reversal
	 * and corrections: mirrored from the row's tax balance), in the row's
	 * currency. One row per ledger `event_key`, written once.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function sales_tax_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
event_key varchar(64) NOT NULL,
order_id bigint(20) unsigned NOT NULL,
order_item_id bigint(20) unsigned NOT NULL,
tax_amount decimal(19,4) NOT NULL,
currency char(3) NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (event_key),
KEY order_id (order_id)
) {$charset_collate};";
	}
}
