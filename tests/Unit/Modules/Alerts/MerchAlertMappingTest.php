<?php
/**
 * Tests for the mapping of MerchAlert's data.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Alerts\Import\MerchAlertMapping;
use MerchBoard\Modules\Alerts\PaidOrder;
use MerchBoard\Modules\Alerts\PaidOrderLine;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( MerchAlertMapping::class )]
final class MerchAlertMappingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( string $text ): string => strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string|false => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stand-in.
	}

	public function test_settings_keep_merchalerts_values_and_the_layout_falls_back(): void {
		$settings = MerchAlertMapping::settings(
			array(
				'overlay_id'         => str_repeat( 'a', 32 ),
				'template'           => '$Product by $FirstName!',
				'font_size'          => '40',
				'audio_enabled'      => '1',
				'audio_category_map' => '{"12":34}',
				'layout'             => '{"elements":{"main_text":{"x":120,"y":900,"w":500,"h":70,"visible":true}}}',
			)
		)->to_array();

		self::assertSame( array( '$Product by $FirstName!', 40, true, array( 12 => 34 ), 120 ), array( $settings['template'], $settings['font_size'], $settings['audio_enabled'], $settings['audio_category_map'], $settings['layout']['elements']['main_text']['x'] ) );

		$fallback = MerchAlertMapping::settings( array( 'template' => 'Global' ), array( 'elements' => array( 'main_text' => array( 'x' => 300, 'y' => 900, 'w' => 600, 'h' => 80, 'visible' => true ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short fixture.
		self::assertSame( 300, $fallback->to_array()['layout']['elements']['main_text']['x'], 'The older layout setting.' );
	}

	public function test_only_sounds_the_overlay_may_keep_stay_chosen(): void {
		$settings = MerchAlertMapping::settings(
			array(
				'audio_default_sound' => '5',
				'audio_category_map'  => '{"12":6,"13":7}',
			)
		);

		[ $kept, $sounds ] = MerchAlertMapping::sounds( $settings, static fn( int $sound ): bool => 6 === $sound );
		$values            = $kept->to_array();

		self::assertSame( array( 0, array( 12 => 6 ), array( 6 ) ), array( $values['audio_default_sound'], $values['audio_category_map'], $sounds ) );
	}

	public function test_the_chat_template_never_uses_the_first_name(): void {
		self::assertSame( '🔔 $UserName just bought $Product! ($UserName)', MerchAlertMapping::chat_template( ' 🔔 $Name just bought $Product! ($UserName) ' ) );
		self::assertSame( '$Names stay', MerchAlertMapping::chat_template( '$Names stay' ), 'Only the variable.' );
		self::assertSame( 'Thanks for $Total!', MerchAlertMapping::chat_template( 'Thanks for $Amount!' ), 'MerchAlert\'s amount is the creator\'s total.' );
		self::assertSame( '', MerchAlertMapping::chat_template( '' ), 'Empty: the default.' );
	}

	public function test_an_alert_keeps_its_time_and_takes_the_username_and_variation_from_the_order(): void {
		$row   = array(
			'order_id'      => 900,
			'order_number'  => '',
			'order_item_id' => 9001,
			'quantity'      => 0,
			'product_id'    => 70,
			'variation_id'  => 71,
			'product_name'  => 'Hoodie',
			'image_id'      => 0,
			'category_ids'  => array( 501 ),
			'created_at'    => '2026-01-02 10:00:00',
		);
		$order = new PaidOrder( 900, '#900', 'StreamFan', array( new PaidOrderLine( 9001, 70, 71, 'Hoodie', 'Size: L', 1, 0 ) ) );
		$event = MerchAlertMapping::event( $row, 7, $order );

		self::assertNotNull( $event );
		self::assertSame( array( 7, '900', 'StreamFan', 'Size: L', 1, '2026-01-02 10:00:00' ), array( $event->creator_id, $event->order_number, $event->customer_username, $event->variation, $event->quantity, $event->created_at->format( 'Y-m-d H:i:s' ) ) );
		self::assertSame( '', MerchAlertMapping::event( $row, 7, null )?->customer_username, 'A deleted order: no username.' );
		self::assertNull( MerchAlertMapping::event( array_merge( $row, array( 'order_item_id' => 0 ) ), 7, null ) );
	}
}
