<?php
/**
 * Runs a transaction at REPEATABLE READ with its own lock wait timeout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use MerchBoard\Core\Logging\Logger;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * E3.2 steps 4-6 and the timeout part of step 14: sets the session's
 * `innodb_lock_wait_timeout`, makes REPEATABLE READ the isolation level of
 * the next transaction only (F10), starts that transaction through the
 * project's Transaction, and restores the previous timeout afterwards.
 *
 * `SET TRANSACTION ISOLATION LEVEL` applies to the next transaction, and an
 * autocommit statement in between can use it up, so it must be the last
 * statement before START TRANSACTION. Transaction::run() may first read
 * information_schema (TransactionSupport); that check is run here before
 * SET TRANSACTION, and its positive result is cached, so run() starts at
 * once. $support must be the instance $transaction uses (the container's
 * shared service).
 *
 * A failing timeout read or setup, or SET TRANSACTION itself (1568 while a
 * transaction is already open), throws before START, and $work never runs.
 * A failing restore is logged; the result or exception of the transaction
 * is kept.
 */
final class RepeatableReadTransaction {

	/**
	 * Constructor.
	 *
	 * @param wpdb               $wpdb        WordPress database object.
	 * @param Transaction        $transaction The project's transaction helper.
	 * @param TransactionSupport $support     The storage engine check $transaction uses.
	 * @param Logger             $logger      Technical log.
	 */
	public function __construct( private wpdb $wpdb, private Transaction $transaction, private TransactionSupport $support, private Logger $logger ) {
	}

	/**
	 * Runs $work in a REPEATABLE READ transaction with the given lock wait timeout.
	 *
	 * @template T
	 * @param callable(): T $work              Unit of work.
	 * @param int           $lock_wait_timeout Seconds an InnoDB row lock is waited for (≥ 1).
	 * @return T Result of $work.
	 * @throws DatabaseException When the timeout or isolation level cannot be set, or Transaction refuses to start or commit; whatever $work throws is passed on after rollback.
	 */
	public function run( callable $work, int $lock_wait_timeout ): mixed {
		if ( $lock_wait_timeout < 1 ) {
			throw new DatabaseException( 'The lock wait timeout must be at least one second.' );
		}

		$wpdb     = $this->wpdb;
		$previous = $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );

		if ( '' !== $wpdb->last_error || ! is_numeric( $previous ) ) {
			throw new DatabaseException( 'Could not read the lock wait timeout.' );
		}

		if ( ! $this->set_timeout( $lock_wait_timeout ) ) {
			throw new DatabaseException( 'Could not set the lock wait timeout.' );
		}

		try {
			// Before SET TRANSACTION: the check may read information_schema.
			if ( array() === $this->support->unsupported_tables() && false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) ) {
				throw new DatabaseException( 'Could not set the transaction isolation level.' );
			}

			// With unsupported tables, run() refuses before START, as without this class.
			return $this->transaction->run( $work );
		} finally {
			if ( ! $this->set_timeout( (int) $previous ) ) {
				$this->logger->error( 'Could not restore the lock wait timeout.' );
			}
		}
	}

	/**
	 * Sets the session's InnoDB lock wait timeout.
	 *
	 * @param int $seconds Seconds.
	 * @return bool Whether the statement succeeded.
	 */
	private function set_timeout( int $seconds ): bool {
		$sql = $this->wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', $seconds );

		return null !== $sql && false !== $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare().
	}
}
