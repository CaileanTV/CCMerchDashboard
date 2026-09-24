<?php
/**
 * Logger contract.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Technical logging for the plugin. Resolve it from the container
 * (`Logger::class`) and inject it; do not call a backend directly.
 *
 * Messages are short, constant English sentences. Variable data goes into
 * `$context` (ids, versions, class names), never into the message. Context is
 * sanitized before it is written (see LogContext), but callers must still not
 * pass secrets, credentials or personal data.
 *
 * Log where an error is handled or where unique diagnostic data exists, and
 * only once: do not log and rethrow in several layers.
 */
interface Logger {

	/**
	 * Detailed diagnostics (written only when WP_DEBUG is on, by default).
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function debug( string $message, array $context = array() ): void;

	/**
	 * Notable, expected event.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function info( string $message, array $context = array() ): void;

	/**
	 * Unexpected situation that was handled.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function warning( string $message, array $context = array() ): void;

	/**
	 * Failed operation.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function error( string $message, array $context = array() ): void;
}
