<?php
/**
 * Tests for the creator profit and payout of a sale price (ADR-049, ADR-064).
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
	 * The locked examples: sale price, markup, profit.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function locked_examples(): array {
		return array(
			'100 / 20 %' => array( '100.00', '20', '20.0000' ),
			'200 / 10 %' => array( '200.00', '10', '20.0000' ),
			'100 / 0 %'  => array( '100.00', '0', '0.0000' ),
		);
	}

	/**
	 * Profit = sale price × markup %.
	 *
	 * @param string $sale    Sale price.
	 * @param string $percent Markup.
	 * @param string $profit  Expected profit.
	 */
	#[DataProvider( 'locked_examples' )]
	public function test_locked_examples( string $sale, string $percent, string $profit ): void {
		self::assertSame( $profit, SalesAmount::format( CreatorShare::profit( SalesAmount::parse( $sale ), MarkupBps::from_percent( $percent )->bps, 2 ) ) );
	}

	/**
	 * The payout (ADR-064): net, VAT, markup, decimals, VAT registered at the
	 * sale, expected payout.
	 *
	 * @return array<string, array{string, string, string, int, bool, string}>
	 */
	public static function payouts(): array {
		return array(
			'25 % VAT, registered: incl. VAT'       => array( '100.00', '25.00', '20', 2, true, '25.0000' ),
			'25 % VAT, not registered: excl. VAT'   => array( '100.00', '25.00', '20', 2, false, '20.0000' ),
			'0 % VAT, not registered: no deduction' => array( '100.00', '0.00', '20', 2, false, '20.0000' ),
			'6 % VAT, registered'                   => array( '100.00', '6.00', '20', 2, true, '21.2000' ),
			'6 % VAT, not registered: its own VAT'  => array( '100.00', '6.00', '20', 2, false, '20.0000' ),
			// 99.99 × 16.67 % = 16.668333 → 16.67; 79.99 × 16.67 % = 13.334333 → 13.33.
			'rounded once, registered'              => array( '79.99', '20.00', '16.67', 2, true, '16.6700' ),
			'rounded once, not registered'          => array( '79.99', '20.00', '16.67', 2, false, '13.3300' ),
			'4 decimals, not registered'            => array( '79.99', '20.00', '16.67', 4, false, '13.3343' ),
			'0 decimals, not registered'            => array( '79.99', '20.00', '16.67', 0, false, '13.0000' ),
			// -0.05 × 50 % = -0.025 → -0.03 (half away from zero).
			'refund half away from zero'            => array( '-0.05', '0.00', '50', 2, false, '-0.0300' ),
			'zero sale'                             => array( '0.00', '0.00', '20', 2, false, '0.0000' ),
		);
	}

	/**
	 * Payout = profit when VAT registered at the sale, otherwise the same
	 * share of the sale price excluding the sale's own VAT.
	 *
	 * @param string $net        Sale price excl. VAT.
	 * @param string $tax        The sale's VAT.
	 * @param string $percent    Markup.
	 * @param int    $decimals   Store decimals.
	 * @param bool   $registered VAT registered at the sale.
	 * @param string $payout     Expected payout.
	 */
	#[DataProvider( 'payouts' )]
	public function test_the_payout_deducts_the_vat_only_while_not_vat_registered( string $net, string $tax, string $percent, int $decimals, bool $registered, string $payout ): void {
		$net_units = SalesAmount::parse( $net );
		$tax_units = SalesAmount::parse( $tax );
		$bps       = MarkupBps::from_percent( $percent )->bps;
		$result    = CreatorShare::payout( $net_units, $tax_units, $bps, $registered, $decimals );

		self::assertSame( $payout, SalesAmount::format( $result ) );

		if ( $registered ) {
			self::assertSame( CreatorShare::profit( SalesAmount::add( $net_units, $tax_units ), $bps, $decimals ), $result, 'VAT registered: the whole profit.' );
		}
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
	 * Rounding of the profit to the store precision.
	 *
	 * @param string $sale     Sale price.
	 * @param string $percent  Markup.
	 * @param int    $decimals Store decimals.
	 * @param string $profit   Expected profit.
	 */
	#[DataProvider( 'rounding' )]
	public function test_rounding_to_the_store_precision( string $sale, string $percent, int $decimals, string $profit ): void {
		$profit_units = CreatorShare::profit( SalesAmount::parse( $sale ), MarkupBps::from_percent( $percent )->bps, $decimals );

		self::assertSame( SalesAmount::format( SalesAmount::parse( $profit ) ), SalesAmount::format( $profit_units ) );
	}

	public function test_refunds_and_reversals_negate_profit_and_payout(): void {
		self::assertSame( -200000, CreatorShare::profit( -1000000, 2000, 2 ) );

		foreach ( array( true, false ) as $registered ) {
			// 79.99 + 20.00 at 16.67 %: the refund of a sale gives exactly the negated payout.
			$sale   = CreatorShare::payout( 799900, 200000, 1667, $registered, 2 );
			$refund = CreatorShare::payout( -799900, -200000, 1667, $registered, 2 );

			self::assertSame( - $sale, $refund );
			self::assertSame( 0, SalesAmount::add( $sale, $refund ), 'A sale and its full refund cancel out.' );
		}
	}

	public function test_profit_and_payout_never_exceed_the_sale_price(): void {
		foreach ( array( 0, 1, 1667, 2000, 5000, 10000 ) as $bps ) {
			foreach ( array( 1, 99, 1000000, 999999999 ) as $units ) {
				$profit = CreatorShare::profit( $units, $bps, 2 );
				// The VAT of a sale of $units excl. VAT at 25 %.
				$tax = intdiv( $units, 4 );

				self::assertGreaterThanOrEqual( 0, $profit );
				self::assertLessThanOrEqual( $units + 50, $profit, 'At most the price (plus half a step of rounding).' );
				self::assertLessThanOrEqual( CreatorShare::profit( $units + $tax, $bps, 2 ), CreatorShare::payout( $units, $tax, $bps, false, 2 ), 'Never more than the profit incl. VAT.' );
			}
		}
	}

	public function test_no_fixed_deduction_is_used(): void {
		self::assertFalse( defined( CreatorShare::class . '::NOT_VAT_REGISTERED_PAYOUT_BPS' ), 'ADR-064 uses the sale\'s own VAT, never a fixed 20 %.' );
		// 100.00 + 6.00 VAT at 20 %: 20.00, not 80 % of 21.20 (16.96).
		self::assertSame( 200000, CreatorShare::payout( 1000000, 60000, 2000, false, 2 ) );
	}

	public function test_an_invalid_precision_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		CreatorShare::payout( 100, 0, 2000, false, 5 );
	}

	public function test_a_markup_above_100_percent_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		CreatorShare::profit( 100, 10001, 2 );
	}
}
