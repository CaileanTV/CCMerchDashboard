<?php
/**
 * Log message and context sanitization.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Logging;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Makes log data safe to write. A last line of defence: callers must still
 * avoid passing sensitive data (see docs/SECURITY.md, "Logging").
 *
 * - Values under sensitive-looking keys are replaced with "[redacted]".
 * - Exceptions become class and code; the message is only kept for the
 *   plugin's own exceptions, whose messages are generic by contract. Messages
 *   of other exceptions (e.g. from WordPress or PHP) can contain SQL, paths or
 *   values and are dropped. Stack traces are never logged.
 * - Other objects become their class name; resources are dropped.
 * - Control characters (line breaks, null bytes) are removed from the message
 *   and all string values so log lines cannot be forged; strings are
 *   truncated and nesting depth is limited.
 */
final class LogContext {

	/**
	 * Replacement for redacted values.
	 */
	public const REDACTED = '[redacted]';

	/**
	 * Maximum length of a string value or message.
	 */
	private const MAX_STRING_LENGTH = 500;

	/**
	 * Maximum nesting depth of context arrays.
	 */
	private const MAX_DEPTH = 3;

	/**
	 * Keys whose values are never logged (matched case-insensitively anywhere
	 * in the key): credentials, session data and personal/payment data.
	 */
	private const SENSITIVE_KEY_PATTERN = '/pass|pwd|secret|token|api[_-]?key|private[_-]?key|auth|cookie|session|nonce|salt|credential|card|cvv|iban|account|registration|email|phone|address/i';

	/**
	 * Namespace whose exception messages may be logged.
	 */
	private const OWN_NAMESPACE = 'MerchBoard\\';

	/**
	 * Sanitizes a log message: single line, bounded length.
	 *
	 * @param string $message Message.
	 */
	public static function message( string $message ): string {
		return self::clean_string( $message );
	}

	/**
	 * Sanitizes a context array.
	 *
	 * @param array<mixed> $context Context from the caller.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $context ): array {
		return self::sanitize_array( $context, 1 );
	}

	/**
	 * Sanitizes one level of an array.
	 *
	 * @param array<mixed> $values Values.
	 * @param int          $depth  Current depth (1-based).
	 * @return array<string, mixed>
	 */
	private static function sanitize_array( array $values, int $depth ): array {
		$clean = array();

		foreach ( $values as $key => $value ) {
			$key = (string) $key;

			$clean[ $key ] = 1 === preg_match( self::SENSITIVE_KEY_PATTERN, $key )
				? self::REDACTED
				: self::sanitize_value( $value, $depth );
		}

		return $clean;
	}

	/**
	 * Sanitizes a single value.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Current depth.
	 */
	private static function sanitize_value( mixed $value, int $depth ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return self::clean_string( $value );
		}

		if ( $value instanceof Throwable ) {
			return self::exception( $value );
		}

		if ( is_array( $value ) ) {
			return $depth >= self::MAX_DEPTH ? '[truncated]' : self::sanitize_array( $value, $depth + 1 );
		}

		if ( is_object( $value ) ) {
			return array( 'object' => get_class( $value ) );
		}

		return '[unsupported]';
	}

	/**
	 * Safe summary of an exception (no trace, no file paths).
	 *
	 * @param Throwable $exception Exception.
	 * @return array<string, int|string>
	 */
	private static function exception( Throwable $exception ): array {
		$summary = array(
			'exception' => get_class( $exception ),
			'code'      => $exception->getCode(),
		);

		if ( str_starts_with( get_class( $exception ), self::OWN_NAMESPACE ) ) {
			$summary['message'] = self::message( $exception->getMessage() );
		}

		return $summary;
	}

	/**
	 * Single line without control characters (line breaks, null bytes, escape
	 * sequences) so entries cannot be forged or corrupt the log; bounded length.
	 *
	 * @param string $value Value.
	 */
	private static function clean_string( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );

		return self::truncate( trim( $value ) );
	}

	/**
	 * Truncates long strings.
	 *
	 * @param string $value Value.
	 */
	private static function truncate( string $value ): string {
		return mb_strlen( $value ) > self::MAX_STRING_LENGTH
			? mb_substr( $value, 0, self::MAX_STRING_LENGTH ) . '…[truncated]'
			: $value;
	}
}
