<?php
/**
 * Tests for the creator profit and payout of a sale price (ADR-049).
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
	 * The locked examples: sale price, markup, VAT registered, profit, payout.
	 *
	 * @return array<string, array{string, string, bool, string, string}>
	 */
	public static function locked_examples(): array {
		return array(
			'100 / 20 % / not VAT registered' => array( '100.00', '20', false, '20.0000', '16.0000' ),
			'100 / 20 % / VAT registered'     => array( '100.00', '20', true, '20.0000', '20.0000' ),
			'200 / 10 % / not VAT registered' => array( '200.00', '10', false, '20.0000', '16.0000' ),
			'200 / 10 % / VAT registered'     => array( '200.00', '10', true, '20.0000', '20.0000' ),
			'100 / 0 % / not VAT registered'  => array( '100.00', '0', false, '0.0000', '0.0000' ),
			'100 / 0 % / VAT registered'      => array( '100.00', '0', true, '0.0000', '0.0000' ),
		);
	}

	/**
	 * Profit = sale price × markup %; payout = profit (VAT registered) or profit × 80 %.
	 *
	 * @param string $sale           Sale price.
	 * @param string $percent        Markup.
	 * @param bool   $vat_registered VAT status.
	 * @param string $profit         Expected profit.
	 * @param string $payout         Expected payout.
	 */
	#[DataProvider( 'locked_examples' )]
	public function test_locked_examples( string $sale, string $percent, bool $vat_registered, string $profit, string $payout ): void {
		$profit_units = CreatorShare::profit( SalesAmount::parse( $sale ), MarkupBps::from_percent( $percent )->bps, 2 );

		self::assertSame( $profit, SalesAmount::format( $profit_units ) );
		self::assertSame( $payout, SalesAmount::format( CreatorShare::payout( $profit_units, $vat_registered, 2 ) ) );
	}

	/**
	 * Rounding to the store precision, half away from zero, without floats.
	 *
	 * @return array<string, array{string, string, int, string, string}>
	 */
	public static function rounding(): array {
		return array(
			// 99.99 × 16.67 % = 16.668333 → 16.67; × 80 % = 13.336 → 13.34.
			'2 decimals'                 => array( '99.99', '16.67', 2, '16.6700', '13.3400' ),
			// 0.1 + 0.2 style inputs that break floats stay exact.
			'0.30 × 50 %'                => array( '0.30', '50', 2, '0.1500', '0.1200' ),
			// 0.05 × 50 % = 0.025 → 0.03 (half away); × 80 % = 0.024 → 0.02.
			'half away from zero'        => array( '0.05', '50', 2, '0.0300', '0.0200' ),
			'refund half away from zero' => array( '-0.05', '50', 2, '-0.0300', '-0.0200' ),
			// 99 × 16.67 % = 16.5033 → 17; × 80 % = 13.6 → 14.
			'0 decimals'                 => array( '99', '16.67', 0, '17.0000', '14.0000' ),
			'4 decimals'                 => array( '99.99', '16.67', 4, '16.6683', '13.3346' ),
			'100 % of the price'         => array( '149.95', '100', 2, '149.9500', '119.9600' ),
			'large amount stays exact'   => array( '123456789.99', '33.33', 2, '41148148.10', '32918518.48' ),
		);
	}

	/**
	 * Rounding of profit and (not VAT registered) payout.
	 *
	 * @param string $sale     Sale price.
	 * @param string $percent  Markup.
	 * @param int    $decimals Store decimals.
	 * @param string $profit   Expected profit.
	 * @param string $payout   Expected payout when not VAT registered.
	 */
	#[DataProvider( 'rounding' )]
	public function test_rounding_to_the_store_precision( string $sale, string $percent, int $decimals, string $profit, string $payout ): void {
		$profit_units = CreatorShare::profit( SalesAmount::parse( $sale ), MarkupBps::from_percent( $percent )->bps, $decimals );

		self::assertSame( SalesAmount::format( SalesAmount::parse( $profit ) ), SalesAmount::format( $profit_units ) );
		self::assertSame( SalesAmount::format( SalesAmount::parse( $payout ) ), SalesAmount::format( CreatorShare::payout( $profit_units, false, $decimals ) ) );
		self::assertSame( $profit_units, CreatorShare::payout( $profit_units, true, $decimals ) );
	}

	public function test_refunds_and_reversals_negate_profit_and_payout(): void {
		$sale   = CreatorShare::profit( 1000000, 2000, 2 );
		$refund = CreatorShare::profit( -1000000, 2000, 2 );

		self::assertSame( -200000, $refund );
		self::assertSame( - CreatorShare::payout( $sale, false, 2 ), CreatorShare::payout( $refund, false, 2 ) );
		self::assertSame( -160000, CreatorShare::payout( $refund, false, 2 ) );
		self::assertSame( -200000, CreatorShare::payout( $refund, true, 2 ) );
	}

	public function test_profit_and_payout_never_exceed_the_sale_price(): void {
		foreach ( array( 0, 1, 1667, 2000, 5000, 10000 ) as $bps ) {
			foreach ( array( 1, 99, 1000000, 999999999 ) as $units ) {
				$profit = CreatorShare::profit( $units, $bps, 2 );

				self::assertGreaterThanOrEqual( 0, $profit );
				self::assertLessThanOrEqual( $units + 50, $profit, 'At most the price (plus half a step of rounding).' );
				self::assertLessThanOrEqual( $profit, CreatorShare::payout( $profit, false, 2 ) );
				self::assertLessThanOrEqual( $profit, CreatorShare::payout( $profit, true, 2 ) );
			}
		}
	}

	public function test_the_not_vat_registered_deduction_is_20_percent_of_the_profit(): void {
		self::assertSame( 8000, CreatorShare::NOT_VAT_REGISTERED_PAYOUT_BPS );
	}

	public function test_an_invalid_precision_is_rejected_for_both_statuses(): void {
		foreach ( array( true, false ) as $vat_registered ) {
			try {
				CreatorShare::payout( 100, $vat_registered, 5 );
				self::fail( 'Expected an exception.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_a_markup_above_100_percent_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		CreatorShare::profit( 100, 10001, 2 );
	}
}
