<?php
/**
 * Logger test double.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Core\Logging\Logger;

/**
 * Records log calls unfiltered (no threshold, no sanitization), so tests can
 * assert exactly what a service asked to log.
 */
final class RecordingLogger implements Logger {

	/**
	 * Recorded entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public array $entries = array();

	/**
	 * Records a debug entry.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->record( 'debug', $message, $context );
	}

	/**
	 * Records an info entry.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->record( 'info', $message, $context );
	}

	/**
	 * Records a warning entry.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->record( 'warning', $message, $context );
	}

	/**
	 * Records an error entry.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->record( 'error', $message, $context );
	}

	/**
	 * Entries at a level.
	 *
	 * @param string $level Level.
	 * @return list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public function at( string $level ): array {
		return array_values( array_filter( $this->entries, static fn( array $e ): bool => $level === $e['level'] ) );
	}

	/**
	 * Stores an entry.
	 *
	 * @param string               $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	private function record( string $level, string $message, array $context ): void {
		$this->entries[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}
}
