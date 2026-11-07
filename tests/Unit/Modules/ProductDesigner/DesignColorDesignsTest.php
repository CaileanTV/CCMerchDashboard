<?php
/**
 * Colour designs: some colours of a product with a design of their own (ADR-096).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\ColorDesigns;
use MerchBoard\Modules\ProductDesigner\Designs\DesignValidator;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DesignValidator::class )]
#[CoversClass( ColorDesigns::class )]
final class DesignColorDesignsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function test_a_design_from_before_is_stored_exactly_as_it_was(): void {
		$design = array(
			'version' => 1,
			'sides'   => array( 'front' => array( self::rect( 'a' ) ) ),
		);
		$before = $this->validator()->validate( $design, self::base() );

		self::assertSame( $before, $this->validator()->validate( $design, self::base(), array(), array( 'black' ) ) );
		self::assertSame( array( 'version', 'sides' ), array_keys( $before ), 'No designs key without colour designs, so the stamp stays.' );
		self::assertSame( $before, $this->validator()->validate( array_merge( $design, array( 'designs' => array() ) ), self::base() ) );
	}

	public function test_colours_get_designs_of_their_own_and_unchosen_colours_are_dropped(): void {
		$design = $this->validator()->validate(
			array(
				'version' => 1,
				'sides'   => array( 'front' => array( self::rect( 'a' ) ) ),
				'designs' => array(
					array(
						'id'     => 'd1',
						'colors' => array( 'orange', 'white' ),
						'sides'  => array( 'back' => array( self::rect( 'b' ) ) ),
						'evil'   => 'x',
					),
					array(
						'id'     => 'd2',
						'colors' => array( 'white-blue' ),
						'sides'  => array( 'front' => array() ),
					),
				),
			),
			self::base(),
			array(),
			array( 'black', 'orange' )
		);

		self::assertSame(
			array(
				array(
					'id'     => 'd1',
					'colors' => array( 'orange' ),
					'sides'  => array( 'back' => $design['designs'][0]['sides']['back'] ?? array() ),
				),
			),
			$design['designs'] ?? null,
			'White is not chosen, and a design left without colours goes.'
		);
		self::assertSame( 'd1', ColorDesigns::part_of( $design, 'orange' ) );
		self::assertSame( ColorDesigns::SHARED, ColorDesigns::part_of( $design, 'black' ) );
		self::assertSame(
			array(
				ColorDesigns::SHARED => array( 'black' ),
				'd1'                 => array( 'orange' ),
			),
			ColorDesigns::parts( $design, array( 'orange', 'black' ) ),
			'The shared design first.'
		);
		self::assertSame( array( 'd1' => array( 'orange' ) ), ColorDesigns::parts( $design, array( 'orange' ) ), 'Only the designs in use.' );
		self::assertSame( array( 'back' ), array_keys( ColorDesigns::part( $design, 'd1' )['sides'] ) );
		self::assertArrayNotHasKey( 'designs', ColorDesigns::part( $design, ColorDesigns::SHARED ) );
		self::assertSame( array(), ColorDesigns::part( $design, 'd7' )['sides'] );
	}

	public function test_a_colour_design_is_checked_like_the_shared_one(): void {
		$valid = array(
			'id'     => 'd1',
			'colors' => array( 'orange' ),
			'sides'  => array( 'front' => array() ),
		);
		$bad   = array(
			'not a list'      => array( 'd1' => $valid ),
			'bad id'          => array( array_merge( $valid, array( 'id' => 'orange' ) ) ),
			'same id'         => array( $valid, array_merge( $valid, array( 'colors' => array( 'black' ) ) ) ),
			'colour twice'    => array( $valid, array_merge( $valid, array( 'id' => 'd2' ) ) ),
			'unknown side'    => array( array_merge( $valid, array( 'sides' => array( 'sleeve' => array() ) ) ) ),
			'no sides'        => array( array( 'id' => 'd1', 'colors' => array( 'orange' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'a bad object'    => array( array_merge( $valid, array( 'sides' => array( 'front' => array( array( 'type' => 'video' ) ) ) ) ) ),
			'too many'        => array_map( static fn( int $n ): array => array_merge( $valid, array( 'id' => 'd' . $n, 'colors' => array() ) ), range( 1, DesignValidator::MAX_DESIGNS + 1 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'colours no list' => array( array_merge( $valid, array( 'colors' => 'orange' ) ) ),
		);

		foreach ( $bad as $label => $designs ) {
			try {
				$this->validator()->validate(
					array(
						'version' => 1,
						'sides'   => array(),
						'designs' => $designs,
					),
					self::base()
				);
				self::fail( 'Refused: ' . $label );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::DESIGN_INVALID, $e->reason(), $label );
			}
		}
	}

	public function test_without_a_list_of_colours_every_colour_of_the_base_product_counts(): void {
		$design = $this->validator()->validate(
			array(
				'version' => 1,
				'sides'   => array(),
				'designs' => array(
					array(
						'id'     => 'd3',
						'colors' => array( 'white', 'gone' ),
						'sides'  => array(),
					),
				),
			),
			self::base()
		);

		self::assertSame( array( 'white' ), $design['designs'][0]['colors'] ?? null, 'A colour the base product no longer has is dropped.' );
	}

	/**
	 * The check.
	 */
	private function validator(): DesignValidator {
		return new DesignValidator( static fn(): bool => false, array() );
	}

	/**
	 * A rectangle.
	 *
	 * @param string $id Id.
	 * @return array<string, mixed>
	 */
	private static function rect( string $id ): array {
		return array(
			'type'        => 'rect',
			'id'          => $id,
			'x'           => 10,
			'y'           => 10,
			'width'       => 10,
			'height'      => 10,
			'angle'       => 0,
			'scaleX'      => 1,
			'scaleY'      => 1,
			'opacity'     => 1,
			'fill'        => '#00ff00',
			'strokeWidth' => 0,
			'radius'      => 0,
		);
	}

	/**
	 * A tee with a front and a back in black, white and orange.
	 */
	private static function base(): BaseProduct {
		$area   = array(
			'x'      => 0,
			'y'      => 0,
			'width'  => 10,
			'height' => 10,
		);
		$views  = array(
			new BaseView( 'front', 'Front', 1000, 1000, array( 'background' => 1 ), $area, 300, 300, 150 ),
			new BaseView( 'back', 'Back', 1000, 1000, array( 'background' => 1 ), $area, 300, 300, 150 ),
		);
		$colors = array(
			array(
				'key'  => 'black',
				'name' => 'Black',
				'hex'  => '#111111',
			),
			array(
				'key'  => 'white',
				'name' => 'White',
				'hex'  => '#ffffff',
			),
			array(
				'key'  => 'orange',
				'name' => 'Orange',
				'hex'  => '#ff8800',
			),
		);

		return new BaseProduct( 1, BaseProduct::DRAFT, 'own', 'Tee', '', 1500000, 'DKK', null, $colors, array( 'M' ), $views, 1, 500000, '', array(), 0, false );
	}
}
