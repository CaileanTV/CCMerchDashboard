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
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\PrintFileCrop;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\SizeOrder;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SizeOrder::class )]
#[CoversClass( BaseView::class )]
#[CoversClass( BaseProduct::class )]
#[CoversClass( GelatoCatalog::class )]
#[CoversClass( SubmissionFiles::class )]
#[CoversClass( PrintFileCrop::class )]
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

	public function test_each_orientation_needs_its_own_design_and_sells_alone(): void {
		$base = self::poster();

		self::assertTrue( $base->colored() );
		self::assertSame( array( 'v-a' ), $base->sides_of( 'A3', 'vertical' ) );
		self::assertSame( array( 'v-a', 'h-a' ), $base->sides_of( 'A3' ) );
		self::assertTrue( $base->covers( array( 'v-a', 'v-sq', 'h-a', 'h-sq' ), array( 'A3', '30x30' ) ) );
		self::assertFalse( $base->covers( array( 'v-a', 'v-sq' ), array( 'A3', '30x30' ) ), 'Every orientation by default.' );
		self::assertTrue( $base->covers( array( 'v-a', 'v-sq' ), array( 'A3', '30x30' ), array( 'vertical' ) ), 'Only vertical posters.' );
		self::assertFalse( $base->covers( array( 'v-a', 'v-sq', 'h-a' ), array( 'A3', '30x30' ), array( 'vertical' ) ), 'No design on an orientation not sold.' );
		self::assertSame( 1, $base->areas( array( 'v-a', 'v-sq', 'h-a', 'h-sq' ), array( 'A3', '30x30' ) ), 'One poster has one design area.' );
		self::assertSame( 0, $base->extra_amount( array( 'v-a', 'v-sq', 'h-a', 'h-sq' ), array( 'A3', '30x30' ) ) );
		self::assertSame( array( 'v-a' ), SubmissionFiles::on_color( array( 'v-a', 'h-a' ), 'vertical', $base ) );
		self::assertSame( array( 'v-a', 'h-a' ), SubmissionFiles::on_color( array( 'v-a', 'h-a' ), 'vertical', null ) );
	}

	public function test_a_size_of_another_shape_keeps_the_middle_and_the_line_shows_its_cut_and_bleed(): void {
		// The A group's file is A0 (849 × 1197); 21 × 29.7 is a little wider, so it loses a little height.
		$side = self::side(
			array( 'vertical' ),
			array(
				'A0'      => array(
					'width_mm'  => 849.0,
					'height_mm' => 1197.0,
					'bleed'     => array( 4.0, 4.0 ),
				),
				'21x29.7' => array(
					'width_mm'  => 218.0,
					'height_mm' => 305.0,
				),
				'Plain'   => array(
					'width_mm'  => 849.0,
					'height_mm' => 1197.0,
				),
			)
		);

		self::assertSame( array( 1.0, 1.0 ), $side->kept( 'A0' ) );
		self::assertSame( array( 1.0, 1.0 ), $side->kept( 'Unknown' ) );
		[ $across, $down ] = $side->kept( '21x29.7' );
		self::assertSame( 1.0, $across );
		self::assertEqualsWithDelta( ( 849 / 1197 ) / ( 218 / 305 ), $down, 0.0001 );
		self::assertGreaterThan( 0.97, $down, 'At most 3 %.' );

		$trims = $side->trims();
		self::assertArrayNotHasKey( 'Plain', $trims, 'Nothing cut, no line.' );
		self::assertEqualsWithDelta( 4 / 849, $trims['A0']['x'], 0.0001 );
		self::assertEqualsWithDelta( 1 - 8 / 1197, $trims['A0']['height'], 0.0001 );
		self::assertEqualsWithDelta( ( 1 - $down ) / 2, $trims['21x29.7']['y'], 0.0001 );
		self::assertSame( 0.0, $trims['21x29.7']['x'] );
	}

	public function test_a_print_file_is_cut_in_the_middle(): void {
		Functions\when( 'wp_raise_memory_limit' )->justReturn( '256M' );

		$image = imagecreatetruecolor( 100, 50 );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$cut   = PrintFileCrop::image( $bytes, 'image/png', 0.9, 1.0 );
		$size  = getimagesizefromstring( (string) $cut );

		self::assertNotNull( $cut );
		self::assertSame( array( 90, 50 ), array( $size[0] ?? 0, $size[1] ?? 0 ) );
		self::assertNull( PrintFileCrop::image( 'not an image', 'image/png', 0.9, 1.0 ) );
	}

	/**
	 * A poster with an A group and a square group per orientation.
	 */
	private static function poster(): BaseProduct {
		$side = static fn( string $key, array $sizes, string $color ): BaseView => new BaseView( $key, $key, 400, 400, array( 'background' => 1 ), array( 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 100 ), 300, 300, 150, '', false, null, $sizes, false, array( $color ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		return new BaseProduct(
			1,
			BaseProduct::ACTIVE,
			'gelato',
			'Poster',
			'',
			1990000,
			'DKK',
			null,
			array(
				array(
					'key'  => 'vertical',
					'name' => 'Vertical',
					'hex'  => '#ffffff',
				),
				array(
					'key'  => 'horizontal',
					'name' => 'Horizontal',
					'hex'  => '#ffffff',
				),
			),
			array( 'A3', '30x30' ),
			array( $side( 'v-a', array( 'A3' ), 'vertical' ), $side( 'v-sq', array( '30x30' ), 'vertical' ), $side( 'h-a', array( 'A3' ), 'horizontal' ), $side( 'h-sq', array( '30x30' ), 'horizontal' ) ),
			1,
			500000
		);
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
