<?php
/**
 * Tests for the files of a design's submission (ADR-080 pkt. 5, step 30.9).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SubmissionFiles::class )]
final class SubmissionFilesTest extends TestCase {

	public function test_slots_name_a_print_file_per_side_or_a_mockup_per_side_and_colour(): void {
		self::assertSame( array( 'print', 'front', null ), SubmissionFiles::slot( 'print:front' ) );
		self::assertSame( array( 'mockup', 'back-2', 'sand_light' ), SubmissionFiles::slot( 'mockup:back-2:sand_light' ) );
		self::assertSame( array( 'mockup', 'front', 'gr%c3%b8n' ), SubmissionFiles::slot( 'mockup:front:gr%c3%b8n' ), 'A colour key as sanitize_title() makes it.' );

		foreach ( array( '', 'print', 'print:', 'print:Front', 'print:front:black', 'mockup:front', 'mockup:front:', 'cover:front', 'print:../x', 'mockup:front:black/../' ) as $slot ) {
			self::assertNull( SubmissionFiles::slot( $slot ), $slot );
		}
	}

	public function test_a_side_is_used_when_it_shows_something(): void {
		$design = array(
			'version' => 1,
			'sides'   => array(
				'front'  => array( array( 'id' => 'a' ) ),
				'back'   => array( array( 'id' => 'b', 'hidden' => true ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				'sleeve' => array(),
				'neck'   => array( array( 'id' => 'c', 'hidden' => true ), array( 'id' => 'd', 'hidden' => false ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
		);

		self::assertSame( array( 'front', 'neck' ), SubmissionFiles::used_sides( $design ) );
		self::assertSame( array(), SubmissionFiles::used_sides( array( 'sides' => 'nope' ) ) );
	}

	public function test_files_are_complete_only_for_the_version_they_were_rendered_for(): void {
		$version          = self::version();
		$version['files'] = (string) wp_json_encode( self::files( SubmissionFiles::stamp( $version ), array( 'front' ), array( 'black', 'sand' ) ) );

		self::assertTrue( SubmissionFiles::complete( $version ) );
		self::assertSame( array( 'p-front.png', 'm-front-black.jpg', 'm-front-sand.jpg' ), SubmissionFiles::names( $version['files'] ) );

		$changed           = $version;
		$changed['design'] = (string) wp_json_encode( array( 'sides' => array( 'front' => array( array( 'id' => 'x' ) ) ) ) );
		self::assertFalse( SubmissionFiles::complete( $changed ), 'Another design: another stamp.' );

		$rebased                 = $version;
		$rebased['base_version'] = 3;
		self::assertFalse( SubmissionFiles::complete( $rebased ), 'Another base version.' );

		$missing          = $version;
		$missing['files'] = (string) wp_json_encode( self::files( SubmissionFiles::stamp( $version ), array( 'front' ), array( 'black' ) ) );
		self::assertFalse( SubmissionFiles::complete( $missing ), 'A mockup per chosen colour.' );

		$extra          = $version;
		$extra['files'] = (string) wp_json_encode( self::files( SubmissionFiles::stamp( $version ), array( 'front', 'back' ), array( 'black', 'sand' ) ) );
		self::assertFalse( SubmissionFiles::complete( $extra ), 'No file of an unused side.' );

		$broken          = $version;
		$broken['files'] = '{"stamp":"x","print":{"front":{"file":1}}}';
		self::assertSame( array(), SubmissionFiles::names( $broken['files'] ) );
		self::assertFalse( SubmissionFiles::complete( $broken ) );
		self::assertSame(
			array(
				'stamp'   => '',
				'print'   => array(),
				'mockups' => array(),
			),
			SubmissionFiles::decode( null )
		);
	}

	/**
	 * A version row as stored.
	 *
	 * @return array<string, mixed>
	 */
	private static function version(): array {
		return array(
			'design'       => (string) wp_json_encode(
				array(
					'version' => 1,
					'sides'   => array(
						'front' => array( array( 'id' => 'a' ) ),
						'back'  => array(),
					),
				)
			),
			'colors'       => '["black","sand"]',
			'base_version' => 2,
			'files'        => null,
		);
	}

	/**
	 * Stored files.
	 *
	 * @phpstan-param list<string> $sides
	 * @phpstan-param list<string> $colors
	 *
	 * @param string   $stamp  Stamp.
	 * @param string[] $sides  Sides.
	 * @param string[] $colors Colours.
	 * @return array<string, mixed>
	 */
	private static function files( string $stamp, array $sides, array $colors ): array {
		$file  = static fn( string $name ): array => array(
			'file'   => $name,
			'mime'   => 'image/png',
			'width'  => 10,
			'height' => 10,
			'bytes'  => 100,
		);
		$files = array(
			'stamp'   => $stamp,
			'print'   => array(),
			'mockups' => array(),
		);

		foreach ( $sides as $side ) {
			$files['print'][ $side ] = $file( 'p-' . $side . '.png' );

			foreach ( $colors as $color ) {
				$files['mockups'][ $side ][ $color ] = $file( 'm-' . $side . '-' . $color . '.jpg' );
			}
		}

		return $files;
	}
}
