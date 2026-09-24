<?php
/**
 * WooCommerce logger backend with fallback.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Uses WooCommerce's logger once WooCommerce has initialised (`woocommerce_init`), so entries appear
 * under WooCommerce → Status → Logs (source "merchboard") alongside the shop's
 * other logs. Otherwise, and whenever the WooCommerce logger is unusable,
 * entries go to the fallback writer. If the WooCommerce logger throws, the
 * entry is written to the fallback as well. WooCommerce is never required.
 *
 * Availability is checked on every write, not once at boot, so the writer is
 * correct regardless of when it was created (activation, early boot).
 */
final class WooCommerceLogWriter implements LogWriter {

	/**
	 * WooCommerce log source (groups the plugin's entries).
	 */
	public const SOURCE = 'merchboard';

	/**
	 * Constructor.
	 *
	 * @param LogWriter $fallback Used when WooCommerce is not available.
	 */
	public function __construct( private LogWriter $fallback ) {
	}

	/**
	 * Writes one entry.
	 *
	 * @param LogLevel             $level   Level.
	 * @param string               $message Sanitized message.
	 * @param array<string, mixed> $context Sanitized context.
	 */
	public function write( LogLevel $level, string $message, array $context ): void {
		// Only after WooCommerce has initialised: creating its logger earlier (e.g. while
		// the plugin migrates on plugins_loaded) can load WooCommerce translations too
		// early. Called by name: WooCommerce is optional and not in the analysis stubs.
		$logger = function_exists( 'wc_get_logger' ) && did_action( 'woocommerce_init' ) > 0
			? call_user_func( 'wc_get_logger' )
			: null;

		if ( ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			$this->fallback->write( $level, $message, $context );
			return;
		}

		try {
			$logger->log( $level->value, $message, array_merge( $context, array( 'source' => self::SOURCE ) ) );
		} catch ( \Throwable $e ) {
			// A broken WooCommerce log handler must not turn a handled error into a
			// fatal one, and the entry must not be lost: write it to the fallback.
			$context['log_backend_failure'] = get_class( $e );
			$this->fallback->write( $level, $message, $context );
		}
	}
}
