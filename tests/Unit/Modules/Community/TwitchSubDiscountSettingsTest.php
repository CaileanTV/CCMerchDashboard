<?php
/**
 * Tests for the Twitch subscriber discount's settings and coupon math (ADR-106).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCoupon;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountSettings;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( TwitchSubDiscountSettings::class )]
#[CoversClass( TwitchSubCoupon::class )]
final class TwitchSubDiscountSettingsTest extends TestCase {

	public function test_nothing_saved_is_off(): void {
		$settings = TwitchSubDiscountSettings::from_stored( '' );

		self::assertFalse( $settings->enabled );
		self::assertFalse( $settings->active() );
		self::assertSame( 0, $settings->bps_for( 1, false ) );
		self::assertTrue( $settings->gifted, 'Gifted subscriptions count by default.' );
	}

	public function test_stored_settings_round_trip_and_rates_follow_the_choices(): void {
		$stored   = array(
			'enabled'     => true,
			'tiers'       => array(
				1 => 1000,
				2 => 1500,
				3 => 2000,
			),
			'gifted'      => false,
			'scope'       => TwitchSubDiscountSettings::SELECTED,
			'product_ids' => array( 7, 9, 7, -1 ),
		);
		$settings = TwitchSubDiscountSettings::from_stored( $stored );

		self::assertTrue( $settings->active() );
		self::assertSame( array( 7, 9 ), $settings->product_ids );
		self::assertSame( 1500, $settings->bps_for( 2, false ) );
		self::assertSame( 0, $settings->bps_for( 2, true ), 'A gift does not count when the creator says so.' );
		self::assertSame( 0, $settings->bps_for( 0, false ), 'No subscription.' );
		self::assertTrue( $settings->covers( 9 ) );
		self::assertFalse( $settings->covers( 8 ) );
		self::assertSame( 20, $settings->highest_percent() );
		self::assertEquals( $settings, TwitchSubDiscountSettings::from_stored( $settings->to_stored() ), 'Round trip.' );
	}

	public function test_unreadable_settings_fall_back_to_off(): void {
		$broken = array(
			'enabled' => true,
			'tiers'   => array(
				1 => 1050,
				2 => 0,
				3 => 0,
			),
			'scope'   => TwitchSubDiscountSettings::ALL,
		);

		self::assertFalse( TwitchSubDiscountSettings::from_stored( $broken )->enabled, 'Only whole percents.' );
		self::assertFalse( TwitchSubDiscountSettings::from_stored( array( 'enabled' => true ) )->enabled );
	}

	public function test_a_selection_without_products_offers_nothing(): void {
		$settings = new TwitchSubDiscountSettings(
			true,
			array(
				1 => 1000,
				2 => 1000,
				3 => 1000,
			),
			true,
			TwitchSubDiscountSettings::SELECTED,
			array()
		);

		self::assertFalse( $settings->active() );
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
}
