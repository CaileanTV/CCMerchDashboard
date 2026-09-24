<?php
/**
 * Runs pending schema migrations.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use InvalidArgumentException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the stored schema version with the latest migration and runs the
 * missing migrations in ascending order.
 *
 * The version option is updated after each successful migration, so a failure
 * leaves the schema at the last version that completed. A short-lived lock
 * (an option, created atomically with add_option()) stops concurrent requests
 * from migrating at the same time.
 */
final class Migrator {

	/**
	 * Option holding the installed schema version (integer, 0 = not installed).
	 */
	public const VERSION_OPTION = 'merchboard_db_version';

	/**
	 * Option used as migration lock; holds the Unix time the lock was taken.
	 */
	public const LOCK_OPTION = 'merchboard_db_migration_lock';

	/**
	 * Seconds after which a lock is considered abandoned (e.g. a crashed request).
	 */
	private const LOCK_TIMEOUT = 300;

	/**
	 * Migrations keyed by the schema version they produce, ascending.
	 *
	 * @var array<int, Migration>
	 */
	private array $migrations;

	/**
	 * Constructor.
	 *
	 * @param wpdb                  $wpdb       WordPress database object.
	 * @param array<int, Migration> $migrations Migrations keyed by positive schema version.
	 * @throws InvalidArgumentException When a key is not a positive integer.
	 */
	public function __construct( private wpdb $wpdb, array $migrations ) {
		foreach ( array_keys( $migrations ) as $version ) {
			if ( $version < 1 ) {
				throw new InvalidArgumentException( 'Migration versions must be positive integers.' );
			}
		}

		ksort( $migrations );
		$this->migrations = $migrations;
	}

	/**
	 * Schema version the code expects.
	 */
	public function latest_version(): int {
		return 0 === count( $this->migrations ) ? 0 : (int) max( array_keys( $this->migrations ) );
	}

	/**
	 * Schema version stored in the database (0 when not installed).
	 */
	public function installed_version(): int {
		$version = get_option( self::VERSION_OPTION, 0 );

		return is_numeric( $version ) ? max( 0, (int) $version ) : 0;
	}

	/**
	 * Cheap check (one autoloaded option) whether migrations are pending.
	 */
	public function needs_migration(): bool {
		return $this->installed_version() < $this->latest_version();
	}

	/**
	 * Runs all pending migrations.
	 *
	 * @return bool True when the schema is up to date afterwards, false when
	 *              another request holds the migration lock.
	 * @throws DatabaseException When a migration fails. Earlier migrations in the
	 *                           same run stay applied and recorded.
	 */
	public function migrate(): bool {
		if ( ! $this->needs_migration() ) {
			return true;
		}

		if ( ! $this->acquire_lock() ) {
			return false;
		}

		try {
			// Re-read after taking the lock: another request may have finished.
			$installed = $this->installed_version();

			foreach ( $this->migrations as $version => $migration ) {
				if ( $version <= $installed ) {
					continue;
				}

				$migration->up( $this->wpdb );
				update_option( self::VERSION_OPTION, $version, true );
			}
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		return true;
	}

	/**
	 * Takes the lock, replacing it when it is older than LOCK_TIMEOUT.
	 */
	private function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, (string) time(), '', false ) ) {
			return true;
		}

		$locked_at = get_option( self::LOCK_OPTION, 0 );

		if ( is_numeric( $locked_at ) && time() - (int) $locked_at < self::LOCK_TIMEOUT ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );

		return add_option( self::LOCK_OPTION, (string) time(), '', false );
	}
}
