<?php
/**
 * Tests for reading the orders YITH WooCommerce Gift Cards recorded on a card (G1).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\YithGiftCardProvider;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * `_ywgc_orders` as the plugin writes it (4.38.0: `update_post_meta()` of an
 * array of order ids, appended by `register_order()`).
 */
#[CoversClass( YithGiftCardProvider::class )]
final class YithGiftCardOrdersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_serialized' )->alias(
			static fn( mixed $data ): bool => is_string( $data ) && 1 === preg_match( '/^(a|i|s|b|O|d|N):/', $data )
		);
	}

	/**
	 * Valid lists.
	 *
	 * @return array<string, array{0: mixed, 1: list<int>}>
	 */
	public static function valid(): array {
		return array(
			'no meta'                         => array( null, array() ),
			'empty meta'                      => array( '', array() ),
			'empty array'                     => array( serialize( array() ), array() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As the plugin stores it.
			'ints'                            => array( serialize( array( 412, 407 ) ), array( 407, 412 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As the plugin stores it.
			'numeric strings and a duplicate' => array( serialize( array( '407', 407, '409' ) ), array( 407, 409 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- As the plugin stores it.
		);
	}

	/**
	 * Values that are never read as "no order".
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid(): array {
		return array(
			'plain text'       => array( '407' ),
			'not serialized'   => array( 'a:broken' ),
			'a serialized int' => array( serialize( 407 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test input.
			'zero'             => array( serialize( array( 0 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test input.
			'negative'         => array( serialize( array( -5 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test input.
			'text item'        => array( serialize( array( 'MB-T23-GC' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test input.
			'nested'           => array( serialize( array( array( 407 ) ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test input.
			'object'           => array( serialize( new stdClass() ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test input.
			'not a string'     => array( 407 ),
		);
	}

	/**
	 * A valid list is read sorted and unique.
	 *
	 * @phpstan-param list<int> $expected
	 *
	 * @param mixed $value    Stored value.
	 * @param int[] $expected Orders.
	 */
	#[DataProvider( 'valid' )]
	public function test_reads_the_plugins_list( mixed $value, array $expected ): void {
		self::assertSame( $expected, YithGiftCardProvider::registered_orders( $value ) );
	}

	/**
	 * Anything else is not exact: null, never an empty list.
	 *
	 * @param mixed $value Stored value.
	 */
	#[DataProvider( 'invalid' )]
	public function test_anything_else_is_not_exact( mixed $value ): void {
		self::assertNull( YithGiftCardProvider::registered_orders( $value ) );
	}
}
