<?php
/**
 * The creator row lock of the payout paths.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The lock protocol of every path that changes or settles a creator's
 * balance (ADR-055, K6.1): the creator's named payout lock, then the
 * transaction, then the creator's row FOR UPDATE as the transaction's first
 * statement, before any balance, payout or settlement data is read. The row
 * stays locked until COMMIT or ROLLBACK.
 *
 * Today every such path also holds the creator's named lock, so the row lock
 * adds no exclusion between them and changes no behaviour. It is the lock a
 * later decision about several creators can take, row by row in ascending
 * id order, and it then waits for every payout path of those creators.
 */
final class CreatorRowLock {

	/**
	 * Locks the creator's row inside the caller's transaction and returns
	 * the settlement start read under the lock.
	 *
	 * @param PayoutRepository $payouts    Storage holding the creator's named lock.
	 * @param int              $creator_id Creator.
	 * @throws DatabaseException When the named lock is not held, or the read fails or times out.
	 * @throws PayoutException When the creator does not exist.
	 */
	public static function acquire( PayoutRepository $payouts, int $creator_id ): ?DateTimeImmutable {
		// Throws unless this connection holds the creator's named lock.
		$payouts->lock_connection( $creator_id );

		return $payouts->settlement_start( $creator_id, true );
	}
}
