<?php
/**
 * Default logger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the level threshold, sanitizes message and context, and hands the
 * entry to a LogWriter.
 */
final class PluginLogger implements Logger {

	/**
	 * Constructor.
	 *
	 * @param LogLevel  $threshold Minimum level that is written.
	 * @param LogWriter $writer    Backend.
	 */
	public function __construct(
		private LogLevel $threshold,
		private LogWriter $writer
	) {
	}

	/**
	 * Logger for a normal WordPress request: WooCommerce's logger when
	 * available, error_log() otherwise. Threshold from WP_DEBUG, overridable
	 * with the `merchboard_log_level` filter ('debug', 'info', 'warning', 'error').
	 */
	public static function for_wordpress(): self {
		$default = LogLevel::default_threshold( defined( 'WP_DEBUG' ) && WP_DEBUG );

		/**
		 * Filters the minimum log level.
		 *
		 * @param mixed $level One of 'debug', 'info', 'warning', 'error'. Anything else
		 *                     (including non-strings from faulty filters) is ignored.
		 */
		$filtered = apply_filters( 'merchboard_log_level', $default->value );

		$threshold = is_string( $filtered ) ? ( LogLevel::tryFrom( $filtered ) ?? $default ) : $default;

		return new self( $threshold, new WooCommerceLogWriter( new ErrorLogWriter() ) );
	}

	/**
	 * Detailed diagnostics.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( LogLevel::Debug, $message, $context );
	}

	/**
	 * Notable, expected event.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( LogLevel::Info, $message, $context );
	}

	/**
	 * Unexpected situation that was handled.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( LogLevel::Warning, $message, $context );
	}

	/**
	 * Failed operation.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( LogLevel::Error, $message, $context );
	}

	/**
	 * Filters, sanitizes and writes.
	 *
	 * @param LogLevel             $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	private function log( LogLevel $level, string $message, array $context ): void {
		if ( ! $level->passes( $this->threshold ) ) {
			return;
		}

		$this->writer->write( $level, LogContext::message( $message ), LogContext::sanitize( $context ) );
	}
}
