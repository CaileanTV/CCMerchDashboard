<?php
/**
 * Tests for the company's campaigns (ADR-080 pkt. 9).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Modules\ProductDesigner\Campaigns\Campaign;
use MerchBoard\Modules\ProductDesigner\Campaigns\CampaignService;
use MerchBoard\Modules\ProductDesigner\Campaigns\DesignerCampaigns;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPrice;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricing;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Campaign::class )]
#[CoversClass( DesignerCampaigns::class )]
#[CoversClass( CampaignService::class )]
final class CampaignTest extends TestCase {

	public function test_a_campaign_covers_its_codes_or_sale_prices_within_its_period_only(): void {
		$codes = new Campaign( 1, 'Black Friday', self::at( '2026-11-27 00:00:00' ), self::at( '2026-11-30 00:00:00' ), array( 'bf20' ), false );
		$sales = new Campaign( 2, 'Sale', self::at( '2026-11-27 00:00:00' ), self::at( '2026-11-30 00:00:00' ), array(), true );

		self::assertTrue( $codes->covers( self::at( '2026-11-27 00:00:00' ), array( 'other', 'bf20' ), false ), 'From the start, inclusive.' );
		self::assertFalse( $codes->covers( self::at( '2026-11-30 00:00:00' ), array( 'bf20' ), false ), 'Until the end, exclusive.' );
		self::assertFalse( $codes->covers( self::at( '2026-11-26 23:59:59' ), array( 'bf20' ), false ) );
		self::assertFalse( $codes->covers( self::at( '2026-11-28 12:00:00' ), array( 'other' ), true ), 'A sale price is not covered by a code-only campaign.' );
		self::assertTrue( $sales->covers( self::at( '2026-11-28 12:00:00' ), array(), true ) );
		self::assertFalse( $sales->covers( self::at( '2026-11-28 12:00:00' ), array( 'bf20' ), false ), 'No sale price, no code of the campaign.' );
	}

	public function test_invalid_campaigns_are_refused(): void {
		$start   = self::at( '2026-11-27 00:00:00' );
		$end     = self::at( '2026-11-30 00:00:00' );
		$invalid = array(
			'no name'          => static fn() => new Campaign( null, '', $start, $end, array( 'a' ), false ),
			'end before start' => static fn() => new Campaign( null, 'X', $end, $start, array( 'a' ), false ),
			'empty period'     => static fn() => new Campaign( null, 'X', $start, $start, array( 'a' ), false ),
			'covers nothing'   => static fn() => new Campaign( null, 'X', $start, $end, array(), false ),
			'duplicate codes'  => static fn() => new Campaign( null, 'X', $start, $end, array( 'a', 'a' ), false ),
			'empty code'       => static fn() => new Campaign( null, 'X', $start, $end, array( '' ), false ),
			'too many codes'   => static fn() => new Campaign( null, 'X', $start, $end, array_map( 'strval', range( 1, Campaign::MAX_CODES + 1 ) ), false ),
			'invalid id'       => static fn() => new Campaign( 0, 'X', $start, $end, array( 'a' ), false ),
			'name too long'    => static fn() => new Campaign( null, str_repeat( 'x', 192 ), $start, $end, array( 'a' ), false ),
		);

		foreach ( $invalid as $label => $factory ) {
			try {
				$factory();
				self::fail( 'Expected an exception: ' . $label );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_codes_are_split_normalized_and_unique(): void {
		self::assertSame( array( 'bf20', 'summer', 'x-1' ), CampaignService::parse_codes( " BF20,summer\n\nx-1 ; bf20  SUMMER " ) );
		self::assertSame( array(), CampaignService::parse_codes( " ,\n " ) );
	}

	public function test_a_line_is_on_sale_when_sold_at_least_one_step_below_its_list_price(): void {
		$snapshot = SalesLinePricing::capture_designer( 501, 900, 20, 0, 'DKK', new DesignerPrice( 7, SalesAmount::parse( '150' ), SalesAmount::parse( '50' ), 'DKK' ), 2, false, self::at( '2026-11-28 12:00:00' ) );

		self::assertFalse( DesignerCampaigns::on_sale( self::line( 2, '400' ), $snapshot ), 'The list price.' );
		self::assertFalse( DesignerCampaigns::on_sale( self::line( 2, '399.995' ), $snapshot ), 'Less than one store step: rounding.' );
		self::assertTrue( DesignerCampaigns::on_sale( self::line( 2, '399.99' ), $snapshot ), 'One store step below.' );
		self::assertTrue( DesignerCampaigns::on_sale( self::line( 1, '160' ), $snapshot ), 'A sale price of 160.' );
		self::assertFalse( DesignerCampaigns::on_sale( self::line( 1, null ), $snapshot ), 'Unknown subtotal: never a sale price.' );
	}

	/**
	 * A line of order 900 with a subtotal incl. VAT.
	 *
	 * @param int         $quantity Quantity.
	 * @param string|null $subtotal Subtotal before coupons incl. VAT; null when unknown.
	 */
	private static function line( int $quantity, ?string $subtotal ): OrderLineSnapshot {
		return new OrderLineSnapshot( 501, 20, 0, 'Tee', $quantity, 0, 7, 0, null === $subtotal ? null : SalesAmount::parse( $subtotal ) );
	}

	/**
	 * A UTC time.
	 *
	 * @param string $time MySQL datetime.
	 */
	private static function at( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}
}
