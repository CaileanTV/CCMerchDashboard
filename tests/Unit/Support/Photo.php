<?php
/**
 * Synthetic product photos for the catalog import's tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

/**
 * Writes an RGB PNG of a background with filled rectangles, without GD (a
 * provider's render: the product, and a marker where a side's print lands).
 */
final class Photo {

	/**
	 * A PNG.
	 *
	 * @param int                                                                           $width      Width.
	 * @param int                                                                           $height     Height.
	 * @param array{0: int, 1: int, 2: int}                                                 $background Background colour.
	 * @param list<array{0: int, 1: int, 2: int, 3: int, 4: array{0: int, 1: int, 2: int}}> $rects      Rectangles: x, y, width, height and colour.
	 */
	public static function png( int $width, int $height, array $background, array $rects = array() ): string {
		$pixel = static fn( array $color ): string => chr( $color[0] ) . chr( $color[1] ) . chr( $color[2] );
		$plain = str_repeat( $pixel( $background ), $width );
		$data  = '';

		for ( $y = 0; $y < $height; $y++ ) {
			$row = $plain;

			foreach ( $rects as [ $left, $top, $w, $h, $color ] ) {
				if ( $y >= $top && $y < $top + $h ) {
					$row = substr_replace( $row, str_repeat( $pixel( $color ), $w ), $left * 3, $w * 3 );
				}
			}

			$data .= "\0" . $row;
		}

		$chunk = static fn( string $type, string $body ): string => pack( 'N', strlen( $body ) ) . $type . $body . pack( 'N', crc32( $type . $body ) );

		return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) ) . $chunk( 'IDAT', (string) gzcompress( $data ) ) . $chunk( 'IEND', '' );
	}
}
