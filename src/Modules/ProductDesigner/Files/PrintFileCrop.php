<?php
/**
 * A print file cut to the shape of one size.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Files;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-115 pkt. 4: a format group's print file has the shape of its largest
 * size; an order line of another size gets the file cut in the middle to its
 * own shape (at most 3 % of one direction). The stored file never changes:
 * the cut is kept as a file of its own, named by the file and the share kept,
 * and made again when it is gone.
 */
final class PrintFileCrop {

	/**
	 * Constructor.
	 *
	 * @param DesignerFiles $files Files (the kept cut).
	 */
	public function __construct( private DesignerFiles $files ) {
	}

	/**
	 * The file cut to the shares it keeps across and down; the bytes as they
	 * are when nothing is cut or the image cannot be cut.
	 *
	 * @param string $bytes  The stored file.
	 * @param string $mime   `image/png` or `image/jpeg`.
	 * @param string $name   The stored file's name (the kept cut's name follows it).
	 * @param float  $across Share of the width kept.
	 * @param float  $down   Share of the height kept.
	 */
	public function cut( string $bytes, string $mime, string $name, float $across, float $down ): string {
		if ( ( $across >= 1.0 && $down >= 1.0 ) || $across <= 0.0 || $down <= 0.0 ) {
			return $bytes;
		}

		$kept = md5( $name . '|' . round( $across, 5 ) . '|' . round( $down, 5 ) ) . ( 'image/jpeg' === $mime ? '.jpg' : '.png' );
		$path = $this->files->path( $kept );

		if ( null !== $path ) {
			$cached = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file the plugin made.

			if ( false !== $cached && '' !== $cached ) {
				return $cached;
			}
		}

		$cut = self::image( $bytes, $mime, $across, $down );

		if ( null === $cut ) {
			return $bytes;
		}

		$this->files->write( $kept, $cut );

		return $cut;
	}

	/**
	 * The middle of an image, or null when GD cannot read or write it.
	 *
	 * @param string $bytes  Image.
	 * @param string $mime   `image/png` or `image/jpeg`.
	 * @param float  $across Share of the width kept.
	 * @param float  $down   Share of the height kept.
	 */
	public static function image( string $bytes, string $mime, float $across, float $down ): ?string {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}

		wp_raise_memory_limit( 'image' );

		$image = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A broken file gives the stored file instead.

		if ( false === $image ) {
			return null;
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );
		$keep_w = max( 1, min( $width, (int) round( $width * min( 1.0, $across ) ) ) );
		$keep_h = max( 1, min( $height, (int) round( $height * min( 1.0, $down ) ) ) );
		$cut    = imagecrop(
			$image,
			array(
				'x'      => intdiv( $width - $keep_w, 2 ),
				'y'      => intdiv( $height - $keep_h, 2 ),
				'width'  => $keep_w,
				'height' => $keep_h,
			)
		);

		unset( $image );

		if ( false === $cut ) {
			return null;
		}

		ob_start();

		if ( 'image/jpeg' === $mime ) {
			$done = imagejpeg( $cut, null, 95 );
		} else {
			imagesavealpha( $cut, true );
			$done = imagepng( $cut, null, 6 );
		}

		$out = (string) ob_get_clean();

		unset( $cut );

		return $done && '' !== $out ? $out : null;
	}
}
