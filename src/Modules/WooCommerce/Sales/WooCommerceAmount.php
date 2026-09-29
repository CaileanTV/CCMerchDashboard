<?php
/**
 * WooCommerce amounts and quantities for the sales planner.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Converts WooCommerce values to the planner's integers (ADR-038, B1 and Q6).
 *
 * WooCommerce stores line totals through `wc_format_decimal()`, which turns
 * even a float into a plain decimal string (or 0 when it is not numeric), so
 * `get_total( 'edit' )` returns an integer or a decimal string. Only those are
 * accepted:
 *
 * - an integer, or a plain decimal string with at most 6 decimals (WooCommerce'
 *   rounding precision), rounded half away from zero to 4 decimals;
 * - never a float, an empty string, exponent notation or anything else: those
 *   are rejected, never cast or guessed.
 *
 * Quantities must be whole numbers; a fraction is rejected, never rounded.
 */
final class WooCommerceAmount {

	/**
	 * Maximum decimals accepted from WooCommerce.
	 */
	public const MAX_DECIMALS = 6;

	/**
	 * An amount in 1/10000 units. An amount out of range throws
	 * SalesAmount's OverflowException.
	 *
	 * @param mixed $value `get_total( 'edit' )` or a similar CRUD value.
	 * @throws InvalidArgumentException When the value is not an integer or a plain decimal string.
	 */
	public static function units( mixed $value ): int {
		if ( is_int( $value ) ) {
			return SalesAmount::parse( (string) $value );
		}

		if ( is_string( $value ) && 1 === preg_match( '/^-?\d+(?:\.\d{1,' . self::MAX_DECIMALS . '})?$/', $value ) ) {
			return SalesAmount::parse( $value );
		}

		throw new InvalidArgumentException( 'Unsupported WooCommerce amount.' );
	}

	/**
	 * A whole quantity.
	 *
	 * @param mixed $value `get_quantity( 'edit' )`: an integer, or a float or string holding a whole number.
	 * @throws InvalidArgumentException When the value is not a whole number.
	 */
	public static function quantity( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		// A float is accepted only when it is exactly a whole number in the safe integer range.
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && abs( $value ) <= 9007199254740991 ) {
			return (int) $value;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,15}(?:\.0+)?$/', $value ) ) {
			return (int) $value;
		}

		throw new InvalidArgumentException( 'Unsupported WooCommerce quantity.' );
	}
}
