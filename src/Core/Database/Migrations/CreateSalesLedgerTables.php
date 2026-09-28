<?php
/**
 * Schema version 5: sales ledger and sales cycle tables.
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
 * Creates `{prefix}merchboard_sales` and `{prefix}merchboard_sales_cycles`
 * with dbDelta() (idempotent). Both tables are part of one schema version
 * because the ledger cannot be written without its cycles (ADR-038).
 */
final class CreateSalesLedgerTables implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a table does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = $wpdb->get_charset_collate();
		$sales           = Tables::name( $wpdb, Tables::SALES );
		$cycles          = Tables::name( $wpdb, Tables::SALES_CYCLES );

		dbDelta( self::sales_schema( $sales, $charset_collate ) );
		dbDelta( self::cycles_schema( $cycles, $charset_collate ) );

		foreach ( array( $sales, $cycles ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			if ( $found !== $table ) {
				throw new DatabaseException( 'Could not create the sales ledger tables.' );
			}
		}
	}

	/**
	 * CREATE TABLE statement for the ledger in dbDelta() format (docs/DATABASE.md).
	 *
	 * - Append-only (ADR-037): rows are never updated or deleted.
	 * - `event_key` is the deterministic event identity; UNIQUE makes a repeated
	 *   event a no-op (ADR-038). There is deliberately no
	 *   UNIQUE(order_item_id, refund_id, type).
	 * - `quantity` and `net_amount` are signed by `type`; `net_amount` is a
	 *   DECIMAL, never a float.
	 * - `refund_id` indexes the lookup of the order when a refund is deleted.
	 * - UTC timestamps set by the application; no foreign keys (ADR-019/020).
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function sales_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
event_key varchar(64) NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
order_id bigint(20) unsigned NOT NULL,
order_item_id bigint(20) unsigned NOT NULL,
cycle int(10) unsigned NOT NULL,
refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
product_id bigint(20) unsigned NOT NULL,
variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
item_name varchar(191) NOT NULL DEFAULT '',
type varchar(10) NOT NULL,
correction_reason varchar(32) DEFAULT NULL,
quantity int(11) NOT NULL,
net_amount decimal(19,4) NOT NULL,
currency char(3) NOT NULL,
occurred_at datetime NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY event_key (event_key),
KEY creator_occurred (creator_id,occurred_at),
KEY order_id (order_id),
KEY refund_id (refund_id)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the cycle events in dbDelta() format.
	 *
	 * Append-only `open`/`close` events per (order, cycle), no mutable status
	 * column (ADR-038). The UNIQUE key also serves lookups by `order_id` (left
	 * prefix), so there is no separate order index.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function cycles_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
order_id bigint(20) unsigned NOT NULL,
cycle int(10) unsigned NOT NULL,
event varchar(5) NOT NULL,
occurred_at datetime NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY order_cycle_event (order_id,cycle,event)
) {$charset_collate};";
	}
}
