<?php
/**
 * Tests for a provider's environment photos among a design's images (ADR-117).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerImages;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SubmissionFiles::class )]
#[CoversClass( DesignerImages::class )]
final class EnvironmentPhotosTest extends TestCase {

	public function test_an_environment_photo_has_a_slot_of_its_own_no_side_can_have(): void {
		self::assertSame( array( 'mockup', '_scene-0', 'lodret' ), SubmissionFiles::slot( 'mockup:_scene-0:lodret' ) );
		self::assertSame( array( true, false ), array( SubmissionFiles::is_scene( '_scene-12' ), SubmissionFiles::is_scene( 'scene-1' ) ) );

		foreach ( array( 'print:_scene-0', 'mockup:_scene-:lodret', 'mockup:_scene-x:lodret', 'mockup:_scene-1000:lodret' ) as $slot ) {
			self::assertNull( SubmissionFiles::slot( $slot ), $slot );
		}
	}

	public function test_environment_photos_follow_every_side_and_can_be_the_main_image(): void {
		$base  = new BaseProduct(
			1,
			BaseProduct::ACTIVE,
			'gelato',
			'Poster',
			'',
			19900,
			'DKK',
			null,
			array(
				array(
					'key'  => 'lodret',
					'name' => 'Lodret',
					'hex'  => '#ffffff',
				),
			),
			array( 'A3' ),
			array(
				new BaseView( 'lodret-format-1', 'Lodret – format 1', 400, 400, array( 'background' => 1 ), self::area(), 30, 40, 100 ),
				new BaseView( 'lodret-format-2', 'Lodret – format 2', 400, 400, array( 'background' => 2 ), self::area(), 30, 30, 100 ),
			)
		);
		$media = array(
			'mockup:_scene-1:lodret'        => 14,
			'mockup:lodret-format-2:lodret' => 12,
			'mockup:_scene-0:lodret'        => 13,
			'mockup:lodret-format-1:lodret' => 11,
		);
		$order = DesignerImages::order( $base, array( 'lodret' ), array( '_scene-1', 'lodret-format-2', '_scene-0', 'lodret-format-1' ), $media, null );

		self::assertSame( array( 11, 12, 13, 14 ), $order['images'], 'The flat images first, then the rooms in their order.' );
		self::assertSame( array( 'lodret' => 11 ), $order['first'], 'The variation image stays the flat one.' );
		self::assertSame(
			13,
			DesignerImages::order(
				$base,
				array( 'lodret' ),
				array_keys(
					array(
						'_scene-0'        => 1,
						'lodret-format-1' => 1,
					)
				),
				$media,
				'_scene-0:lodret'
			)['images'][0],
			'A room chosen as the main image comes first.'
		);

		$design = array(
			'version' => 1,
			'sides'   => array( 'lodret-format-1' => array( array( 'id' => 'r1' ) ) ),
		);

		self::assertTrue( DesignerImages::valid( '_scene-0:lodret', $design, array( 'lodret' ) ) );
		self::assertFalse( DesignerImages::valid( '_scene-0:vandret', $design, array( 'lodret' ) ), 'Only a chosen colour.' );

		// Step 57.22: a provider's view, chosen before its photo is stored.
		self::assertTrue( DesignerImages::valid( '_view-left-side:lodret', $design, array( 'lodret' ) ) );
		self::assertFalse( DesignerImages::valid( '_view-left-side:vandret', $design, array( 'lodret' ) ), 'Only a chosen colour.' );
		self::assertFalse( DesignerImages::valid( '_view-' . str_repeat( 'a', 25 ) . ':lodret', $design, array( 'lodret' ) ), 'A camera of at most 24 characters.' );
		self::assertFalse( DesignerImages::valid( '_view-:lodret', $design, array( 'lodret' ) ) );
		self::assertFalse( DesignerImages::valid( '_view-Front:lodret', $design, array( 'lodret' ) ) );
	}

	/**
	 * A print area.
	 *
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	private static function area(): array {
		return array(
			'x'      => 100,
			'y'      => 100,
			'width'  => 150,
			'height' => 200,
		);
	}
}
