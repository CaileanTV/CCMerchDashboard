<?php
/**
 * Database failure.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a query or schema change fails.
 *
 * The message is generic and never contains SQL, `$wpdb->last_error` or values,
 * so it is safe to log (LogContext keeps messages of MerchBoard exceptions) and
 * cannot leak database details to users. It is still never shown in the UI.
 *
 * Diagnostic data for the log travels separately in `context()`, so the layer
 * that handles the exception can log it once with full, sanitized detail.
 */
final class DatabaseException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                              $message  Generic message.
	 * @param array<string, int|string|bool|null> $context  Diagnostic data for the log (no user data).
	 * @param Throwable|null                      $previous Cause.
	 */
	public function __construct( string $message, private array $context = array(), ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
	}

	/**
	 * Diagnostic data for the log.
	 *
	 * @return array<string, int|string|bool|null>
	 */
	public function context(): array {
		return $this->context;
	}
}
