<?php
/**
 * Row conversion helpers for the pricing repositories.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Database values ↔ domain values. Amounts are DECIMAL(19,4) strings in the
 * database and integer units in PHP (never floats); timestamps are UTC.
 */
final class PricingRows {

	/**
	 * A positive integer id from a row.
	 *
	 * @param mixed $value Raw value.
	 * @throws DatabaseException When it is not a positive integer.
	 */
	public static function id( mixed $value ): int {
		if ( ! is_numeric( $value ) || (int) $value < 1 ) {
			throw new DatabaseException( 'Invalid pricing row.' );
		}

		return (int) $value;
	}

	/**
	 * A DECIMAL(19,4) column in units.
	 *
	 * @param mixed $value Raw value.
	 * @throws DatabaseException When it is not a plain decimal.
	 */
	public static function units( mixed $value ): int {
		try {
			return SalesAmount::parse( is_string( $value ) ? $value : '' );
		} catch ( Throwable ) {
			throw new DatabaseException( 'Invalid pricing amount.' );
		}
	}

	/**
	 * Units as a DECIMAL(19,4) string.
	 *
	 * @param int $units Units.
	 */
	public static function decimal( int $units ): string {
		return SalesAmount::format( $units );
	}

	/**
	 * A DATETIME column (UTC).
	 *
	 * @param mixed $value Raw value.
	 * @throws DatabaseException When it is not a timestamp.
	 */
	public static function time( mixed $value ): DateTimeImmutable {
		$time = is_string( $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) ) : false;

		if ( false === $time ) {
			throw new DatabaseException( 'Invalid pricing timestamp.' );
		}

		return $time;
	}

	/**
	 * A timestamp as a DATETIME value (UTC).
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	public static function datetime( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The current time (UTC), to the second.
	 */
	public static function now(): DateTimeImmutable {
		return self::time( current_time( 'mysql', true ) );
	}
}
