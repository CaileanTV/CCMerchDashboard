<?php
/**
 * Tests for the 1/10000 money arithmetic.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( SalesAmount::class )]
final class SalesAmountTest extends TestCase {

	/**
	 * Decimal strings and their units.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function decimals(): array {
		return array(
			'integer'                     => array( '100', 1000000 ),
			'two decimals'                => array( '12.50', 125000 ),
			'four decimals'               => array( '0.0001', 1 ),
			'negative'                    => array( '-30.25', -302500 ),
			'zero'                        => array( '0', 0 ),
			'negative zero'               => array( '-0.0000', 0 ),
			'leading zeros'               => array( '007.5', 75000 ),
			'round down below half'       => array( '1.00004', 10000 ),
			'round half away from zero'   => array( '1.00005', 10001 ),
			'round half away, negative'   => array( '-1.00005', -10001 ),
			'only the 5th decimal counts' => array( '1.000049999', 10000 ),
			'round up carries'            => array( '0.99999', 10000 ),
			'largest amount'              => array( '922337203685477.5807', PHP_INT_MAX ),
			'most negative amount'        => array( '-922337203685477.5807', -PHP_INT_MAX ),
		);
	}

	#[DataProvider( 'decimals' )]
	public function test_parse_is_exact_and_deterministic( string $decimal, int $units ): void {
		self::assertSame( $units, SalesAmount::parse( $decimal ) );
		self::assertSame( $units, SalesAmount::parse( $decimal ), 'Same input, same result.' );
	}

	public function test_format_has_exactly_four_decimals_and_round_trips(): void {
		self::assertSame( '12.5000', SalesAmount::format( 125000 ) );
		self::assertSame( '-0.0001', SalesAmount::format( -1 ) );
		self::assertSame( '0.0000', SalesAmount::format( 0 ) );
		self::assertSame( '922337203685477.5807', SalesAmount::format( PHP_INT_MAX ) );

		foreach ( array( 0, 1, -1, 9999, -10000, 123456789, -987654321012, PHP_INT_MAX, -PHP_INT_MAX ) as $units ) {
			self::assertSame( $units, SalesAmount::parse( SalesAmount::format( $units ) ) );
		}
	}

	/**
	 * Strings that are not plain decimals.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid(): array {
		return array(
			'empty'          => array( '' ),
			'exponent'       => array( '1e3' ),
			'plus sign'      => array( '+1' ),
			'comma'          => array( '1,5' ),
			'no integer'     => array( '.5' ),
			'trailing point' => array( '5.' ),
			'thousands'      => array( '1 000' ),
			'text'           => array( 'abc' ),
		);
	}

	#[DataProvider( 'invalid' )]
	public function test_rejects_anything_but_plain_decimals( string $decimal ): void {
		$this->expectException( InvalidArgumentException::class );

		SalesAmount::parse( $decimal );
	}

	public function test_overflow_is_an_error_not_a_float(): void {
		$rejected = 0;

		foreach ( array(
			static fn() => SalesAmount::parse( '922337203685477.5808' ),
			static fn() => SalesAmount::parse( '922337203685477.58075' ),
			static fn() => SalesAmount::parse( '10000000000000000000' ),
			static fn() => SalesAmount::add( PHP_INT_MAX, 1 ),
			static fn() => SalesAmount::add( PHP_INT_MIN, -1 ),
			static fn() => SalesAmount::negate( PHP_INT_MIN ),
			static fn() => SalesAmount::format( PHP_INT_MIN ),
		) as $i => $call ) {
			try {
				$call();
				self::fail( 'Expected OverflowException #' . $i );
			} catch ( OverflowException ) {
				++$rejected;
			}
		}

		self::assertSame( 7, $rejected );

		self::assertSame( PHP_INT_MAX, SalesAmount::add( PHP_INT_MAX - 1, 1 ) );
		self::assertSame( -5, SalesAmount::negate( 5 ) );
	}
}
