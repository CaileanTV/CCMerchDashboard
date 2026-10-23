<?php
/**
 * Tests for the setup of the creator's overlay settings page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use MerchBoard\Modules\Alerts\PageLayout\CreatorPageLayout;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreatorPageLayout::class )]
final class CreatorPageLayoutTest extends TestCase {

	public function test_the_default_is_the_page_as_before(): void {
		self::assertSame(
			array(
				'version'         => 1,
				'cards'           => array(
					'full'  => array( 'designs' ),
					'left'  => array( 'browser_source', 'sounds', 'alert_text', 'text_style' ),
					'right' => array( 'box_image', 'animations', 'timing' ),
				),
				'fields'          => CreatorPageLayout::FIELDS,
				'designs_compact' => false,
			),
			CreatorPageLayout::defaults()->to_array()
		);
		self::assertSame( CreatorPageLayout::defaults()->to_array(), CreatorPageLayout::from_array( 'nonsense' )->to_array() );
	}

	public function test_a_setup_keeps_its_order_and_is_always_complete(): void {
		$layout = CreatorPageLayout::from_array(
			array(
				'cards'           => array(
					'full'  => array( 'timing', 'unknown', 7 ),
					'left'  => array( 'designs', 'timing', 'images' ),
					'right' => array( 'alert_text', 'box_image' ),
				),
				'fields'          => array(
					'timing'     => array( 'anim_duration', 'anim_duration', 'nope' ),
					'alert_text' => 'not a list',
					'designs'    => array( 'x' ),
				),
				'designs_compact' => 'yes',
			)
		);

		self::assertSame(
			array(
				'full'  => array( 'timing' ),
				// Repeated and unknown cards are dropped (the images card always follows the animations card); missing ones come at their default place (zone and index).
				'left'  => array( 'browser_source', 'sounds', 'designs', 'text_style' ),
				'right' => array( 'alert_text', 'animations', 'box_image' ),
			),
			$layout->cards
		);
		self::assertSame( array( 'display_from_sound', 'display_duration', 'anim_duration' ), $layout->fields['timing'], 'Missing fields at their default index.' );
		self::assertSame( array( 'anim_duration', 'display_duration', 'display_from_sound' ), CreatorPageLayout::from_array( array( 'fields' => array( 'timing' => array( 'anim_duration', 'display_duration', 'display_from_sound' ) ) ) )->fields['timing'] );
		self::assertSame( CreatorPageLayout::FIELDS['alert_text'], $layout->fields['alert_text'] );
		self::assertSame( array_keys( CreatorPageLayout::FIELDS ), array_keys( $layout->fields ) );
		self::assertFalse( $layout->designs_compact, 'Only true switches it on.' );
		self::assertTrue( CreatorPageLayout::from_array( array( 'designs_compact' => true ) )->designs_compact );

		$cards = array_merge( ...array_values( $layout->cards ) );
		sort( $cards );
		self::assertSame( array( 'alert_text', 'animations', 'box_image', 'browser_source', 'designs', 'sounds', 'text_style', 'timing' ), $cards, 'Every card exactly once.' );
		self::assertSame( $layout->to_array(), CreatorPageLayout::from_array( $layout->to_array() )->to_array(), 'Stable.' );
	}
}
