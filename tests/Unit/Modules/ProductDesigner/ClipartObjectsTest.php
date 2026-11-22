<?php
/**
 * Tests for a clipart as an object in a design (ADR-121, step 56.8).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey\Functions;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\ClipartObjects;
use MerchBoard\Modules\ProductDesigner\Designs\DesignValidator;
use MerchBoard\Modules\ProductDesigner\Files\Clipart;
use MerchBoard\Modules\ProductDesigner\Files\ClipartDefinition;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ClipartObjects::class )]
#[CoversClass( DesignValidator::class )]
final class ClipartObjectsTest extends TestCase {

	private const RED = array(
		'type'  => 'solid',
		'color' => '#ff0000',
	);

	private const GRADIENT = array(
		'type'  => 'gradient',
		'from'  => '#65b6f5',
		'to'    => '#f1a90c',
		'angle' => 45,
	);

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * A clipart of 100 × 200 px with one part.
	 *
	 * @phpstan-param list<string>|null $palette
	 *
	 * @param bool          $locked  Whether its placement is locked.
	 * @param string[]|null $palette Its palette.
	 * @param bool          $parts   Whether it has the part.
	 */
	private static function clipart( bool $locked = false, ?array $palette = null, bool $parts = true ): Clipart {
		$rect = array(
			'x'      => 0,
			'y'      => 0,
			'width'  => 50,
			'height' => 50,
		);
		$part = array(
			'label'    => '',
			'box'      => $rect,
			'stroke'   => null,
			'scale'    => 1,
			'paint'    => self::RED,
			'gradient' => self::GRADIENT,
		);
		$step = array(
			'type'  => 'part',
			'part'  => 0,
			'rect'  => $rect,
			'fill'  => array(
				'x' => 0,
				'y' => 0,
			),
			'grown' => null,
		);

		$atlas = array(
			'file'   => str_repeat( 'a', 32 ) . '.png',
			'width'  => 50,
			'height' => 50,
		);

		$definition = ClipartDefinition::from_data(
			array(
				'bases'   => null,
				'parts'   => $parts ? array( $part ) : array(),
				'steps'   => $parts ? array( $step ) : array(),
				'atlas'   => $parts ? $atlas : null,
				'palette' => $palette,
				'locked'  => $locked,
			),
			100,
			200
		);

		self::assertNotNull( $definition );

		return new Clipart( 8, DesignerAssetRepository::ARTWORK, 'Bottle', 'a.png', 'image/png', 100, 200, null, $definition );
	}

	/**
	 * A checked image object.
	 *
	 * @param array<string, mixed> $set Fields set.
	 * @return array<string, mixed>
	 */
	private static function object_with( array $set = array() ): array {
		return array_merge(
			array(
				'type'    => 'image',
				'id'      => 'a',
				'x'       => 700,
				'y'       => 5,
				'width'   => 999,
				'height'  => 999,
				'angle'   => 30,
				'scaleX'  => 2,
				'scaleY'  => 2,
				'flipX'   => true,
				'flipY'   => true,
				'opacity' => 0.4,
				'locked'  => false,
				'hidden'  => false,
				'asset'   => 8,
			),
			$set
		);
	}

	public function test_the_size_is_the_cliparts_and_a_part_is_never_faded(): void {
		$saved = ClipartObjects::normalized( self::object_with(), array( self::RED ), self::clipart(), 1000, 1000, false );

		self::assertNotNull( $saved );
		self::assertSame( array( 100, 200, 1 ), array( $saved['width'], $saved['height'], $saved['opacity'] ) );
		self::assertSame( array( self::RED ), $saved['paints'] );
		self::assertSame( array( 700, 30, true ), array( $saved['x'], $saved['angle'], $saved['flipX'] ), 'Free placement stays.' );
	}

	public function test_missing_or_wrong_paints_are_refused_and_never_filled_in(): void {
		$wrong = array(
			null,
			array(),
			array( self::RED, self::RED ),
			array( 'red' ),
			array( array( 'type' => 'solid' ) ),
			array( array_merge( self::RED, array( 'color' => "#ff0000\n" ) ) ),
			array( array_merge( self::GRADIENT, array( 'angle' => 360 ) ) ),
			array( array_merge( self::GRADIENT, array( 'angle' => '45' ) ) ),
			array( 'a' => self::RED ),
		);

		foreach ( $wrong as $paints ) {
			self::assertNull( ClipartObjects::normalized( self::object_with(), $paints, self::clipart(), 1000, 1000, false ) );
		}
	}

	public function test_a_part_keeps_its_proportions(): void {
		self::assertNull( ClipartObjects::normalized( self::object_with( array( 'scaleY' => 2.1 ) ), array( self::RED ), self::clipart(), 1000, 1000, false ) );

		$rounded = ClipartObjects::normalized( self::object_with( array( 'scaleY' => 2.0001 ) ), array( self::RED ), self::clipart(), 1000, 1000, false );

		self::assertSame( 2, $rounded['scaleY'] ?? null );
	}

	public function test_the_palette_is_for_both_kinds_of_paint_and_not_for_embroidery(): void {
		$clipart = self::clipart( false, array( '#ff0000', '#65b6f5', '#f1a90c' ) );

		$green = array(
			'type'  => 'solid',
			'color' => '#00ff00',
		);
		$mixed = array_merge( self::GRADIENT, array( 'to' => '#00ff00' ) );

		self::assertNotNull( ClipartObjects::normalized( self::object_with(), array( self::GRADIENT ), $clipart, 1000, 1000, false ) );
		self::assertNull( ClipartObjects::normalized( self::object_with(), array( $green ), $clipart, 1000, 1000, false ) );
		self::assertNull( ClipartObjects::normalized( self::object_with(), array( $mixed ), $clipart, 1000, 1000, false ) );
		self::assertNotNull( ClipartObjects::normalized( self::object_with(), array( $green ), $clipart, 1000, 1000, true ) );
	}

	public function test_a_gradient_on_embroidery_becomes_its_first_colour(): void {
		$saved = ClipartObjects::normalized( self::object_with(), array( self::GRADIENT ), self::clipart(), 1000, 1000, true );

		$first = array(
			'type'  => 'solid',
			'color' => '#65b6f5',
		);

		self::assertSame( array( $first ), $saved['paints'] ?? null );
	}

	public function test_a_locked_clipart_moves_sideways_only_and_stays_whole(): void {
		$place = static function ( int|float $x ): array {
			$saved = ClipartObjects::normalized( self::object_with( array( 'x' => $x ) ), array( self::RED ), self::clipart( true ), 3000, 2000, false );
			self::assertNotNull( $saved );

			return array( $saved['x'], $saved['y'], $saved['scaleX'], $saved['scaleY'], $saved['angle'], $saved['flipX'], $saved['flipY'] );
		};

		self::assertSame( array( 700.0, 1000.0, 10.0, 10.0, 0, false, false ), $place( 700 ) );
		self::assertSame( 500.0, $place( -50 )[0] );
		self::assertSame( 2500.0, $place( 9999 )[0] );
	}

	public function test_a_locked_clipart_too_wide_takes_the_width(): void {
		$saved = ClipartObjects::normalized( self::object_with(), null, self::clipart( true, null, false ), 100, 1000, false );

		self::assertNotNull( $saved );
		self::assertSame( array( 1.0, 50.0, 500.0 ), array( $saved['scaleX'], $saved['x'], $saved['y'] ) );
		self::assertArrayNotHasKey( 'paints', $saved, 'Without parts the key is left out.' );
	}

	public function test_a_clipart_from_before_is_an_image_like_any_other(): void {
		$legacy = new Clipart( 8, DesignerAssetRepository::CLIPART, 'Star', 'a.png', 'image/png', 10, 10, null, null );
		$broken = new Clipart( 8, DesignerAssetRepository::ARTWORK, 'Bottle', 'a.png', 'image/png', 10, 10, null, null );

		self::assertSame( self::object_with(), ClipartObjects::normalized( self::object_with(), array( self::RED ), $legacy, 1000, 1000, false ) );
		self::assertNull( ClipartObjects::normalized( self::object_with(), array( self::RED ), $broken, 1000, 1000, false ) );
	}

	private static function base(): BaseProduct {
		$area  = array(
			'x'      => 0,
			'y'      => 0,
			'width'  => 10,
			'height' => 10,
		);
		$views = array(
			new BaseView( 'front', 'Front', 1000, 1000, array( 'background' => 1 ), $area, 300, 300, 150 ),
			new BaseView( 'chest', 'Chest', 1000, 1000, array( 'background' => 1 ), $area, 100, 100, 150, 'madeira-classic' ),
		);

		return new BaseProduct( 1, BaseProduct::DRAFT, 'own', 'Bottle', '', 1500000, 'DKK', null, array(), array(), $views );
	}

	/**
	 * A design with the clipart on both sides and in a colour design.
	 *
	 * @param mixed $paints The paints.
	 * @return array<string, mixed>
	 */
	private static function design( mixed $paints ): array {
		$image = self::object_with( array( 'x' => 700.5 ) );

		if ( null !== $paints ) {
			$image['paints'] = $paints;
		}

		return array(
			'version' => 1,
			'sides'   => array(
				'front' => array( $image ),
				'chest' => array( $image ),
			),
			'designs' => array(
				array(
					'id'     => 'd1',
					'colors' => array(),
					'sides'  => array( 'front' => array( $image ) ),
				),
			),
		);
	}

	public function test_what_is_saved_passes_the_check_at_submission_also_after_json(): void {
		$clipart   = self::clipart( true );
		$validator = new DesignValidator( static fn(): Clipart => $clipart, array() );
		$saved     = $validator->validate( self::design( array( self::GRADIENT ) ), self::base() );
		$stored    = json_decode( (string) json_encode( $saved ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- As stored.
		$find      = static fn(): Clipart => $clipart;

		self::assertIsArray( $stored );
		self::assertSame( 'gradient', $saved['sides']['front'][0]['paints'][0]['type'] );
		self::assertSame( 'solid', $saved['sides']['chest'][0]['paints'][0]['type'], 'The embroidered side.' );
		self::assertTrue( ClipartObjects::as_saved( $stored, self::base(), $find ) );
		self::assertEquals( $saved, $validator->validate( $stored, self::base() ), 'Saved again it is the same.' );

		foreach ( array( 'y' => 5, 'scaleX' => 1, 'width' => 999, 'paints' => array() ) as $key => $value ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
			$changed = $stored;

			$changed['sides']['front'][0][ $key ] = $value;

			self::assertFalse( ClipartObjects::as_saved( $changed, self::base(), $find ), $key );
		}

		self::assertTrue( ClipartObjects::as_saved( $stored, self::base(), static fn(): ?Clipart => null ), 'Other assets are not looked at.' );
	}

	public function test_the_validator_refuses_a_part_without_paints(): void {
		$clipart = self::clipart();

		$this->expectException( DesignerException::class );
		( new DesignValidator( static fn(): Clipart => $clipart, array() ) )->validate( self::design( null ), self::base() );
	}

	public function test_an_upload_gets_no_paints_and_is_stored_as_before(): void {
		$saved = ( new DesignValidator( static fn(): bool => true, array() ) )->validate( self::design( array( self::RED ) ), self::base() );

		self::assertSame( self::object_with( array( 'x' => 700.5 ) ), $saved['sides']['front'][0], 'A design from before keeps its stamp.' );
	}
}
