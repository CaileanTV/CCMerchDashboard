<?php
/**
 * Tests for a clipart's definition (ADR-121, step 56.6).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey\Functions;
use Closure;
use MerchBoard\Modules\ProductDesigner\Files\Clipart;
use MerchBoard\Modules\ProductDesigner\Files\ClipartDefinition;
use MerchBoard\Modules\ProductDesigner\Files\CreatorUploads;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( ClipartDefinition::class )]
#[CoversClass( Clipart::class )]
final class ClipartDefinitionTest extends TestCase {

	private const WIDTH = 1300;

	private const HEIGHT = 2000;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * A definition as the browser bakes it, with synthetic numbers.
	 *
	 * @return array<string, mixed>
	 */
	private static function data(): array {
		$json = '{
			"bases": [9, 4],
			"parts": [{
				"label": "",
				"box": {"x": 30, "y": 60, "width": 1200, "height": 1800},
				"stroke": "#000000",
				"scale": 0.75,
				"paint": {"type": "solid", "color": "#FFFFFF"},
				"gradient": {"type": "gradient", "from": "#65b6f5", "to": "#f1a90c", "angle": 45}
			}],
			"steps": [
				{"type": "image", "rect": {"x": 130, "y": 100, "width": 400, "height": 700}, "image": {"x": 2500, "y": 0}},
				{"type": "part", "part": 0, "rect": {"x": 10, "y": 40, "width": 1240, "height": 1840}, "fill": {"x": 0, "y": 0}, "grown": {"x": 1241, "y": 0}}
			],
			"atlas": {"file": "0123456789abcdef0123456789abcdef.png", "width": 3000, "height": 1840},
			"palette": null,
			"locked": true
		}';

		return (array) json_decode( $json, true );
	}

	private static function read( mixed $data ): ?ClipartDefinition {
		return ClipartDefinition::from_data( $data, self::WIDTH, self::HEIGHT );
	}

	public function test_what_the_browser_bakes_is_taken_and_stored_unchanged(): void {
		$definition = self::read( self::data() );

		self::assertNotNull( $definition );
		self::assertSame( array( 4, 9 ), $definition->bases );
		self::assertSame( '#ffffff', $definition->parts[0]['paint']['color'] ?? null );
		self::assertSame( 0.75, $definition->parts[0]['scale'] );
		self::assertTrue( $definition->locked );
		self::assertEquals( $definition, ClipartDefinition::from_stored( $definition->to_json(), self::WIDTH, self::HEIGHT ) );
	}

	public function test_a_whole_scale_comes_back_from_json_as_a_number(): void {
		$data                      = self::data();
		$data['parts'][0]['scale'] = 1;

		$definition = self::read( $data );

		self::assertNotNull( $definition );
		self::assertSame( 1.0, $definition->parts[0]['scale'] );
		self::assertEquals( $definition, ClipartDefinition::from_stored( $definition->to_json(), self::WIDTH, self::HEIGHT ) );
	}

	public function test_a_flat_clipart_has_only_its_base_products_and_lock(): void {
		$flat = array(
			'bases'  => array( 4 ),
			'parts'  => array(),
			'steps'  => array(),
			'locked' => false,
		);

		self::assertNull( self::read( $flat )?->atlas );
		self::assertTrue( self::read( $flat )?->offered_on( 4 ) );
		self::assertNull( self::read( array_merge( $flat, array( 'palette' => array( '#000000' ) ) ) ) );
	}

	/**
	 * Changes that make a definition invalid.
	 *
	 * @return iterable<string, array{Closure(array<string, mixed>): array<string, mixed>}>
	 */
	public static function broken(): iterable {
		$set = static function ( array $path, mixed $value ): Closure {
			return static function ( array $data ) use ( $path, $value ): array {
				$at = &$data;

				foreach ( $path as $key ) {
					$at = &$at[ $key ];
				}

				$at = $value;

				return $data;
			};
		};

		yield 'no lock' => array( $set( array( 'locked' ), null ) );
		yield 'no base products said' => array(
			static function ( array $data ): array {
				unset( $data['bases'] );

				return $data;
			},
		);
		yield 'a base product as text' => array( $set( array( 'bases' ), array( '4' ) ) );
		yield 'no atlas' => array( $set( array( 'atlas' ), null ) );
		yield 'an atlas with a path' => array( $set( array( 'atlas', 'file' ), '../../wp-config.png' ) );
		yield 'an atlas with a line break' => array( $set( array( 'atlas', 'file' ), "0123456789abcdef0123456789abcdef.png\n" ) );
		yield 'an atlas too large' => array( $set( array( 'atlas', 'width' ), 8001 ) );
		yield 'too many parts' => array( $set( array( 'parts' ), array_fill( 0, 9, self::data()['parts'][0] ) ) );
		yield 'a part without its step' => array( $set( array( 'parts' ), array_fill( 0, 2, self::data()['parts'][0] ) ) );
		yield 'a part twice' => array( $set( array( 'steps', 0 ), self::data()['steps'][1] ) );
		yield 'an unknown part' => array( $set( array( 'steps', 1, 'part' ), 1 ) );
		yield 'an unknown step' => array( $set( array( 'steps', 0, 'type' ), 'text' ) );
		yield 'a step past the clipart' => array( $set( array( 'steps', 1, 'rect', 'x' ), 70 ) );
		yield 'a step before the clipart' => array( $set( array( 'steps', 1, 'rect', 'x' ), -1 ) );
		yield 'a place past the atlas' => array( $set( array( 'steps', 1, 'grown', 'x' ), 1761 ) );
		yield 'a huge place' => array( $set( array( 'steps', 1, 'fill', 'x' ), PHP_INT_MAX ) );
		yield 'a stroke without its mask' => array( $set( array( 'steps', 1, 'grown' ), null ) );
		yield 'a mask without a stroke' => array( $set( array( 'parts', 0, 'stroke' ), null ) );
		yield 'a colour with a line break' => array( $set( array( 'parts', 0, 'paint', 'color' ), "#ffffff\n" ) );
		yield 'a colour by name' => array( $set( array( 'parts', 0, 'stroke' ), 'black' ) );
		yield 'an angle of 360' => array( $set( array( 'parts', 0, 'gradient', 'angle' ), 360 ) );
		yield 'a scale of 2' => array( $set( array( 'parts', 0, 'scale' ), 2 ) );
		yield 'a label too long' => array( $set( array( 'parts', 0, 'label' ), str_repeat( 'a', 101 ) ) );
		yield 'a label with a control character' => array( $set( array( 'parts', 0, 'label' ), "a\tb" ) );
		yield 'a palette without the start colours' => array( $set( array( 'palette' ), array( '#ffffff' ) ) );
		yield 'an empty palette' => array( $set( array( 'palette' ), array() ) );
	}

	/**
	 * A broken definition reads as none.
	 *
	 * @param Closure $change The change.
	 * @phpstan-param Closure(array<string, mixed>): array<string, mixed> $change
	 */
	#[DataProvider( 'broken' )]
	public function test_a_broken_definition_reads_as_none( Closure $change ): void {
		self::assertNull( self::read( $change( self::data() ) ) );
	}

	public function test_a_palette_holds_every_start_colour(): void {
		$data            = self::data();
		$data['palette'] = array( '#FFFFFF', '#65B6F5', '#f1a90c', '#f1a90c' );

		self::assertSame( array( '#ffffff', '#65b6f5', '#f1a90c' ), self::read( $data )?->palette );
	}

	public function test_a_clipart_larger_than_its_limit_reads_as_none(): void {
		self::assertNull( ClipartDefinition::from_data( self::data(), 4001, 4001 ) );
		self::assertNull( ClipartDefinition::from_data( self::data(), 8001, 100 ) );
		self::assertNull( ClipartDefinition::from_stored( '{', self::WIDTH, self::HEIGHT ) );
		self::assertNull( ClipartDefinition::from_stored( null, self::WIDTH, self::HEIGHT ) );
	}

	public function test_only_the_base_products_change(): void {
		$definition = self::read( self::data() );

		self::assertNotNull( $definition );

		$next = $definition->with_bases( array( 12 ) );

		self::assertNotNull( $next );
		self::assertSame( array( 12 ), $next->bases );
		self::assertSame( $definition->parts, $next->parts );
		self::assertTrue( $definition->with_bases( null )?->offered_on( 999 ) );
		self::assertFalse( $definition->with_bases( array() )?->offered_on( 4 ) );
		self::assertNull( $definition->with_bases( array( 0 ) ) );
	}

	public function test_a_clipart_from_before_is_offered_on_all_and_an_artwork_only_where_chosen(): void {
		$clipart = static fn( string $kind, ?string $retired, ?ClipartDefinition $definition ): Clipart => new Clipart( 1, $kind, 'Star', 'a.png', 'image/png', 10, 10, $retired, $definition );
		$legacy  = $clipart( DesignerAssetRepository::CLIPART, null, null );
		$artwork = $clipart( DesignerAssetRepository::ARTWORK, null, self::read( self::data() ) );
		$broken  = $clipart( DesignerAssetRepository::ARTWORK, null, null );
		$removed = $clipart( DesignerAssetRepository::CLIPART, '2026-11-22 20:00:00', null );

		self::assertTrue( $legacy->offered_on( 999 ) );
		self::assertTrue( $artwork->offered_on( 4 ) );
		self::assertFalse( $artwork->offered_on( 5 ) );
		self::assertFalse( $broken->offered_on( 4 ) );
		self::assertFalse( $broken->readable(), 'Unreadable fails closed, also where it is used.' );
		self::assertFalse( $removed->offered_on( 4 ) );
		self::assertTrue( $removed->readable() );
	}

	public function test_code_from_before_refuses_an_artwork(): void {
		$asset = array(
			'creator_id' => null,
			'kind'       => DesignerAssetRepository::ARTWORK,
		);

		self::assertFalse( CreatorUploads::may_use( 7, $asset ) );
		self::assertFalse( CreatorUploads::may_see( 7, $asset ) );
	}
}
