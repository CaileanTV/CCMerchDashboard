<?php
/**
 * Tests for the overlay settings of alert designer 2.0 (ADR-088).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Tts\TtsVoices;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( OverlaySettings::class )]
#[CoversClass( TtsVoices::class )]
final class OverlaySettingsV2Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( string $text ): string => strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( strip_tags( $text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string|false => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stand-in.
	}

	public function test_settings_from_before_get_the_box_around_their_elements_and_the_free_layout(): void {
		$values = OverlaySettings::from_array(
			array(
				'template' => 'Hi',
				'layout'   => array(
					'elements' => array(
						'product_image' => array(
							'x' => 100,
							'y' => 200,
							'w' => 120,
							'h' => 120,
						),
						'main_text'     => array(
							'x' => 240,
							'y' => 210,
							'w' => 600,
							'h' => 80,
						),
					),
				),
			)
		)->to_array();

		self::assertSame( 'free', $values['layout_mode'], 'Elements keep their own boxes.' );
		self::assertSame(
			array(
				'x'        => 100,
				'y'        => 200,
				'w'        => 740,
				'h'        => 120,
				'opacity'  => 100,
				'rotation' => 0,
			),
			$values['widget'],
			'The hidden secondary text is not part of the box.'
		);
		self::assertSame( 100, $values['layout']['elements']['product_image']['x'], 'Elements stay in canvas coordinates.' );
	}

	public function test_text_around_the_image_widens_the_box_by_its_margin(): void {
		$widget = OverlaySettings::from_array(
			array(
				'font_size'  => 20,
				'text_frame' => array( 'enabled' => true ),
			)
		)->to_array()['widget'];

		// The default image (40, 880, 120 × 120) with 24 px (1.2 × 20) round it.
		self::assertSame( 16, $widget['x'] );
		self::assertSame( 856, $widget['y'] );
		self::assertSame( 168, $widget['w'] );
	}

	public function test_a_stored_box_is_kept_within_bounds(): void {
		$values = OverlaySettings::from_array(
			array(
				'widget'      => array(
					'x'        => 50,
					'y'        => 60,
					'w'        => 5,
					'h'        => 400,
					'opacity'  => 140,
					'rotation' => -720,
				),
				'layout_mode' => 'above',
			)
		)->to_array();

		self::assertSame(
			array(
				'x'        => 50,
				'y'        => 60,
				'w'        => 10,
				'h'        => 400,
				'opacity'  => 100,
				'rotation' => -180,
			),
			$values['widget']
		);
		self::assertSame( 'top', $values['layout_mode'], '"above" before ADR-109.' );
		self::assertSame( 'free', OverlaySettings::from_array( array( 'layout_mode' => 'grid' ) )->to_array()['layout_mode'] );

		// ADR-109: the five layouts; the names before it are read as the layout that looks the same.
		foreach ( array(
			'top'        => 'top',
			'left'       => 'left',
			'right'      => 'right',
			'background' => 'background',
			'free'       => 'free',
			'above'      => 'top',
			'side'       => 'left',
			'overlay'    => 'background',
		) as $mode => $read ) {
			self::assertSame( $read, OverlaySettings::from_array( array( 'layout_mode' => $mode ) )->to_array()['layout_mode'] );
		}
	}

	public function test_new_text_styles_have_defaults_and_bounds(): void {
		$defaults = OverlaySettings::defaults()->to_array();

		self::assertSame( 100, $defaults['text_opacity'] );
		self::assertSame( 130, $defaults['line_height'], 'The overlay\'s line height before 2.0.' );
		self::assertSame( 'ltr', $defaults['text_direction'] );
		self::assertSame( 'none', $defaults['text_case'] );
		self::assertSame( 0, $defaults['stroke_width'] );
		self::assertTrue( $defaults['text_shadow_enabled'], 'The default has a shadow.' );
		self::assertFalse( $defaults['highlight']['enabled'] );
		self::assertSame( 'shop', $defaults['number_format'] );
		self::assertSame( 100, $defaults['audio_volume'] );
		self::assertFalse( $defaults['tts']['enabled'] );
		self::assertSame( TtsVoices::DEFAULT, $defaults['tts']['voice'] );

		$values = OverlaySettings::from_array(
			array(
				'letter_spacing'   => 500,
				'text_case'        => 'shout',
				'text_align'       => 'justify',
				'stroke_color'     => 'red',
				'italic'           => 'yes',
				'display_duration' => 120000,
				'max_name_length'  => -4,
				'margin'           => array( 'top' => -900 ),
				'highlight'        => array(
					'enabled' => true,
					'effect'  => 'explode',
				),
				'tts'              => array(
					'enabled' => true,
					'voice'   => 'xx-Robot',
					'delay'   => 99999,
				),
				'text_shadow'      => '',
			)
		)->to_array();

		self::assertSame( 100, $values['letter_spacing'] );
		self::assertSame( 'none', $values['text_case'] );
		self::assertSame( 'justify', $values['text_align'] );
		self::assertSame( '#000000', $values['stroke_color'] );
		self::assertTrue( $values['italic'] );
		self::assertSame( 90000, $values['display_duration'], 'At most 90 s.' );
		self::assertSame( 0, $values['max_name_length'] );
		self::assertSame( -500, $values['margin']['top'] );
		self::assertSame( 'none', $values['highlight']['effect'] );
		self::assertSame( TtsVoices::DEFAULT, $values['tts']['voice'] );
		self::assertSame( 10000, $values['tts']['delay'] );
		self::assertFalse( $values['text_shadow_enabled'], 'Without a shadow the switch is off.' );
	}

	public function test_animations_of_before_get_the_box_fade_of_their_duration(): void {
		$animations = OverlaySettings::from_array(
			array(
				'anim_duration' => 300,
				'animations'    => array(
					'main_text' => array( 'in' => 'wave' ),
					'alert'     => array(
						'in'    => 'zoom',
						'out'   => 'spin',
						'in_ms' => 800,
					),
				),
			)
		)->animations();

		self::assertSame( 'zoom', $animations['alert']['in'] );
		self::assertSame( 'rise', $animations['alert']['out'], 'Unknown animations fall back to the fade and rise of before.' );
		self::assertSame( 800, $animations['alert']['in_ms'] );
		self::assertSame( 300, $animations['alert']['out_ms'] );
		self::assertSame( 300, $animations['main_text']['in_ms'] );
	}

	public function test_variations_are_normalized_on_top_of_the_default(): void {
		$settings = OverlaySettings::from_array(
			array(
				'font_size'  => 40,
				'variations' => array(
					array(
						'id'         => 'abc123',
						'name'       => '<b>Big</b> buyers',
						'conditions' => array(
							'min_amount' => '500',
							'categories' => array( 5, '5', 0, 'x', 6 ),
						),
						'look'       => array(
							'template' => 'Wow $UserName',
							'widget'   => array( 'x' => 999 ),
						),
					),
					array(
						'id'   => 'abc123',
						'name' => 'Same id',
					),
					'nonsense',
					array( 'name' => 'Without id' ),
				),
			)
		);

		$variations = $settings->variations();

		self::assertCount( 2, $variations, 'A repeated id and a non-array are dropped.' );
		self::assertSame( 'abc123', $variations[0]['id'] );
		self::assertSame( 'Big buyers', $variations[0]['name'] );
		self::assertTrue( $variations[0]['enabled'] );
		self::assertSame( 500, $variations[0]['conditions']['min_amount'] );
		self::assertSame( array( 5, 6 ), $variations[0]['conditions']['categories'] );
		self::assertSame( 'Wow $UserName', $variations[0]['look']['template'] );
		self::assertSame( 40, $variations[0]['look']['font_size'], 'A missing key is the default\'s.' );
		self::assertArrayNotHasKey( 'widget', $variations[0]['look'], 'The box is the overlay\'s.' );
		self::assertSame( OverlaySettings::LOOK_KEYS, array_keys( $variations[0]['look'] ) );
		self::assertMatchesRegularExpression( '/^v[0-9a-f]{12}$/', $variations[1]['id'] );
	}

	public function test_at_most_ten_variations_are_kept(): void {
		$many = array();

		for ( $i = 0; $i < 14; $i++ ) {
			$many[] = array( 'id' => 'var' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) );
		}

		self::assertCount( OverlaySettings::MAX_VARIATIONS, OverlaySettings::from_array( array( 'variations' => $many ) )->variations() );
	}

	public function test_a_variations_look_keeps_the_overlays_box_and_grid(): void {
		$settings = OverlaySettings::from_array(
			array(
				'widget'     => array(
					'x' => 10,
					'y' => 20,
					'w' => 300,
					'h' => 200,
				),
				'layout'     => array( 'canvas' => array( 'snap_grid' => true ) ),
				'variations' => array(
					array(
						'id'   => 'big001',
						'look' => array(
							'template' => 'Big!',
							'layout'   => array( 'canvas' => array( 'snap_grid' => false ) ),
						),
					),
				),
			)
		);

		$look = $settings->look( 'big001' )->to_array();

		self::assertSame( 'Big!', $look['template'] );
		self::assertSame( 300, $look['widget']['w'] );
		self::assertTrue( $look['layout']['canvas']['snap_grid'] );
		self::assertSame( $settings, $settings->look( '' ) );
		self::assertSame( $settings, $settings->look( 'unknown' ) );
	}

	public function test_matching_variations_with_conditions_win_otherwise_the_open_ones_share(): void {
		$settings = OverlaySettings::from_array(
			array(
				'variations' => array(
					array(
						'id'         => 'big001',
						'conditions' => array( 'min_amount' => 400 ),
					),
					array(
						'id'         => 'hood01',
						'conditions' => array( 'categories' => array( 5 ) ),
					),
					array(
						'id'         => 'off001',
						'enabled'    => false,
						'conditions' => array( 'min_quantity' => 2 ),
					),
					array( 'id' => 'open01' ),
				),
			)
		);

		$chosen = array();

		for ( $id = 1; $id <= 40; $id++ ) {
			$chosen[] = $settings->choose( self::event( $id, '500.0000', array( 9 ), 3 ) );
		}

		self::assertSame( array( 'big001' ), array_values( array_unique( $chosen ) ), 'Only the matching variation with conditions; the switched-off one never.' );

		$chosen = array();

		for ( $id = 1; $id <= 40; $id++ ) {
			$chosen[] = $settings->choose( self::event( $id, '100.0000', array( 9 ), 1 ) );
		}

		$open = array_values( array_unique( $chosen ) );
		sort( $open );

		self::assertSame( array( '', 'open01' ), $open, 'The default and the variation without conditions share.' );
		self::assertSame( $settings->choose( self::event( 7, '100.0000', array( 9 ), 1 ) ), $settings->choose( self::event( 7, '100.0000', array( 9 ), 1 ) ), 'The same alert gets the same choice.' );
		self::assertSame( 'hood01', $settings->choose( self::event( 3, null, array( 5 ), 1 ) ), 'Without an amount only the category matches.' );
	}

	public function test_fills_and_fonts_are_cleaned_in_every_variation(): void {
		$image    = array(
			'type'  => 'image',
			'image' => 42,
		);
		$settings = OverlaySettings::from_array(
			array(
				'font_family' => 'Gone Font',
				'text_fills'  => array( 'main_text' => $image ),
				'variations'  => array(
					array(
						'id'   => 'var001',
						'look' => array(
							'font_family' => 'Roboto',
							'text_fills'  => array( 'secondary_text' => $image ),
						),
					),
				),
			)
		)->with_fills( static fn( array $fills ): array => OverlaySettings::fills_without( $fills, 42 ) )
			->with_fonts( static fn( string $family ): bool => 'Roboto' === $family );

		$values = $settings->to_array();

		self::assertSame( 'none', $values['text_fills']['main_text']['type'] );
		self::assertSame( 'none', $values['variations'][0]['look']['text_fills']['secondary_text']['type'] );
		self::assertSame( 'inherit', $values['font_family'] );
		self::assertSame( 'Roboto', $values['variations'][0]['look']['font_family'] );
	}

	/**
	 * An alert.
	 *
	 * @param int         $id         Alert id.
	 * @param string|null $line_total Line total.
	 * @param int[]       $categories Category ids.
	 * @param int         $quantity   Quantity.
	 */
	private static function event( int $id, ?string $line_total, array $categories, int $quantity ): AlertEvent {
		return new AlertEvent( 1, 7, '7', 70 + $id, 10, 0, 'Shirt', '', $quantity, 0, array_values( $categories ), 'fan', new DateTimeImmutable( '2026-11-03 12:00:00' ), $id, $line_total, $line_total, 'DKK' );
	}
}
