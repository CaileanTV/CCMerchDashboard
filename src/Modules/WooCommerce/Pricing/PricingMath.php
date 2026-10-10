<?php
/**
 * Exact money arithmetic for creator pricing.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * Integer arithmetic on amounts in 1/10 000 units (SalesAmount, ADR-038 B1;
 * ADR-049). No floats and no `bcmath`.
 *
 * `mul_div_round()` computes `a × b / c` exactly: the product is formed as a
 * decimal digit string (so it cannot overflow), divided by `c` with long
 * division, and the quotient is rounded half away from zero. Only the final
 * result must fit into an integer; otherwise it throws.
 */
final class PricingMath {

	/**
	 * Decimals of the stored amounts (DECIMAL(19,4)).
	 */
	public const DECIMALS = SalesAmount::DECIMALS;

	/**
	 * Basis points per 100 %.
	 */
	public const BPS_PER_WHOLE = 10000;

	/**
	 * Units of the smallest step for a store precision: 2 decimals → 100 units
	 * (0.01), 0 decimals → 10 000 units (1).
	 *
	 * @param int $decimals WooCommerce price decimals, 0–4.
	 * @throws InvalidArgumentException When the precision cannot be represented in units.
	 */
	public static function step( int $decimals ): int {
		if ( $decimals < 0 || $decimals > self::DECIMALS ) {
			throw new InvalidArgumentException( 'Unsupported price precision.' );
		}

		return 10 ** ( self::DECIMALS - $decimals );
	}

	/**
	 * Whether an amount has no more decimals than the store precision.
	 *
	 * @param int $units    Amount in units.
	 * @param int $decimals WooCommerce price decimals, 0–4.
	 */
	public static function fits_precision( int $units, int $decimals ): bool {
		return 0 === $units % self::step( $decimals );
	}

	/**
	 * A non-negative amount as a price string (display) with exactly the
	 * store's decimals, e.g. 1178100 units at 2 decimals → "117.81". The amount
	 * must already fit the precision; nothing is rounded here.
	 *
	 * @param int $units    Amount in units, ≥ 0.
	 * @param int $decimals WooCommerce price decimals, 0–4.
	 * @throws InvalidArgumentException When the amount is negative or has more decimals than allowed.
	 */
	public static function price_string( int $units, int $decimals ): string {
		if ( $units < 0 || ! self::fits_precision( $units, $decimals ) ) {
			throw new InvalidArgumentException( 'The price cannot be written with the store precision.' );
		}

		$formatted = SalesAmount::format( $units );

		return 0 === $decimals ? (string) strstr( $formatted, '.', true ) : substr( $formatted, 0, strlen( $formatted ) - ( self::DECIMALS - $decimals ) );
	}

	/**
	 * A share of a signed amount: round_half_away(units × bps / 10 000) to
	 * the store precision, in one exact division (no double rounding, no
	 * floats). The sign follows the amount, so the share of a full refund is
	 * exactly the negated share of the sale.
	 *
	 * @param int $units    Amount in units, signed.
	 * @param int $bps      Share in basis points, 0–10 000.
	 * @param int $decimals Store price decimals, 0–4.
	 * @throws InvalidArgumentException When an input is out of range.
	 * @throws OverflowException When the share does not fit into an integer.
	 */
	public static function share( int $units, int $bps, int $decimals ): int {
		if ( $bps < 0 || $bps > self::BPS_PER_WHOLE ) {
			throw new InvalidArgumentException( 'The share must be between 0 and 10000 basis points.' );
		}

		$step  = self::step( $decimals );
		$steps = self::mul_div_round( $units, $bps, self::BPS_PER_WHOLE * $step );

		if ( abs( $steps ) > intdiv( PHP_INT_MAX, $step ) ) {
			throw new OverflowException( 'Amount out of range.' );
		}

		return $steps * $step;
	}

	/**
	 * Exact a × b / c, rounded half away from zero. Throws an
	 * OverflowException (from to_int()) when the result does not fit into an
	 * integer.
	 *
	 * @param int $a Factor.
	 * @param int $b Factor.
	 * @param int $c Divisor, 1 … PHP_INT_MAX / 10.
	 * @throws InvalidArgumentException When the divisor is out of range.
	 */
	public static function mul_div_round( int $a, int $b, int $c ): int {
		if ( $c < 1 || $c > intdiv( PHP_INT_MAX, 10 ) ) {
			throw new InvalidArgumentException( 'Divisor out of range.' );
		}

		if ( 0 === $a || 0 === $b ) {
			return 0;
		}

		$negative = ( $a < 0 ) !== ( $b < 0 );
		$product  = self::multiply( ltrim( (string) $a, '-' ), ltrim( (string) $b, '-' ) );

		[ $quotient, $remainder ] = self::divide( $product, $c );

		$units = self::to_int( $quotient );

		// Half away from zero; 2 × remainder < 2 × c, which fits (c ≤ PHP_INT_MAX / 10).
		if ( 2 * $remainder >= $c ) {
			$units = SalesAmount::add( $units, 1 );
		}

		return $negative ? SalesAmount::negate( $units ) : $units;
	}

	/**
	 * Product of two non-negative decimal digit strings (schoolbook).
	 *
	 * @param string $a Digits.
	 * @param string $b Digits.
	 */
	private static function multiply( string $a, string $b ): string {
		$x      = array_map( 'intval', array_reverse( str_split( $a ) ) );
		$y      = array_map( 'intval', array_reverse( str_split( $b ) ) );
		$result = array_fill( 0, count( $x ) + count( $y ), 0 );

		foreach ( $x as $i => $xd ) {
			$carry = 0;

			foreach ( $y as $j => $yd ) {
				$sum               = $result[ $i + $j ] + $xd * $yd + $carry;
				$result[ $i + $j ] = $sum % 10;
				$carry             = intdiv( $sum, 10 );
			}

			$k = $i + count( $y );

			while ( $carry > 0 ) {
				$sum          = $result[ $k ] + $carry;
				$result[ $k ] = $sum % 10;
				$carry        = intdiv( $sum, 10 );
				++$k;
			}
		}

		$digits = ltrim( implode( '', array_reverse( $result ) ), '0' );

		return '' === $digits ? '0' : $digits;
	}

	/**
	 * Long division of a non-negative digit string by a positive integer.
	 *
	 * @param string $digits  Dividend.
	 * @param int    $divisor Divisor, ≤ PHP_INT_MAX / 10.
	 * @return array{0: string, 1: int} Quotient digits and remainder.
	 */
	private static function divide( string $digits, int $divisor ): array {
		$quotient  = '';
		$remainder = 0;

		foreach ( str_split( $digits ) as $digit ) {
			$remainder = $remainder * 10 + (int) $digit;
			$quotient .= (string) intdiv( $remainder, $divisor );
			$remainder = $remainder % $divisor;
		}

		$quotient = ltrim( $quotient, '0' );

		return array( '' === $quotient ? '0' : $quotient, $remainder );
	}

	/**
	 * A non-negative digit string as an integer.
	 *
	 * @param string $digits Digits without leading zeros.
	 * @throws OverflowException When it does not fit.
	 */
	private static function to_int( string $digits ): int {
		$max = (string) PHP_INT_MAX;

		if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) {
			throw new OverflowException( 'Amount out of range.' );
		}

		return (int) $digits;
	}
}
