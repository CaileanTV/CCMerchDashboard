<?php
/**
 * Stand-in for WooCommerce's wc_get_logger(), loaded only in isolated test
 * processes (defining a global function cannot be undone).
 *
 * @package MerchBoard
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Mirrors WooCommerce: a logger class plus the global wc_get_logger() function.

/**
 * Records WC_Logger::log() calls.
 */
final class MerchBoardTestWcLogger {

	/**
	 * Recorded calls.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public static array $entries = array();

	/**
	 * Exception to throw from log(), if any.
	 *
	 * @var Throwable|null
	 */
	public static ?Throwable $throw = null;

	/**
	 * Mirrors WC_Logger::log().
	 *
	 * @param string               $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @throws Throwable When configured.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( null !== self::$throw ) {
			throw self::$throw;
		}

		self::$entries[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}
}

/**
 * Mirrors WooCommerce's wc_get_logger(). Typed as `object` on purpose: the real
 * return value can be replaced via the `woocommerce_logging_class` filter, so
 * the plugin must not assume it has a log() method.
 */
function wc_get_logger(): object {
	return new MerchBoardTestWcLogger();
}
