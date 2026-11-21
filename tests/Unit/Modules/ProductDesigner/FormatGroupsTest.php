<?php
/**
 * Tests of sizes, values and formats of sides (ADR-115).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\SizeOrder;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SizeOrder::class )]
#[CoversClass( BaseView::class )]
#[CoversClass( BaseProduct::class )]
#[CoversClass( GelatoCatalog::class )]
final class FormatGroupsTest extends TestCase {

	public function test_clothing_sizes_go_from_small_to_large_and_others_by_print_area(): void {
		self::assertSame( array( 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '5XL', '8XL' ), SizeOrder::sort( array( '3XL', 'M', 'XS', 'XXL', '8XL', 'L', 'S', '5XL', 'XL' ) ) );
		self::assertSame( 7, SizeOrder::rank( '2XL' ) );
		self::assertSame( SizeOrder::rank( '2XL' ), SizeOrder::rank( 'XXL' ) );
		self::assertNull( SizeOrder::rank( 'A3' ) );

		self::assertSame(
			array( 'A4', 'A3', 'Same as A3', 'A2', 'Unknown' ),
			SizeOrder::sort(
				array( 'A2', 'Unknown', 'A3', 'A4', 'Same as A3' ),
				array(
					'A4'         => 62370.0,
					'A3'         => 124740.0,
					'Same as A3' => 124740.0,
					'A2'         => 249480.0,
				)
			),
			'Smallest first; alike keep their order; one without an area last.'
		);
		self::assertSame( array( 'M', 'A3', 'S' ), SizeOrder::sort( array( 'M', 'A3', 'S' ) ), 'Not all clothing: the catalog\'s order.' );
	}

	public function test_a_size_name_keeps_its_inch_mark_and_loses_runs_of_spaces(): void {
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) );

		self::assertSame( '21x29.7 cm / 8x12"', BaseProduct::size_name( ' 21x29.7 cm / 8x12" ' ) );
		self::assertSame( 'A2 (42 x 59.4 cm)', BaseProduct::size_name( 'A2 (42 x 59.4  cm)' ) );
		self::assertSame( 'S M', BaseProduct::size_name( 'S,M' ), 'A comma separates sizes.' );
		self::assertSame( 60, mb_strlen( BaseProduct::size_name( str_repeat( 'x', 80 ) ) ) );
	}

	public function test_a_side_keeps_its_values_and_formats(): void {
		$side = self::side(
			array( 'vertical' ),
			array(
				'A3' => array(
					'width_mm'  => 305.0,
					'height_mm' => 428.0,
					'bleed'     => array( 4.0, 4.0 ),
				),
			)
		);
		// As JSON gives it back: whole millimetres as integers.
		$stored            = $side->to_array();
		$stored['formats'] = array(
			'A3' => array(
				'width_mm'  => 305,
				'height_mm' => 428,
				'bleed'     => array( 4, 4 ),
			),
		);
		$back              = BaseView::from_array( $stored );

		self::assertSame( $side->colors, $back->colors );
		self::assertSame( $side->formats, $back->formats, 'Whole millimetres come back as floats.' );
		self::assertTrue( $back->on_color( 'vertical' ) );
		self::assertFalse( $back->on_color( 'horizontal' ) );
		self::assertTrue( self::side( array(), array() )->on_color( 'horizontal' ), 'Without values: on every colour.' );
		self::assertArrayNotHasKey( 'formats', self::side( array(), array() )->to_array(), 'A stored side without formats does not change.' );
		self::assertSame( $side->formats, $side->with_layers( array( 'background' => 2 ) )->formats );
	}

	public function test_a_bleed_as_wide_as_the_print_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		self::side(
			array(),
			array(
				'A3' => array(
					'width_mm'  => 8.0,
					'height_mm' => 428.0,
					'bleed'     => array( 4.0, 4.0 ),
				),
			)
		);
	}

	public function test_the_bleed_is_half_the_difference_to_the_finished_size_and_only_on_prints(): void {
		self::assertSame( array( 4.0, 4.0 ), GelatoCatalog::bleed( array( 305.0, 428.0 ), array( 297.0, 420.0 ) ) );
		self::assertSame( array( 4.0, 4.0 ), GelatoCatalog::bleed( array( 428.0, 305.0 ), array( 297.0, 420.0 ) ), 'A horizontal print of a vertically named product.' );
		self::assertNull( GelatoCatalog::bleed( array( 300.0, 400.0 ), array( 300.0, 400.0 ) ), 'No difference: no bleed.' );
		self::assertNull( GelatoCatalog::bleed( array( 300.0, 400.0 ), array( 500.0, 700.0 ) ), 'Clothing: the "size" is the garment.' );
		self::assertNull( GelatoCatalog::bleed( array( 305.0, 428.0 ), null ) );
	}

	/**
	 * A side with values and formats.
	 *
	 * @param string[]                                                                                   $colors  Values.
	 * @param array<string, array{width_mm: float, height_mm: float, bleed?: array{0: float, 1: float}}> $formats Formats.
	 */
	private static function side( array $colors, array $formats ): BaseView {
		return new BaseView( 'vertical-format-1', 'Vertical – format 1', 400, 400, array( 'background' => 1 ), array( 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 140 ), 849, 1197, 150, '', false, null, array_keys( $formats ), false, $colors, $formats ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}
}
