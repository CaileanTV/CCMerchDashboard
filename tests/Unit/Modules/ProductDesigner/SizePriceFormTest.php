<?php
/**
 * Tests of the prices per size as the base product form sends them.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Modules\ProductDesigner\Admin\BaseProductActions;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( BaseProductActions::class )]
final class SizePriceFormTest extends TestCase {

	public function test_sizes_travel_as_values_so_quotes_keep_their_price(): void {
		self::assertSame(
			array(
				'21x29.7 cm / 8x12"' => '149',
				'45x45 cm / 18x18″'  => '',
				'A2 (42 x 59.4 cm)'  => '349,95',
			),
			BaseProductActions::entered_size_prices(
				array(
					'size_price_names' => array( '21x29.7 cm / 8x12"', '45x45 cm / 18x18″', 'A2 (42 x 59.4 cm)', 'Lost' ),
					'size_prices'      => array( '149', '', '349,95' ),
				)
			)
		);
	}

	public function test_without_names_the_prices_are_keyed_by_size(): void {
		self::assertSame( array( 'M' => '199' ), BaseProductActions::entered_size_prices( array( 'size_prices' => array( 'M' => '199' ) ) ) );
		self::assertSame( array(), BaseProductActions::entered_size_prices( array() ) );
	}
}
