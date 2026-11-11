<?php
/**
 * Tests for the gifting settings, the bot's texts and a gift row (ADR-107).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Community\Gifting\GiftDraws;
use MerchBoard\Modules\Community\Gifting\GiftingSettings;
use MerchBoard\Modules\Community\Gifting\GiftTexts;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( GiftingSettings::class )]
#[CoversClass( GiftTexts::class )]
#[CoversClass( Gift::class )]
#[CoversClass( GiftDraws::class )]
final class GiftingTest extends TestCase {

	public function test_nothing_saved_is_off_for_60_seconds_in_danish(): void {
		$settings = GiftingSettings::from_stored( '' );

		self::assertSame( array( false, false, 60, 'da' ), array( $settings->enabled, $settings->paused, $settings->window, $settings->language ) );
		self::assertFalse( $settings->open() );
	}

	public function test_stored_settings_are_read_and_anything_unreadable_falls_back(): void {
		$settings = GiftingSettings::from_stored(
			array(
				'enabled'  => true,
				'paused'   => false,
				'window'   => 90,
				'language' => 'en',
			)
		);

		self::assertTrue( $settings->open() );
		self::assertSame( 90, $settings->window );
		self::assertSame( 'en', $settings->language );
		self::assertFalse( $settings->test_mode );
		self::assertSame(
			array(
				'enabled'   => true,
				'paused'    => false,
				'window'    => 90,
				'language'  => 'en',
				'test_mode' => false,
				'confirm'   => true,
			),
			$settings->to_stored()
		);

		$odd = GiftingSettings::from_stored(
			array(
				'enabled'   => 'yes',
				'window'    => 500,
				'language'  => 'de',
				'test_mode' => 1,
			)
		);
		self::assertSame( array( false, 60, 'da', false ), array( $odd->enabled, $odd->window, $odd->language, $odd->test_mode ) );
	}

	public function test_the_test_mode_is_kept_by_a_pause_and_stored(): void {
		$test = GiftingSettings::from_stored(
			array(
				'enabled'   => true,
				'test_mode' => true,
			)
		);

		self::assertTrue( $test->test_mode );
		self::assertTrue( $test->with_paused( true )->test_mode, 'A pause from the chat keeps the test mode.' );
		self::assertTrue( $test->to_stored()['test_mode'] );
		self::assertFalse( $test->with_test_mode( false )->test_mode );
		self::assertTrue( $test->with_test_mode( false )->open(), 'The test mode does not change whether gifting is open.' );
	}

	public function test_the_confirmations_are_on_unless_switched_off(): void {
		self::assertTrue( GiftingSettings::defaults()->confirm );
		self::assertTrue( GiftingSettings::from_stored( array( 'enabled' => true ) )->confirm, 'Settings saved before the field: on.' );

		$off = GiftingSettings::from_stored( array( 'confirm' => false ) );
		self::assertFalse( $off->confirm );
		self::assertFalse( $off->with_paused( true )->confirm );
		self::assertFalse( $off->with_test_mode( true )->confirm );
	}

	public function test_the_chat_is_reminded_at_half_a_quarter_and_ten_seconds_left(): void {
		self::assertSame( array( 30, 15, 10 ), GiftDraws::reminders( 60 ) );
		self::assertSame( array( 90, 45, 10 ), GiftDraws::reminders( 180 ) );
		self::assertSame( array( 15, 10 ), GiftDraws::reminders( 30 ), 'No reminders 2 seconds apart.' );
		self::assertSame( array( 25, 10 ), GiftDraws::reminders( 50 ) );
		self::assertSame( array( 10, 5 ), GiftDraws::reminders( 20 ) );
		self::assertSame( '⏰ 15 seconds left! Type !enter to join the draw for Hoodie.', GiftTexts::reminder( 'en', 15, 'Hoodie' ) );
		self::assertSame( '⏰ 10 sekunder tilbage! Skriv !enter for at være med i lodtrækningen om Hoodie.', GiftTexts::reminder( 'da', 10, 'Hoodie' ) );
	}

	public function test_the_confirmation_fits_in_one_chat_message(): void {
		self::assertSame( '✅ Med i lodtrækningen om Hoodie: @anna @bo', GiftTexts::entered( 'da', 'Hoodie', array( 'anna', 'bo' ), array() ) );
		self::assertSame( 'Already in: @carl', GiftTexts::entered( 'en', 'Hoodie', array(), array( 'carl' ) ) );
		self::assertSame( '', GiftTexts::entered( 'da', 'Hoodie', array(), array() ) );
		self::assertSame( '⏳ Entries are closed! The winner of Hoodie is revealed in 10 seconds …', GiftTexts::closed( 'en', 'Hoodie', 10 ) );

		$many    = array_map( static fn( int $i ): string => sprintf( 'viewer_with_long_name%02d', $i ), range( 1, 40 ) );
		$message = GiftTexts::entered( 'da', 'Hoodie', $many, $many );
		self::assertLessThanOrEqual( 500, mb_strlen( $message ), 'Twitch allows 500 characters.' );
		self::assertMatchesRegularExpression( '/og \d+ flere · Allerede med: @viewer_with_long_name01 .* og \d+ flere$/', $message );
	}

	public function test_a_paused_gifting_is_not_open(): void {
		$paused = GiftingSettings::from_stored( array( 'enabled' => true ) )->with_paused( true );

		self::assertTrue( $paused->enabled );
		self::assertTrue( $paused->paused );
		self::assertFalse( $paused->open() );
		self::assertTrue( $paused->with_paused( false )->open() );
	}

	public function test_the_texts_follow_the_creators_language(): void {
		self::assertSame(
			'🎁 Mads har givet Stream tee! Skriv !enter inden for 60 sekunder for at være med i lodtrækningen.',
			GiftTexts::announce( 'da', 'Mads', 'Stream tee', 60 )
		);
		self::assertSame(
			'🎁 An anonymous viewer gifted Stream tee! Type !enter within 45 seconds to join the draw.',
			GiftTexts::announce( 'en', null, 'Stream tee', 45 )
		);
		self::assertSame(
			'🎉 @viewer1 vandt Stream tee! Hent gaven inden for 24 timer: https://shop.test/?merchboard_gift_claim=abc',
			GiftTexts::winner( 'da', 'viewer1', 'Stream tee', 'https://shop.test/?merchboard_gift_claim=abc' )
		);
		self::assertSame( 'Gifts are paused.', GiftTexts::text( 'en', 'paused' ) );
		self::assertSame( 'Gaver er i gang igen.', GiftTexts::text( 'xx', 'resumed' ), 'An unknown language is Danish.' );
		self::assertSame( '', GiftTexts::text( 'da', 'unknown' ) );
	}

	public function test_a_row_becomes_a_gift_with_its_entrants(): void {
		$gift = Gift::from_row(
			array(
				'id'               => '7',
				'creator_id'       => '2',
				'order_id'         => '1042',
				'product_id'       => '501',
				'unit_price'       => '249.0000',
				'shipping_credit'  => '50.0000',
				'currency'         => 'DKK',
				'buyer_name'       => null,
				'status'           => Gift::ENTERING,
				'draws'            => '1',
				'entrants'         => '{"111":"viewer1","222":"viewer2"}',
				'window_ends'      => '2026-11-10 20:16:00',
				'winner_twitch_id' => null,
				'winner_login'     => null,
				'won_at'           => null,
				'claim_deadline'   => null,
				'claim_token'      => null,
				'coupon_id'        => null,
				'claimed_order_id' => null,
				'created_at'       => '2026-11-10 20:15:00',
				'updated_at'       => '2026-11-10 20:15:00',
			)
		);

		self::assertSame( array( 7, 2, 1042, 501, 1 ), array( $gift->id, $gift->creator_id, $gift->order_id, $gift->product_id, $gift->draws ) );
		self::assertNull( $gift->buyer_name );
		self::assertSame(
			array(
				'111' => 'viewer1',
				'222' => 'viewer2',
			),
			$gift->entrants
		);
	}
}
