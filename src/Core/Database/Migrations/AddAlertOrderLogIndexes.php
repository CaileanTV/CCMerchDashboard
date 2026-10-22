<?php
/**
 * Schema version 23: indexes for the alerts order log (phase 25, step 9.3).
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
 * The administrator's order log reads alerts across all creators by time
 * ("today", "last 24 hours") and the chat messages of a page of orders,
 * which the existing indexes (all by creator first) do not serve:
 *
 * - `merchboard_alert_events`: `KEY created_at (created_at, id)`;
 * - `merchboard_alert_chat_messages`: `KEY order_id (order_id)`.
 *
 * Only indexes are added; no data changes. Idempotent: each index only when
 * missing.
 */
final class AddAlertOrderLogIndexes implements Migration {

	/**
	 * Index name and columns by table.
	 */
	public const INDEXES = array(
		Tables::ALERT_EVENTS        => array( 'created_at', 'created_at, id' ),
		Tables::ALERT_CHAT_MESSAGES => array( 'order_id', 'order_id' ),
	);

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When an index does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		foreach ( self::INDEXES as $name => [ $index, $columns ] ) {
			$table = Tables::name( $wpdb, $name );

			if ( ! self::has_index( $wpdb, $table, $index ) ) {
				// The column list is a constant of this class.
				$sql = $wpdb->prepare( "ALTER TABLE %i ADD KEY %i ({$columns})", $table, $index ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Constant column list.

				if ( is_string( $sql ) ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
				}
			}

			if ( ! self::has_index( $wpdb, $table, $index ) ) {
				throw new DatabaseException( 'Could not add the alert order log indexes.' );
			}
		}
	}

	/**
	 * Whether the table has an index.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 * @param string $index Index name.
	 */
	private static function has_index( wpdb $wpdb, string $table, string $index ): bool {
		return null !== $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index ) );
	}
}
