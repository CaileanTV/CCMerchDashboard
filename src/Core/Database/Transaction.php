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
 * Guarantees depend on the storage engine: InnoDB (MySQL/MariaDB default and
 * WordPress' default for new tables) and SQLite roll back; MyISAM ignores
 * transactions. Callers that must not lose an audit record therefore also log
 * failures, so a non-transactional engine can never hide a problem.
 *
 * Not re-entrant: MySQL implicitly commits an open transaction when a new one
 * starts, so nested run() calls are rejected instead of silently committing.
 */
final class Transaction {

	/**
	 * Whether a transaction started by this instance is open.
	 *
	 * @var bool
	 */
	private bool $active = false;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Runs $work and commits, or rolls back and rethrows on any exception.
	 *
	 * @template T
	 * @param callable(): T $work Unit of work.
	 * @return T Result of $work.
	 * @throws DatabaseException When the transaction cannot be started or committed, or is nested.
	 * @throws Throwable Whatever $work throws (after rollback).
	 */
	public function run( callable $work ): mixed {
		if ( $this->active ) {
			throw new DatabaseException( 'Nested transactions are not supported.' );
		}

		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			throw new DatabaseException( 'Could not start a database transaction.' );
		}

		$this->active = true;

		try {
			$result = $work();
		} catch ( Throwable $e ) {
			// Roll back, then let the caller handle the original error.
			$this->wpdb->query( 'ROLLBACK' );
			$this->active = false;
			throw $e;
		}

		$this->active = false;

		if ( false === $this->wpdb->query( 'COMMIT' ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw new DatabaseException( 'Could not commit the database transaction.' );
		}

		return $result;
	}
}
