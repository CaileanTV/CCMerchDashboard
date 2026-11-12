<?php
/**
 * The print resolution of a print file when it is sent.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Files;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Print files made before 2.1.0 have no resolution in the file (a canvas
 * writes PNG without `pHYs` and JPEG with a JFIF density of 1:1), so
 * programs read them as 72 dpi: 295 mm at 300 dpi (3484 px) showed as
 * 1229 mm. When such a file is downloaded or fetched by a print provider,
 * the side's dpi from its base product is written into the bytes sent. The
 * stored file, the design and the base product are never changed, and a
 * file that already says its resolution is sent as it is.
 */
final class PrintFileDpi {

	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	/**
	 * Constructor.
	 *
	 * @param BaseProductRepository $bases Base products.
	 */
	public function __construct( private BaseProductRepository $bases ) {
	}

	/**
	 * A print file's bytes with the side's dpi, for a version row (with
	 * `base_id`) and a side key; the bytes as they are when the dpi is unknown.
	 *
	 * @param string              $bytes File bytes.
	 * @param string              $mime  `image/png` or `image/jpeg`.
	 * @param array<string,mixed> $row   The version row.
	 * @param string              $side  Side key.
	 */
	public function apply( string $bytes, string $mime, array $row, string $side ): string {
		try {
			$base = is_numeric( $row['base_id'] ?? null ) ? $this->bases->find( (int) $row['base_id'] ) : null;
		} catch ( DatabaseException ) {
			return $bytes;
		}

		foreach ( null === $base ? array() : $base->views as $view ) {
			if ( $view->key === $side ) {
				return self::with_dpi( $bytes, $mime, $view->dpi );
			}
		}

		return $bytes;
	}

	/**
	 * Bytes with a dpi when they have none (PNG or JPEG by MIME type).
	 *
	 * @param string $bytes File bytes.
	 * @param string $mime  MIME type.
	 * @param int    $dpi   Dots per inch.
	 */
	public static function with_dpi( string $bytes, string $mime, int $dpi ): string {
		if ( $dpi < 1 ) {
			return $bytes;
		}

		return 'image/jpeg' === $mime ? self::jpeg( $bytes, $dpi ) : self::png( $bytes, $dpi );
	}

	/**
	 * A PNG with a `pHYs` chunk after `IHDR`, unless it has one.
	 *
	 * @param string $bytes PNG.
	 * @param int    $dpi   Dots per inch.
	 */
	public static function png( string $bytes, int $dpi ): string {
		if ( strlen( $bytes ) < 33 || ! str_starts_with( $bytes, self::PNG_SIGNATURE ) || 'IHDR' !== substr( $bytes, 12, 4 ) ) {
			return $bytes;
		}

		$offset = 8;
		$length = strlen( $bytes );

		// Already says its resolution: as it is.
		while ( $offset + 12 <= $length ) {
			$size = unpack( 'N', substr( $bytes, $offset, 4 ) );
			$type = substr( $bytes, $offset + 4, 4 );

			if ( 'pHYs' === $type ) {
				return $bytes;
			}

			if ( 'IDAT' === $type || ! is_array( $size ) ) {
				break;
			}

			$offset += 12 + (int) $size[1];
		}

		$per_metre = (int) round( $dpi / 0.0254 );
		$data      = 'pHYs' . pack( 'NNC', $per_metre, $per_metre, 1 );
		$chunk     = pack( 'N', 9 ) . $data . pack( 'N', crc32( $data ) );
		$ihdr_end  = 8 + 12 + 13;

		return substr( $bytes, 0, $ihdr_end ) . $chunk . substr( $bytes, $ihdr_end );
	}

	/**
	 * A JPEG whose JFIF header says the dpi, unless it says a resolution in
	 * dots per inch or per cm already; a JPEG without JFIF gets a header.
	 *
	 * @param string $bytes JPEG.
	 * @param int    $dpi   Dots per inch.
	 */
	public static function jpeg( string $bytes, int $dpi ): string {
		if ( strlen( $bytes ) < 4 || "\xFF\xD8" !== substr( $bytes, 0, 2 ) ) {
			return $bytes;
		}

		$density = pack( 'n', min( 65535, $dpi ) );

		if ( "\xFF\xE0" === substr( $bytes, 2, 2 ) && "JFIF\0" === substr( $bytes, 6, 5 ) && strlen( $bytes ) > 18 ) {
			if ( "\x00" !== $bytes[13] ) {
				return $bytes;
			}

			return substr( $bytes, 0, 13 ) . "\x01" . $density . $density . substr( $bytes, 18 );
		}

		return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01" . $density . $density . "\x00\x00" . substr( $bytes, 2 );
	}
}
