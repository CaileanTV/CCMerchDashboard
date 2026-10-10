<?php
/**
 * Tests for the exact pricing arithmetic and the markup value.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( PricingMath::class )]
#[CoversClass( MarkupBps::class )]
final class PricingMathTest extends TestCase {

	/**
	 * Sale price, share in percent, store decimals and the expected share (ADR-049 rounding table).
	 *
	 * @return array<string, array{string, string, int, string}>
	 */
	public static function profits(): array {
		return array(
			'99.00 × 19 %'                  => array( '99', '19', 2, '18.8100' ),
			'199.95 × 17.5 %'               => array( '199.95', '17.5', 2, '34.9900' ),
			'0.10 × 19 % rounds up'         => array( '0.10', '19', 2, '0.0200' ),
			'0.01 × 19 % rounds to zero'    => array( '0.01', '19', 2, '0.0000' ),
			'0.05 × 10 % half away'         => array( '0.05', '10', 2, '0.0100' ),
			'0 %'                           => array( '99', '0', 2, '0.0000' ),
			'100 %'                         => array( '99.99', '100', 2, '99.9900' ),
			'0 decimals rounds to whole'    => array( '99', '19', 0, '19.0000' ),
			'0 decimals half away'          => array( '5', '10', 0, '1.0000' ),
			'4 decimals keeps all'          => array( '99', '19', 4, '18.8100' ),
			'4 decimals sub-cent'           => array( '0.0005', '10', 4, '0.0001' ),
			'zero price'                    => array( '0', '50', 2, '0.0000' ),
			'100.00 × 20 %'                 => array( '100', '20', 2, '20.0000' ),
			'200.00 × 10 %'                 => array( '200', '10', 2, '20.0000' ),
			'99.99 × 16.67 %'               => array( '99.99', '16.67', 2, '16.6700' ),
			'refund -100.00 × 20 %'         => array( '-100', '20', 2, '-20.0000' ),
			'refund half away from zero'    => array( '-0.05', '10', 2, '-0.0100' ),
			// 0.00495: rounding to 4 decimals first (0.0050) and then to 2 would give 0.01.
			'one exact division, no double' => array( '0.99', '0.5', 2, '0.0000' ),
		);
	}

	/**
	 * A share is round_half_away(amount × bps / 10 000) at the store precision, signed.
	 *
	 * @param string $amount   Sale price (signed).
	 * @param string $percent  Share.
	 * @param int    $decimals Store decimals.
	 * @param string $expected Share.
	 */
	#[DataProvider( 'profits' )]
	public function test_share( string $amount, string $percent, int $decimals, string $expected ): void {
		$profit = PricingMath::share( SalesAmount::parse( $amount ), MarkupBps::from_percent( $percent )->bps, $decimals );

		self::assertSame( $expected, SalesAmount::format( $profit ) );
	}

	public function test_step_and_precision(): void {
		self::assertSame( 10000, PricingMath::step( 0 ) );
		self::assertSame( 100, PricingMath::step( 2 ) );
		self::assertSame( 1, PricingMath::step( 4 ) );
		self::assertTrue( PricingMath::fits_precision( 990000, 2 ) );
		self::assertFalse( PricingMath::fits_precision( 990001, 2 ) );
		self::assertTrue( PricingMath::fits_precision( 990001, 4 ) );
		self::assertFalse( PricingMath::fits_precision( 5000, 0 ) );
	}

	public function test_price_strings_have_exactly_the_store_decimals(): void {
		self::assertSame( '117.81', PricingMath::price_string( 1178100, 2 ) );
		self::assertSame( '120.00', PricingMath::price_string( 1200000, 2 ) );
		self::assertSame( '0.00', PricingMath::price_string( 0, 2 ) );
		self::assertSame( '119', PricingMath::price_string( 1190000, 0 ) );
		self::assertSame( '0.0001', PricingMath::price_string( 1, 4 ) );
		self::assertSame( '117.8', PricingMath::price_string( 1178000, 1 ) );
	}

	public function test_price_strings_never_round(): void {
		$this->expectException( InvalidArgumentException::class );

		PricingMath::price_string( 1178150, 2 );
	}

	public function test_unsupported_precision_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PricingMath::step( 5 );
	}

	public function test_negative_precision_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PricingMath::share( 10000, 100, -1 );
	}

	public function test_the_share_of_a_refund_is_the_negated_share_of_the_sale(): void {
		foreach ( array( 1, 49, 50, 51, 999999, 1234567 ) as $units ) {
			self::assertSame( - PricingMath::share( $units, 1667, 2 ), PricingMath::share( - $units, 1667, 2 ) );
		}
	}

	public function test_markup_above_100_percent_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PricingMath::share( 10000, 10001, 2 );
	}

	public function test_mul_div_round_is_exact_beyond_the_integer_range(): void {
		// PHP_INT_MAX × 3 does not fit into an integer (nor a float exactly).
		self::assertSame( PHP_INT_MAX, PricingMath::mul_div_round( PHP_INT_MAX, 3, 3 ) );
		self::assertSame( PHP_INT_MAX, PricingMath::mul_div_round( PHP_INT_MAX, 10000, 10000 ) );
		self::assertSame( -PHP_INT_MAX, PricingMath::mul_div_round( -PHP_INT_MAX, 7, 7 ) );
	}

	public function test_mul_div_round_rounds_half_away_from_zero(): void {
		self::assertSame( 1, PricingMath::mul_div_round( 1, 1, 2 ) );
		self::assertSame( -1, PricingMath::mul_div_round( -1, 1, 2 ) );
		self::assertSame( 0, PricingMath::mul_div_round( 1, 4, 9 ) );
		self::assertSame( 1, PricingMath::mul_div_round( 1, 5, 9 ) );
		self::assertSame( -1, PricingMath::mul_div_round( 1, -5, 9 ) );
		self::assertSame( 0, PricingMath::mul_div_round( 0, PHP_INT_MAX, 1 ) );
	}

	public function test_mul_div_round_overflow_throws(): void {
		$this->expectException( OverflowException::class );

		PricingMath::mul_div_round( PHP_INT_MAX, 2, 1 );
	}

	public function test_mul_div_round_rejects_an_unsafe_divisor(): void {
		$this->expectException( InvalidArgumentException::class );

		PricingMath::mul_div_round( 1, 1, 0 );
	}

	public function test_huge_profit_overflow_throws(): void {
		$this->expectException( OverflowException::class );

		// Whole-unit rounding of an amount near the integer limit cannot be represented.
		PricingMath::share( PHP_INT_MAX, 10000, 0 );
	}

	/**
	 * Valid percentages and their basis points.
	 *
	 * @return array<string, array{string, int, string}>
	 */
	public static function percentages(): array {
		return array(
			'zero'          => array( '0', 0, '0.00' ),
			'whole'         => array( '20', 2000, '20.00' ),
			'one decimal'   => array( '17.5', 1750, '17.50' ),
			'two decimals'  => array( '0.01', 1, '0.01' ),
			'hundred'       => array( '100', 10000, '100.00' ),
			'trimmed'       => array( ' 19 ', 1900, '19.00' ),
			'leading zeros' => array( '019.50', 1950, '19.50' ),
		);
	}

	/**
	 * Parsing and formatting a markup.
	 *
	 * @param string $percent Input.
	 * @param int    $bps     Basis points.
	 * @param string $out     Formatted.
	 */
	#[DataProvider( 'percentages' )]
	public function test_markup_from_percent( string $percent, int $bps, string $out ): void {
		$markup = MarkupBps::from_percent( $percent );

		self::assertSame( $bps, $markup->bps );
		self::assertSame( $out, $markup->percent() );
	}

	/**
	 * Invalid percentages.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_percentages(): array {
		return array(
			'above 100'      => array( '100.01' ),
			'negative'       => array( '-1' ),
			'three decimals' => array( '1.005' ),
			'comma'          => array( '17,5' ),
			'exponent'       => array( '1e2' ),
			'empty'          => array( '' ),
			'text'           => array( 'abc' ),
			'four digits'    => array( '1000' ),
		);
	}

	/**
	 * A markup outside 0–100 % or not a plain percentage is rejected.
	 *
	 * @param string $percent Input.
	 */
	#[DataProvider( 'invalid_percentages' )]
	public function test_invalid_markup_is_rejected( string $percent ): void {
		$this->expectException( InvalidArgumentException::class );

		MarkupBps::from_percent( $percent );
	}

	public function test_markup_bps_range(): void {
		self::assertSame( 0, ( new MarkupBps( 0 ) )->bps );
		self::assertSame( 10000, ( new MarkupBps( 10000 ) )->bps );

		$this->expectException( InvalidArgumentException::class );

		new MarkupBps( -1 );
	}
}
