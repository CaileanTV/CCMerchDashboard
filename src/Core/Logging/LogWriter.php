<?php
/**
 * Log backend contract.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Writes an already filtered and sanitized entry to a backend.
 */
interface LogWriter {

	/**
	 * Writes one entry.
	 *
	 * @param LogLevel             $level   Level.
	 * @param string               $message Sanitized message.
	 * @param array<string, mixed> $context Sanitized context.
	 */
	public function write( LogLevel $level, string $message, array $context ): void;
}
