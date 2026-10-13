<?php
/**
 * Database transaction helper.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a unit of work in a real database transaction (ADR-023).
 *
 * Guarantees depend on the storage engine: InnoDB (the MySQL/MariaDB default)
 * and SQLite roll back; MyISAM silently ignores transactions. So run() first
 * asks TransactionSupport and refuses to start when any plugin table cannot
 * roll back (fail closed, ADR-024): nothing is written, and the caller logs the
 * DatabaseException, whose context names the tables and engines.
 *
 * Not re-entrant: MySQL implicitly commits an open transaction when a new one
 * starts, so nested run() calls are rejected instead of silently committing.
 * Statements that cause an implicit commit (DDL such as CREATE/ALTER TABLE)
 * must never run inside $work.
 */
final class Transaction {

	/**
	 * Whether a transaction started by this instance is open.
	 *
	 * @var bool
	 */
	private bool $active = false;

	/**
	 * Whether a ROLLBACK of the last run() failed (E3, F-4).
	 *
	 * @var bool
	 */
	private bool $rollback_failed = false;

	/**
	 * Constructor.
	 *
	 * @param wpdb               $wpdb    WordPress database object.
	 * @param TransactionSupport $support Storage engine check.
	 */
	public function __construct( private wpdb $wpdb, private TransactionSupport $support ) {
	}

	/**
	 * Runs $work and commits, or rolls back and rethrows on any exception.
	 *
	 * @template T
	 * @param callable(): T $work Unit of work.
	 * @return T Result of $work.
	 * @throws DatabaseException When a plugin table cannot roll back (nothing runs), the transaction cannot be started or committed, or is nested.
	 * @throws Throwable Whatever $work throws (after rollback).
	 */
	public function run( callable $work ): mixed {
		if ( $this->active ) {
			throw new DatabaseException( 'Nested transactions are not supported.' );
		}

		$this->rollback_failed = false;

		$unsupported = $this->support->unsupported_tables();

		if ( array() !== $unsupported ) {
			throw new DatabaseException(
				'Database tables do not support transactions; write refused.',
				array(
					'tables' => implode(
						', ',
						array_map(
							static fn( string $table, string $engine ): string => $table . ' (' . $engine . ')',
							array_keys( $unsupported ),
							$unsupported
						)
					),
				)
			);
		}

		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			throw new DatabaseException( 'Could not start a database transaction.' );
		}

		$this->active = true;

		try {
			$result = $work();
		} catch ( Throwable $e ) {
			// Roll back, then let the caller handle the original error.
			$this->rollback_failed = false === $this->wpdb->query( 'ROLLBACK' );
			$this->active          = false;
			throw $e;
		}

		$this->active = false;

		if ( false === $this->wpdb->query( 'COMMIT' ) ) {
			$this->rollback_failed = false === $this->wpdb->query( 'ROLLBACK' );
			throw new DatabaseException( 'Could not commit the database transaction.' );
		}

		return $result;
	}

	/**
	 * Whether the ROLLBACK of the last run() failed, read from its return
	 * value (never from `$wpdb->last_error`). Reset at the start of every
	 * run() that is not rejected as nested. A failed ROLLBACK may leave the
	 * transaction open; the caller then reports an unknown outcome and runs
	 * no SQL that can write or commit (E3.6, F-4).
	 */
	public function rollback_failed(): bool {
		return $this->rollback_failed;
	}
}
