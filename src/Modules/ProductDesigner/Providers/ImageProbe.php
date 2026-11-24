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
	 * A cut-out (step 57.21, as `cutout.ts` in the browser): the largest
	 * distance from the backdrop that is still backdrop, the alpha (GD: 0
	 * opaque, 127 clear) of a kept pixel next to a removed one (160 of 255),
	 * and the smallest share of the photo kept.
	 */
	private const CUTOUT_TOLERANCE = 40;

	private const CUTOUT_EDGE = 47;

	private const CUTOUT_KEPT = 0.04;

	/**
	 * The largest photo cut (2048 × 2048; Printify's are 1200 × 1200 and take
	 * about a second, 2400 × 2400 took 4 s and 108 MB), and the memory a
	 * pixel takes while it is cut (measured 15–19 bytes, with room to spare).
	 */
	private const CUTOUT_MAX_PIXELS = 4194304;

	private const CUTOUT_BYTES_PER_PIXEL = 24;

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
	 * A studio photo without its backdrop, as a PNG (step 57.21): the same
	 * rule as `removeBackground()` in the browser (ADR-114). The backdrop is
	 * the median of the edge; from the edge, every pixel within
	 * CUTOUT_TOLERANCE of it, joined to the edge by its four neighbours,
	 * becomes transparent (its colour kept), and a kept pixel next to one
	 * removed is softened to CUTOUT_EDGE.
	 *
	 * `$other` is the same view in another colour of the product: a pixel is
	 * only removed when it is backdrop there too, so a white garment on a
	 * white backdrop keeps its body where the other colour shows the product.
	 * A photo of another size, or one that cannot be read, is ignored, unless
	 * `$required` (a light product): then nothing is cut.
	 *
	 * Null for a photo that is no studio photo (ImageProbe::studio()), cannot
	 * be read, is larger than CUTOUT_MAX_PIXELS or than memory allows, loses
	 * nothing, or would keep less than CUTOUT_KEPT: the photo then stays as
	 * it is.
	 *
	 * @param string      $bytes   The photo.
	 * @param string|null $other   The same view in another colour, or null.
	 * @param bool        $required Whether nothing is cut without a usable `$other`.
	 */
	public static function cutout( string $bytes, ?string $other = null, bool $required = false ): ?string {
		$size = '' === $bytes ? false : getimagesizefromstring( $bytes );

		// A cut of a large photo could stop PHP (memory or time): such a photo stays as it is.
		if ( ! is_array( $size ) || $size[0] * $size[1] > self::CUTOUT_MAX_PIXELS || ! self::room_for( $size[0] * $size[1] ) || ! self::studio( $bytes ) ) {
			return null;
		}

		try {
			$im = self::image( $bytes );
		} catch ( InvalidArgumentException ) {
			return null;
		}

		$width  = imagesx( $im );
		$height = imagesy( $im );
		$ref    = null;

		if ( null !== $other ) {
			try {
				$candidate = self::image( $other );
				$ref       = imagesx( $candidate ) === $width && imagesy( $candidate ) === $height ? $candidate : null;
			} catch ( InvalidArgumentException ) {
				$ref = null;
			}
		}

		if ( $required && null === $ref ) {
			return null;
		}

		$count = $width * $height;
		$back  = self::backdrop( $im );
		$alike = self::alike( $im, $back );

		if ( null !== $ref ) {
			$there = self::alike( $ref, self::backdrop( $ref ) );
			// Backdrop only where both photos show it.
			$alike = $alike & $there;
		}

		$gone    = self::flood( $alike, $width, $height );
		$removed = substr_count( $gone, "\1" );

		if ( 0 === $removed || $count - $removed < self::CUTOUT_KEPT * $count ) {
			return null;
		}

		imagealphablending( $im, false );
		imagesavealpha( $im, true );

		for ( $y = 0; $y < $height; $y++ ) {
			$row = $y * $width;

			for ( $x = 0; $x < $width; $x++ ) {
				$at = $row + $x;

				if ( "\1" === $gone[ $at ] ) {
					imagesetpixel( $im, $x, $y, ( self::pixel( $im, $x, $y ) & 0xFFFFFF ) | ( 127 << 24 ) );
					continue;
				}

				$edge = ( $x > 0 && "\1" === $gone[ $at - 1 ] )
					|| ( $x < $width - 1 && "\1" === $gone[ $at + 1 ] )
					|| ( $y > 0 && "\1" === $gone[ $at - $width ] )
					|| ( $y < $height - 1 && "\1" === $gone[ $at + $width ] );

				if ( $edge ) {
					$color = self::pixel( $im, $x, $y );
					$alpha = max( ( $color >> 24 ) & 127, self::CUTOUT_EDGE );
					imagesetpixel( $im, $x, $y, ( $color & 0xFFFFFF ) | ( $alpha << 24 ) );
				}
			}
		}

		ob_start();
		$written = imagepng( $im );
		$png     = (string) ob_get_clean();

		return $written && '' !== $png ? $png : null;
	}

	/**
	 * A photo's backdrop as `#rrggbb` (the median of its edge), or null when
	 * it cannot be read.
	 *
	 * @param string $bytes The photo.
	 */
	public static function backdrop_of( string $bytes ): ?string {
		try {
			$back = self::backdrop( self::image( $bytes ) );
		} catch ( InvalidArgumentException ) {
			return null;
		}

		return sprintf( '#%02x%02x%02x', $back[0], $back[1], $back[2] );
	}

	/**
	 * Whether a cut-out of this many pixels fits in PHP's memory: about
	 * CUTOUT_BYTES_PER_PIXEL (two decoded photos, the masks and the stack).
	 *
	 * @param int $pixels Pixels.
	 */
	private static function room_for( int $pixels ): bool {
		$limit = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $limit || '-1' === $limit ) {
			return true;
		}

		$unit  = strtolower( substr( $limit, -1 ) );
		$bytes = (int) $limit * ( array(
			'g' => 1073741824,
			'm' => 1048576,
			'k' => 1024,
		)[ $unit ] ?? 1 );

		return $bytes <= 0 || memory_get_usage() + $pixels * self::CUTOUT_BYTES_PER_PIXEL <= $bytes;
	}

	/**
	 * A photo's backdrop: the median of each channel along its edge.
	 *
	 * @param GdImage $image Photo.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function backdrop( GdImage $image ): array {
		$width   = imagesx( $image );
		$height  = imagesy( $image );
		$channel = array( array(), array(), array() );
		$add     = static function ( int $color ) use ( &$channel ): void {
			$channel[0][] = ( $color >> 16 ) & 255;
			$channel[1][] = ( $color >> 8 ) & 255;
			$channel[2][] = $color & 255;
		};

		for ( $x = 0; $x < $width; $x++ ) {
			$add( self::pixel( $image, $x, 0 ) );
			$add( self::pixel( $image, $x, $height - 1 ) );
		}

		for ( $y = 1; $y < $height - 1; $y++ ) {
			$add( self::pixel( $image, 0, $y ) );
			$add( self::pixel( $image, $width - 1, $y ) );
		}

		$median = array();

		foreach ( $channel as $values ) {
			sort( $values );
			$median[] = (int) ( $values[ intdiv( count( $values ), 2 ) ] ?? 255 );
		}

		return array( $median[0], $median[1], $median[2] );
	}

	/**
	 * One byte per pixel, row by row: "\1" where it looks like the backdrop
	 * (within CUTOUT_TOLERANCE, or mostly transparent), "\0" elsewhere.
	 *
	 * @param GdImage                       $image Photo.
	 * @param array{0: int, 1: int, 2: int} $back  Its backdrop.
	 */
	private static function alike( GdImage $image, array $back ): string {
		$width  = imagesx( $image );
		$height = imagesy( $image );
		$limit  = self::CUTOUT_TOLERANCE ** 2;
		$alike  = str_repeat( "\0", $width * $height );

		for ( $y = 0; $y < $height; $y++ ) {
			$row = $y * $width;

			for ( $x = 0; $x < $width; $x++ ) {
				$color = self::pixel( $image, $x, $y );
				$red   = ( ( $color >> 16 ) & 255 ) - $back[0];
				$green = ( ( $color >> 8 ) & 255 ) - $back[1];
				$blue  = ( $color & 255 ) - $back[2];

				if ( ( ( $color >> 24 ) & 127 ) > 63 || $red * $red + $green * $green + $blue * $blue <= $limit ) {
					$alike[ $row + $x ] = "\1";
				}
			}
		}

		return $alike;
	}

	/**
	 * The backdrop pixels joined to the edge by their four neighbours: "\1"
	 * where removed.
	 *
	 * @param string $alike  Backdrop-like pixels ("\1"), row by row.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 */
	private static function flood( string $alike, int $width, int $height ): string {
		$count = $width * $height;
		$gone  = str_repeat( "\0", $count );
		$stack = array();
		$seed  = static function ( int $at ) use ( $alike, &$gone, &$stack ): void {
			if ( "\1" === $alike[ $at ] && "\0" === $gone[ $at ] ) {
				$gone[ $at ] = "\1";
				$stack[]     = $at;
			}
		};

		for ( $x = 0; $x < $width; $x++ ) {
			$seed( $x );
			$seed( ( $height - 1 ) * $width + $x );
		}

		for ( $y = 0; $y < $height; $y++ ) {
			$seed( $y * $width );
			$seed( $y * $width + $width - 1 );
		}

		while ( array() !== $stack ) {
			$at = (int) array_pop( $stack );
			$x  = $at % $width;

			foreach ( array( $x > 0 ? $at - 1 : -1, $x < $width - 1 ? $at + 1 : -1, $at - $width, $at + $width ) as $next ) {
				if ( $next >= 0 && $next < $count && "\1" === $alike[ $next ] && "\0" === $gone[ $next ] ) {
					$gone[ $next ] = "\1";
					$stack[]       = $next;
				}
			}
		}

		return $gone;
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
	 * The stripes of a sheet around a product (ADR-122): STRIPES columns of
	 * full hues, 360 / STRIPES degrees apart, the first red.
	 */
	public const STRIPES = 12;

	/**
	 * Most stripes in a row that may be missing between two that show.
	 */
	private const MAX_SKIP = 3;

	/**
	 * A PNG of the striped sheet in a print area's proportions.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @throws InvalidArgumentException When GD cannot make it.
	 */
	public static function stripes( int $width, int $height ): string {
		$image = imagecreatetruecolor( max( 1, $width ), max( 1, $height ) );

		if ( false === $image ) {
			throw new InvalidArgumentException( 'The stripes cannot be made.' );
		}

		for ( $i = 0; $i < self::STRIPES; $i++ ) {
			[ $red, $green, $blue ] = self::hue( $i * 360 / self::STRIPES );

			$color = imagecolorallocate( $image, $red, $green, $blue );
			$left  = intdiv( $i * $width, self::STRIPES );
			$right = intdiv( ( $i + 1 ) * $width, self::STRIPES ) - 1;

			imagefilledrectangle( $image, $left, 0, $right, $height - 1, false === $color ? 0 : $color );
		}

		ob_start();
		imagepng( $image, null, 9 );

		return (string) ob_get_clean();
	}

	/**
	 * Where on a striped sheet around a product a photo's middle is, as a
	 * share of the sheet's width from its left edge (ADR-122), or null when
	 * the stripes do not show. The product's axis is the middle of what
	 * differs from the blank photo along the row where the print shows most;
	 * the place on the sheet is interpolated between the stripes' edges on
	 * each side of the axis.
	 *
	 * @param string $blank   The blank photo (bytes).
	 * @param string $stripes The photo with the striped sheet (bytes).
	 * @throws InvalidArgumentException When a photo cannot be read.
	 */
	public static function stripe_centre( string $blank, string $stripes ): ?float {
		$a      = self::image( $blank );
		$width  = imagesx( $a );
		$height = imagesy( $a );
		$b      = self::sized( self::image( $stripes ), $width, $height );

		// The row where most of the print shows.
		$best = array( -1, 0 );

		for ( $y = 0; $y < $height; $y += max( 1, intdiv( $height, 100 ) ) ) {
			$count = 0;

			for ( $x = 0; $x < $width; $x += 4 ) {
				$count += null === self::stripe_at( $a, $b, $x, $y ) ? 0 : 1;
			}

			$best = $count > $best[1] ? array( $y, $count ) : $best;
		}

		if ( $best[0] < 0 ) {
			return null;
		}

		// The stripe of each column along that row.
		$row = array();

		for ( $x = 0; $x < $width; $x++ ) {
			$stripe = self::stripe_at( $a, $b, $x, $best[0] );

			if ( null !== $stripe ) {
				$row[ $x ] = $stripe;
			}
		}

		if ( count( $row ) < 2 ) {
			return null;
		}

		$columns = array_keys( $row );
		$axis    = ( min( $columns ) + max( $columns ) ) / 2;
		// Edges between neighbouring stripes, the sheet unwrapped: column => place on the sheet in stripes.
		$edges   = array();
		$shown   = null;
		$current = 0;
		$sign    = 0;

		foreach ( $row as $x => $stripe ) {
			if ( null === $shown ) {
				$shown   = $stripe;
				$current = $stripe;
				continue;
			}

			if ( $stripe === $shown ) {
				continue;
			}

			$step = ( ( $stripe - $shown ) % self::STRIPES + self::STRIPES ) % self::STRIPES;
			// Forwards or backwards by up to MAX_SKIP stripes: a stripe too close to the product's colour may not show.
			$turn = $step <= self::MAX_SKIP ? $step : ( self::STRIPES - $step <= self::MAX_SKIP ? $step - self::STRIPES : 0 );

			// The first edge sets the way round; a turn the other way after it is a blend at an edge or noise.
			if ( 0 === $turn || ( 0 !== $sign && ( $turn > 0 ? 1 : -1 ) !== $sign ) ) {
				continue;
			}

			$sign = $turn > 0 ? 1 : -1;
			// The edge seen is the near edge of the stripe now shown.
			$edges[ $x ] = $turn > 0 ? $current + $turn : $current + $turn + 1;
			$current    += $turn;
			$shown       = $stripe;
		}

		if ( array() === $edges ) {
			// One stripe only: its middle.
			return ( ( (int) reset( $row ) ) + 0.5 ) / self::STRIPES;
		}

		$points = array();

		foreach ( $edges as $x => $position ) {
			$points[] = array( (int) $x, (float) $position );
		}

		if ( 1 === count( $points ) ) {
			// One edge: half a stripe beyond it, towards the axis.
			$position = $points[0][1] + ( $axis < $points[0][0] ? -0.5 : 0.5 ) * $sign;
		} else {
			// Between the edges on each side of the axis; outside them, on from the two nearest.
			$at   = 0;
			$last = count( $points ) - 2;

			while ( $at < $last && $points[ $at + 1 ][0] <= $axis ) {
				++$at;
			}

			[ $a, $b ] = array( $points[ $at ], $points[ $at + 1 ] );

			$position = $a[1] + ( $axis - $a[0] ) / max( 1, $b[0] - $a[0] ) * ( $b[1] - $a[1] );
		}

		$share = fmod( $position / self::STRIPES, 1.0 );

		return $share < 0 ? $share + 1 : $share;
	}

	/**
	 * Height of a drawing of a product view in pixels: twice what the
	 * designer shows (ADR-122).
	 */
	public const DRAWING_HEIGHT = 176;

	/**
	 * Widest a drawn product is, as times its height.
	 */
	private const DRAWING_WIDEST = 4;

	/**
	 * Most a pixel of a plain background differs from the photo's corner,
	 * per channel.
	 */
	private const BACKGROUND = 8;

	/**
	 * Where a line of a drawing starts to show and where it is solid: the
	 * strength of a change in brightness (Sobel).
	 */
	private const LINE_FROM = 18;

	/**
	 * See LINE_FROM.
	 */
	private const LINE_SOLID = 58;

	/**
	 * A line drawing of a product view for the guides of a print around it
	 * (ADR-122), as Printify's editor shows them: grey on transparent, the
	 * product's outline on the blank photo's plain background and the lines
	 * inside it where the brightness changes, none inside the print. With
	 * the part of the print the view shows (from the striped photo) and
	 * where the sheet starts in it, both as shares of the drawing. Null when
	 * the background is not plain or the print does not show.
	 *
	 * @param string $blank   The blank photo (bytes).
	 * @param string $stripes The photo with the striped sheet (bytes).
	 * @return array{png: string, box: array{x: float, y: float, width: float, height: float}, seam: float|null}|null
	 * @throws InvalidArgumentException When a photo cannot be read or the drawing made.
	 */
	public static function drawing( string $blank, string $stripes ): ?array {
		$a      = self::flat( self::image( $blank ) );
		$width  = imagesx( $a );
		$height = imagesy( $a );
		$b      = self::sized( self::flat( self::image( $stripes ) ), $width, $height );
		$corner = self::pixel( $a, 0, 0 );

		// A plain background: the corners alike.
		foreach ( array( array( $width - 1, 0 ), array( 0, $height - 1 ), array( $width - 1, $height - 1 ) ) as [ $x, $y ] ) {
			if ( self::differs( self::pixel( $a, $x, $y ), $corner ) ) {
				return null;
			}
		}

		// The product and the print on a grid.
		$x_step  = max( 1, intdiv( max( $width, $height ), 400 ) );
		$y_step  = $x_step;
		$product = array( $width, $height, -1, -1 );
		$columns = array();
		$rows    = array();

		for ( $y = 0; $y < $height; $y += $y_step ) {
			for ( $x = 0; $x < $width; $x += $x_step ) {
				if ( self::differs( self::pixel( $a, $x, $y ), $corner ) ) {
					$product = array( min( $product[0], $x ), min( $product[1], $y ), max( $product[2], $x ), max( $product[3], $y ) );
				}

				if ( null !== self::stripe_at( $a, $b, $x, $y ) ) {
					$columns[ $x ] = ( $columns[ $x ] ?? 0 ) + 1;
					$rows[ $y ]    = ( $rows[ $y ] ?? 0 ) + 1;
				}
			}
		}

		// A product as light as its background is found by its print alone.
		if ( array() === $columns || array() === $rows ) {
			return null;
		}

		// The print: columns and rows where it shows at least a tenth as much as where it shows most.
		$most_c  = max( $columns );
		$most_r  = max( $rows );
		$columns = array_keys( array_filter( $columns, static fn( int $count ): bool => $count * 10 >= $most_c ) );
		$rows    = array_keys( array_filter( $rows, static fn( int $count ): bool => $count * 10 >= $most_r ) );

		if ( array() === $columns || array() === $rows ) {
			return null;
		}

		$print = array( min( $columns ), min( $rows ), max( $columns ), max( $rows ) );
		$seam  = self::seam( $a, $b, $print );

		// The product and its print with a small margin, DRAWING_HEIGHT high: a near-white product can hide in its background.
		$product = array( min( $product[0], $print[0] ), min( $product[1], $print[1] ), max( $product[2], $print[2] ), max( $product[3], $print[3] ) );
		$margin  = max( 2, intdiv( $product[3] - $product[1], 50 ) );
		$left    = max( 0, $product[0] - $margin );
		$top     = max( 0, $product[1] - $margin );
		$crop_w  = min( $width - 1, $product[2] + $margin ) - $left + 1;
		$crop_h  = min( $height - 1, $product[3] + $margin ) - $top + 1;

		// Not a product but a line across the photo (a shelf, a shadow).
		if ( $crop_w > self::DRAWING_WIDEST * $crop_h ) {
			return null;
		}

		$scale = self::DRAWING_HEIGHT / $crop_h;
		$w     = max( 1, (int) round( $crop_w * $scale ) );
		$h     = self::DRAWING_HEIGHT;
		$small = imagecreatetruecolor( $w, $h );
		$lines = imagecreatetruecolor( $w, $h );

		if ( false === $small || false === $lines ) {
			throw new InvalidArgumentException( 'The drawing cannot be made.' );
		}

		imagecopyresampled( $small, $a, 0, 0, $left, $top, $w, $h, $crop_w, $crop_h );

		$box = array(
			max( 0, (int) floor( ( $print[0] - $left ) * $scale ) ),
			max( 0, (int) floor( ( $print[1] - $top ) * $scale ) ),
			min( $w, (int) ceil( ( $print[2] - $left ) * $scale ) ),
			min( $h, (int) ceil( ( $print[3] - $top ) * $scale ) ),
		);

		// A print of a line only frames nothing.
		if ( $box[2] - $box[0] < 2 || $box[3] - $box[1] < 2 ) {
			return null;
		}

		self::draw( $small, $lines, $corner, $box );

		ob_start();
		$written = imagepng( $lines, null, 9 );
		$png     = (string) ob_get_clean();

		if ( ! $written || '' === $png ) {
			throw new InvalidArgumentException( 'The drawing cannot be written.' );
		}

		$share = static fn( float $value, int $of ): float => round( max( 0.0, min( 1.0, $value / $of ) ), 4 );

		return array(
			'png'  => $png,
			'box'  => array(
				'x'      => $share( $box[0], $w ),
				'y'      => $share( $box[1], $h ),
				'width'  => $share( $box[2] - $box[0], $w ),
				'height' => $share( $box[3] - $box[1], $h ),
			),
			'seam' => null === $seam ? null : $share( ( $seam - $left ) * $scale, $w ),
		);
	}

	/**
	 * Where the striped sheet starts on a photo: the column where its last
	 * stripe meets its first, left to right, along the middle of the print;
	 * null when the photo does not show it well inside the print.
	 *
	 * @param GdImage                               $a     Blank photo.
	 * @param GdImage                               $b     Striped photo.
	 * @param array{0: int, 1: int, 2: int, 3: int} $inside The print: left, top, right, bottom.
	 */
	private static function seam( GdImage $a, GdImage $b, array $inside ): ?int {
		$y      = intdiv( $inside[1] + $inside[3], 2 );
		$inset  = intdiv( $inside[2] - $inside[0], 10 );
		$before = null;

		for ( $x = $inside[0]; $x <= $inside[2]; $x++ ) {
			$stripe = self::stripe_at( $a, $b, $x, $y );

			if ( null === $stripe ) {
				continue;
			}

			if ( self::STRIPES - 1 === $before && 0 === $stripe ) {
				// Not a blend: the last stripe a little before, the first a little after.
				$sure = self::STRIPES - 1 === self::stripe_at( $a, $b, max( $inside[0], $x - 3 ), $y ) && 0 === self::stripe_at( $a, $b, min( $inside[2], $x + 3 ), $y );

				return $sure && $x > $inside[0] + $inset && $x < $inside[2] - $inset ? $x : null;
			}

			$before = $stripe;
		}

		return null;
	}

	/**
	 * Draws a product's lines on a transparent image: its outline, where the
	 * background reached from the edges ends, and outside the print the
	 * changes in brightness inside it.
	 *
	 * @param GdImage                               $photo  The product, scaled.
	 * @param GdImage                               $lines  The drawing, as large.
	 * @param int                                   $corner The background colour.
	 * @param array{0: int, 1: int, 2: int, 3: int} $inside  The print: left, top, right, bottom.
	 */
	private static function draw( GdImage $photo, GdImage $lines, int $corner, array $inside ): void {
		$w          = imagesx( $photo );
		$h          = imagesy( $photo );
		$brightness = array();
		$outside    = array();
		$queue      = array();

		imagealphablending( $lines, false );
		imagesavealpha( $lines, true );
		imagefill( $lines, 0, 0, (int) imagecolorallocatealpha( $lines, 0, 0, 0, 127 ) );

		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$c                          = self::pixel( $photo, $x, $y );
				$brightness[ $y * $w + $x ] = 0.299 * ( ( $c >> 16 ) & 255 ) + 0.587 * ( ( $c >> 8 ) & 255 ) + 0.114 * ( $c & 255 );

				if ( 0 === $x || 0 === $y || $w - 1 === $x || $h - 1 === $y ) {
					$queue[] = array( $x, $y );
				}
			}
		}

		// The background: what is reached from the edges without crossing the product.
		while ( array() !== $queue ) {
			[ $x, $y ] = (array) array_pop( $queue );
			$key       = $y * $w + $x;

			if ( $x < 0 || $y < 0 || $x >= $w || $y >= $h || isset( $outside[ $key ] ) || self::differs( self::pixel( $photo, $x, $y ), $corner ) ) {
				continue;
			}

			$outside[ $key ] = true;
			array_push( $queue, array( $x + 1, $y ), array( $x - 1, $y ), array( $x, $y + 1 ), array( $x, $y - 1 ) );
		}

		$at = static fn( int $x, int $y ): float => $brightness[ $y * $w + $x ];

		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				if ( isset( $outside[ $y * $w + $x ] ) ) {
					continue;
				}

				$strength = 0.0;

				// The outline: next to the background or the image's edge.
				if ( 0 === $x || 0 === $y || $w - 1 === $x || $h - 1 === $y || isset( $outside[ $y * $w + $x + 1 ] ) || isset( $outside[ $y * $w + $x - 1 ] ) || isset( $outside[ ( $y + 1 ) * $w + $x ] ) || isset( $outside[ ( $y - 1 ) * $w + $x ] ) ) {
					$strength = 1.0;
				} elseif ( $x < $inside[0] - 1 || $x > $inside[2] + 1 || $y < $inside[1] - 1 || $y > $inside[3] + 1 ) {
					$gx       = $at( $x + 1, $y - 1 ) + 2 * $at( $x + 1, $y ) + $at( $x + 1, $y + 1 ) - $at( $x - 1, $y - 1 ) - 2 * $at( $x - 1, $y ) - $at( $x - 1, $y + 1 );
					$gy       = $at( $x - 1, $y + 1 ) + 2 * $at( $x, $y + 1 ) + $at( $x + 1, $y + 1 ) - $at( $x - 1, $y - 1 ) - 2 * $at( $x, $y - 1 ) - $at( $x + 1, $y - 1 );
					$strength = max( 0.0, min( 1.0, ( sqrt( $gx * $gx + $gy * $gy ) - self::LINE_FROM ) / ( self::LINE_SOLID - self::LINE_FROM ) ) );
				}

				if ( $strength > 0 ) {
					imagesetpixel( $lines, $x, $y, (int) imagecolorallocatealpha( $lines, 150, 155, 162, max( 0, min( 127, (int) round( 127 * ( 1 - $strength ) ) ) ) ) );
				}
			}
		}
	}

	/**
	 * A pixel's colour; black where GD cannot read it.
	 *
	 * @param GdImage $image Image.
	 * @param int     $x     Column.
	 * @param int     $y     Row.
	 */
	private static function pixel( GdImage $image, int $x, int $y ): int {
		$color = imagecolorat( $image, $x, $y );

		return false === $color ? 0 : $color;
	}

	/**
	 * Whether two colours differ by more than BACKGROUND in a channel.
	 *
	 * @param int $a Colour.
	 * @param int $b Colour.
	 */
	private static function differs( int $a, int $b ): bool {
		return abs( ( ( $a >> 16 ) & 255 ) - ( ( $b >> 16 ) & 255 ) ) > self::BACKGROUND
			|| abs( ( ( $a >> 8 ) & 255 ) - ( ( $b >> 8 ) & 255 ) ) > self::BACKGROUND
			|| abs( ( $a & 255 ) - ( $b & 255 ) ) > self::BACKGROUND;
	}

	/**
	 * The stripe a pixel of the striped photo shows, or null where it
	 * shows the product or is too dull to tell.
	 *
	 * @param GdImage $a Blank photo.
	 * @param GdImage $b Striped photo.
	 * @param int     $x Column.
	 * @param int     $y Row.
	 */
	private static function stripe_at( GdImage $a, GdImage $b, int $x, int $y ): ?int {
		$p     = imagecolorat( $a, $x, $y );
		$q     = imagecolorat( $b, $x, $y );
		$red   = ( $q >> 16 ) & 255;
		$green = ( $q >> 8 ) & 255;
		$blue  = $q & 255;
		$max   = max( $red, $green, $blue );
		$min   = min( $red, $green, $blue );

		if ( abs( ( ( $p >> 16 ) & 255 ) - $red ) + abs( ( ( $p >> 8 ) & 255 ) - $green ) + abs( ( $p & 255 ) - $blue ) < self::MIN_DIFFERENCE || $max < self::MIN_BRIGHTNESS || ( $max - $min ) < 0.35 * $max ) {
			return null;
		}

		$delta = $max - $min;
		$hue   = match ( $max ) {
			$red    => fmod( ( $green - $blue ) / $delta + 6, 6 ),
			$green  => ( $blue - $red ) / $delta + 2,
			default => ( $red - $green ) / $delta + 4,
		};

		return (int) round( $hue * 60 / ( 360 / self::STRIPES ) ) % self::STRIPES;
	}

	/**
	 * A full colour of a hue.
	 *
	 * @param float $degrees Hue.
	 * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
	 */
	private static function hue( float $degrees ): array {
		$h = fmod( $degrees, 360 ) / 60;
		$x = (int) round( 255 * ( 1 - abs( fmod( $h, 2 ) - 1 ) ) );
		$x = max( 0, min( 255, $x ) );

		return match ( (int) floor( $h ) ) {
			0       => array( 255, $x, 0 ),
			1       => array( $x, 255, 0 ),
			2       => array( 0, 255, $x ),
			3       => array( 0, $x, 255 ),
			4       => array( $x, 0, 255 ),
			default => array( 255, 0, $x ),
		};
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
	 * the bleed are drawn by the designer (trims). With room below, the sheet
	 * fits in the square above it (ADR-122: the drawings under the guides).
	 *
	 * @param float $width_mm  Print width with bleed.
	 * @param float $height_mm Print height with bleed.
	 * @param int   $room      Pixels kept free under the sheet, below SHEET_SIZE / 2.
	 * @return array{0: string, 1: array{x: int, y: int, width: int, height: int}} PNG bytes and the sheet.
	 * @throws InvalidArgumentException When it cannot be made.
	 */
	public static function sheet( float $width_mm, float $height_mm, int $room = 0 ): array {
		if ( ! self::available() || $width_mm <= 0 || $height_mm <= 0 || $room < 0 || $room >= self::SHEET_SIZE / 2 ) {
			throw new InvalidArgumentException( 'The sheet cannot be made.' );
		}

		$scale  = min( ( self::SHEET_SIZE - 2 * self::SHEET_MARGIN ) / $width_mm, ( self::SHEET_SIZE - 2 * self::SHEET_MARGIN - $room ) / $height_mm );
		$width  = max( 1, (int) round( $width_mm * $scale ) );
		$height = max( 1, (int) round( $height_mm * $scale ) );
		$box    = array(
			'x'      => intdiv( self::SHEET_SIZE - $width, 2 ),
			'y'      => intdiv( self::SHEET_SIZE - $room - $height, 2 ),
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
	 * An image on white: a transparent background becomes plain.
	 *
	 * @param GdImage $image Image.
	 * @throws InvalidArgumentException When it cannot be made.
	 */
	private static function flat( GdImage $image ): GdImage {
		$flat = imagecreatetruecolor( imagesx( $image ), imagesy( $image ) );

		if ( false === $flat ) {
			throw new InvalidArgumentException( 'The image cannot be flattened.' );
		}

		imagefill( $flat, 0, 0, (int) imagecolorallocate( $flat, 255, 255, 255 ) );
		imagealphablending( $flat, true );
		imagecopy( $flat, $image, 0, 0, 0, 0, imagesx( $image ), imagesy( $image ) );

		return $flat;
	}

	/**
	 * An image at a size: a render of the same view can come back larger or smaller.
	 *
	 * @param GdImage $image  Image.
	 * @param int     $width  Width.
	 * @param int     $height Height.
	 * @throws InvalidArgumentException When it cannot be scaled.
	 */
	private static function sized( GdImage $image, int $width, int $height ): GdImage {
		if ( imagesx( $image ) === $width && imagesy( $image ) === $height ) {
			return $image;
		}

		$scaled = imagescale( $image, $width, $height );

		if ( false === $scaled ) {
			throw new InvalidArgumentException( 'The stripes render cannot be scaled.' );
		}

		return $scaled;
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
