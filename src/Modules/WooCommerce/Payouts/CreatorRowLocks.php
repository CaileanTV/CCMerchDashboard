<?php
/**
 * The creator row locks of an order decision.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * R1 of E3 (E3.1, E3.2 step 8): under the order's lock and inside the
 * caller's transaction, the rows of the order's creators are locked FOR
 * UPDATE one by one in ascending id order, without their named payout locks
 * (an order path never holds N1 and N2 together). Every payout path takes
 * its creator's row FOR UPDATE as well (CreatorRowLock), so a decision
 * holding these rows waits for, and blocks, those paths. The rows stay
 * locked until COMMIT or ROLLBACK. How long a lock is waited for is the
 * transaction's `innodb_lock_wait_timeout`.
 */
final class CreatorRowLocks {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Locks the creators' rows of the order in ascending id order.
	 *
	 * @phpstan-param list<int> $creator_ids
	 *
	 * @param SalesRepository $sales       Storage holding the order's lock.
	 * @param int             $order_id    WooCommerce order.
	 * @param int[]           $creator_ids The order's creators.
	 * @return list<int> The locked creator ids, ascending.
	 * @throws DatabaseException When the order's lock is not held, a read fails or times out, or a creator does not exist.
	 */
	public function acquire_for_order( SalesRepository $sales, int $order_id, array $creator_ids ): array {
		// Throws unless this instance holds the order's lock.
		$sales->lock_connection( $order_id );

		$ids = array_values( array_unique( $creator_ids ) );
		sort( $ids );

		$wpdb  = $this->wpdb;
		$table = Tables::name( $wpdb, Tables::CREATORS );

		foreach ( $ids as $id ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $table, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A row lock inside the caller's transaction; never cached.

			if ( '' !== $wpdb->last_error ) {
				throw new DatabaseException( 'Could not lock the creator row.', array( 'creator_id' => $id ) );
			}

			// FOR UPDATE only on rows that exist (I4).
			if ( null === $found ) {
				throw new DatabaseException( 'The creator of the order does not exist.', array( 'creator_id' => $id ) );
			}
		}

		return $ids;
	}
}
