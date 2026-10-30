<?php
/**
 * Tests for the embroidery packs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey\Functions;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\ThreadPacks;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[CoversClass( ThreadPacks::class )]
#[CoversClass( BaseView::class )]
final class ThreadPacksTest extends TestCase {

	private const RED = 0xCB333B;

	private const YELLOW = 0xFFCD01;

	private const BLACK = 0x000000;

	/**
	 * Files to remove.
	 *
	 * @var list<string>
	 */
	private array $files = array();

	protected function tearDown(): void {
		array_map( 'unlink', $this->files );
		parent::tearDown();
	}

	public function test_gelato_s_pack_exists_without_stored_packs(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$packs = ThreadPacks::all();

		self::assertSame( array( 'gelato' ), array_column( $packs, 'id' ) );
		self::assertCount( 15, $packs[0]['threads'] );
		self::assertSame( 6, $packs[0]['max'] );
		self::assertSame(
			array(
				'code' => '1838',
				'name' => 'Red',
				'hex'  => '#cb333b',
			),
			$packs[0]['threads'][9]
		);
	}

	public function test_a_stored_pack_counts_and_a_broken_one_does_not(): void {
		$thread = array(
			'code' => 'A1',
			'name' => 'Lime',
			'hex'  => '#AABB00',
		);

		Functions\when( 'get_option' )->justReturn(
			array(
				array(
					'id'      => 'own',
					'name'    => 'Own',
					'max'     => 1,
					'threads' => array( $thread ),
				),
				array(
					'id'      => 'no-threads',
					'name'    => 'Empty',
					'max'     => 1,
					'threads' => array(),
				),
				array(
					'id'      => 'too-many',
					'name'    => 'Limit above the threads',
					'max'     => 2,
					'threads' => array( $thread ),
				),
				array(
					'id'      => 'twice',
					'name'    => 'The same code twice',
					'max'     => 1,
					'threads' => array( $thread, $thread ),
				),
				'broken',
			)
		);

		self::assertSame( array( 'own' ), array_column( ThreadPacks::all(), 'id' ) );
		self::assertSame( '#aabb00', ThreadPacks::find( 'own' )['threads'][0]['hex'] ?? null );
		self::assertNull( ThreadPacks::find( 'gelato' ), 'Stored packs replace the default.' );
	}

	#[RequiresPhpExtension( 'gd' )]
	public function test_a_print_file_may_hold_the_threads_of_the_pack_up_to_its_limit(): void {
		$pack = self::pack( 2 );

		self::assertTrue( ThreadPacks::check( $this->png( array( self::RED, self::YELLOW ) ), $pack ) );
		self::assertTrue( ThreadPacks::check( $this->png( array( self::RED + 0x010101 ) ), $pack ), 'Canvas noise.' );
		self::assertTrue( ThreadPacks::check( $this->png( array() ), $pack ), 'Nothing visible.' );
		self::assertFalse( ThreadPacks::check( $this->png( array( self::RED, self::YELLOW, self::BLACK ) ), $pack ), 'One thread too many.' );
		self::assertFalse( ThreadPacks::check( $this->png( array( 0x00FF00 ) ), $pack ), 'Not a thread.' );
		self::assertFalse( ThreadPacks::check( $this->png( array( self::RED ), true ), $pack ), 'Half transparent.' );
	}

	#[RequiresPhpExtension( 'gd' )]
	public function test_a_file_that_is_no_image_is_refused(): void {
		$path          = (string) tempnam( sys_get_temp_dir(), 'mb' );
		$this->files[] = $path;
		file_put_contents( $path, 'not a png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test file.

		self::assertFalse( ThreadPacks::check( $path, self::pack( 2 ) ) );
	}

	public function test_a_side_remembers_its_pack_and_an_older_side_has_none(): void {
		$stored = array(
			'key'       => 'front',
			'name'      => 'Front',
			'width'     => 100,
			'height'    => 100,
			'layers'    => array( 'background' => 1 ),
			'area'      => array(
				'x'      => 0,
				'y'      => 0,
				'width'  => 10,
				'height' => 10,
			),
			'width_mm'  => 10,
			'height_mm' => 10,
			'dpi'       => 300,
		);

		self::assertSame( '', BaseView::from_array( $stored )->thread_pack );
		self::assertSame( $stored, BaseView::from_array( $stored )->to_array(), 'A printed side stores what it always did.' );

		$side = array_merge( $stored, array( 'thread_pack' => 'gelato' ) );

		self::assertSame( 'gelato', BaseView::from_array( $side )->thread_pack );
		self::assertSame( $side, BaseView::from_array( $side )->to_array() );
	}

	/**
	 * A pack of red, yellow and black.
	 *
	 * @param int $max The limit.
	 * @return array{id: string, name: string, max: int, threads: list<array{code: string, name: string, hex: string}>}
	 */
	private static function pack( int $max ): array {
		return array(
			'id'      => 'test',
			'name'    => 'Test',
			'max'     => $max,
			'threads' => array(
				array(
					'code' => '1',
					'name' => 'Red',
					'hex'  => '#cb333b',
				),
				array(
					'code' => '2',
					'name' => 'Yellow',
					'hex'  => '#ffcd01',
				),
				array(
					'code' => '3',
					'name' => 'Black',
					'hex'  => '#000000',
				),
			),
		);
	}

	/**
	 * A transparent PNG with a block per colour.
	 *
	 * @param array<int, int> $colors 0xRRGGBB.
	 * @param bool            $half   Whether the blocks are half transparent.
	 * @return string Path.
	 */
	private function png( array $colors, bool $half = false ): string {
		$image = imagecreatetruecolor( 40, 10 );

		self::assertNotFalse( $image );
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		imagefill( $image, 0, 0, (int) imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );

		foreach ( $colors as $index => $color ) {
			imagefilledrectangle( $image, $index * 10, 0, $index * 10 + 9, 9, (int) imagecolorallocatealpha( $image, ( $color >> 16 ) & 0xFF, ( $color >> 8 ) & 0xFF, $color & 0xFF, $half ? 60 : 0 ) );
		}

		$path          = (string) tempnam( sys_get_temp_dir(), 'mb' );
		$this->files[] = $path;
		imagepng( $image, $path );

		return $path;
	}
}
