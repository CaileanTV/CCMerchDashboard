<?php
/**
 * Tests for sizes in inches written in centimetres.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Modules\ProductDesigner\Providers\SizeUnits;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SizeUnits::class )]
final class SizeUnitsTest extends TestCase {

	public function test_inches_become_whole_centimetres_and_other_sizes_stay(): void {
		self::assertSame( '41 × 81 cm', SizeUnits::to_cm( '16″×32″' ) );
		self::assertSame( '30 × 46 cm', SizeUnits::to_cm( '12″×18″' ) );
		self::assertSame( '30 × 42 cm', SizeUnits::to_cm( '11.7" x 16.5"' ) );
		self::assertSame( '41 × 81 cm', SizeUnits::to_cm( '16 x 32 in' ) );
		self::assertSame( '91 cm', SizeUnits::to_cm( '36″' ) );

		foreach ( array( 'S', '2XL', '11 oz', '33cl', 'One size', '20 x 30 cm' ) as $size ) {
			self::assertSame( $size, SizeUnits::to_cm( $size ) );
		}

		self::assertTrue( SizeUnits::has_inches( array( 'S', '12″×18″' ) ) );
		self::assertFalse( SizeUnits::has_inches( array( 'S', 'M' ) ) );
	}

	public function test_a_choice_is_renamed_everywhere_a_size_is_named(): void {
		$choice = SizeUnits::choice(
			array(
				'name'     => 'Enhanced Matte Paper Poster (in)',
				'sizes'    => array( '12″×18″', '16″×32″' ),
				'variants' => array(
					'Black' => array(
						'12″×18″' => '11',
						'16″×32″' => '12',
					),
				),
				'sides'    => array(
					array(
						'name'  => 'Print file 12″×18″',
						'sizes' => array( '12″×18″' ),
					),
					array( 'name' => 'Front' ),
				),
			)
		);

		self::assertSame( 'Enhanced Matte Paper Poster (cm)', $choice['name'] );
		self::assertSame( array( '30 × 46 cm', '41 × 81 cm' ), $choice['sizes'] );
		self::assertSame(
			array(
				'30 × 46 cm' => '11',
				'41 × 81 cm' => '12',
			),
			$choice['variants']['Black']
		);
		self::assertSame(
			array(
				array(
					'name'  => 'Print file 30 × 46 cm',
					'sizes' => array( '30 × 46 cm' ),
				),
				array( 'name' => 'Front' ),
			),
			$choice['sides']
		);
	}

	public function test_sizes_that_would_get_the_same_name_keep_their_inches(): void {
		$choice = array(
			'name'     => 'Poster',
			'sizes'    => array( '12″×18″', '11.9″×18.1″' ),
			'variants' => array(),
			'sides'    => array(),
		);

		self::assertNull( SizeUnits::names( $choice['sizes'] ) );
		self::assertSame( $choice, SizeUnits::choice( $choice ) );
	}
}
