<?php
/**
 * Log levels.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * The four levels the plugin uses. Values equal the PSR-3 / WooCommerce level
 * names, so they map 1:1 to WC_Logger::log() (see docs/ARCHITECTURE.md §11).
 */
enum LogLevel: string {

	/**
	 * Detailed diagnostics; only written when WP_DEBUG is on (or opted in).
	 */
	case Debug = 'debug';

	/**
	 * Notable, expected events (e.g. schema migrated).
	 */
	case Info = 'info';

	/**
	 * Something unexpected that the plugin handled.
	 */
	case Warning = 'warning';

	/**
	 * An operation failed.
	 */
	case Error = 'error';

	/**
	 * Numeric severity for threshold comparison.
	 */
	public function severity(): int {
		return match ( $this ) {
			self::Debug   => 100,
			self::Info    => 200,
			self::Warning => 300,
			self::Error   => 400,
		};
	}

	/**
	 * Whether a message at this level passes the given threshold.
	 *
	 * @param LogLevel $threshold Minimum level that is written.
	 */
	public function passes( LogLevel $threshold ): bool {
		return $this->severity() >= $threshold->severity();
	}

	/**
	 * Default threshold: debug when WP_DEBUG is on, warning otherwise, so
	 * production logs contain only problems. The plugin never changes
	 * WordPress' debug settings; it only reads them.
	 *
	 * @param bool $wp_debug Value of WP_DEBUG.
	 */
	public static function default_threshold( bool $wp_debug ): self {
		return $wp_debug ? self::Debug : self::Warning;
	}
}
