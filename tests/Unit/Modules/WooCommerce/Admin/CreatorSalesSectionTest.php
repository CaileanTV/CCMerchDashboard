<?php
/**
 * Tests for the money text of the admin Sales section.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorSalesSection;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( CreatorSalesSection::class )]
final class CreatorSalesSectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// The site's number format, as WordPress' Danish locale sets it.
		Functions\when( 'number_format_i18n' )->alias(
			static fn( int|float $number ): string => number_format( (float) $number, 0, ',', '.' )
		);
		$GLOBALS['wp_locale'] = (object) array( 'number_format' => array( 'decimal_point' => ',' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stand-in for WP_Locale.
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_locale'] );
		parent::tearDown();
	}

	/**
	 * Amounts and their text.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public static function amounts(): array {
		return array(
			'simple'                     => array( '1234.5000', '1.234,50 DKK' ),
			'zero'                       => array( '0.0000', '0,00 DKK' ),
			'negative'                   => array( '-266.0000', '-266,00 DKK' ),
			'rounds half away from zero' => array( '0.0050', '0,01 DKK' ),
			'negative half'              => array( '-0.0050', '-0,01 DKK' ),
			'rounds down'                => array( '0.0049', '0,00 DKK' ),
			'no negative zero'           => array( '-0.0049', '0,00 DKK' ),
			'carry into the integer'     => array( '9.9950', '10,00 DKK' ),
			'large, exact'               => array( '123456789012345.6749', '123.456.789.012.345,67 DKK' ),
			'fewer decimals'             => array( '1.5', '1,50 DKK' ),
			'not an amount'              => array( '12,50', '' ),
			'not a string'               => array( 12.5, '' ),
		);
	}

	/**
	 * Integer arithmetic, two decimals, the site's separators.
	 *
	 * @param mixed  $amount   Amount.
	 * @param string $expected Text.
	 */
	#[DataProvider( 'amounts' )]
	public function test_money_text( mixed $amount, string $expected ): void {
		self::assertSame( $expected, CreatorSalesSection::money( $amount, 'DKK' ) );
	}
}
