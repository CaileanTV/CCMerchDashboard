<?php
/**
 * PHP error log backend.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one line per entry with error_log(), which WordPress routes to
 * wp-content/debug.log when WP_DEBUG_LOG is on and to the server's PHP error
 * log otherwise. Never writes to the browser.
 *
 * Format: `[merchboard] ERROR: Message. {"key":"value"}`
 */
final class ErrorLogWriter implements LogWriter {

	/**
	 * Line sink; error_log() in production, replaceable in tests.
	 *
	 * @var callable(string): mixed
	 */
	private $sink;

	/**
	 * Constructor.
	 *
	 * @param callable(string): mixed|null $sink Receives each formatted line.
	 */
	public function __construct( ?callable $sink = null ) {
		$this->sink = $sink ?? static function ( string $line ): bool {
			return error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This class is the plugin's error_log backend.
		};
	}

	/**
	 * Writes one entry.
	 *
	 * @param LogLevel             $level   Level.
	 * @param string               $message Sanitized message.
	 * @param array<string, mixed> $context Sanitized context.
	 */
	public function write( LogLevel $level, string $message, array $context ): void {
		$line = sprintf( '[merchboard] %s: %s', strtoupper( $level->value ), $message );

		if ( array() !== $context ) {
			$json  = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
			$line .= ' ' . ( false === $json ? '{}' : $json );
		}

		( $this->sink )( $line );
	}
}
