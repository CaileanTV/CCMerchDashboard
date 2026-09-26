<?php
/**
 * Logger that resolves the real logger on first use.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Logging;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * For services created while merchboard.php loads (the protected meta guard,
 * ADR-035). The shared PluginLogger reads its threshold filter when it is
 * created; creating it that early would fix the threshold before themes and
 * later plugins can filter it, for every service. This wrapper asks for the
 * shared logger only when something is logged.
 */
final class LazyLogger implements Logger {

	/**
	 * The resolved logger.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger = null;

	/**
	 * Returns the shared logger.
	 *
	 * @var Closure(): Logger
	 */
	private Closure $resolve;

	/**
	 * Constructor.
	 *
	 * @param Closure $resolve Returns the shared logger (`Closure(): Logger`).
	 * @phpstan-param Closure(): Logger $resolve
	 */
	public function __construct( Closure $resolve ) {
		$this->resolve = $resolve;
	}

	/**
	 * Detailed diagnostics.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->logger()->debug( $message, $context );
	}

	/**
	 * Notable events.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->logger()->info( $message, $context );
	}

	/**
	 * Something unexpected that the plugin handled.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->logger()->warning( $message, $context );
	}

	/**
	 * A failure.
	 *
	 * @param string               $message Constant message.
	 * @param array<string, mixed> $context Structured data.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->logger()->error( $message, $context );
	}

	/**
	 * The shared logger, resolved once.
	 */
	private function logger(): Logger {
		if ( null === $this->logger ) {
			$this->logger = ( $this->resolve )();
		}

		return $this->logger;
	}
}
