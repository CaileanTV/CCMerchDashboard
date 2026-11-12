<?php
/**
 * The dpi of old print files when they are sent.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Modules\ProductDesigner\Files\PrintFileDpi;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PrintFileDpi::class )]
final class PrintFileDpiTest extends TestCase {

	/**
	 * A 1×1 PNG as a canvas writes it (no pHYs).
	 */
	private static function png(): string {
		return (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A fixed 1x1 test image.
	}

	public function test_an_old_png_gets_the_sides_dpi_after_ihdr_and_a_png_with_one_stays(): void {
		$old   = self::png();
		$fixed = PrintFileDpi::with_dpi( $old, 'image/png', 300 );

		self::assertSame( strlen( $old ) + 21, strlen( $fixed ) );
		self::assertSame( 'pHYs', substr( $fixed, 37, 4 ) );
		// 300 dpi = 11811 pixels per metre, unit metre; so 3484 px is 295 mm.
		self::assertSame(
			array(
				'x'    => 11811,
				'y'    => 11811,
				'unit' => 1,
			),
			unpack( 'Nx/Ny/Cunit', substr( $fixed, 41, 9 ) )
		);
		self::assertSame( crc32( substr( $fixed, 37, 13 ) ), unpack( 'N', substr( $fixed, 50, 4 ) )[1] ?? null );
		self::assertSame( substr( $old, 33 ), substr( $fixed, 54 ), 'The image data is unchanged.' );
		self::assertSame( $fixed, PrintFileDpi::with_dpi( $fixed, 'image/png', 150 ), 'A file that says its resolution is sent as it is.' );
	}

	public function test_an_old_jpeg_gets_the_dpi_and_one_in_dpi_stays(): void {
		$old   = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
		$fixed = PrintFileDpi::with_dpi( $old, 'image/jpeg', 300 );

		self::assertSame( "\x01\x01\x2C\x01\x2C", substr( $fixed, 13, 5 ) );
		self::assertSame( strlen( $old ), strlen( $fixed ) );
		self::assertSame( $fixed, PrintFileDpi::with_dpi( $fixed, 'image/jpeg', 150 ) );

		$bare = "\xFF\xD8\xFF\xDB\x00\x02\xFF\xD9";
		self::assertSame( "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x01\x2C\x01\x2C\x00\x00\xFF\xDB\x00\x02\xFF\xD9", PrintFileDpi::with_dpi( $bare, 'image/jpeg', 300 ) );
	}

	public function test_anything_else_is_sent_as_it_is(): void {
		self::assertSame( 'not an image', PrintFileDpi::with_dpi( 'not an image', 'image/png', 300 ) );
		self::assertSame( 'not an image', PrintFileDpi::with_dpi( 'not an image', 'image/jpeg', 300 ) );
		self::assertSame( self::png(), PrintFileDpi::with_dpi( self::png(), 'image/png', 0 ) );
	}
}
