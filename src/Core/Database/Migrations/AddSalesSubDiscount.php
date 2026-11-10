<?php
/**
 * Migration 49: the Twitch subscriber discount of a ledger row (phase 43, ADR-106).
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
 * Adds `sub_discount` to `merchboard_sales_tax`: the Twitch subscriber
 * discount (ADR-106 pkt. 7) a row carries, in the paid basis (incl. VAT,
 * like the row's net + tax). Every row of an order line with a subscriber
 * discount has it (the discount on the line's first positive row, 0 on the
 * others), and such rows store their profit and payout in `designer_profit`
 * and `designer_payout`, which from now on mean "the row's stored profit and
 * payout". NULL for every other row, so existing rows are unchanged.
 */
final class AddSalesSubDiscount implements Migration {

	/**
	 * The column.
	 */
	private const COLUMN = 'sub_discount';

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the column is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::SALES_TAX );

		if ( ! self::has_column( $wpdb, $table ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `sub_discount` decimal(19,4) DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables and a constant column definition; versioned migration.
		}

		if ( ! self::has_column( $wpdb, $table ) ) {
			throw new DatabaseException( 'Could not add the subscriber discount column.', array( 'column' => self::COLUMN ) );
		}
	}

	/**
	 * Whether the table has the column.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_column( wpdb $wpdb, string $table ): bool {
		return self::COLUMN === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, self::COLUMN ) );
	}
}
