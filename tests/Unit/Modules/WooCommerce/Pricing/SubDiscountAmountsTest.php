<?php
/**
 * Tests for the profit of rows with a Twitch subscriber discount (ADR-106 pkt. 7).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorShare;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerRowAmounts;
use MerchBoard\Modules\WooCommerce\Pricing\SubDiscountAmounts;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( SubDiscountAmounts::class )]
final class SubDiscountAmountsTest extends TestCase {

	/**
	 * A share product at 25 % (list price 200, profit 50): paid, discount,
	 * expected profit.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function share_examples(): array {
		return array(
			'no discount'                               => array( '200.00', '0.00', '50.0000' ),
			'10 % subscriber discount: creator pays 20' => array( '180.00', '20.00', '30.0000' ),
			'discount equal to the profit'              => array( '150.00', '50.00', '0.0000' ),
			'discount above the profit: never negative' => array( '140.00', '60.00', '0.0000' ),
			'on top of a sale price of 160'             => array( '144.00', '16.00', '24.0000' ),
			// The company coupon (10) is shared as before (2.50), the top-up (10) is the creator's.
			'filled up after a 5 % company coupon'      => array( '180.00', '10.00', '37.5000' ),
		);
	}

	/**
	 * The creator pays the whole discount; the company's share of the price
	 * before the discount is unchanged.
	 *
	 * @param string $paid     Paid incl. VAT.
	 * @param string $discount The subscriber discount the row carries.
	 * @param string $expected Expected profit.
	 */
	#[DataProvider( 'share_examples' )]
	public function test_share_product_creator_pays_the_discount( string $paid, string $discount, string $expected ): void {
		$profit = SubDiscountAmounts::sale( SalesAmount::parse( $paid ), SalesAmount::parse( $discount ), static fn( int $units ): int => CreatorShare::profit( $units, 2500, 2 ) );

		$this->assertSame( $expected, SalesAmount::format( $profit ) );
	}

	/**
	 * Designer product, base 150 and profit 50: outside a campaign the rule
	 * already takes discounts from the profit, so the result is the same
	 * with and without the subscriber rule; in a campaign the creator pays
	 * the subscriber discount and shares only the campaign's.
	 */
	public function test_designer_product_in_and_outside_a_campaign(): void {
		$outside = static fn( int $units ): int => DesignerRowAmounts::profit( 1, $units, SalesAmount::parse( '150' ), SalesAmount::parse( '50' ), false, 2 );
		$inside  = static fn( int $units ): int => DesignerRowAmounts::profit( 1, $units, SalesAmount::parse( '150' ), SalesAmount::parse( '50' ), true, 2 );

		$this->assertSame( '30.0000', SalesAmount::format( SubDiscountAmounts::sale( SalesAmount::parse( '180' ), SalesAmount::parse( '20' ), $outside ) ) );
		$this->assertSame( SalesAmount::format( $outside( SalesAmount::parse( '180' ) ) ), SalesAmount::format( SubDiscountAmounts::sale( SalesAmount::parse( '180' ), SalesAmount::parse( '20' ), $outside ) ) );

		// Campaign price 180 (shared: 45), then a subscriber discount of 18: the creator pays the 18.
		$this->assertSame( '27.0000', SalesAmount::format( SubDiscountAmounts::sale( SalesAmount::parse( '162' ), SalesAmount::parse( '18' ), $inside ) ) );
	}

	/**
	 * The line's rows carry the discount signed: the first sale all of it, a
	 * refund its share back, a sale after a reversal all of it again, and a
	 * row after the discount was taken off the order gives it back.
	 */
	public function test_carried(): void {
		$line = SalesAmount::parse( '20' );
		$paid = SalesAmount::parse( '180' );

		$this->assertSame( $line, SubDiscountAmounts::carried( $paid, $line, 0, 0 ) );
		$this->assertSame( 0, SubDiscountAmounts::carried( SalesAmount::parse( '15' ), $line, $line, $paid ), 'A correction keeps the discount.' );
		$this->assertSame( SalesAmount::parse( '-10' ), SubDiscountAmounts::carried( SalesAmount::parse( '-90' ), $line, $line, $paid ), 'Half refunded: half the discount back.' );
		$this->assertSame( -$line, SubDiscountAmounts::carried( -$paid, $line, $line, $paid ), 'A reversal takes all of it back.' );
		$this->assertSame( $line, SubDiscountAmounts::carried( $paid, $line, 0, 0 ), 'Paid again after the reversal: charged again.' );
		$this->assertSame( -$line, SubDiscountAmounts::carried( $line, null, $line, $paid ), 'The discount taken off the order: given back.' );
		$this->assertSame( SalesAmount::parse( '-5' ), SubDiscountAmounts::carried( SalesAmount::parse( '5' ), null, $line, $paid ), 'Never more than the row paid.' );
		$this->assertSame( 0, SubDiscountAmounts::carried( 0, $line, 0, 0 ) );
	}

	/**
	 * A discount given back on a later row: the line ends where a line
	 * without the discount would be.
	 */
	public function test_a_discount_given_back_restores_the_share(): void {
		$share = static fn( int $units ): int => CreatorShare::profit( $units, 2500, 2 );
		$first = SubDiscountAmounts::sale( SalesAmount::parse( '180' ), SalesAmount::parse( '20' ), $share );
		$back  = SubDiscountAmounts::sale( SalesAmount::parse( '20' ), SalesAmount::parse( '-20' ), $share );

		$this->assertSame( '30.0000', SalesAmount::format( $first ) );
		$this->assertSame( $share( SalesAmount::parse( '200' ) ), SalesAmount::add( $first, $back ) );
	}

	/**
	 * A refund of a subscriber-discounted share line takes back its profit
	 * in proportion and ends at 0 when refunded in full, in parts too.
	 */
	public function test_refunds_end_at_zero(): void {
		$sold   = SalesAmount::parse( '180' );
		$profit = SubDiscountAmounts::sale( $sold, SalesAmount::parse( '20' ), static fn( int $units ): int => CreatorShare::profit( $units, 2500, 2 ) );
		$first  = DesignerRowAmounts::refund( SalesAmount::parse( '-60' ), $sold, $profit, 0, 0, 2 );
		$second = DesignerRowAmounts::refund( SalesAmount::parse( '-120' ), $sold, $profit, SalesAmount::parse( '-60' ), $first, 2 );

		$this->assertSame( '-10.0000', SalesAmount::format( $first ) );
		$this->assertSame( 0, SalesAmount::add( $profit, SalesAmount::add( $first, $second ) ) );
	}

	/**
	 * Negative amounts are refused.
	 */
	public function test_negative_amounts_are_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		SubDiscountAmounts::sale( 100, -101, static fn( int $units ): int => $units );
	}
}
