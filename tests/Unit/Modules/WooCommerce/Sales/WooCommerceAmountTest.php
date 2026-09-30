<?php
/**
 * Tests for WooCommerce amounts and quantities, and the counted statuses.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\WooCommerceAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( WooCommerceAmount::class )]
#[CoversClass( CountedOrderStatuses::class )]
final class WooCommerceAmountTest extends TestCase {

	/**
	 * WooCommerce values (as `wc_format_decimal()` stores them) and their units.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function amounts(): array {
		return array(
			'integer zero (non-numeric input)' => array( 0, 0 ),
			'integer'                          => array( 100, 1000000 ),
			'two decimals'                     => array( '12.50', 125000 ),
			'negative refund total'            => array( '-30.25', -302500 ),
			'minus zero'                       => array( '-0', 0 ),
			'six decimals rounded down'        => array( '1.000049', 10000 ),
			'six decimals rounded half up'     => array( '1.000050', 10001 ),
			'negative half away from zero'     => array( '-1.000050', -10001 ),
			'trimmed float string'             => array( '19.99', 199900 ),
		);
	}

	#[DataProvider( 'amounts' )]
	public function test_amounts_are_converted_deterministically( mixed $value, int $units ): void {
		self::assertSame( $units, WooCommerceAmount::units( $value ) );
	}

	/**
	 * Values that are never cast or guessed (ADR-038, Q6).
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_amounts(): array {
		return array(
			'float'            => array( 19.99 ),
			'empty string'     => array( '' ),
			'exponent'         => array( '1.0E-5' ),
			'seven decimals'   => array( '1.0000001' ),
			'locale separator' => array( '12,50' ),
			'spaces'           => array( ' 12.50' ),
			'null'             => array( null ),
			'boolean'          => array( false ),
			'array'            => array( array( '1' ) ),
		);
	}

	#[DataProvider( 'invalid_amounts' )]
	public function test_unsupported_amounts_are_rejected( mixed $value ): void {
		$this->expectException( InvalidArgumentException::class );

		WooCommerceAmount::units( $value );
	}

	public function test_whole_quantities_are_accepted_as_integers(): void {
		self::assertSame( 2, WooCommerceAmount::quantity( 2 ) );
		self::assertSame( -1, WooCommerceAmount::quantity( -1 ) );
		self::assertSame( 3, WooCommerceAmount::quantity( 3.0 ) );
		self::assertSame( 4, WooCommerceAmount::quantity( '4' ) );
		self::assertSame( -2, WooCommerceAmount::quantity( '-2.000' ) );
	}

	/**
	 * Quantities that are never rounded.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_quantities(): array {
		return array(
			'fraction float'  => array( 1.5 ),
			'fraction string' => array( '1.5' ),
			'infinite'        => array( INF ),
			'empty'           => array( '' ),
			'null'            => array( null ),
			'huge float'      => array( 1e20 ),
		);
	}

	#[DataProvider( 'invalid_quantities' )]
	public function test_fractional_or_invalid_quantities_are_rejected( mixed $value ): void {
		$this->expectException( InvalidArgumentException::class );

		WooCommerceAmount::quantity( $value );
	}

	public function test_counted_statuses_are_processing_and_completed_only(): void {
		$statuses = new CountedOrderStatuses( static fn(): array => CountedOrderStatuses::DEFAULT );

		self::assertSame( array( 'processing', 'completed' ), CountedOrderStatuses::DEFAULT );
		self::assertTrue( $statuses->counts( 'processing' ) );
		self::assertTrue( $statuses->counts( 'completed' ) );

		foreach ( array( 'pending', 'on-hold', 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft', 'wc-completed', 'Completed' ) as $status ) {
			self::assertFalse( $statuses->counts( $status ), $status );
		}
	}
}
