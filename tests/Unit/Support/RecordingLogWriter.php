<?php
/**
 * Log writer test double.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Core\Logging\LogLevel;
use MerchBoard\Core\Logging\LogWriter;

/**
 * Records what reaches the backend (after threshold and sanitization).
 */
final class RecordingLogWriter implements LogWriter {

	/**
	 * Written entries.
	 *
	 * @var list<array{level: LogLevel, message: string, context: array<string, mixed>}>
	 */
	public array $writes = array();

	/**
	 * Records the entry.
	 *
	 * @param LogLevel             $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function write( LogLevel $level, string $message, array $context ): void {
		$this->writes[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}
}
