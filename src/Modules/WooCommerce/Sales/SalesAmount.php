<?php
/**
 * Money arithmetic of the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * Amounts as integers in 1/10000 units (ADR-038, B1): `12.5` is `125000`.
 *
 * No floats and no `bcmath`. Conversion from decimal strings is
 * deterministic: more than 4 decimals are rounded half away from zero
 * (`0.00005` → 1 unit, `-0.00005` → -1 unit). Sums are checked for integer
 * overflow instead of silently becoming floats.
 */
final class SalesAmount {

	/**
	 * Units per currency unit.
	 */
	public const SCALE = 10000;

	/**
	 * Decimals kept (DECIMAL(19,4) in `merchboard_sales`).
	 */
	public const DECIMALS = 4;

	/**
	 * Parses a decimal string, e.g. "12.5", "-0.12345" or "100".
	 *
	 * @param string $decimal Plain decimal: optional minus, digits, optional fraction. No exponent, no plus sign, no separators.
	 * @throws InvalidArgumentException When the string is not a plain decimal.
	 * @throws OverflowException When the amount does not fit into an integer of units.
	 */
	public static function parse( string $decimal ): int {
		if ( 1 !== preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $parts ) ) {
			throw new InvalidArgumentException( 'Invalid decimal amount.' );
		}

		$fraction = $parts[3] ?? '';
		$digits   = ltrim( $parts[2] . substr( str_pad( $fraction, self::DECIMALS, '0' ), 0, self::DECIMALS ), '0' );
		$digits   = '' === $digits ? '0' : $digits;
		$max      = (string) PHP_INT_MAX;

		if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) {
			throw new OverflowException( 'Amount out of range.' );
		}

		$units = (int) $digits;

		// Half away from zero: only the first dropped digit decides.
		if ( strlen( $fraction ) > self::DECIMALS && (int) $fraction[ self::DECIMALS ] >= 5 ) {
			$units = self::add( $units, 1 );
		}

		return '-' === $parts[1] ? -$units : $units;
	}

	/**
	 * Formats units as a decimal string with exactly 4 decimals ("-12.5000").
	 *
	 * @param int $units Amount in 1/10000 units.
	 * @throws OverflowException For PHP_INT_MIN (has no positive counterpart).
	 */
	public static function format( int $units ): string {
		$absolute = $units < 0 ? self::negate( $units ) : $units;

		return ( $units < 0 ? '-' : '' ) . intdiv( $absolute, self::SCALE ) . '.' . str_pad( (string) ( $absolute % self::SCALE ), self::DECIMALS, '0', STR_PAD_LEFT );
	}

	/**
	 * Checked addition.
	 *
	 * @param int $a Units.
	 * @param int $b Units.
	 * @throws OverflowException When the sum does not fit into an integer.
	 */
	public static function add( int $a, int $b ): int {
		if ( ( $b > 0 && $a > PHP_INT_MAX - $b ) || ( $b < 0 && $a < PHP_INT_MIN - $b ) ) {
			throw new OverflowException( 'Amount out of range.' );
		}

		return $a + $b;
	}

	/**
	 * Checked negation.
	 *
	 * @param int $units Units.
	 * @throws OverflowException For PHP_INT_MIN.
	 */
	public static function negate( int $units ): int {
		if ( PHP_INT_MIN === $units ) {
			throw new OverflowException( 'Amount out of range.' );
		}

		return -$units;
	}
}
