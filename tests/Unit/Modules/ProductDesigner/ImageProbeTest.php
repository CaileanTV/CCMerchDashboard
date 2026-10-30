<?php
/**
 * Tests for the measuring of a provider's renders (review 30.15).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use InvalidArgumentException;
use MerchBoard\Modules\ProductDesigner\Providers\ImageProbe;
use MerchBoard\Tests\Unit\Support\Photo;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[CoversClass( ImageProbe::class )]
#[RequiresPhpExtension( 'gd' )]
final class ImageProbeTest extends TestCase {

	private const STUDIO = array( 230, 230, 230 );

	private const SHIRT = array( 30, 30, 30 );

	public function test_each_side_s_marker_is_found_where_it_differs_from_the_blank_render(): void {
		$blank  = $this->blank();
		$marker = Photo::png(
			400,
			300,
			self::STUDIO,
			array(
				array( 100, 50, 200, 220, self::SHIRT ),
				// Magenta and cyan as the provider shades them on the fabric.
				array( 150, 80, 100, 120, array( 210, 12, 200 ) ),
				array( 120, 200, 40, 20, array( 8, 190, 205 ) ),
			)
		);

		$found = ImageProbe::markers( $blank, $marker, 3 );

		self::assertSame( array( 400, 300 ), array( $found['width'], $found['height'] ) );
		self::assertSame( array( 0, 1 ), array_keys( $found['found'] ), 'The third side has no marker in this photo.' );
		self::assertBox( array( 150, 80, 100, 120 ), $found['found'][0] );
		self::assertBox( array( 120, 200, 40, 20 ), $found['found'][1] );
		self::assertSame( 12000, $found['found'][0]['count'] );
	}

	public function test_a_marker_render_of_another_size_is_scaled_to_the_blank_one(): void {
		$marker = Photo::png(
			800,
			600,
			self::STUDIO,
			array(
				array( 200, 100, 400, 440, self::SHIRT ),
				array( 300, 160, 200, 240, array( 255, 0, 255 ) ),
			)
		);

		$found = ImageProbe::markers( $this->blank(), $marker, 1 );

		self::assertBox( array( 150, 80, 100, 120 ), $found['found'][0] );
	}

	public function test_nothing_is_found_without_a_marker_hue(): void {
		$grey = Photo::png( 400, 300, self::STUDIO, array( array( 100, 50, 200, 220, array( 120, 120, 120 ) ) ) );

		self::assertSame( array(), ImageProbe::markers( $this->blank(), $grey, 6 )['found'], 'A changed grey is no marker.' );
		self::assertSame( array(), ImageProbe::markers( $this->blank(), $this->blank(), 6 )['found'] );
	}

	public function test_the_product_colour_is_the_median_in_the_middle_of_the_area(): void {
		self::assertSame( '#1e1e1e', ImageProbe::color( $this->blank(), array( 'x' => 150, 'y' => 80, 'width' => 100, 'height' => 120 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_a_print_area_keeps_the_print_size_s_proportions_in_the_middle_of_the_box(): void {
		self::assertSame( array( 'x' => 155, 'y' => 80, 'width' => 90, 'height' => 120 ), ImageProbe::fit( array( 'x' => 150, 'y' => 80, 'width' => 100, 'height' => 120 ), 30, 40 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'x' => 150, 'y' => 115, 'width' => 100, 'height' => 50 ), ImageProbe::fit( array( 'x' => 150, 'y' => 80, 'width' => 100, 'height' => 120 ), 200, 100 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_the_print_size_follows_what_the_photo_shows_of_the_layer(): void {
		$box = static fn( int $width, int $height ): array => array( 'x' => 0, 'y' => 0, 'width' => $width, 'height' => $height ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( array( 132, 46 ), ImageProbe::print_size( $box( 484, 170 ), 132, 84 ), 'The beanie: the width fills the box, the layer is cut off in height.' );
		self::assertSame( array( 40, 60 ), ImageProbe::print_size( $box( 200, 300 ), 80, 60 ), 'Cut off in width.' );
		self::assertSame( array( 244, 244 ), ImageProbe::print_size( $box( 286, 280 ), 244, 244 ), 'Measuring noise changes nothing.' );

		$area = ImageProbe::fit( $box( 484, 170 ), 132, 46 );

		self::assertSame( array( 484, 169 ), array( $area['width'], $area['height'] ), 'The whole width of the box stays.' );
	}

	public function test_a_photo_becomes_a_png_of_the_side_s_size(): void {
		[ $png, $width, $height ] = ImageProbe::png( $this->blank(), 200, 150 );

		self::assertSame( array( 200, 150 ), array( $width, $height ) );
		self::assertSame( array( 200, 150, IMAGETYPE_PNG ), array_slice( (array) getimagesizefromstring( $png ), 0, 3 ) );
	}

	public function test_images_that_are_too_large_or_no_images_are_refused_before_they_are_decoded(): void {
		$refused = 0;

		foreach ( array( Photo::png( ImageProbe::MAX_SIDE + 1, 1, self::STUDIO ), 'not an image', '' ) as $image ) {
			try {
				ImageProbe::png( $image );
			} catch ( InvalidArgumentException ) {
				++$refused;
			}
		}

		self::assertSame( 3, $refused );
	}

	/**
	 * The blank render: a black shirt in a light studio.
	 */
	private function blank(): string {
		return Photo::png( 400, 300, self::STUDIO, array( array( 100, 50, 200, 220, self::SHIRT ) ) );
	}

	/**
	 * A found box within two pixels (the trimmed edges).
	 *
	 * @param array{0: int, 1: int, 2: int, 3: int}                      $expected x, y, width, height.
	 * @param array{count: int, x: int, y: int, width: int, height: int} $actual   Found.
	 */
	private static function assertBox( array $expected, array $actual ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit's naming.
		foreach ( array( 'x', 'y', 'width', 'height' ) as $index => $field ) {
			self::assertEqualsWithDelta( $expected[ $index ], $actual[ $field ], 2, $field );
		}
	}
}
