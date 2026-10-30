<?php
/**
 * Measures a provider's renders of a base product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use GdImage;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The catalog import (review 30.15) has the provider render the blank
 * product twice: with a transparent file and with one solid marker colour
 * per side (`assets/designer/marker-<n>.png`). The pixels that differ
 * between the two renders and have a marker's hue show where that side's
 * print lands in the photo: its bounding box (1 % trimmed at each edge
 * against stray pixels) is the print area. The blank render's colour in the
 * middle of a print area is the product colour Gelato does not name. Needs
 * GD; images are refused above MAX_SIDE pixels before they are decoded.
 */
final class ImageProbe {

	/**
	 * The marker colours, in the order of the sides.
	 */
	public const MARKERS = array(
		array( 255, 0, 255 ),
		array( 0, 255, 255 ),
		array( 255, 255, 0 ),
		array( 0, 255, 0 ),
		array( 0, 0, 255 ),
		array( 255, 0, 0 ),
	);

	/**
	 * The longest side of an image measured (memory: GD keeps 4 bytes per pixel).
	 */
	public const MAX_SIDE = 4000;

	/**
	 * Smallest difference (sum of the channels) between the renders that counts.
	 */
	private const MIN_DIFFERENCE = 60;

	/**
	 * Darkest marker pixel whose hue is still read.
	 */
	private const MIN_BRIGHTNESS = 40;

	/**
	 * Largest distance between a pixel's and a marker's normalised colour.
	 */
	private const MAX_DISTANCE = 0.9;

	/**
	 * Largest share a box's proportions may differ from a layer's and still be the layer.
	 */
	private const MAX_SKEW = 0.05;

	/**
	 * The share of marker pixels ignored at each edge of the box.
	 */
	private const TRIM = 0.01;

	/**
	 * Pixels looked at along the longest side (every n-th pixel above it).
	 */
	private const SAMPLES = 500;

	/**
	 * Whether GD can measure.
	 */
	public static function available(): bool {
		return function_exists( 'imagecreatefromstring' ) && function_exists( 'imagecolorat' ) && function_exists( 'imagepng' );
	}

	/**
	 * Where each marker colour shows in a marker render, against the blank
	 * render of the same photo. A marker render of another size is scaled to
	 * the blank one.
	 *
	 * @param string $blank   The blank render (bytes).
	 * @param string $marker  The marker render (bytes).
	 * @param int    $markers How many marker colours to look for (the sides).
	 * @return array{width: int, height: int, found: array<int, array{count: int, x: int, y: int, width: int, height: int}>}
	 * @throws InvalidArgumentException When an image cannot be read or is too large.
	 */
	public static function markers( string $blank, string $marker, int $markers ): array {
		$a      = self::image( $blank );
		$b      = self::image( $marker );
		$width  = imagesx( $a );
		$height = imagesy( $a );

		if ( imagesx( $b ) !== $width || imagesy( $b ) !== $height ) {
			$scaled = imagescale( $b, $width, $height );

			if ( false === $scaled ) {
				throw new InvalidArgumentException( 'The marker render cannot be scaled.' );
			}

			$b = $scaled;
		}

		$normal  = array_map( static fn( array $color ): array => self::normal( $color[0], $color[1], $color[2] ), array_slice( self::MARKERS, 0, max( 0, $markers ) ) );
		$step    = max( 1, intdiv( max( $width, $height ), self::SAMPLES ) );
		$columns = array();
		$rows    = array();
		$counts  = array();

		foreach ( range( 0, $height - 1, $step ) as $y ) {
			foreach ( range( 0, $width - 1, $step ) as $x ) {
				$p     = imagecolorat( $a, $x, $y );
				$q     = imagecolorat( $b, $x, $y );
				$red   = ( $q >> 16 ) & 255;
				$green = ( $q >> 8 ) & 255;
				$blue  = $q & 255;

				if ( abs( ( ( $p >> 16 ) & 255 ) - $red ) + abs( ( ( $p >> 8 ) & 255 ) - $green ) + abs( ( $p & 255 ) - $blue ) < self::MIN_DIFFERENCE || max( $red, $green, $blue ) < self::MIN_BRIGHTNESS ) {
					continue;
				}

				$index = self::nearest( self::normal( $red, $green, $blue ), $normal );

				if ( null === $index ) {
					continue;
				}

				$counts[ $index ]        = ( $counts[ $index ] ?? 0 ) + 1;
				$columns[ $index ][ $x ] = ( $columns[ $index ][ $x ] ?? 0 ) + 1;
				$rows[ $index ][ $y ]    = ( $rows[ $index ][ $y ] ?? 0 ) + 1;
			}
		}

		$found = array();

		foreach ( $counts as $index => $count ) {
			[ $left, $right ] = self::range( $columns[ $index ], $count );
			[ $top, $bottom ] = self::range( $rows[ $index ], $count );

			$found[ $index ] = array(
				'count'  => $count * $step * $step,
				'x'      => $left,
				'y'      => $top,
				'width'  => min( $width, $right + $step ) - $left,
				'height' => min( $height, $bottom + $step ) - $top,
			);
		}

		ksort( $found );

		return array(
			'width'  => $width,
			'height' => $height,
			'found'  => $found,
		);
	}

	/**
	 * The colour in the middle of an area (the median of a grid over its
	 * middle half), as `#rrggbb`.
	 *
	 * @param string                                         $image Image (bytes).
	 * @param array{x: int, y: int, width: int, height: int} $area  Area.
	 * @throws InvalidArgumentException When the image cannot be read.
	 */
	public static function color( string $image, array $area ): string {
		$im       = self::image( $image );
		$channels = array( array(), array(), array() );

		for ( $i = 0; $i < 9; $i++ ) {
			for ( $j = 0; $j < 9; $j++ ) {
				$x = (int) min( imagesx( $im ) - 1, max( 0, $area['x'] + $area['width'] * ( 0.25 + $i / 16 ) ) );
				$y = (int) min( imagesy( $im ) - 1, max( 0, $area['y'] + $area['height'] * ( 0.25 + $j / 16 ) ) );
				$p = imagecolorat( $im, $x, $y );

				$channels[0][] = ( $p >> 16 ) & 255;
				$channels[1][] = ( $p >> 8 ) & 255;
				$channels[2][] = $p & 255;
			}
		}

		$median = array_map(
			static function ( array $values ): int {
				sort( $values );

				return (int) $values[ intdiv( count( $values ), 2 ) ];
			},
			$channels
		);

		return sprintf( '#%02x%02x%02x', $median[0], $median[1], $median[2] );
	}

	/**
	 * An image as a PNG, scaled to a size when one is given.
	 *
	 * @param string   $image  Image (bytes): PNG, JPG or WebP.
	 * @param int|null $width  Width, or null to keep it.
	 * @param int|null $height Height, or null to keep it.
	 * @return array{0: string, 1: int, 2: int} PNG bytes, width and height.
	 * @throws InvalidArgumentException When the image cannot be read or written.
	 */
	public static function png( string $image, ?int $width = null, ?int $height = null ): array {
		$im = self::image( $image );

		if ( null !== $width && null !== $height && $width > 0 && $height > 0 && ( imagesx( $im ) !== $width || imagesy( $im ) !== $height ) ) {
			$scaled = imagescale( $im, $width, $height );

			if ( false === $scaled ) {
				throw new InvalidArgumentException( 'The image cannot be scaled.' );
			}

			$im = $scaled;
		}

		ob_start();
		$written = imagepng( $im );
		$bytes   = (string) ob_get_clean();

		if ( ! $written || '' === $bytes ) {
			throw new InvalidArgumentException( 'The image cannot be written.' );
		}

		return array( $bytes, imagesx( $im ), imagesy( $im ) );
	}

	/**
	 * The print size of a measured box. A provider can show less of a layer
	 * than its size says: a 132 × 84 mm layer on a beanie's cuff showed 484 ×
	 * 170 px (2026-10-30), 132 × 46 mm. The side that fills the box sets the
	 * scale, and what the photo shows is the print size. Within MAX_SKEW the
	 * layer's size stands, so measuring noise changes nothing.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $box       Box.
	 * @param int                                            $width_mm  Layer width.
	 * @param int                                            $height_mm Layer height.
	 * @return array{0: int, 1: int} Print width and height in millimetres.
	 */
	public static function print_size( array $box, int $width_mm, int $height_mm ): array {
		$across = $box['width'] / max( 1, $width_mm );
		$down   = $box['height'] / max( 1, $height_mm );
		$scale  = max( $across, $down );

		if ( $scale <= 0 || abs( $across - $down ) / $scale <= self::MAX_SKEW ) {
			return array( $width_mm, $height_mm );
		}

		return array( max( 1, (int) round( $box['width'] / $scale ) ), max( 1, (int) round( $box['height'] / $scale ) ) );
	}

	/**
	 * A print area of a print size's proportions inside a box, in its middle.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $box       Box.
	 * @param int                                            $width_mm  Print width.
	 * @param int                                            $height_mm Print height.
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	public static function fit( array $box, int $width_mm, int $height_mm ): array {
		$ratio  = $width_mm / max( 1, $height_mm );
		$width  = $box['width'];
		$height = $box['height'];

		if ( $width / max( 1, $height ) > $ratio ) {
			$width = max( 1, (int) round( $height * $ratio ) );
		} else {
			$height = max( 1, (int) round( $width / $ratio ) );
		}

		return array(
			'x'      => $box['x'] + intdiv( $box['width'] - $width, 2 ),
			'y'      => $box['y'] + intdiv( $box['height'] - $height, 2 ),
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * A decoded true-colour image; its size is checked before it is decoded.
	 *
	 * @param string $bytes Image.
	 * @throws InvalidArgumentException When it cannot be read or is too large.
	 */
	private static function image( string $bytes ): GdImage {
		$size = '' === $bytes ? false : getimagesizefromstring( $bytes );

		if ( ! self::available() || ! is_array( $size ) || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE ) {
			throw new InvalidArgumentException( 'The image cannot be measured.' );
		}

		$image = imagecreatefromstring( $bytes );

		if ( false === $image ) {
			throw new InvalidArgumentException( 'The image cannot be read.' );
		}

		imagepalettetotruecolor( $image );

		return $image;
	}

	/**
	 * A colour divided by its brightest channel.
	 *
	 * @param int $red   Red.
	 * @param int $green Green.
	 * @param int $blue  Blue.
	 * @return array{0: float, 1: float, 2: float}
	 */
	private static function normal( int $red, int $green, int $blue ): array {
		$max = max( 1, $red, $green, $blue );

		return array( $red / $max, $green / $max, $blue / $max );
	}

	/**
	 * The nearest marker within MAX_DISTANCE, or null.
	 *
	 * @param array{0: float, 1: float, 2: float}       $color   Normalised colour.
	 * @param list<array{0: float, 1: float, 2: float}> $markers Normalised markers.
	 */
	private static function nearest( array $color, array $markers ): ?int {
		$best     = null;
		$distance = self::MAX_DISTANCE;

		foreach ( $markers as $index => $marker ) {
			$d = abs( $color[0] - $marker[0] ) + abs( $color[1] - $marker[1] ) + abs( $color[2] - $marker[2] );

			if ( $d < $distance ) {
				$best     = $index;
				$distance = $d;
			}
		}

		return $best;
	}

	/**
	 * The first and last position of a histogram, TRIM ignored at each end.
	 *
	 * @param array<int, int> $histogram Count by position.
	 * @param int             $total     Total count.
	 * @return array{0: int, 1: int}
	 */
	private static function range( array $histogram, int $total ): array {
		ksort( $histogram );

		$cut       = (int) floor( $total * self::TRIM );
		$positions = array_keys( $histogram );
		$first     = $positions[0];
		$last      = $positions[ count( $positions ) - 1 ];
		$seen      = 0;

		foreach ( $histogram as $position => $count ) {
			$seen += $count;

			if ( $seen > $cut ) {
				$first = $position;
				break;
			}
		}

		$seen = 0;

		foreach ( array_reverse( $histogram, true ) as $position => $count ) {
			$seen += $count;

			if ( $seen > $cut ) {
				$last = $position;
				break;
			}
		}

		return array( $first, max( $first, $last ) );
	}
}
