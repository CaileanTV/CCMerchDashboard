<?php
/**
 * The G1 activation row.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\G1;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrations\CreateGiftCardAssessmentTables;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Row `id = 1` of `merchboard_g1_state`, created inactive by schema version
 * 12 and never created or deleted here. It is only ever changed once, from
 * inactive to active, by a conditional UPDATE on the connection that holds
 * the transaction (E3.5(b)); an active row is never overwritten (B2).
 * Statement failures throw at once, so the caller reads the error number
 * of exactly the failed statement.
 */
final class G1StateRow {

	/**
	 * Locks the row FOR UPDATE inside the caller's transaction.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @return array{cutoff_utc: string|null, activated_at: string|null, activated_by: int|null}|null Null when the row is missing.
	 * @throws DatabaseException When the read fails.
	 */
	public static function lock( wpdb $wpdb ): ?array {
		$sql = $wpdb->prepare( 'SELECT cutoff_utc, activated_at, activated_by FROM %i WHERE id = %d FOR UPDATE', Tables::name( $wpdb, Tables::G1_STATE ), CreateGiftCardAssessmentTables::STATE_ROW );

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not read the G1 activation row.' );
		}

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the result of $wpdb->prepare(); a row lock, never cached.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the G1 activation row.' );
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'cutoff_utc'   => is_string( $row['cutoff_utc'] ?? null ) ? $row['cutoff_utc'] : null,
			'activated_at' => is_string( $row['activated_at'] ?? null ) ? $row['activated_at'] : null,
			'activated_by' => is_numeric( $row['activated_by'] ?? null ) ? (int) $row['activated_by'] : null,
		);
	}

	/**
	 * Activates the inactive row, only on the given connection.
	 *
	 * @param wpdb   $wpdb          WordPress database object.
	 * @param string $cutoff_utc    Cut-off (UTC, 'Y-m-d H:i:s').
	 * @param string $activated_at  Activation time (UTC, 'Y-m-d H:i:s').
	 * @param int    $activated_by  Activating user.
	 * @param int    $connection_id Connection that holds the transaction.
	 * @return int Rows changed (1, or 0 on another connection or an active row).
	 * @throws DatabaseException When the statement fails.
	 */
	public static function activate( wpdb $wpdb, string $cutoff_utc, string $activated_at, int $activated_by, int $connection_id ): int {
		$sql = $wpdb->prepare(
			'UPDATE %i SET cutoff_utc = %s, activated_at = %s, activated_by = %d WHERE id = %d AND activated_at IS NULL AND CONNECTION_ID() = %d',
			Tables::name( $wpdb, Tables::G1_STATE ),
			$cutoff_utc,
			$activated_at,
			$activated_by,
			CreateGiftCardAssessmentTables::STATE_ROW,
			$connection_id
		);

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not activate G1.' );
		}

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the result of $wpdb->prepare(); a guarded write.

		if ( ! is_int( $result ) ) {
			throw new DatabaseException( 'Could not activate G1.' );
		}

		return $result;
	}
}
