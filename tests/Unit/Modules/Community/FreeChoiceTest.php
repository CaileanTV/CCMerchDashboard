<?php
/**
 * Tests for the rule of free choice of design.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use MerchBoard\Modules\Community\Gifting\FreeChoice;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( FreeChoice::class )]
final class FreeChoiceTest extends TestCase {

	/**
	 * A design in one colour, its sizes with customer and base price.
	 *
	 * @param array<string, array{0: int, 1: int}> $sizes   Prices by size.
	 * @param string                               $colour  Colour.
	 * @param int                                  $creator Creator.
	 * @return array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}
	 */
	private static function design( array $sizes, string $colour = 'black', int $creator = 7 ): array {
		$variants = array();

		foreach ( $sizes as $size => $prices ) {
			$variants[] = array(
				'attributes' => array(
					'size'  => (string) $size,
					'color' => $colour,
				),
				'price'      => $prices[0],
				'base'       => $prices[1],
			);
		}

		return array(
			'creator'  => $creator,
			'variants' => $variants,
		);
	}

	/**
	 * The usual design: 2XL costs more than M.
	 *
	 * @param string $colour  Colour.
	 * @param int    $creator Creator.
	 * @return array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}
	 */
	private static function usual( string $colour = 'black', int $creator = 7 ): array {
		return self::design(
			array(
				'M'   => array( 200, 150 ),
				'2XL' => array( 250, 190 ),
			),
			$colour,
			$creator
		);
	}

	public function test_the_same_prices_give_free_choice_also_when_a_size_costs_more(): void {
		self::assertTrue(
			FreeChoice::offered(
				array(
					21 => self::usual(),
					22 => self::usual(),
					23 => self::usual(),
				)
			)
		);
	}

	public function test_another_customer_price_gives_no_free_choice(): void {
		$dearer = self::design(
			array(
				'M'   => array( 210, 150 ),
				'2XL' => array( 250, 190 ),
			)
		);

		self::assertFalse(
			FreeChoice::offered(
				array(
					21 => self::usual(),
					22 => $dearer,
				)
			)
		);
	}

	public function test_the_same_customer_price_with_another_base_price_gives_no_free_choice(): void {
		$other = self::design(
			array(
				'M'   => array( 200, 120 ),
				'2XL' => array( 250, 190 ),
			)
		);

		self::assertFalse(
			FreeChoice::offered(
				array(
					21 => self::usual(),
					22 => $other,
				)
			)
		);
	}

	public function test_another_creator_gives_no_free_choice(): void {
		self::assertFalse(
			FreeChoice::offered(
				array(
					21 => self::usual(),
					22 => self::usual( 'black', 8 ),
				)
			)
		);
	}

	public function test_the_same_prices_on_other_sizes_give_no_free_choice(): void {
		$swapped = array(
			'M'   => array( 250, 190 ),
			'2XL' => array( 200, 150 ),
		);

		self::assertFalse(
			FreeChoice::offered(
				array(
					21 => self::usual(),
					22 => self::design( $swapped ),
				)
			)
		);
		self::assertFalse(
			FreeChoice::offered(
				array(
					21 => self::usual( 'black' ),
					22 => self::design( $swapped, 'white' ),
				)
			),
			'Also without a variant in common.'
		);
	}

	public function test_a_size_only_some_designs_have_at_another_price_gives_no_free_choice(): void {
		$small = self::design(
			array(
				'M' => array( 200, 150 ),
			)
		);

		self::assertFalse(
			FreeChoice::offered(
				array(
					21 => $small,
					22 => self::usual(),
				)
			)
		);
	}

	public function test_every_pair_is_compared_not_only_with_the_first(): void {
		$first = self::design(
			array(
				'S'  => array( 200, 150 ),
				'XL' => array( 250, 190 ),
			)
		);

		self::assertFalse(
			FreeChoice::offered(
				array(
					21 => $first,
					22 => self::usual(),
					23 => self::design(
						array(
							'M'   => array( 250, 190 ),
							'2XL' => array( 200, 150 ),
						)
					),
				)
			)
		);
	}

	public function test_other_colours_do_not_stand_in_the_way(): void {
		self::assertTrue(
			FreeChoice::offered(
				array(
					21 => self::usual( 'black' ),
					22 => self::usual( 'white' ),
				)
			)
		);
	}

	public function test_options_are_compared_without_case_or_order(): void {
		$reversed = self::usual( 'Black' );

		foreach ( $reversed['variants'] as $index => $variant ) {
			$reversed['variants'][ $index ]['attributes'] = array_reverse( $variant['attributes'], true );
		}

		self::assertTrue(
			FreeChoice::offered(
				array(
					21 => self::usual( 'black' ),
					22 => $reversed,
				)
			)
		);
	}

	public function test_products_without_variants_compare_by_their_one_price(): void {
		$mug = array(
			'creator'  => 7,
			'variants' => array(
				array(
					'attributes' => array(),
					'price'      => 120,
					'base'       => 80,
				),
			),
		);

		self::assertTrue(
			FreeChoice::offered(
				array(
					21 => $mug,
					22 => $mug,
				)
			)
		);
	}

	public function test_one_design_or_one_without_a_price_gives_no_free_choice(): void {
		self::assertFalse( FreeChoice::offered( array() ) );
		self::assertFalse( FreeChoice::offered( array( 21 => self::usual() ) ) );
		self::assertFalse(
			FreeChoice::offered(
				array(
					21 => self::usual(),
					22 => self::design( array() ),
				)
			)
		);
	}

	public function test_a_design_added_after_the_purchase_cannot_be_chosen(): void {
		$locked = array(
			21 => self::usual(),
			22 => self::usual(),
		);

		self::assertSame( array( 21, 22 ), FreeChoice::allowed( $locked, $locked + array( 23 => self::usual() ) ) );
	}

	public function test_a_design_with_a_new_price_or_no_longer_sold_drops_out(): void {
		$locked  = array(
			21 => self::usual(),
			22 => self::usual(),
			23 => self::usual(),
		);
		$current = array(
			21 => self::usual(),
			22 => self::design(
				array(
					'M'   => array( 200, 140 ),
					'2XL' => array( 250, 190 ),
				)
			),
		);

		self::assertSame( array( 21 ), FreeChoice::allowed( $locked, $current ) );
	}

	public function test_a_size_sold_out_keeps_the_design(): void {
		$locked  = array( 21 => self::usual() );
		$current = array(
			21 => self::design(
				array(
					'M' => array( 200, 150 ),
				)
			),
			22 => self::usual(),
		);

		self::assertSame( array( 21 ), FreeChoice::allowed( $locked, $current ) );
	}

	public function test_a_design_that_changed_creator_drops_out(): void {
		$locked  = array(
			21 => self::usual(),
			22 => self::usual(),
		);
		$current = array(
			21 => self::usual(),
			22 => self::usual( 'black', 8 ),
		);

		self::assertSame( array( 21 ), FreeChoice::allowed( $locked, $current ) );
	}

	public function test_with_none_left_the_winner_still_has_the_designs_of_the_purchase(): void {
		$locked = array(
			21 => self::usual(),
			22 => self::usual(),
		);

		self::assertSame( array( 21, 22 ), FreeChoice::allowed( $locked, array() ) );
	}
}
