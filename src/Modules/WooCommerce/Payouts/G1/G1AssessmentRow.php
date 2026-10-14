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
 * then missing under the lock means I5 was broken. The synchronization
 * reads existence inside its own transaction after R0 (E3.3 step 4b, S2)
 * and inserts a missing row as `pending` (step 6).
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

	/**
	 * Inserts the order's `pending` row, only on the given connection
	 * (E3.3 step 6, E3.5(g')): no INSERT IGNORE, no ON DUPLICATE KEY UPDATE,
	 * so an existing row is a failed statement (1062), never a silent no-op.
	 * Every other column stays NULL; nothing is audited (E3.5(i)).
	 *
	 * @param wpdb   $wpdb          WordPress database object.
	 * @param int    $order_id      WooCommerce order.
	 * @param string $updated_at    Insert time (UTC, 'Y-m-d H:i:s').
	 * @param int    $connection_id Connection that holds the order's lock and transaction.
	 * @return int Rows inserted (1, or 0 on another connection).
	 * @throws DatabaseException When the statement fails (e.g. 1062).
	 */
	public static function insert_pending( wpdb $wpdb, int $order_id, string $updated_at, int $connection_id ): int {
		$sql = $wpdb->prepare(
			'INSERT INTO %i (order_id, status, reason_code, source, assessed_at, assessed_by, decision_reason, updated_at) SELECT %d, %s, NULL, NULL, NULL, NULL, NULL, %s FROM DUAL WHERE CONNECTION_ID() = %d',
			Tables::name( $wpdb, Tables::ORDER_GIFT_CARD_ASSESSMENTS ),
			$order_id,
			'pending',
			$updated_at,
			$connection_id
		);

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not store the pending G1 assessment.', array( 'order_id' => $order_id ) );
		}

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the result of $wpdb->prepare(); a guarded write.

		if ( ! is_int( $result ) ) {
			throw new DatabaseException( 'Could not store the pending G1 assessment.', array( 'order_id' => $order_id ) );
		}

		return $result;
	}
}
