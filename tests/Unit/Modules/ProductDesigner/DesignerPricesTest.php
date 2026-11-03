<?php
/**
 * Tests for the price of designer products (ADR-080 pkt. 8).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use InvalidArgumentException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( DesignerPrices::class )]
final class DesignerPricesTest extends TestCase {

	/**
	 * Base price, profit kind, value, decimals; expected base, profit and list price.
	 *
	 * @return array<string, array{string, string, string, int, string, string, string}>
	 */
	public static function prices(): array {
		return array(
			'fixed amount'                         => array( '150', 'fixed', '50', 2, '150.0000', '50.0000', '200.0000' ),
			'percent of the base price'            => array( '150', 'percent', '20', 2, '150.0000', '30.0000', '180.0000' ),
			'no upper limit'                       => array( '100', 'percent', '250', 2, '100.0000', '250.0000', '350.0000' ),
			'no profit'                            => array( '99.95', 'fixed', '0', 2, '99.9500', '0.0000', '99.9500' ),
			'percent rounded half away from zero'  => array( '99.95', 'percent', '15', 2, '99.9500', '14.9900', '114.9400' ),
			'whole kroner: base price rounded up'  => array( '149.10', 'fixed', '50.5', 0, '150.0000', '51.0000', '201.0000' ),
			'whole kroner: percent of the rounded' => array( '149.10', 'percent', '10', 0, '150.0000', '15.0000', '165.0000' ),
		);
	}

	/**
	 * The list price is base + profit at the store precision; the base price
	 * is never rounded down.
	 *
	 * @param string $base     Base price.
	 * @param string $kind     Profit kind.
	 * @param string $value    Percent or amount.
	 * @param int    $decimals Store precision.
	 * @param string $want_b   Expected base price.
	 * @param string $want_p   Expected profit.
	 * @param string $want_l   Expected list price.
	 */
	#[DataProvider( 'prices' )]
	public function test_the_price_is_base_plus_profit_and_never_below_the_base_price( string $base, string $kind, string $value, int $decimals, string $want_b, string $want_p, string $want_l ): void {
		$price = DesignerPrices::compute( 7, $base, $kind, $value, 'DKK', $decimals );

		self::assertSame( $want_b, SalesAmount::format( $price->base_units ) );
		self::assertSame( $want_p, SalesAmount::format( $price->profit_units ) );
		self::assertSame( $want_l, SalesAmount::format( $price->price_units() ) );
		self::assertGreaterThanOrEqual( SalesAmount::parse( $base ), $price->base_units );
		self::assertSame( 7, $price->creator_id );
		self::assertSame( 'DKK', $price->currency );
	}

	public function test_one_design_area_is_included_and_each_further_used_one_is_secured(): void {
		$base   = new BaseProduct( null, BaseProduct::DRAFT, 'own', 'Tee', '', 1500000, 'DKK', null, array(), array(), array(), 1, 500000 );
		$object = array( 'id' => 'a' );
		$design = static fn( array $sides ): array => array( 'version' => 1, 'sides' => $sides ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( 1500000, DesignerPrices::secured_base( $base, $design( array() ) ), 'No used area yet.' );
		self::assertSame( 1500000, DesignerPrices::secured_base( $base, $design( array( 'front' => array( $object ) ) ) ) );
		self::assertSame( 2000000, DesignerPrices::secured_base( $base, $design( array( 'front' => array( $object ), 'back' => array( $object ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 2500000, DesignerPrices::secured_base( $base, (string) json_encode( $design( array( 'front' => array( $object ), 'back' => array( $object ), 'sleeve' => array( $object ) ) ) ) ), 'Also from the stored JSON.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound, WordPress.WP.AlternativeFunctions.json_encode_json_encode
		self::assertSame( 1500000, DesignerPrices::secured_base( $base, $design( array( 'front' => array( $object ), 'back' => array( array( 'id' => 'b', 'hidden' => true ) ), 'neck' => array() ) ) ), 'A hidden or empty area is not used.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 1500000, DesignerPrices::secured_base( $base, 'no json' ) );

		$price = DesignerPrices::compute( 1, SalesAmount::format( DesignerPrices::secured_base( $base, $design( array( 'front' => array( $object ), 'back' => array( $object ) ) ) ) ), 'percent', '20', 'DKK', 2 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( array( 2000000, 400000, 2400000 ), array( $price->base_units, $price->profit_units, $price->price_units() ), 'The owner\'s example: 150 + 50, 20 % gives 40 and 240.' );
	}

	public function test_the_most_expensive_area_is_included_and_small_areas_have_their_own_price(): void {
		$side   = static fn( string $key, bool $small = false, array $sizes = array() ): BaseView => new BaseView( $key, $key, 1000, 1000, array( 'background' => 1 ), array( 'x' => 0, 'y' => 0, 'width' => 500, 'height' => 500 ), 300, 300, 150, '', false, null, $sizes, $small ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$base   = new BaseProduct( null, BaseProduct::DRAFT, 'own', 'Tee', '', 1500000, 'DKK', null, array(), array(), array( $side( 'front' ), $side( 'back' ), $side( 'sleeve', true ), $side( 'neck', true ) ), 1, 500000, '', array(), 200000 );
		$object = array( 'id' => 'a' );
		$price  = static fn( string ...$keys ): int => DesignerPrices::secured_base( $base, array( 'version' => 1, 'sides' => array_fill_keys( $keys, array( $object ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertSame( 1700000, $price( 'front', 'sleeve' ), 'The owner\'s example: 150 + a sleeve at 20.' );
		self::assertSame( 2000000, $price( 'front', 'back' ) );
		self::assertSame( 2200000, $price( 'front', 'back', 'sleeve' ) );
		self::assertSame( 1500000, $price( 'sleeve' ), 'A sleeve alone is included.' );
		self::assertSame( 1700000, $price( 'sleeve', 'neck' ) );
		self::assertSame( 1700000, $price( 'sleeve', 'front' ), 'The large area is included whatever the order.' );
		self::assertSame( 1500000, $price( 'front' ) );

		// Sides per size: the size that costs most.
		$sized = new BaseProduct( null, BaseProduct::DRAFT, 'own', 'Tee', '', 1500000, 'DKK', null, array(), array( 'S', 'L' ), array( $side( 'front-s', false, array( 'S' ) ), $side( 'sleeve-s', true, array( 'S' ) ), $side( 'front-l', false, array( 'L' ) ), $side( 'back-l', false, array( 'L' ) ) ), 1, 500000, '', array(), 200000 );
		$all   = array( 'front-s', 'sleeve-s', 'front-l', 'back-l' );

		self::assertSame( 500000, $sized->extra_amount( $all, array( 'S', 'L' ) ) );
		self::assertSame( 200000, $sized->extra_amount( $all, array( 'S' ) ) );
		self::assertSame( 500000, $sized->extra_amount( $all ) );
	}

	public function test_invalid_settings_are_refused(): void {
		$refused = 0;

		foreach ( array( array( '150', 'share', '10' ), array( '150', 'fixed', '-1' ), array( '-1', 'fixed', '10' ), array( '0', 'fixed', '0' ) ) as [ $base, $kind, $value ] ) {
			try {
				DesignerPrices::compute( 7, $base, $kind, $value, 'DKK', 2 );
			} catch ( InvalidArgumentException ) {
				++$refused;
			}
		}

		self::assertSame( 4, $refused, 'An unknown kind, a negative value or base price and a price of 0 are refused.' );
	}
}
