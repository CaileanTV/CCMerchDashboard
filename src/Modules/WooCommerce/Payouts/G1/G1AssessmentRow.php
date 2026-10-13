<?php
/**
 * The G1 assessment row of an order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\G1;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * R2 of E3 for the G1 assessment (B-4): whether the order's row exists is
 * read without a lock before the transaction (E3.2 step 2c), and only a row
 * that exists is locked FOR UPDATE inside it (I4). Rows are only changed by
 * a path that holds the order's lock (I5), so a row read as existing and
 * then missing under the lock means I5 was broken.
 */
final class G1AssessmentRow {

	/**
	 * Whether the order has an assessment row (plain read).
	 *
	 * @param wpdb $wpdb     WordPress database object.
	 * @param int  $order_id WooCommerce order.
	 * @throws DatabaseException When the read fails.
	 */
	public static function exists( wpdb $wpdb, int $order_id ): bool {
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id = %d', Tables::name( $wpdb, Tables::ORDER_GIFT_CARD_ASSESSMENTS ), $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A plain existence read; never cached.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the G1 assessment.', array( 'order_id' => $order_id ) );
		}

		return null !== $found;
	}

	/**
	 * Locks the order's existing row FOR UPDATE inside the caller's transaction.
	 *
	 * @param wpdb $wpdb     WordPress database object.
	 * @param int  $order_id WooCommerce order.
	 * @return array{status: string, reason_code: string|null, updated_at: string}|null Null when the row is missing.
	 * @throws DatabaseException When the read fails.
	 */
	public static function lock( wpdb $wpdb, int $order_id ): ?array {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, reason_code, updated_at FROM %i WHERE order_id = %d FOR UPDATE', Tables::name( $wpdb, Tables::ORDER_GIFT_CARD_ASSESSMENTS ), $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A row lock inside the caller's transaction; never cached.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not lock the G1 assessment.', array( 'order_id' => $order_id ) );
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'status'      => (string) $row['status'],
			'reason_code' => is_string( $row['reason_code'] ?? null ) ? $row['reason_code'] : null,
			'updated_at'  => (string) $row['updated_at'],
		);
	}
}
