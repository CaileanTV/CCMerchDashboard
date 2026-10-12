<?php
/**
 * Exact amounts of YITH WooCommerce Gift Cards.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts;

use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\YithGiftCardProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plugin stores amounts as text typed by the admin or as floats it
 * wrote itself (verified on 4.38.0). Only an exact decimal at the store's
 * decimals becomes units; anything else is null, never 0 (ADR-057).
 */
final class YithGiftCardAmountTest extends TestCase {

	/**
	 * Stored values and their units with 2 store decimals (null = invalid_data).
	 *
	 * @return array<string, array{mixed, int|null}>
	 */
	public static function amounts(): array {
		return array(
			'integer as typed'         => array( '150', 1500000 ),
			'two decimals'             => array( '99.99', 999900 ),
			'trailing zeros'           => array( '50.000000', 500000 ),
			'spaces around'            => array( ' 25.5 ', 255000 ),
			'zero is a real balance'   => array( '0', 0 ),
			'comma (Danish typing)'    => array( '99,99', null ),
			'float residue'            => array( '49.999999999', null ),
			'more decimals than store' => array( '10.005', null ),
			'negative'                 => array( '-5', null ),
			'empty'                    => array( '', null ),
			'missing'                  => array( null, null ),
			'exponent'                 => array( '1e3', null ),
			'thousands separator'      => array( '1.000,00', null ),
			'not a string'             => array( 150, null ),
		);
	}

	/**
	 * Each stored value.
	 *
	 * @param mixed    $stored   Stored value.
	 * @param int|null $expected Units, or null.
	 */
	#[DataProvider( 'amounts' )]
	public function test_only_exact_amounts_become_units( mixed $stored, ?int $expected ): void {
		self::assertSame( $expected, YithGiftCardProvider::exact_amount( $stored, 2 ) );
	}

	/**
	 * A store without decimals accepts only whole amounts.
	 */
	public function test_store_without_decimals(): void {
		self::assertSame( 1500000, YithGiftCardProvider::exact_amount( '150.00', 0 ) );
		self::assertNull( YithGiftCardProvider::exact_amount( '150.5', 0 ) );
	}
}
