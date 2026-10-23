<?php
/**
 * Schema version 24: amounts of alerts (phase 25, after step 10.1).
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
 * The alert variables `$Price`, `$LineTotal` and `$Total` (the project
 * owner's choice 2026-10-23) need what the customer paid, stored when the
 * order is paid. `merchboard_alert_events` gets:
 *
 * - `line_total decimal(19,4) NULL`: the line with VAT, after discounts;
 * - `creator_total decimal(19,4) NULL`: all the creator's lines of the order;
 * - `currency char(3) NOT NULL DEFAULT ''`: the order's currency.
 *
 * NULL is unknown (alerts from before this version): their variables are
 * empty. No data changes. Idempotent: each column only when missing.
 */
final class AddAlertEventAmounts implements Migration {

	/**
	 * Column definitions after the column they follow.
	 */
	public const COLUMNS = array(
		'line_total'    => 'decimal(19,4) NULL AFTER quantity',
		'creator_total' => 'decimal(19,4) NULL AFTER line_total',
		'currency'      => "char(3) NOT NULL DEFAULT '' AFTER creator_total",
	);

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::ALERT_EVENTS );

		foreach ( self::COLUMNS as $column => $definition ) {
			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				// The column and its definition are constants of this class.
				$sql = $wpdb->prepare( "ALTER TABLE %i ADD COLUMN {$column} {$definition}", $table ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Constant column and definition.

				if ( is_string( $sql ) ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
				}
			}

			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				throw new DatabaseException( 'Could not add the alert amounts.' );
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
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	}
}
