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
 * leaves the schema at the last version that completed. A short-lived lock row
 * in the options table stops concurrent requests from migrating at the same
 * time (see acquire_lock()).
 */
final class Migrator {

	/**
	 * Option holding the installed schema version (integer, 0 = not installed).
	 */
	public const VERSION_OPTION = 'merchboard_db_version';

	/**
	 * Option used as migration lock; holds "<Unix time>:<random token>" of the
	 * request that owns it.
	 */
	public const LOCK_OPTION = 'merchboard_db_migration_lock';

	/**
	 * Seconds after which a lock is considered abandoned (e.g. a crashed request).
	 */
	private const LOCK_TIMEOUT = 300;

	/**
	 * Maximum length of the database error kept for the log.
	 */
	private const DB_ERROR_MAX_LENGTH = 200;

	/**
	 * Migrations keyed by the schema version they produce, ascending.
	 *
	 * @var array<int, Migration>
	 */
	private array $migrations;

	/**
	 * Value of the lock row while this instance holds the lock.
	 *
	 * @var string|null
	 */
	private ?string $lock = null;

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
	 * @throws DatabaseException When a migration fails, with log context (version,
	 *                           migration class, reason, bounded DDL error). Earlier
	 *                           migrations in the same run stay applied and recorded.
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

				try {
					$migration->up( $this->wpdb );
				} catch ( DatabaseException $e ) {
					// Add the diagnostic data only the migrator has; the version option
					// is deliberately not updated.
					throw new DatabaseException(
						'Database migration failed.',
						array(
							'schema_version'    => $version,
							'installed_version' => $installed,
							'migration'         => get_class( $migration ),
							'reason'            => $e->getMessage(),
							'db_error'          => $this->db_error(),
						),
						$e
					);
				}

				update_option( self::VERSION_OPTION, $version, true );
				$installed = $version;
			}
		} finally {
			$this->release_lock();
		}

		return true;
	}

	/**
	 * The last database error as plain, bounded text for the log.
	 *
	 * Schema (DDL) errors contain no user data, but some database drivers (e.g.
	 * WordPress' SQLite integration) put HTML with the query and file paths into
	 * `last_error`, so markup is stripped and the length is capped.
	 */
	private function db_error(): string {
		// Space before each tag so text from adjacent elements does not run together.
		$error = wp_strip_all_tags( str_replace( '<', ' <', (string) $this->wpdb->last_error ) );
		$error = html_entity_decode( $error, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Collapse all whitespace, including non-breaking spaces from &nbsp;.
		$error = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $error ) );
		// Some drivers append a PHP backtrace (internal file paths); it is not needed
		// to diagnose a schema error.
		$error = trim( (string) preg_replace( '/\s*Backtrace:.*$/s', '', $error ) );

		return mb_substr( $error, 0, self::DB_ERROR_MAX_LENGTH );
	}

	/**
	 * Takes the lock, replacing it when it is older than LOCK_TIMEOUT.
	 *
	 * Works on the options row directly because add_option() is not a lock on
	 * MySQL/MariaDB: it uses INSERT … ON DUPLICATE KEY UPDATE, which overwrites
	 * an existing row and still reports success. Instead (like WordPress'
	 * WP_Upgrader::create_lock()):
	 *
	 * - INSERT IGNORE creates the row atomically; only one request can succeed.
	 * - An abandoned lock is taken over with a compare-and-swap UPDATE on the
	 *   stale value, so of several requests that saw the same stale lock only one
	 *   wins (a delete-then-insert would let two requests both "take over").
	 * - The value carries a random token, so release_lock() deletes only this
	 *   request's lock, never one another request took over in the meantime.
	 *
	 * The row bypasses the options cache on purpose; the cache entry is cleared.
	 */
	private function acquire_lock(): bool {
		$wpdb    = $this->wpdb;
		$now     = time();
		$token   = $now . ':' . bin2hex( random_bytes( 8 ) );
		$options = $wpdb->options;

		$inserted = $this->write(
			$wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $options, self::LOCK_OPTION, $token, 'off' )
		);

		if ( 1 === $inserted ) {
			return $this->hold_lock( $token );
		}

		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $options, self::LOCK_OPTION ) );

		// Released in the meantime: the next request migrates (no retry loop).
		if ( ! is_string( $current ) ) {
			return false;
		}

		if ( $now - self::locked_at( $current ) < self::LOCK_TIMEOUT ) {
			return false;
		}

		$swapped = $this->write(
			$wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $options, $token, self::LOCK_OPTION, $current )
		);

		return 1 === $swapped && $this->hold_lock( $token );
	}

	/**
	 * Runs a lock statement; false when it could not run.
	 *
	 * @param string|null $sql Result of $wpdb->prepare() (null when the placeholders do not match).
	 * @return int|bool Affected rows, or false.
	 */
	private function write( ?string $sql ): int|bool {
		return null === $sql ? false : $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass the result of $wpdb->prepare().
	}

	/**
	 * Records the lock as held by this instance.
	 *
	 * @param string $token Lock value.
	 */
	private function hold_lock( string $token ): bool {
		$this->lock = $token;
		wp_cache_delete( self::LOCK_OPTION, 'options' );

		return true;
	}

	/**
	 * Deletes the lock row if it is still this instance's lock.
	 */
	private function release_lock(): void {
		if ( null === $this->lock ) {
			return;
		}

		$wpdb = $this->wpdb;
		$this->write(
			$wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::LOCK_OPTION, $this->lock )
		);
		$this->lock = null;
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/**
	 * Unix time a lock value was taken; 0 (abandoned) when unreadable. Also
	 * reads the plain timestamp that earlier builds wrote.
	 *
	 * @param string $value Lock value.
	 */
	private static function locked_at( string $value ): int {
		$time = explode( ':', $value, 2 )[0];

		return ctype_digit( $time ) ? (int) $time : 0;
	}
}
