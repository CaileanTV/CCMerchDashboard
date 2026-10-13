<?php
/**
 * The G1 activation frame.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\G1;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\ConnectionChangedException;
use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\OptionsTableEngine;
use MerchBoard\Core\Database\RepeatableReadTransaction;
use MerchBoard\Core\Database\SchemaVersionRow;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Activates G1 once with a cut-off (E3.3 "Aktiveringen", E1; T3). Nothing
 * calls it yet: no container service, no UI, REST or WP-CLI.
 *
 * Before START: the options table must be InnoDB, the session's connection
 * id is read, and RepeatableReadTransaction sets the lock wait timeout and
 * REPEATABLE READ right before Transaction::run() starts. In the
 * transaction: R0 (the version row with a shared lock, at least version
 * 12), then the activation row FOR UPDATE, the database clock
 * (UTC_TIMESTAMP()), the check that the cut-off does not lie before it, the
 * conditional UPDATE (`activated_at IS NULL`, the connection) and the audit
 * record on the same connection, so both commit together or not at all.
 * After COMMIT the connection id is checked again (E3.5(d)).
 *
 * Classification (E3.6, F-5): the error number is read inside the
 * transaction right after the failed statement (a later ROLLBACK replaces
 * it); 1205/1213 give `busy` (D-1), an unreadable number gives
 * `outcome_unknown` (D-3; a later attempt reads the stored activation, B1),
 * a changed connection, a failed COMMIT and a failed ROLLBACK give
 * `outcome_unknown` (D-5). A failed ROLLBACK is read from
 * Transaction::rollback_failed(), never from `$wpdb->last_error`; it is
 * logged as critical and no further SQL runs here.
 *
 * $transaction must be the instance $frame runs on.
 */
final class G1Activation {

	/**
	 * Schema version that created the G1 tables.
	 */
	private const SCHEMA_VERSION = 12;

	/**
	 * Default seconds an InnoDB row lock is waited for.
	 */
	public const LOCK_WAIT_TIMEOUT = 5;

	/**
	 * Error numbers of a lock conflict (D-1).
	 */
	private const LOCK_CONFLICTS = array( 1205, 1213 );

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb              WordPress database object.
	 * @param Transaction               $transaction       The transaction $frame runs on.
	 * @param RepeatableReadTransaction $frame             Timeout and isolation around Transaction::run().
	 * @param AuditTrail                $audit             Audit trail.
	 * @param Logger                    $logger            Technical log.
	 * @param int                       $lock_wait_timeout Seconds an InnoDB row lock is waited for (≥ 1).
	 */
	public function __construct(
		private wpdb $wpdb,
		private Transaction $transaction,
		private RepeatableReadTransaction $frame,
		private AuditTrail $audit,
		private Logger $logger,
		private int $lock_wait_timeout = self::LOCK_WAIT_TIMEOUT
	) {
	}

	/**
	 * Activates G1 with the given cut-off, as the current user.
	 *
	 * @param DateTimeImmutable $cutoff First moment from which orders are assessed.
	 */
	public function activate( DateTimeImmutable $cutoff ): G1ActivationResult {
		$actor = get_current_user_id();

		if ( $actor < 1 ) {
			return G1ActivationResult::error( 'no_actor' );
		}

		if ( ! OptionsTableEngine::is_innodb( $this->wpdb ) ) {
			return G1ActivationResult::error( 'options_not_innodb' );
		}

		$connection = $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );

		if ( '' !== $this->wpdb->last_error || ! is_numeric( $connection ) ) {
			return G1ActivationResult::error( 'db_error' );
		}

		$connection_id = (int) $connection;
		$cutoff_utc    = $cutoff->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$failed_errno  = false;
		$entered       = false;
		$finished      = false;

		try {
			$result = $this->frame->run(
				function () use ( $cutoff_utc, $actor, $connection_id, &$failed_errno, &$entered, &$finished ): G1ActivationResult {
					$entered = true;
					$result  = $this->work( $cutoff_utc, $actor, $connection_id, $failed_errno );
					// Only now can a failure be COMMIT's.
					$finished = true;

					return $result;
				},
				$this->lock_wait_timeout
			);
		} catch ( Throwable $e ) {
			return $this->classify( $e, $entered, $finished, $failed_errno );
		}

		if ( G1ActivationResult::COMPLETED === $result->code && (string) $connection_id !== (string) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) {
			// After COMMIT: another session means the outcome is unknown (E3.5(d)).
			return G1ActivationResult::outcome_unknown();
		}

		return $result;
	}

	/**
	 * The transaction's work, in the order of E3.3.
	 *
	 * @param string         $cutoff_utc    Cut-off (UTC).
	 * @param int            $actor         Activating user.
	 * @param int            $connection_id Connection of the transaction.
	 * @param int|null|false $failed_errno  Set to the error number of a failed statement (null: unreadable).
	 * @throws DatabaseException When a statement fails ($failed_errno is set first).
	 * @throws ConnectionChangedException When a guarded write ran on another connection.
	 */
	private function work( string $cutoff_utc, int $actor, int $connection_id, int|null|false &$failed_errno ): G1ActivationResult {
		$wpdb = $this->wpdb;

		// R0: the version row with a shared lock; a missing row is an error.
		try {
			$version = SchemaVersionRow::read_shared( $wpdb );
		} catch ( DatabaseException $e ) {
			$errno = DatabaseErrno::last( $wpdb );

			if ( 0 === $errno ) {
				return G1ActivationResult::error( 'schema_version' );
			}

			$failed_errno = $errno;
			throw $e;
		}

		if ( ! is_numeric( $version ) || (int) $version < self::SCHEMA_VERSION ) {
			return G1ActivationResult::error( 'schema_version' );
		}

		$row = $this->statement( static fn (): ?array => G1StateRow::lock( $wpdb ), $failed_errno );

		if ( null === $row ) {
			return G1ActivationResult::error( 'state_row_missing' );
		}

		if ( null !== $row['activated_at'] ) {
			return G1ActivationResult::conflict( $row['cutoff_utc'], $row['activated_at'], $row['activated_by'] );
		}

		$now = $this->statement(
			static function () use ( $wpdb ): string {
				$now = $wpdb->get_var( 'SELECT UTC_TIMESTAMP()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The database clock inside the transaction; never cached.

				if ( '' !== $wpdb->last_error || ! is_string( $now ) ) {
					throw new DatabaseException( 'Could not read the database time.' );
				}

				return $now;
			},
			$failed_errno
		);

		// The cut-off must not lie before the activation (equal is allowed).
		if ( $cutoff_utc < $now ) {
			return G1ActivationResult::error( 'cutoff_before_activation' );
		}

		$changed = $this->statement( static fn (): int => G1StateRow::activate( $wpdb, $cutoff_utc, $now, $actor, $connection_id ), $failed_errno );

		if ( 1 !== $changed ) {
			// (h): 0 rows on another connection is an unknown outcome; on this one an error.
			if ( (string) $connection_id !== (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The session's id; never cached.
				throw new ConnectionChangedException( 'The database connection changed before G1 was activated.' );
			}

			$failed_errno = 0;
			throw new DatabaseException( 'G1 was not activated.' );
		}

		$this->statement( fn (): int => $this->audit->record_on_connection( AuditAction::G1Activated, null, array( 'cutoff_utc' => $cutoff_utc ), $connection_id ), $failed_errno );

		return G1ActivationResult::completed( $cutoff_utc, $now, $actor );
	}

	/**
	 * Runs one statement; on a DatabaseException records the error number
	 * of that statement before passing the exception on.
	 *
	 * @template T
	 * @param callable(): T  $statement    The statement.
	 * @param int|null|false $failed_errno Set on failure (null: unreadable).
	 * @return T
	 * @throws DatabaseException When the statement fails.
	 */
	private function statement( callable $statement, int|null|false &$failed_errno ): mixed {
		try {
			return $statement();
		} catch ( DatabaseException $e ) {
			$failed_errno = DatabaseErrno::last( $this->wpdb );
			throw $e;
		}
	}

	/**
	 * Maps a failure to its result code (E3.6). Runs no SQL.
	 *
	 * @param Throwable      $e            The failure.
	 * @param bool           $entered      Whether the transaction's work started.
	 * @param bool           $finished     Whether the work returned (a failure is then COMMIT's).
	 * @param int|null|false $failed_errno Error number of the failed statement; false when none failed.
	 */
	private function classify( Throwable $e, bool $entered, bool $finished, int|null|false $failed_errno ): G1ActivationResult {
		// Only after the work started can this run's ROLLBACK have been attempted.
		if ( $entered && $this->transaction->rollback_failed() ) {
			$this->logger->error( 'The G1 activation could not be rolled back; its outcome is unknown.', array( 'severity' => 'critical' ) );

			return G1ActivationResult::outcome_unknown();
		}

		if ( $e instanceof ConnectionChangedException || $finished || ( $entered && null === $failed_errno ) ) {
			return G1ActivationResult::outcome_unknown();
		}

		if ( is_int( $failed_errno ) && in_array( $failed_errno, self::LOCK_CONFLICTS, true ) ) {
			return G1ActivationResult::busy();
		}

		return G1ActivationResult::error( 'db_error' );
	}
}
