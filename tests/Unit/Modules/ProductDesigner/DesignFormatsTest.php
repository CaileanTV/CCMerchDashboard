<?php
/**
 * A poster's formats sharing one design (ADR-118).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignValidator;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DesignValidator::class )]
final class DesignFormatsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function test_formats_that_share_a_design_are_kept_with_their_copies(): void {
		$design = $this->validator()->validate(
			array(
				'version' => 1,
				'sides'   => array(
					'lodret-a'      => array( self::rect( 'a' ) ),
					'lodret-square' => array( self::rect( 'a' ) ),
				),
				'formats' => array(
					'shared' => array(
						'lodret'  => array(
							'width'      => 2000,
							'height'     => 3000,
							'objects'    => array( self::rect( 'a' ) ),
							'background' => '#ABCDEF',
						),
						'vandret' => array(
							'width'   => 3000,
							'height'  => 2000,
							'objects' => array(),
						),
					),
					'links'  => array(
						'lodret-a'      => 'lodret',
						'lodret-square' => 'lodret',
						'vandret-a'     => 'lodret',
						'tee'           => 'lodret',
					),
					'fit'    => array(
						'lodret-square' => 'fit',
						'lodret-a'      => 'stretch',
					),
				),
			),
			self::base()
		);

		$formats = $design['formats'] ?? self::fail( 'No formats.' );

		self::assertSame(
			array(
				'lodret-a'      => 'lodret',
				'lodret-square' => 'lodret',
			),
			$formats['links'],
			'Only a format side of that orientation; a plain side has none.'
		);
		self::assertSame( array( 'lodret-square' => 'fit' ), $formats['fit'], 'An unknown fit is smart.' );
		self::assertSame( array( 'lodret' ), array_keys( $formats['shared'] ), 'A shared design no side uses is dropped.' );
		self::assertArrayNotHasKey( 'background', $formats['shared']['lodret'] ?? array(), 'No background colour on this base product.' );
		self::assertSame( array( 'lodret-a', 'lodret-square' ), array_keys( $design['sides'] ), 'The copies are printed.' );
	}

	public function test_without_a_link_the_key_is_left_out_and_a_bad_shared_design_is_refused(): void {
		$design = array(
			'version' => 1,
			'sides'   => array( 'lodret-a' => array( self::rect( 'a' ) ) ),
		);

		self::assertSame( array( 'version', 'sides' ), array_keys( $this->validator()->validate( array_merge( $design, array( 'formats' => array( 'links' => array() ) ) ), self::base() ) ) );

		$this->expectException( DesignerException::class );
		$this->validator()->validate(
			array_merge(
				$design,
				array(
					'formats' => array(
						'shared' => array(
							'lodret' => array(
								'width'   => 0,
								'height'  => 3000,
								'objects' => array(),
							),
						),
						'links'  => array( 'lodret-a' => 'lodret' ),
					),
				)
			),
			self::base()
		);
	}

	/**
	 * The validator.
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
	 * A poster with two vertical formats, one horizontal and a plain side.
	 */
	private static function base(): BaseProduct {
		$area   = array(
			'x'      => 0,
			'y'      => 0,
			'width'  => 10,
			'height' => 10,
		);
		$format = static fn( float $width, float $height ): array => array(
			'width_mm'  => $width,
			'height_mm' => $height,
		);
		$views  = array(
			new BaseView( 'lodret-a', 'Lodret – format 1', 1000, 1000, array( 'background' => 1 ), $area, 297, 420, 150, '', false, null, array( 'A3' ), false, array( 'lodret' ), array( 'A3' => $format( 297, 420 ) ) ),
			new BaseView( 'lodret-square', 'Lodret – format 2', 1000, 1000, array( 'background' => 1 ), $area, 300, 300, 150, '', false, null, array( '30x30' ), false, array( 'lodret' ), array( '30x30' => $format( 300, 300 ) ) ),
			new BaseView( 'vandret-a', 'Vandret – format 1', 1000, 1000, array( 'background' => 1 ), $area, 420, 297, 150, '', false, null, array( 'A3' ), false, array( 'vandret' ), array( 'A3' => $format( 420, 297 ) ) ),
			new BaseView( 'tee', 'Tee', 1000, 1000, array( 'background' => 1 ), $area, 300, 300, 150 ),
		);
		$colors = array(
			array(
				'key'  => 'lodret',
				'name' => 'Lodret',
				'hex'  => '#ffffff',
			),
			array(
				'key'  => 'vandret',
				'name' => 'Vandret',
				'hex'  => '#ffffff',
			),
		);

		return new BaseProduct( 1, BaseProduct::DRAFT, 'gelato', 'Poster', '', 1500000, 'DKK', null, $colors, array( 'A3', '30x30' ), $views, 1, 0, '', array(), 0, false );
	}
}
