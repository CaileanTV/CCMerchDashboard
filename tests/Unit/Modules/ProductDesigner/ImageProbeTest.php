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

	public function test_a_poster_s_marker_has_its_exact_edges_beside_the_trimmed_box(): void {
		// Large enough to be sampled every 2nd pixel, small enough for 128 MB in any test order.
		$wall   = array( 200, 190, 180 );
		$paper  = array( 250, 250, 250 );
		$blank  = Photo::png( 1200, 900, $wall, array( array( 181, 123, 723, 601, $paper ) ) );
		$marker = Photo::png(
			1200,
			900,
			$wall,
			array(
				array( 181, 123, 723, 601, array( 255, 0, 255 ) ),
				// A stray magenta speck elsewhere on the wall.
				array( 1100, 850, 3, 3, array( 255, 0, 255 ) ),
			)
		);
		$found = ImageProbe::markers( $blank, $marker, 1 )['found'][0];

		self::assertGreaterThanOrEqual( 4, 181 + 723 - ( $found['x'] + $found['width'] ), 'The trimmed box stops short of the right edge.' );
		self::assertSame(
			array(
				'x'      => 180,
				'y'      => 122,
				'width'  => 725,
				'height' => 603,
			),
			$found['edge'],
			'The exact edges, one pixel more on each side; the speck is left out.'
		);
	}

	public function test_a_poster_is_a_white_sheet_of_its_print_area_with_a_soft_shadow(): void {
		[ $png, $box ] = ImageProbe::sheet( 428, 602 );
		$image         = imagecreatefromstring( $png );

		self::assertNotFalse( $image );
		self::assertSame( array( 1000, 1000 ), array( imagesx( $image ), imagesy( $image ) ) );
		self::assertSame(
			array(
				'x'      => 166,
				'y'      => 30,
				'width'  => 668,
				'height' => 940,
			),
			$box,
			'The print area with its bleed, 940 px on its long side, in the middle.'
		);

		$alpha = static fn( int $x, int $y ): int => ( imagecolorat( $image, $x, $y ) >> 24 ) & 127;
		$rgb   = static fn( int $x, int $y ): int => imagecolorat( $image, $x, $y ) & 0xffffff;

		self::assertSame( array( 0, 0xffffff ), array( $alpha( 500, 500 ), $rgb( 500, 500 ) ), 'A white sheet.' );
		self::assertSame( 127, $alpha( 5, 5 ), 'Transparent around it, so it is its own outline.' );
		self::assertLessThan( 127, $alpha( 500, 30 + 940 + 4 ), 'A shadow below it.' );
		self::assertSame( array( 940, 668 ), array( ImageProbe::sheet( 602, 428 )[1]['width'], ImageProbe::sheet( 602, 428 )[1]['height'] ) );
	}

	public function test_a_poster_s_print_area_covers_the_box_inside_the_photo(): void {
		$box = array(
			'x'      => 300,
			'y'      => 200,
			'width'  => 750,
			'height' => 1000,
		);

		self::assertSame( array( 'x' => 299, 'y' => 200, 'width' => 752, 'height' => 1000 ), ImageProbe::cover( $box, 758, 1008, 2000, 1500 ), 'Wider by the bleed\'s share, never narrower.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'x' => 0, 'y' => 0, 'width' => 800, 'height' => 1000 ), ImageProbe::cover( array( 'x' => 0, 'y' => 0, 'width' => 800, 'height' => 1000 ), 900, 1000, 800, 1000 ), 'Never outside the photo.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
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

	public function test_a_transparent_background_stays_transparent(): void {
		$photo = imagecreatetruecolor( 20, 20 );

		self::assertNotFalse( $photo );
		imagealphablending( $photo, false );
		imagesavealpha( $photo, true );
		imagefill( $photo, 0, 0, (int) imagecolorallocatealpha( $photo, 0, 0, 0, 127 ) );
		imagefilledrectangle( $photo, 5, 5, 14, 14, (int) imagecolorallocatealpha( $photo, 200, 40, 40, 0 ) );
		ob_start();
		imagepng( $photo );
		$bytes = (string) ob_get_clean();

		foreach ( array( ImageProbe::png( $bytes ), ImageProbe::png( $bytes, 40, 40 ) ) as [ $png ] ) {
			$image = imagecreatefromstring( $png );

			self::assertNotFalse( $image );
			self::assertSame( 127, ( (int) imagecolorat( $image, 1, 1 ) >> 24 ) & 0x7F, 'The corner stays transparent.' );
		}
	}

	public function test_only_a_png_with_transparent_pixels_is_without_background(): void {
		$png = static function ( bool $alpha, bool $clear ): string {
			$image = imagecreatetruecolor( 40, 40 );

			self::assertNotFalse( $image );
			imagealphablending( $image, false );
			imagesavealpha( $image, $alpha );
			imagefill( $image, 0, 0, (int) imagecolorallocatealpha( $image, 230, 230, 230, $clear ? 127 : 0 ) );
			imagefilledrectangle( $image, 10, 10, 29, 29, (int) imagecolorallocatealpha( $image, 20, 20, 20, 0 ) );
			ob_start();
			imagepng( $image );

			return (string) ob_get_clean();
		};

		self::assertTrue( ImageProbe::transparent( $png( true, true ) ), 'A product on a transparent ground.' );
		self::assertFalse( ImageProbe::transparent( $png( true, false ) ), 'An alpha channel, but every pixel opaque.' );
		self::assertFalse( ImageProbe::transparent( $png( false, true ) ), 'No alpha channel.' );
		$jpeg = imagecreatetruecolor( 4, 4 );
		self::assertNotFalse( $jpeg );
		ob_start();
		imagejpeg( $jpeg );
		self::assertFalse( ImageProbe::transparent( (string) ob_get_clean() ), 'A JPEG.' );
		self::assertFalse( ImageProbe::transparent( '' ) );
	}

	public function test_only_a_photo_on_a_light_plain_backdrop_is_a_studio_photo(): void {
		$photo = static function ( array $ground, bool $room ): string {
			$image = imagecreatetruecolor( 120, 120 );

			self::assertNotFalse( $image );
			imagefill( $image, 0, 0, (int) imagecolorallocate( $image, $ground[0], $ground[1], $ground[2] ) );
			// A shirt in the middle; in a room the edge shows a lamp and a desk.
			imagefilledrectangle( $image, 40, 30, 80, 110, (int) imagecolorallocate( $image, 15, 15, 15 ) );

			if ( $room ) {
				imagefilledrectangle( $image, 0, 80, 119, 119, (int) imagecolorallocate( $image, 90, 60, 40 ) );
				imagefilledrectangle( $image, 100, 0, 119, 50, (int) imagecolorallocate( $image, 30, 200, 160 ) );
			}

			ob_start();
			imagejpeg( $image );

			return (string) ob_get_clean();
		};

		self::assertTrue( ImageProbe::studio( $photo( array( 244, 244, 244 ), false ) ), 'Light grey studio backdrop.' );
		self::assertFalse( ImageProbe::studio( $photo( array( 20, 40, 45 ), false ) ), 'A dark backdrop: the picture itself.' );
		self::assertFalse( ImageProbe::studio( $photo( array( 244, 244, 244 ), true ) ), 'A room along the edge.' );
		self::assertFalse( ImageProbe::studio( 'not an image' ) );
	}

	public function test_a_flat_side_lies_on_a_white_sheet(): void {
		$image = imagecreatefromstring( ImageProbe::blank( 30, 20 ) );

		self::assertNotFalse( $image );
		self::assertSame( array( 30, 20 ), array( imagesx( $image ), imagesy( $image ) ) );
		self::assertSame( 0xFFFFFF, imagecolorat( $image, 29, 19 ) );

		$this->expectException( InvalidArgumentException::class );
		ImageProbe::blank( 0, 20 );
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
