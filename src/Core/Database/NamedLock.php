<?php
/**
 * MySQL named locks.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `GET_LOCK`/`RELEASE_LOCK` on WordPress' connection: a lock belongs to the
 * connection and is released by release() or when the request ends. A
 * database without named locks (SQLite in Playground) answers null, which
 * counts as not acquired, so callers fall back to their safe path.
 */
class NamedLock {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Takes a lock.
	 *
	 * @param string $name    Lock name (at most 64 characters).
	 * @param int    $timeout Seconds to wait; 0 not to wait.
	 * @return bool Whether it is held now.
	 */
	public function acquire( string $name, int $timeout = 0 ): bool {
		$wpdb = $this->wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', substr( $name, 0, 64 ), max( 0, $timeout ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.
	}

	/**
	 * Releases a lock this connection holds.
	 *
	 * @param string $name Lock name.
	 */
	public function release( string $name ): void {
		$wpdb = $this->wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', substr( $name, 0, 64 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
	}

	/**
	 * Runs work under a lock; null when the lock is not had in time.
	 *
	 * @template T
	 * @param string        $name    Lock name.
	 * @param int           $timeout Seconds to wait.
	 * @param callable(): T $work    The work.
	 * @return T|null
	 */
	public function run( string $name, int $timeout, callable $work ): mixed {
		if ( ! $this->acquire( $name, $timeout ) ) {
			return null;
		}

		try {
			return $work();
		} finally {
			$this->release( $name );
		}
	}
}
