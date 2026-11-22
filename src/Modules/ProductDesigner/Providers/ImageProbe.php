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
	 * The share of a marker's fullest column or row that counts as solid for
	 * its exact edges.
	 */
	private const SOLID = 0.25;

	/**
	 * Pixels looked at along the longest side (every n-th pixel above it).
	 */
	private const SAMPLES = 500;

	/**
	 * Whether an image is a PNG with transparent pixels: a product photo
	 * without background (ADR-114). Judged on a grid of 32 × 32 points; a
	 * PNG with an alpha channel counts when GD cannot measure.
	 *
	 * @param string $bytes Image.
	 */
	public static function transparent( string $bytes ): bool {
		// PNG: colour type 4 or 6 has an alpha channel; tRNS gives a palette or colour one.
		if ( ! str_starts_with( $bytes, "\x89PNG" ) || strlen( $bytes ) < 26 || ( ! in_array( ord( $bytes[25] ), array( 4, 6 ), true ) && ! str_contains( substr( $bytes, 0, 4096 ), 'tRNS' ) ) ) {
			return false;
		}

		try {
			$im = self::image( $bytes );
		} catch ( InvalidArgumentException ) {
			return true;
		}

		$width  = imagesx( $im );
		$height = imagesy( $im );

		for ( $i = 0; $i < 32; $i++ ) {
			for ( $j = 0; $j < 32; $j++ ) {
				// GD's alpha runs from 0 (opaque) to 127 (transparent).
				if ( ( ( imagecolorat( $im, intdiv( $i * ( $width - 1 ), 31 ), intdiv( $j * ( $height - 1 ), 31 ) ) >> 24 ) & 127 ) > 63 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a photo is a studio photo: the product on a light, plain
	 * backdrop along nearly all of its edge (ADR-114, the owner's test
	 * 2026-11-16: a provider's lifestyle photos lost the product). The same
	 * rule as `isStudioPhoto()` in the browser: the backdrop is the median of
	 * the edge, its darkest channel at least 200, and at least 90 % of the
	 * edge within 40 of it.
	 *
	 * @param string $bytes Image.
	 */
	public static function studio( string $bytes ): bool {
		try {
			$im = self::image( $bytes );
		} catch ( InvalidArgumentException ) {
			return false;
		}

		$width  = imagesx( $im );
		$height = imagesy( $im );
		$points = array();
		$steps  = 64;

		for ( $i = 0; $i < $steps; $i++ ) {
			$x        = intdiv( $i * ( $width - 1 ), $steps - 1 );
			$y        = intdiv( $i * ( $height - 1 ), $steps - 1 );
			$points[] = array( $x, 0 );
			$points[] = array( $x, $height - 1 );
			$points[] = array( 0, $y );
			$points[] = array( $width - 1, $y );
		}

		$colors = array_map(
			static function ( array $point ) use ( $im ): array {
				$p = imagecolorat( $im, $point[0], $point[1] );

				return array( ( $p >> 16 ) & 255, ( $p >> 8 ) & 255, $p & 255, ( $p >> 24 ) & 127 );
			},
			$points
		);
		$median = array();

		foreach ( array( 0, 1, 2 ) as $channel ) {
			$values = array_column( $colors, $channel );
			sort( $values );
			$median[] = $values[ intdiv( count( $values ), 2 ) ];
		}

		if ( min( $median ) < 200 ) {
			return false;
		}

		$plain = array_filter( $colors, static fn( array $c ): bool => $c[3] < 127 && sqrt( ( $c[0] - $median[0] ) ** 2 + ( $c[1] - $median[1] ) ** 2 + ( $c[2] - $median[2] ) ** 2 ) <= 40 );

		return count( $plain ) >= 0.9 * count( $colors );
	}

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
	 * Each marker has a box with TRIM ignored at its edges, against stray
	 * pixels on a garment, and its `edge`: the marker's own edges to the
	 * pixel, for a photo that shows the whole print (a poster, ADR-115).
	 *
	 * @param string $blank   The blank render (bytes).
	 * @param string $marker  The marker render (bytes).
	 * @param int    $markers How many marker colours to look for (the sides).
	 * @return array{width: int, height: int, found: array<int, array{count: int, x: int, y: int, width: int, height: int, edge: array{x: int, y: int, width: int, height: int}}>}
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
				$index = self::marker_at( $a, $b, $x, $y, $normal );

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
				'edge'   => self::edge( $a, $b, $normal, $index, $columns[ $index ], $rows[ $index ], $step ),
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

		// A provider's photo can have a transparent background; without this it turns black.
		imagesavealpha( $im, true );

		ob_start();
		$written = imagepng( $im );
		$bytes   = (string) ob_get_clean();

		if ( ! $written || '' === $bytes ) {
			throw new InvalidArgumentException( 'The image cannot be written.' );
		}

		return array( $bytes, imagesx( $im ), imagesy( $im ) );
	}

	/**
	 * A white PNG: what a flat side lies on.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string PNG bytes.
	 * @throws InvalidArgumentException When it cannot be made.
	 */
	public static function blank( int $width, int $height ): string {
		$im = $width > 0 && $height > 0 ? imagecreatetruecolor( $width, $height ) : false;

		if ( false === $im ) {
			throw new InvalidArgumentException( 'The image cannot be made.' );
		}

		$white = imagecolorallocate( $im, 255, 255, 255 );

		imagefilledrectangle( $im, 0, 0, $width, $height, false === $white ? 0 : $white );
		ob_start();
		$written = imagepng( $im );
		$bytes   = (string) ob_get_clean();

		if ( ! $written || '' === $bytes ) {
			throw new InvalidArgumentException( 'The image cannot be written.' );
		}

		return $bytes;
	}

	/**
	 * The side of a poster's sheet template (ADR-119), square.
	 */
	public const SHEET_SIZE = 1000;

	/**
	 * The room around the sheet for its shadow.
	 */
	private const SHEET_MARGIN = 30;

	/**
	 * A poster as Gelato's editor shows it (ADR-119): a white sheet in the
	 * shape of the print area with its bleed, its long side SHEET_SIZE less
	 * twice SHEET_MARGIN, in the middle of a transparent square with a soft
	 * shadow a little below. The design area is the whole sheet; the cut and
	 * the bleed are drawn by the designer (trims).
	 *
	 * @param float $width_mm  Print width with bleed.
	 * @param float $height_mm Print height with bleed.
	 * @return array{0: string, 1: array{x: int, y: int, width: int, height: int}} PNG bytes and the sheet.
	 * @throws InvalidArgumentException When it cannot be made.
	 */
	public static function sheet( float $width_mm, float $height_mm ): array {
		if ( ! self::available() || $width_mm <= 0 || $height_mm <= 0 ) {
			throw new InvalidArgumentException( 'The sheet cannot be made.' );
		}

		$long   = self::SHEET_SIZE - 2 * self::SHEET_MARGIN;
		$width  = $width_mm >= $height_mm ? $long : max( 1, (int) round( $long * $width_mm / $height_mm ) );
		$height = $width_mm >= $height_mm ? max( 1, (int) round( $long * $height_mm / $width_mm ) ) : $long;
		$box    = array(
			'x'      => intdiv( self::SHEET_SIZE - $width, 2 ),
			'y'      => intdiv( self::SHEET_SIZE - $height, 2 ),
			'width'  => $width,
			'height' => $height,
		);
		$im     = imagecreatetruecolor( self::SHEET_SIZE, self::SHEET_SIZE );

		if ( false === $im ) {
			throw new InvalidArgumentException( 'The sheet cannot be made.' );
		}

		imagealphablending( $im, false );
		imagesavealpha( $im, true );
		imagefilledrectangle( $im, 0, 0, self::SHEET_SIZE - 1, self::SHEET_SIZE - 1, (int) imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
		imagealphablending( $im, true );

		// The shadow: twelve faint rectangles, wider and lighter outwards, 2 px down.
		for ( $grow = 12; $grow >= 1; $grow-- ) {
			$near  = ( 13 - $grow ) / 12;
			$color = (int) imagecolorallocatealpha( $im, 0, 0, 0, max( 0, min( 127, 127 - (int) round( 26 * $near * $near ) ) ) );

			imagefilledrectangle( $im, $box['x'] - $grow, $box['y'] - $grow + 2, $box['x'] + $width - 1 + $grow, $box['y'] + $height - 1 + $grow + 2, $color );
		}

		imagealphablending( $im, false );
		imagefilledrectangle( $im, $box['x'], $box['y'], $box['x'] + $width - 1, $box['y'] + $height - 1, (int) imagecolorallocatealpha( $im, 255, 255, 255, 0 ) );

		ob_start();
		$written = imagepng( $im, null, 6 );
		$bytes   = (string) ob_get_clean();

		if ( ! $written || '' === $bytes ) {
			throw new InvalidArgumentException( 'The sheet cannot be written.' );
		}

		return array( $bytes, $box );
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
	 * A print area of a print size's proportions that covers a box, around
	 * its middle and inside the image: on a photo that shows the whole print
	 * (a poster), a design that fills the area reaches the print's edge, with
	 * no strip of the blank print left beside it (the owner's test 2026-11-21).
	 *
	 * @param array{x: int, y: int, width: int, height: int} $box       Box.
	 * @param int                                            $width_mm  Print width.
	 * @param int                                            $height_mm Print height.
	 * @param int                                            $image_w   The photo's width.
	 * @param int                                            $image_h   The photo's height.
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	public static function cover( array $box, int $width_mm, int $height_mm, int $image_w, int $image_h ): array {
		$ratio  = $width_mm / max( 1, $height_mm );
		$width  = $box['width'];
		$height = $box['height'];

		if ( $width / max( 1, $height ) > $ratio ) {
			$height = max( 1, (int) ceil( $width / $ratio ) );
		} else {
			$width = max( 1, (int) ceil( $height * $ratio ) );
		}

		$width  = min( $width, max( 1, $image_w ) );
		$height = min( $height, max( 1, $image_h ) );

		return array(
			'x'      => max( 0, min( $box['x'] - intdiv( $width - $box['width'], 2 ), $image_w - $width ) ),
			'y'      => max( 0, min( $box['y'] - intdiv( $height - $box['height'], 2 ), $image_h - $height ) ),
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Which marker a pixel shows: it differs from the blank render enough and
	 * its hue is a marker's; null otherwise.
	 *
	 * @param GdImage                                   $a      Blank render.
	 * @param GdImage                                   $b      Marker render.
	 * @param int                                       $x      Column.
	 * @param int                                       $y      Row.
	 * @param list<array{0: float, 1: float, 2: float}> $normal Normalised markers.
	 */
	private static function marker_at( GdImage $a, GdImage $b, int $x, int $y, array $normal ): ?int {
		$p     = imagecolorat( $a, $x, $y );
		$q     = imagecolorat( $b, $x, $y );
		$red   = ( $q >> 16 ) & 255;
		$green = ( $q >> 8 ) & 255;
		$blue  = $q & 255;

		if ( abs( ( ( $p >> 16 ) & 255 ) - $red ) + abs( ( ( $p >> 8 ) & 255 ) - $green ) + abs( ( $p & 255 ) - $blue ) < self::MIN_DIFFERENCE || max( $red, $green, $blue ) < self::MIN_BRIGHTNESS ) {
			return null;
		}

		return self::nearest( self::normal( $red, $green, $blue ), $normal );
	}

	/**
	 * A marker's own edges to the pixel: the sampled columns and rows where it
	 * is solid (SOLID of its fullest), then pixel by pixel outwards while the
	 * marker goes on, and one pixel more, as an edge pixel blended with its
	 * surroundings can miss the threshold.
	 *
	 * @param GdImage                                   $a       Blank render.
	 * @param GdImage                                   $b       Marker render.
	 * @param list<array{0: float, 1: float, 2: float}> $normal  Normalised markers.
	 * @param int                                       $index   The marker.
	 * @param array<int, int>                           $columns Its sampled pixels by column.
	 * @param array<int, int>                           $rows    Its sampled pixels by row.
	 * @param int                                       $step    The sampling step.
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	private static function edge( GdImage $a, GdImage $b, array $normal, int $index, array $columns, array $rows, int $step ): array {
		$width            = imagesx( $a );
		$height           = imagesy( $a );
		[ $left, $right ] = self::solid( $columns );
		[ $top, $bottom ] = self::solid( $rows );
		$column           = static function ( int $x ) use ( $a, $b, $normal, $index, &$top, &$bottom, $step ): bool {
			foreach ( range( $top, $bottom, $step ) as $y ) {
				if ( self::marker_at( $a, $b, $x, $y, $normal ) === $index ) {
					return true;
				}
			}

			return false;
		};
		$row              = static function ( int $y ) use ( $a, $b, $normal, $index, &$left, &$right, $step ): bool {
			foreach ( range( $left, $right, $step ) as $x ) {
				if ( self::marker_at( $a, $b, $x, $y, $normal ) === $index ) {
					return true;
				}
			}

			return false;
		};

		$left   = self::grow( $column, $left, -1, $width - 1, $step );
		$right  = self::grow( $column, $right, 1, $width - 1, $step );
		$top    = self::grow( $row, $top, -1, $height - 1, $step );
		$bottom = self::grow( $row, $bottom, 1, $height - 1, $step );
		$left   = max( 0, $left - 1 );
		$top    = max( 0, $top - 1 );
		$right  = min( $width - 1, $right + 1 );
		$bottom = min( $height - 1, $bottom + 1 );

		return array(
			'x'      => $left,
			'y'      => $top,
			'width'  => $right - $left + 1,
			'height' => $bottom - $top + 1,
		);
	}

	/**
	 * An edge moved outwards pixel by pixel while the marker goes on, between
	 * two samples: never further than a step and one.
	 *
	 * @param callable(int): bool $hit   Whether the marker shows at a position.
	 * @param int                 $edge  The edge.
	 * @param int                 $delta -1 or 1.
	 * @param int                 $last  The last position in the photo.
	 * @param int                 $step  The sampling step.
	 */
	private static function grow( callable $hit, int $edge, int $delta, int $last, int $step ): int {
		for ( $i = 0; $i <= $step; $i++ ) {
			$next = $edge + $delta;

			if ( $next < 0 || $next > $last || ! $hit( $next ) ) {
				break;
			}

			$edge = $next;
		}

		return $edge;
	}

	/**
	 * The first and last position where a histogram has at least SOLID of its
	 * largest count: stray pixels elsewhere in the photo fall outside.
	 *
	 * @param array<int, int> $histogram Count by position.
	 * @return array{0: int, 1: int}
	 */
	private static function solid( array $histogram ): array {
		if ( array() === $histogram ) {
			return array( 0, 0 );
		}

		ksort( $histogram );

		$least = self::SOLID * max( $histogram );
		$solid = array_keys( array_filter( $histogram, static fn( int $count ): bool => $count >= $least ) );

		return array( $solid[0], $solid[ count( $solid ) - 1 ] );
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
