<?php
/**
 * Tests of sides that belong to sizes.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use InvalidArgumentException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( BaseProduct::class )]
#[CoversClass( BaseView::class )]
final class SizedSidesTest extends TestCase {

	public function test_a_side_keeps_its_sizes_and_is_on_every_size_without_any(): void {
		$side = self::side( 'small', array( '33 cl' ) );

		self::assertSame( array( '33 cl' ), BaseView::from_array( $side->to_array() )->sizes );
		self::assertTrue( $side->on_size( '33 cl' ) );
		self::assertFalse( $side->on_size( '44 cl' ) );

		$plain = self::side( 'plain', array() );

		self::assertArrayNotHasKey( 'sizes', $plain->to_array(), 'A stored side without sizes does not change.' );
		self::assertTrue( $plain->on_size( '44 cl' ) );
	}

	public function test_an_empty_size_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		self::side( 'small', array( '' ) );
	}

	public function test_only_the_areas_of_one_size_count(): void {
		$base = self::base( self::side( 'small', array( '33 cl' ) ), self::side( 'large', array( '44 cl' ) ), self::side( 'lid', array( '44 cl' ) ) );

		self::assertTrue( $base->sized() );
		self::assertSame( array( 'large', 'lid' ), $base->sides_of( '44 cl' ) );
		self::assertSame( 1, $base->areas( array( 'small', 'large' ), array( '33 cl', '44 cl' ) ) );
		self::assertSame( 2, $base->areas( array( 'small', 'large', 'lid' ) ), 'The size with the most, so the company never gets less.' );
		self::assertSame( 1, $base->areas( array( 'small', 'large', 'lid' ), array( '33 cl' ) ) );
	}

	public function test_every_used_side_counts_without_sides_per_size(): void {
		$base = self::base( self::side( 'front', array() ), self::side( 'back', array() ) );

		self::assertFalse( $base->sized() );
		self::assertSame( 2, $base->areas( array( 'front', 'back' ), array( '33 cl' ) ) );
		self::assertTrue( $base->covers( array( 'front' ), array( '33 cl', '44 cl' ) ) );
	}

	public function test_each_chosen_size_needs_a_design_and_no_other_size_has_one(): void {
		$base = self::base( self::side( 'small', array( '33 cl' ) ), self::side( 'large', array( '44 cl' ) ) );

		self::assertTrue( $base->covers( array( 'small', 'large' ), array( '33 cl', '44 cl' ) ) );
		self::assertTrue( $base->covers( array( 'small' ), array( '33 cl' ) ) );
		self::assertFalse( $base->covers( array( 'small' ), array( '33 cl', '44 cl' ) ), 'A size without a design.' );
		self::assertFalse( $base->covers( array( 'small', 'large' ), array( '33 cl' ) ), 'A design on a size that is not sold.' );
	}

	/**
	 * A side.
	 *
	 * @phpstan-param list<string> $sizes
	 *
	 * @param string   $key   Key.
	 * @param string[] $sizes Its sizes.
	 */
	private static function side( string $key, array $sizes ): BaseView {
		return new BaseView(
			$key,
			ucfirst( $key ),
			400,
			400,
			array( 'background' => 1 ),
			array(
				'x'      => 10,
				'y'      => 10,
				'width'  => 100,
				'height' => 100,
			),
			50,
			50,
			300,
			'',
			false,
			null,
			$sizes
		);
	}

	/**
	 * A base product with sides, in 33 cl and 44 cl.
	 *
	 * @param BaseView ...$views Sides.
	 */
	private static function base( BaseView ...$views ): BaseProduct {
		return new BaseProduct( 1, BaseProduct::DRAFT, 'own', 'Cup', '', 500000, 'DKK', null, array(), array( '33 cl', '44 cl' ), array_values( $views ), 1 );
	}
}
