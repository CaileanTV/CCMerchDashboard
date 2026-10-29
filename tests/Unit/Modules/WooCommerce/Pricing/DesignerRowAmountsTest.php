<?php
/**
 * Tests for the profit and payout of designer products (ADR-080 pkt. 8).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerRowAmounts;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( DesignerRowAmounts::class )]
final class DesignerRowAmountsTest extends TestCase {

	/**
	 * Base 150, profit 50, list price 200 (all incl. VAT): quantity, paid,
	 * campaign, expected profit.
	 *
	 * @return array<string, array{int, string, bool, string}>
	 */
	public static function examples(): array {
		return array(
			'no discount'                            => array( 1, '200.00', false, '50.0000' ),
			'two units, no discount'                 => array( 2, '400.00', false, '100.0000' ),
			'creator\'s own code: discount = profit' => array( 1, '150.00', false, '0.0000' ),
			'company coupon 20'                      => array( 1, '180.00', false, '30.0000' ),
			'company coupon 80: never negative'      => array( 1, '120.00', false, '0.0000' ),
			'free'                                   => array( 1, '0.00', false, '0.0000' ),
			'paid above the list price: capped'      => array( 1, '230.00', false, '50.0000' ),
			'campaign 20: shared'                    => array( 1, '180.00', true, '45.0000' ),
			'refund of one unit at the paid price'   => array( -1, '-180.00', false, '-30.0000' ),
			'refund of two units, no discount'       => array( -2, '-400.00', false, '-100.0000' ),
			'amount-only refund: the company bears'  => array( 0, '-20.00', false, '0.0000' ),
			'order correction without quantity'      => array( 0, '15.00', false, '0.0000' ),
			'partial refund of one unit'             => array( -1, '-50.00', false, '0.0000' ),
			'campaign refund'                        => array( -1, '-180.00', true, '-45.0000' ),
		);
	}

	/**
	 * The examples of features/product-designer.md.
	 *
	 * @param int    $quantity Quantity.
	 * @param string $paid     Paid incl. VAT.
	 * @param bool   $campaign Whether a campaign covers the row.
	 * @param string $profit   Expected profit.
	 */
	#[DataProvider( 'examples' )]
	public function test_the_company_keeps_the_base_price_outside_its_own_campaigns( int $quantity, string $paid, bool $campaign, string $profit ): void {
		$bps = DesignerRowAmounts::share_bps( SalesAmount::parse( '50' ), SalesAmount::parse( '200' ) );

		self::assertSame( 2500, $bps );
		self::assertSame( $profit, SalesAmount::format( DesignerRowAmounts::profit( $quantity, SalesAmount::parse( $paid ), SalesAmount::parse( '150' ), SalesAmount::parse( '50' ), $campaign, 2 ) ) );
	}

	public function test_no_discount_outside_a_campaign_can_take_the_company_below_the_base_price_or_the_creator_below_zero(): void {
		$base   = SalesAmount::parse( '150' );
		$profit = SalesAmount::parse( '50' );

		for ( $paid = 0; $paid <= 25000; $paid += 37 ) {
			$units = $paid * 100;
			$got   = DesignerRowAmounts::profit( 1, $units, $base, $profit, false, 2 );

			self::assertGreaterThanOrEqual( 0, $got );
			self::assertLessThanOrEqual( $profit, $got );
			// What the company keeps is never below the base price, unless the customer paid less than it.
			self::assertSame( min( $units, max( $base, $units - $profit ) ), $units - $got, 'paid ' . $paid );
		}
	}

	public function test_the_payout_is_the_profit_when_vat_registered_and_without_vat_otherwise(): void {
		$profit = SalesAmount::parse( '50' );

		self::assertSame( $profit, DesignerRowAmounts::payout( $profit, SalesAmount::parse( '160' ), SalesAmount::parse( '200' ), true, 2 ) );
		self::assertSame( '40.0000', SalesAmount::format( DesignerRowAmounts::payout( $profit, SalesAmount::parse( '160' ), SalesAmount::parse( '200' ), false, 2 ) ) );
		self::assertSame( '-24.0000', SalesAmount::format( DesignerRowAmounts::payout( SalesAmount::parse( '-30' ), SalesAmount::parse( '-144' ), SalesAmount::parse( '-180' ), false, 2 ) ) );
		self::assertSame( '33.3300', SalesAmount::format( DesignerRowAmounts::payout( SalesAmount::parse( '41.66' ), SalesAmount::parse( '100' ), SalesAmount::parse( '125' ), false, 2 ) ), 'Rounded once to the store precision.' );
		self::assertSame( 0, DesignerRowAmounts::payout( 0, 0, 0, false, 2 ) );
	}

	public function test_a_campaign_shares_the_discount_exactly(): void {
		// Base 99.95 + profit 15.05 = 115.00; paid 100.00 in a campaign: 100 × 15.05 / 115 = 13.0869… → 13.09.
		self::assertSame( '13.0900', SalesAmount::format( DesignerRowAmounts::profit( 1, SalesAmount::parse( '100' ), SalesAmount::parse( '99.95' ), SalesAmount::parse( '15.05' ), true, 2 ) ) );
		self::assertSame( '-13.0900', SalesAmount::format( DesignerRowAmounts::profit( -1, SalesAmount::parse( '-100' ), SalesAmount::parse( '99.95' ), SalesAmount::parse( '15.05' ), true, 2 ) ), 'A refund takes back exactly the same.' );
		self::assertSame( '13.0000', SalesAmount::format( DesignerRowAmounts::profit( 1, SalesAmount::parse( '100' ), SalesAmount::parse( '99.95' ), SalesAmount::parse( '15.05' ), true, 0 ) ), 'Whole kroner.' );
	}

	public function test_a_refund_takes_back_the_profit_in_proportion_and_a_full_refund_ends_at_zero(): void {
		$parse = static fn( string $amount ): int => SalesAmount::parse( $amount );
		$back  = static fn( string $paid, string $returned, string $returned_profit, string $sold = '200', string $profit = '100' ): string => SalesAmount::format( DesignerRowAmounts::refund( $parse( $paid ), $parse( $sold ), $parse( $profit ), $parse( $returned ), $parse( $returned_profit ), 2 ) );

		// Base 100 + profit 100 sold at 200: half refunded takes back half.
		self::assertSame( '-50.0000', $back( '-100', '0', '0' ) );
		// The rest of the line (an amount-only refund) takes back the rest: the line ends at 0.
		self::assertSame( '-50.0000', $back( '-100', '-100', '-50' ) );
		// Never more than the line gave, also when more is refunded than was paid.
		self::assertSame( '0.0000', $back( '-50', '-200', '-100' ) );
		self::assertSame( '-100.0000', $back( '-250', '0', '0' ) );
		// A discounted sale (paid 180, profit 80): a full refund takes back 80, a third takes back a third.
		self::assertSame( '-80.0000', $back( '-180', '0', '0', '180', '80' ) );
		self::assertSame( '-26.6700', $back( '-60', '0', '0', '180', '80' ) );
		self::assertSame( '-53.3300', $back( '-120', '-60', '-26.67', '180', '80' ), 'Rounded once on the total, so the parts add up.' );
		// Nothing sold, or no profit: nothing to take back.
		self::assertSame( '0.0000', $back( '-100', '0', '0', '0', '0' ) );
		self::assertSame( '0.0000', $back( '-100', '0', '0', '200', '0' ) );
	}

	public function test_a_line_sold_without_vat_compares_without_vat(): void {
		$base   = DesignerRowAmounts::without_vat( SalesAmount::parse( '100' ), 2500, true, 2 );
		$profit = DesignerRowAmounts::without_vat( SalesAmount::parse( '100' ), 2500, false, 2 );

		self::assertSame( array( '80.0000', '80.0000' ), array( SalesAmount::format( $base ), SalesAmount::format( $profit ) ) );
		// An export at the full price (160 without VAT): the creator gets the profit without VAT.
		self::assertSame( '80.0000', SalesAmount::format( DesignerRowAmounts::profit( 1, SalesAmount::parse( '160' ), $base, $profit, false, 2 ) ) );
		// The base price is rounded up: 99.95 / 1.25 = 79.96; 99.99 / 1.25 = 79.992 → 80.00, the profit half: 15.05 / 1.25 = 12.04.
		self::assertSame( '79.9600', SalesAmount::format( DesignerRowAmounts::without_vat( SalesAmount::parse( '99.95' ), 2500, true, 2 ) ) );
		self::assertSame( '80.0000', SalesAmount::format( DesignerRowAmounts::without_vat( SalesAmount::parse( '99.99' ), 2500, true, 2 ) ) );
		self::assertSame( '79.9900', SalesAmount::format( DesignerRowAmounts::without_vat( SalesAmount::parse( '99.99' ), 2500, false, 2 ) ) );
		self::assertSame( '12.0400', SalesAmount::format( DesignerRowAmounts::without_vat( SalesAmount::parse( '15.05' ), 2500, false, 2 ) ) );
	}

	public function test_a_campaign_never_gives_more_than_the_units_profit(): void {
		// Paid above the list price (a price changed meanwhile): 250 × 50 / 200 = 62.50 is capped at 50.
		self::assertSame( '50.0000', SalesAmount::format( DesignerRowAmounts::profit( 1, SalesAmount::parse( '250' ), SalesAmount::parse( '150' ), SalesAmount::parse( '50' ), true, 2 ) ) );
		self::assertSame( '-50.0000', SalesAmount::format( DesignerRowAmounts::profit( -1, SalesAmount::parse( '-250' ), SalesAmount::parse( '150' ), SalesAmount::parse( '50' ), true, 2 ) ) );
	}

	public function test_invalid_inputs_are_refused(): void {
		$refused = 0;

		foreach ( array( array( -1, 100 ), array( 101, 100 ), array( 0, 0 ) ) as [ $profit, $price ] ) {
			try {
				DesignerRowAmounts::share_bps( $profit, $price );
			} catch ( InvalidArgumentException ) {
				++$refused;
			}
		}

		self::assertSame( 3, $refused, 'A negative profit, a profit above the price and no price are refused.' );

		$this->expectException( InvalidArgumentException::class );
		DesignerRowAmounts::profit( 1, 100, -1, 0, false, 2 );
	}
}
