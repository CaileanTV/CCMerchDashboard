<?php
/**
 * The operation registry.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Operations;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Access to `merchboard_operations` inside the E3 frame's transaction
 * (F-5). The pre-check reads the operation id with a shared lock (I4: no
 * FOR UPDATE on a row that may be missing); the insert is the transaction's
 * first write and is guarded by the connection (E3.5(b)). Statement
 * failures throw at once, so the caller reads the error number of exactly
 * the failed statement. Rows are never updated or deleted.
 */
final class OperationRegistry {

	/**
	 * Reads the operation's row with a shared lock.
	 *
	 * @param wpdb   $wpdb WordPress database object.
	 * @param string $id   Operation id.
	 * @return array{kind: string, subject_id: int, payload_hash: string}|null Null when the id is not registered.
	 * @throws DatabaseException When the read fails.
	 */
	public static function find_shared( wpdb $wpdb, string $id ): ?array {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT kind, subject_id, payload_hash FROM %i WHERE operation_id = %s LOCK IN SHARE MODE', Tables::name( $wpdb, Tables::OPERATIONS ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A locking read inside the caller's transaction; never cached.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the operation registry.' );
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'kind'         => (string) $row['kind'],
			'subject_id'   => (int) $row['subject_id'],
			'payload_hash' => (string) $row['payload_hash'],
		);
	}

	/**
	 * Whether a registered row is this operation (same kind, order and payload hash).
	 *
	 * @param array{kind: string, subject_id: int, payload_hash: string} $row       Registered row.
	 * @param Operation                                                  $operation Operation.
	 */
	public static function matches( array $row, Operation $operation ): bool {
		return $row['kind'] === $operation->kind && $row['subject_id'] === $operation->order_id && hash_equals( $row['payload_hash'], $operation->payload_hash );
	}

	/**
	 * Registers the operation, only on the given connection.
	 *
	 * @param wpdb      $wpdb          WordPress database object.
	 * @param Operation $operation     Operation.
	 * @param int|null  $actor_user_id Acting user; null for a system job.
	 * @param string    $created_at    UTC DATETIME ('Y-m-d H:i:s').
	 * @param int       $connection_id Connection that holds the transaction.
	 * @return int Rows inserted (1, or 0 on another connection).
	 * @throws DatabaseException When the statement fails (also on a duplicate id).
	 */
	public static function insert( wpdb $wpdb, Operation $operation, ?int $actor_user_id, string $created_at, int $connection_id ): int {
		$sql = $wpdb->prepare(
			'INSERT INTO %i (operation_id, kind, subject_id, payload_hash, actor_user_id, created_at) SELECT %s, %s, %d, %s, NULLIF(%d, 0), %s FROM DUAL WHERE CONNECTION_ID() = %d',
			Tables::name( $wpdb, Tables::OPERATIONS ),
			$operation->id,
			$operation->kind,
			$operation->order_id,
			$operation->payload_hash,
			$actor_user_id ?? 0,
			$created_at,
			$connection_id
		);

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not register the operation.' );
		}

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the result of $wpdb->prepare(); a guarded write.

		if ( ! is_int( $result ) ) {
			throw new DatabaseException( 'Could not register the operation.' );
		}

		return $result;
	}
}
