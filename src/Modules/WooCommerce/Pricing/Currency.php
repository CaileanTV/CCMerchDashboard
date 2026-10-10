<?php
/**
 * Currency code validation for pricing.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * ISO 4217 codes as WooCommerce stores them (three upper-case letters).
 * Pricing never converts between currencies (ADR-049).
 */
final class Currency {

	/**
	 * Returns the code when it is valid.
	 *
	 * @param string $code Currency code.
	 * @throws InvalidArgumentException When it is not three upper-case letters.
	 */
	public static function assert_valid( string $code ): string {
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
			throw new InvalidArgumentException( 'Invalid currency code.' );
		}

		return $code;
	}
}
