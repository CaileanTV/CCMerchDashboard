<?php
/**
 * Tests for the Twitch subscriber discount's campaigns and coupon math (ADR-106).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCampaign;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCoupon;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountSettings;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( TwitchSubDiscountSettings::class )]
#[CoversClass( TwitchSubCampaign::class )]
#[CoversClass( TwitchSubCoupon::class )]
final class TwitchSubDiscountSettingsTest extends TestCase {

	public function test_nothing_saved_has_no_campaigns_and_gifts_count(): void {
		$settings = TwitchSubDiscountSettings::from_stored( '' );

		self::assertSame( array(), $settings->campaigns );
		self::assertTrue( $settings->counts( true ), 'Gifted subscriptions count by default.' );
		self::assertNull( $settings->campaign_for( 7, '2026-11-10' ) );
	}

	public function test_a_selection_goes_before_all_products(): void {
		$settings = new TwitchSubDiscountSettings(
			false,
			array(
				self::campaign( 'aaaaaaaaaaaaaaaa', TwitchSubCampaign::ALL, array(), 1000 ),
				self::campaign( 'bbbbbbbbbbbbbbbb', TwitchSubCampaign::SELECTED, array( 7 ), 2000 ),
			)
		);

		self::assertSame( 'bbbbbbbbbbbbbbbb', $settings->campaign_for( 7, '2026-11-10' )?->id );
		self::assertSame( 'aaaaaaaaaaaaaaaa', $settings->campaign_for( 8, '2026-11-10' )?->id );
		self::assertFalse( $settings->counts( true ), 'A gift does not count when the creator says so.' );
		self::assertTrue( $settings->counts( false ) );
	}

	public function test_a_campaign_runs_in_its_period_only(): void {
		$weekend = self::campaign( 'cccccccccccccccc', TwitchSubCampaign::SELECTED, array( 7 ), 1500, '2026-11-14', '2026-11-15' );

		self::assertFalse( $weekend->active( '2026-11-13' ) );
		self::assertTrue( $weekend->active( '2026-11-14' ) );
		self::assertTrue( $weekend->active( '2026-11-15' ), 'The last day counts.' );
		self::assertFalse( $weekend->active( '2026-11-16' ) );
		self::assertSame( 1500, $weekend->bps( 2 ) );
		self::assertSame( 0, $weekend->bps( 0 ) );
	}

	public function test_periods_overlap_only_when_they_meet(): void {
		$november = self::campaign( 'dddddddddddddddd', TwitchSubCampaign::ALL, array(), 1000, '2026-11-01', '2026-11-30' );
		$december = self::campaign( 'eeeeeeeeeeeeeeee', TwitchSubCampaign::ALL, array(), 1000, '2026-12-01', null );
		$always   = self::campaign( 'ffffffffffffffff', TwitchSubCampaign::ALL, array(), 1000 );

		self::assertFalse( $november->overlaps( $december ) );
		self::assertTrue( $always->overlaps( $november ) );
		self::assertTrue( $december->overlaps( $always ) );
	}

	public function test_stored_campaigns_round_trip_and_unreadable_ones_are_left_out(): void {
		$settings = new TwitchSubDiscountSettings( true, array( self::campaign( 'abcdefabcdefabcd', TwitchSubCampaign::SELECTED, array( 7, 9 ), 1000, '2026-11-01', null ) ) );
		$stored   = $settings->to_stored();

		self::assertEquals( $settings, TwitchSubDiscountSettings::from_stored( $stored ), 'Round trip.' );

		$stored['campaigns'][] = array(
			'id'    => 'not-an-id',
			'tiers' => array(
				1 => 1050,
				2 => 0,
				3 => 0,
			),
		);
		self::assertCount( 1, TwitchSubDiscountSettings::from_stored( $stored )->campaigns );
	}

	public function test_best_discount_wins_per_line(): void {
		// Two units with 20.00 subscriber discount each.
		self::assertSame( 40.0, TwitchSubCoupon::top_up( 2, 20.0, 0.0, 2 ), 'No other coupon: the whole discount.' );
		self::assertSame( 10.0, TwitchSubCoupon::top_up( 2, 20.0, 30.0, 2 ), 'A smaller coupon is topped up.' );
		self::assertSame( 0.0, TwitchSubCoupon::top_up( 2, 20.0, 50.0, 2 ), 'A bigger coupon wins; nothing is added.' );
		self::assertSame( 3.33, TwitchSubCoupon::top_up( 1, 3.333, 0.0, 2 ), 'Rounded to the store precision.' );
	}

	public function test_the_discount_is_stored_in_the_paid_basis(): void {
		self::assertSame( '20.0000', TwitchSubCoupon::in_paid_basis( 20.0, 160.0, 40.0, true ), 'Prices incl. VAT: as discounted.' );
		self::assertSame( '25.0000', TwitchSubCoupon::in_paid_basis( 20.0, 160.0, 40.0, false ), 'Prices excl. VAT: plus the VAT.' );
		self::assertSame( '20.0000', TwitchSubCoupon::in_paid_basis( 20.0, 160.0, 0.0, true ), 'Sold without VAT.' );
	}

	/**
	 * A campaign that is on, with the same percent for every tier.
	 *
	 * @phpstan-param list<int> $products
	 *
	 * @param string      $id       Id.
	 * @param string      $scope    Scope.
	 * @param int[]       $products Products.
	 * @param int         $bps      Basis points per tier.
	 * @param string|null $starts   First day.
	 * @param string|null $ends     Last day.
	 */
	private static function campaign( string $id, string $scope, array $products, int $bps, ?string $starts = null, ?string $ends = null ): TwitchSubCampaign {
		return new TwitchSubCampaign(
			$id,
			'Kampagne',
			true,
			array(
				1 => $bps,
				2 => $bps,
				3 => $bps,
			),
			$scope,
			$products,
			$starts,
			$ends
		);
	}
}
