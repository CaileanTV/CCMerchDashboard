<?php
/**
 * Tests for amounts in alert texts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Alerts\AlertMoney;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AlertMoney::class )]
final class AlertMoneyTest extends TestCase {

	/**
	 * The shop's currency position.
	 *
	 * @var string
	 */
	private string $position = 'right_space';

	protected function setUp(): void {
		parent::setUp();
		self::shop( ',', '.' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'DKK' );
		Functions\when( 'get_woocommerce_currency_symbol' )->alias( static fn( string $currency ): string => 'DKK' === $currency ? '&#107;&#114;.' : $currency );
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $fallback = false ): mixed => $this->option( $name, $fallback ) );
	}

	/**
	 * The shop's options.
	 *
	 * @param string $name     Option.
	 * @param mixed  $fallback Fallback.
	 */
	private function option( string $name, mixed $fallback ): mixed {
		return 'woocommerce_currency_pos' === $name ? $this->position : $fallback;
	}

	public function test_the_shops_format_symbol_and_position(): void {
		self::assertSame( '1.234.567,51 kr.', AlertMoney::format( '1234567.5050', '' ), 'The shop\'s currency, symbol decoded.' );
		self::assertSame( '0,01 kr.', AlertMoney::format( '0.0050', 'DKK' ), 'Half away from zero.' );
		self::assertSame( '-0,01 kr.', AlertMoney::format( '-0.0050', 'DKK' ) );
		self::assertSame( '12,00 EUR', AlertMoney::format( '12.0000', 'EUR' ), 'The order\'s currency.' );

		$this->position = 'left';
		self::assertSame( 'kr.249,00', AlertMoney::format( '249.0000', 'DKK' ) );
	}

	public function test_an_unknown_amount_shows_nothing(): void {
		self::assertSame( '', AlertMoney::format( null, 'DKK' ) );
		self::assertSame( '', AlertMoney::format( 'abc', 'DKK' ) );
	}

	public function test_the_price_per_item(): void {
		self::assertSame( '249.5000', AlertMoney::per_item( '499.0000', 2 ) );
		self::assertSame( '33.3333', AlertMoney::per_item( '100.0000', 3 ) );
		self::assertSame( '66.6667', AlertMoney::per_item( '200.0000', 3 ), 'Rounded half away from zero.' );
		self::assertNull( AlertMoney::per_item( null, 2 ) );
		self::assertNull( AlertMoney::per_item( '10.0000', 0 ) );
	}

	/**
	 * Stubs the shop's number format (two decimals).
	 *
	 * @param string $decimal  Decimal separator.
	 * @param string $thousand Thousand separator.
	 */
	public static function shop( string $decimal, string $thousand ): void {
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		Functions\when( 'wc_get_price_decimal_separator' )->justReturn( $decimal );
		Functions\when( 'wc_get_price_thousand_separator' )->justReturn( $thousand );
	}
}
