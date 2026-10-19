<?php
/**
 * Tests for the overlay settings.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( OverlaySettings::class )]
#[CoversClass( OverlayId::class )]
final class OverlaySettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( string $text ): string => strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string|false => json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stand-in.
	}

	public function test_defaults_are_merchalerts(): void {
		$values = OverlaySettings::defaults()->to_array();

		self::assertSame( '$Product was purchased by $UserName!', $values['template'] );
		self::assertSame( '', $values['secondary_template'] );
		self::assertSame( 'inherit', $values['font_family'] );
		self::assertSame( 32, $values['font_size'] );
		self::assertSame( '700', $values['font_weight'] );
		self::assertSame( '#ffffff', $values['text_color'] );
		self::assertSame( '2px 2px 4px rgba(0,0,0,0.8)', $values['text_shadow'] );
		self::assertSame( 'center', $values['text_align'] );
		self::assertSame( 'fixed', $values['text_fit'], 'Fixed text size, as in MerchAlert.' );
		self::assertSame( 120, $values['image_size'] );
		self::assertSame( 80, $values['bg_opacity'] );
		self::assertSame( 500, $values['anim_duration'] );
		self::assertSame( 5000, $values['display_duration'] );
		self::assertFalse( $values['audio_enabled'] );
		self::assertFalse( $values['display_from_sound'], 'The display time applies, as in MerchAlert.' );
		self::assertSame( OverlaySettings::default_layout(), $values['layout'] );
	}

	public function test_merchalerts_stored_strings_keep_their_meaning(): void {
		$settings = OverlaySettings::from_array(
			array(
				'template'            => "Thanks \$UserName!\nfor \$Product",
				'font_size'           => '48',
				'font_weight'         => '800',
				'text_color'          => '#FA0',
				'text_shadow'         => '',
				'bg_opacity'          => '50',
				'display_duration'    => '7000',
				'audio_enabled'       => '1',
				'audio_default_sound' => '42',
				'audio_category_map'  => '{"5":42,"0":3,"7":"x"}',
				'layout'              => array(
					'elements' => array(
						'product_image' => array(
							'x'             => '-20',
							'y'             => '10',
							'w'             => '5',
							'h'             => '300',
							'visible'       => '0',
							'lock_aspect'   => '1',
							'border_radius' => '900',
						),
						'unknown'       => array( 'x' => 1 ),
					),
					'canvas'   => array(
						'snap_grid' => '1',
						'grid_size' => '500',
					),
				),
			)
		)->to_array();

		self::assertSame( "Thanks \$UserName!\nfor \$Product", $settings['template'] );
		self::assertSame( 48, $settings['font_size'] );
		self::assertSame( '800', $settings['font_weight'] );
		self::assertSame( '#ffaa00', $settings['text_color'] );
		self::assertSame( '', $settings['text_shadow'], 'An empty shadow stays empty (no shadow).' );
		self::assertSame( 50, $settings['bg_opacity'] );
		self::assertSame( 7000, $settings['display_duration'] );
		self::assertTrue( $settings['audio_enabled'] );
		self::assertSame( 42, $settings['audio_default_sound'] );
		self::assertSame( array( 5 => 42 ), $settings['audio_category_map'] );
		self::assertSame(
			array(
				'x'             => -20,
				'y'             => 10,
				'w'             => 10,
				'h'             => 300,
				'visible'       => false,
				'lock_aspect'   => true,
				'border_radius' => 500,
			),
			$settings['layout']['elements']['product_image']
		);
		self::assertSame( array( 'product_image', 'main_text', 'secondary_text' ), array_keys( $settings['layout']['elements'] ) );
		self::assertSame(
			array(
				'snap_grid' => true,
				'grid_size' => 100,
			),
			$settings['layout']['canvas']
		);
	}

	/**
	 * Values that could escape a CSS declaration fall back to safe defaults.
	 *
	 * @return array<string, array{string, mixed, mixed}>
	 */
	public static function css_injections(): array {
		return array(
			'font family with a brace'   => array( 'font_family', 'Roboto;}body{display:none', 'inherit' ),
			'font family with a quote'   => array( 'font_family', 'Roboto", x', 'inherit' ),
			'font family with url'       => array( 'font_family', 'url(http://x)', 'inherit' ),
			'shadow with a brace'        => array( 'text_shadow', '2px 2px 4px red;}body{', '' ),
			'shadow with a named color'  => array( 'text_shadow', '2px 2px 4px red', '' ),
			'shadow with url'            => array( 'text_shadow', '2px 2px url(x) #000', '' ),
			'color as a name'            => array( 'text_color', 'red', '#ffffff' ),
			'color with expression'      => array( 'bg_color', '#000;}', '#000000' ),
			'align outside the list'     => array( 'text_align', 'justify', 'center' ),
			'fit outside the list'       => array( 'text_fit', 'stretch', 'fixed' ),
			'weight outside the list'    => array( 'font_weight', '950', '700' ),
			'size as text'               => array( 'font_size', '32px', 32 ),
			'size above the maximum'     => array( 'font_size', 5000, 200 ),
			'display below the minimum'  => array( 'display_duration', 10, 1000 ),
			'opacity as an array'        => array( 'bg_opacity', array( 1 ), 80 ),
			'template with tags removed' => array( 'template', '<b>$Product</b>', '$Product' ),
			'empty template'             => array( 'template', '   ', '$Product was purchased by $UserName!' ),
		);
	}

	#[DataProvider( 'css_injections' )]
	public function test_invalid_values_fall_back( string $key, mixed $value, mixed $expected ): void {
		self::assertSame( $expected, OverlaySettings::from_array( array( $key => $value ) )->to_array()[ $key ] );
	}

	public function test_valid_shadows_and_fonts_are_kept(): void {
		foreach ( array( '1px 1px #000', '-2px 3px 6px rgba(0, 0, 0, 0.5)', '0 0 10px #ff00ff80', '2px 2px 4px rgb(10,20,30)' ) as $shadow ) {
			self::assertSame( $shadow, OverlaySettings::from_array( array( 'text_shadow' => $shadow ) )->to_array()['text_shadow'] );
		}

		self::assertSame( 'Open Sans', OverlaySettings::from_array( array( 'font_family' => 'Open Sans' ) )->to_array()['font_family'] );
	}

	public function test_css_variables_and_boxes_use_only_validated_values(): void {
		$settings = OverlaySettings::from_array(
			array(
				'font_family'        => 'Open Sans',
				'text_shadow'        => '',
				'bg_color'           => '#102030',
				'bg_opacity'         => 45,
				'anim_duration'      => 250,
				'secondary_template' => 'For $UserName',
				'layout'             => array( 'elements' => array( 'secondary_text' => array( 'visible' => true ) ) ),
			)
		);

		self::assertSame(
			array(
				'anim'              => '0.25s',
				'font-family'       => '"Open Sans", sans-serif',
				'font-size'         => '32px',
				'font-weight'       => '700',
				'text-color'        => '#ffffff',
				'text-shadow'       => 'none',
				'text-align'        => 'center',
				'img-border-radius' => '4px',
			),
			$settings->css_variables()
		);
		self::assertSame( 'left:40px;top:880px;width:120px;height:120px;', $settings->element_style( 'product_image' ) );
		self::assertSame( 'left:176px;top:895px;width:700px;height:80px;background:rgba(16,32,48,0.45);border-radius:8px;padding:20px;', $settings->element_style( 'main_text' ) );
		self::assertTrue( $settings->shows_secondary() );
		self::assertStringNotContainsString( 'display:none', $settings->element_style( 'secondary_text' ) );
	}

	public function test_a_growing_box_keeps_its_height_as_the_least_and_grows_up_near_the_bottom(): void {
		$top    = OverlaySettings::from_array(
			array(
				'text_fit' => 'grow',
				'layout'   => array( 'elements' => array( 'main_text' => array( 'x' => 176, 'y' => 40, 'w' => 700, 'h' => 80 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short box.
			)
		);
		$grow   = OverlaySettings::from_array( array( 'text_fit' => 'grow' ) );
		$shrink = OverlaySettings::from_array( array( 'text_fit' => 'shrink' ) );

		self::assertSame( 'grow', $grow->text_fit() );
		self::assertTrue( $grow->grows_up( 'main_text' ), 'The default box is near the bottom.' );
		self::assertStringStartsWith( 'left:176px;bottom:105px;width:700px;height:auto;min-height:80px;', $grow->element_style( 'main_text' ), 'It keeps its bottom edge (1080 - 895 - 80).' );
		self::assertFalse( $top->grows_up( 'main_text' ) );
		self::assertStringStartsWith( 'left:176px;top:40px;width:700px;height:auto;min-height:80px;', $top->element_style( 'main_text' ), 'Near the top it grows down.' );
		self::assertFalse( $grow->grows_up( 'product_image' ) );
		self::assertFalse( OverlaySettings::defaults()->grows_up( 'main_text' ), 'Only with grow.' );
		self::assertSame( 'left:40px;top:880px;width:120px;height:120px;', $grow->element_style( 'product_image' ), 'The image never grows.' );
		self::assertStringStartsWith( 'left:176px;top:895px;width:700px;height:80px;', $shrink->element_style( 'main_text' ), 'Shrinking happens in the overlay script.' );
	}

	public function test_text_animations_default_to_the_fade_and_are_normalized(): void {
		$default = array(
			'in'     => 'fade',
			'effect' => 'none',
			'out'    => 'fade',
			'color'  => '#ff2bd6',
			'speed'  => 'normal',
		);

		self::assertSame(
			array(
				'main_text'      => $default,
				'secondary_text' => $default,
			),
			OverlaySettings::defaults()->animations()
		);

		$settings = OverlaySettings::from_array(
			array(
				'animations' => array(
					'main_text'      => array(
						'in'     => 'typewriter',
						'effect' => 'explode',
						'out'    => 'wave',
						'color'  => '#0F0',
						'speed'  => 'fast',
					),
					'secondary_text' => 'nonsense',
				),
			)
		);

		self::assertSame(
			array(
				'in'     => 'typewriter',
				'effect' => 'none',
				'out'    => 'wave',
				'color'  => '#00ff00',
				'speed'  => 'fast',
			),
			$settings->animations()['main_text']
		);
		self::assertSame( $default, $settings->animations()['secondary_text'] );
	}

	public function test_text_fills_default_to_none_and_are_normalized(): void {
		$default = array(
			'type'   => 'none',
			'preset' => 'rainbow',
			'image'  => 0,
			'motion' => 'none',
		);

		self::assertSame(
			array(
				'main_text'      => $default,
				'secondary_text' => $default,
			),
			OverlaySettings::defaults()->text_fills()
		);

		$settings = OverlaySettings::from_array(
			array(
				'text_fills' => array(
					'main_text'      => array(
						'type'   => 'image',
						'preset' => 'lava',
						'image'  => '42',
						'motion' => 'fall',
					),
					'secondary_text' => array(
						'type'   => 'preset',
						'preset' => 'gold',
						'image'  => -5,
						'motion' => 'spin',
					),
				),
			)
		);

		self::assertSame(
			array(
				'main_text'      => array(
					'type'   => 'image',
					'preset' => 'rainbow',
					'image'  => 42,
					'motion' => 'fall',
				),
				'secondary_text' => array(
					'type'   => 'preset',
					'preset' => 'gold',
					'image'  => 0,
					'motion' => 'none',
				),
			),
			$settings->text_fills()
		);
		self::assertSame( $settings->text_fills(), OverlaySettings::from_json( $settings->to_json() )->text_fills(), 'Stored and read back unchanged.' );
	}

	public function test_the_secondary_text_needs_a_template_and_visibility(): void {
		self::assertFalse( OverlaySettings::defaults()->shows_secondary() );
		self::assertStringEndsWith( 'display:none;', OverlaySettings::defaults()->element_style( 'secondary_text' ) );
		self::assertFalse( OverlaySettings::from_array( array( 'secondary_template' => 'x' ) )->shows_secondary(), 'Hidden in the default layout.' );
	}

	public function test_json_round_trip_and_invalid_json(): void {
		$settings = OverlaySettings::from_array( array( 'font_size' => 40 ) );

		self::assertSame( $settings->to_array(), OverlaySettings::from_json( $settings->to_json() )->to_array() );
		self::assertSame( OverlaySettings::defaults()->to_array(), OverlaySettings::from_json( 'not json' )->to_array() );
	}

	public function test_overlay_ids_are_32_lowercase_hex_characters(): void {
		$id = OverlayId::generate();

		self::assertTrue( OverlayId::valid( $id ) );
		self::assertNotSame( $id, OverlayId::generate() );
		self::assertFalse( OverlayId::valid( strtoupper( $id ) ) );
		self::assertFalse( OverlayId::valid( substr( $id, 1 ) ) );
		self::assertFalse( OverlayId::valid( $id . "\n" ) );
	}
}
