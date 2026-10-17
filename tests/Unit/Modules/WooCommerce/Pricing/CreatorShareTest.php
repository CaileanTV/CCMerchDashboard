<?php
/**
 * Tests for the creator profit and payout of a sale price (ADR-049, ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorShare;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( CreatorShare::class )]
final class CreatorShareTest extends TestCase {

	/**
	 * The locked examples: sale price, markup, profit, payout. The payout is
	 * the whole profit for every creator (ADR-063 removed the 20 % deduction).
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function locked_examples(): array {
		return array(
			'100 / 20 %' => array( '100.00', '20', '20.0000', '20.0000' ),
			'200 / 10 %' => array( '200.00', '10', '20.0000', '20.0000' ),
			'100 / 0 %'  => array( '100.00', '0', '0.0000', '0.0000' ),
		);
	}

	/**
	 * Profit = sale price × markup %; payout = profit.
	 *
	 * @param string $sale    Sale price.
	 * @param string $percent Markup.
	 * @param string $profit  Expected profit.
	 * @param string $payout  Expected payout.
	 */
	#[DataProvider( 'locked_examples' )]
	public function test_locked_examples( string $sale, string $percent, string $profit, string $payout ): void {
		$profit_units = CreatorShare::profit( SalesAmount::parse( $sale ), MarkupBps::from_percent( $percent )->bps, 2 );

		self::assertSame( $profit, SalesAmount::format( $profit_units ) );
		self::assertSame( $payout, SalesAmount::format( CreatorShare::payout( $profit_units, 2 ) ) );
	}

	/**
	 * Rounding to the store precision, half away from zero, without floats.
	 *
	 * @return array<string, array{string, string, int, string}>
	 */
	public static function rounding(): array {
		return array(
			// 99.99 × 16.67 % = 16.668333 → 16.67.
			'2 decimals'                 => array( '99.99', '16.67', 2, '16.6700' ),
			// 0.1 + 0.2 style inputs that break floats stay exact.
			'0.30 × 50 %'                => array( '0.30', '50', 2, '0.1500' ),
			// 0.05 × 50 % = 0.025 → 0.03 (half away).
			'half away from zero'        => array( '0.05', '50', 2, '0.0300' ),
			'refund half away from zero' => array( '-0.05', '50', 2, '-0.0300' ),
			// 99 × 16.67 % = 16.5033 → 17.
			'0 decimals'                 => array( '99', '16.67', 0, '17.0000' ),
			'4 decimals'                 => array( '99.99', '16.67', 4, '16.6683' ),
			'100 % of the price'         => array( '149.95', '100', 2, '149.9500' ),
			'large amount stays exact'   => array( '123456789.99', '33.33', 2, '41148148.10' ),
		);
	}

	/**
	 * Rounding of the profit; the payout is the rounded profit, unchanged.
	 *
	 * @param string $sale     Sale price.
	 * @param string $percent  Markup.
	 * @param int    $decimals Store decimals.
	 * @param string $profit   Expected profit and payout.
	 */
	#[DataProvider( 'rounding' )]
	public function test_rounding_to_the_store_precision( string $sale, string $percent, int $decimals, string $profit ): void {
		$profit_units = CreatorShare::profit( SalesAmount::parse( $sale ), MarkupBps::from_percent( $percent )->bps, $decimals );

		self::assertSame( SalesAmount::format( SalesAmount::parse( $profit ) ), SalesAmount::format( $profit_units ) );
		self::assertSame( $profit_units, CreatorShare::payout( $profit_units, $decimals ) );
	}

	public function test_refunds_and_reversals_negate_profit_and_payout(): void {
		$sale   = CreatorShare::profit( 1000000, 2000, 2 );
		$refund = CreatorShare::profit( -1000000, 2000, 2 );

		self::assertSame( -200000, $refund );
		self::assertSame( - CreatorShare::payout( $sale, 2 ), CreatorShare::payout( $refund, 2 ) );
		self::assertSame( -200000, CreatorShare::payout( $refund, 2 ) );
	}

	public function test_profit_and_payout_never_exceed_the_sale_price(): void {
		foreach ( array( 0, 1, 1667, 2000, 5000, 10000 ) as $bps ) {
			foreach ( array( 1, 99, 1000000, 999999999 ) as $units ) {
				$profit = CreatorShare::profit( $units, $bps, 2 );

				self::assertGreaterThanOrEqual( 0, $profit );
				self::assertLessThanOrEqual( $units + 50, $profit, 'At most the price (plus half a step of rounding).' );
				self::assertSame( $profit, CreatorShare::payout( $profit, 2 ) );
			}
		}
	}

	public function test_the_20_percent_deduction_is_gone(): void {
		self::assertFalse( defined( CreatorShare::class . '::NOT_VAT_REGISTERED_PAYOUT_BPS' ), 'ADR-063: no deduction constant is left.' );
		self::assertSame( 160000, CreatorShare::payout( 160000, 2 ), 'The whole profit, never 80 %.' );
	}

	public function test_an_invalid_precision_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		CreatorShare::payout( 100, 5 );
	}

	public function test_a_markup_above_100_percent_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		CreatorShare::profit( 100, 10001, 2 );
	}
}
