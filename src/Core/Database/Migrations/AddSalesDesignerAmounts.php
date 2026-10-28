<?php
/**
 * Migration 36: per-row amounts of designer products (phase 30, ADR-080).
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
 * Adds the creator's profit and payout of a designer product's ledger row to
 * `merchboard_sales_tax`, the per-row companion of the sales ledger that
 * every profit and payout sum already joins (ADR-048, ADR-050):
 *
 * - `designer_profit` and `designer_payout`: computed once in PHP when the
 *   row is written (Pricing\DesignerRowAmounts, ADR-080 pkt. 8), so the SQL
 *   sums only add stored values and always equal the PHP calculation.
 *   `designer_payout` is NULL when the creator's VAT status was unknown.
 * - `campaign_id`: the company's campaign that covered the row (ADR-080
 *   pkt. 9), NULL for none.
 *
 * All NULL for legacy rows, which keep the share model of ADR-049.
 * Idempotent: a column is added only when missing.
 */
final class AddSalesDesignerAmounts implements Migration {

	/**
	 * Columns and their definitions.
	 */
	private const COLUMNS = array(
		'designer_profit' => 'decimal(19,4) DEFAULT NULL',
		'designer_payout' => 'decimal(19,4) DEFAULT NULL',
		'campaign_id'     => 'bigint(20) unsigned DEFAULT NULL',
	);

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::SALES_TAX );

		foreach ( self::COLUMNS as $column => $definition ) {
			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables and constant column definitions; versioned migration.
			}

			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				throw new DatabaseException( 'Could not add the designer amount columns.', array( 'column' => $column ) );
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
