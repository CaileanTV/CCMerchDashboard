<?php
/**
 * Tests for the variant a designer product shows first (phase 59).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerDefaults;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DesignerDefaults::class )]
final class DesignerDefaultsTest extends TestCase {

	public function test_the_colour_of_the_main_image_is_shown_first_and_no_size_of_several(): void {
		self::assertSame(
			array(
				'color' => 'magenta',
				'size'  => '',
			),
			DesignerDefaults::of( self::base(), array( 'black', 'magenta' ), array( 'S', 'M' ), 'front:magenta' )
		);
	}

	public function test_the_only_size_is_always_shown_first(): void {
		self::assertSame(
			array(
				'color' => 'black',
				'size'  => '750 ml',
			),
			DesignerDefaults::of( self::base( size: 'M' ), array( 'black', 'white' ), array( '750 ml' ), 'front:black' )
		);
	}

	public function test_the_base_product_s_fixed_colour_and_size_win_when_chosen(): void {
		self::assertSame(
			array(
				'color' => 'white',
				'size'  => 'M',
			),
			DesignerDefaults::of( self::base( 'white', 'M' ), array( 'black', 'white' ), array( 'S', 'M' ), 'front:black' )
		);
	}

	public function test_a_fixed_default_the_creator_did_not_choose_falls_back(): void {
		self::assertSame(
			array(
				'color' => 'black',
				'size'  => '',
			),
			DesignerDefaults::of( self::base( 'white', 'XL' ), array( 'black', 'sand' ), array( 'S', 'M' ), 'front:black' )
		);
	}

	public function test_the_colour_is_the_part_after_the_last_colon(): void {
		self::assertSame( 'sand', DesignerDefaults::color( self::base(), array( 'black', 'sand' ), 'mockup:front:sand' ), 'Also before a view is stored.' );
		self::assertSame( 'sand', DesignerDefaults::color( self::base(), array( 'black', 'sand' ), '_view-front:sand' ) );
	}

	public function test_no_main_image_or_a_colour_not_chosen_shows_no_colour_first(): void {
		self::assertSame( '', DesignerDefaults::color( self::base(), array( 'black', 'sand' ), null ) );
		self::assertSame( '', DesignerDefaults::color( self::base(), array( 'black', 'sand' ), 'front' ) );
		self::assertSame( '', DesignerDefaults::color( self::base(), array( 'black', 'sand' ), 'front:pink' ) );
		self::assertSame( '', DesignerDefaults::color( self::base(), array( 'black', 'sand' ), 'front:' ) );
	}

	/**
	 * A base product with a fixed default.
	 *
	 * @param string $color The fixed colour key, or ''.
	 * @param string $size  The fixed size, or ''.
	 */
	private static function base( string $color = '', string $size = '' ): BaseProduct {
		return new BaseProduct( 4, BaseProduct::DRAFT, 'own', 'Tee', '', 1499500, 'DKK', null, array(), array(), array(), default_color: $color, default_size: $size );
	}
}
