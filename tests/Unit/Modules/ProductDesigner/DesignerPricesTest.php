<?php
/**
 * Tests for the price of designer products (ADR-080 pkt. 8).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use InvalidArgumentException;
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
