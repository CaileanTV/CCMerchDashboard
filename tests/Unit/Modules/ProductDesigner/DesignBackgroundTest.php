<?php
/**
 * The background colour of a design on an all-over base product (ADR-095).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\ProductDesigner\Designs\DesignValidator;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DesignValidator::class )]
#[CoversClass( SubmissionFiles::class )]
final class DesignBackgroundTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function test_a_base_product_that_offers_it_keeps_the_colour_of_each_printed_side(): void {
		$design = $this->validator()->validate(
			array(
				'version'    => 1,
				'sides'      => array( 'back' => array() ),
				'background' => array(
					'back'  => '#1F2A44',
					'front' => '#c8102e',
				),
			),
			self::base( true )
		);

		self::assertSame(
			array(
				'front' => '#c8102e',
				'back'  => '#1f2a44',
			),
			$design['background'] ?? null,
			'Lower case, in the base product\'s order of sides.'
		);
		self::assertSame( array( 'back', 'front' ), array_keys( $design['sides'] ), 'A side with only a colour is a side of the design.' );
	}

	public function test_another_base_product_drops_the_colour(): void {
		$design = $this->validator()->validate(
			array(
				'version'    => 1,
				'sides'      => array(),
				'background' => array( 'front' => '#c8102e' ),
			),
			self::base( false )
		);

		self::assertArrayNotHasKey( 'background', $design, 'A draft from before the tick was removed (ADR-095 pkt. 7).' );
		self::assertSame( array(), $design['sides'] );
	}

	public function test_an_embroidered_or_unknown_side_and_a_bad_colour_are_refused(): void {
		foreach ( array( array( 'chest' => '#c8102e' ), array( 'hood' => '#c8102e' ), array( 'front' => 'red' ), array( 'front' => '#c8102' ), 'front' ) as $background ) {
			try {
				$this->validator()->validate(
					array(
						'version'    => 1,
						'sides'      => array(),
						'background' => $background,
					),
					self::base( true )
				);
				self::fail( 'Refused: ' . wp_json_encode( $background ) );
			} catch ( DesignerException $e ) {
				self::assertSame( DesignerException::DESIGN_INVALID, $e->reason() );
			}
		}
	}

	public function test_a_side_with_only_a_colour_is_printed_but_free(): void {
		$object = array( 'id' => 'a' );
		$design = array(
			'version'    => 1,
			'sides'      => array(
				'front' => array(),
				'back'  => array( $object ),
			),
			'background' => array( 'front' => '#c8102e' ),
		);

		self::assertSame( array( 'front', 'back' ), SubmissionFiles::used_sides( $design ), 'It gets a print file and mockups.' );
		self::assertSame( array( 'back' ), SubmissionFiles::designed_sides( $design ) );
		self::assertSame( 1500000, DesignerPrices::secured_base( self::base( true ), $design ), 'No extra area: the owner\'s choice 2026-11-07.' );

		$design['sides']['front'] = array( $object );
		self::assertSame( 2000000, DesignerPrices::secured_base( self::base( true ), $design ), 'With a design on it, the side costs as before.' );
	}

	/**
	 * The validator without assets or fonts.
	 */
	private function validator(): DesignValidator {
		return new DesignValidator( static fn(): bool => false, array() );
	}

	/**
	 * A base product with a front, a back and an embroidered chest.
	 *
	 * @param bool $background Whether it offers a background colour.
	 */
	private static function base( bool $background ): BaseProduct {
		$area  = array(
			'x'      => 0,
			'y'      => 0,
			'width'  => 10,
			'height' => 10,
		);
		$views = array(
			new BaseView( 'front', 'Front', 1000, 1000, array( 'background' => 1 ), $area, 300, 300, 150 ),
			new BaseView( 'back', 'Back', 1000, 1000, array( 'background' => 1 ), $area, 300, 300, 150 ),
			new BaseView( 'chest', 'Chest', 1000, 1000, array( 'background' => 1 ), $area, 100, 100, 150, 'madeira-classic' ),
		);

		return new BaseProduct( 1, BaseProduct::DRAFT, 'own', 'Jersey', '', 1500000, 'DKK', null, array(), array(), $views, 1, 500000, '', array(), 0, $background );
	}
}
