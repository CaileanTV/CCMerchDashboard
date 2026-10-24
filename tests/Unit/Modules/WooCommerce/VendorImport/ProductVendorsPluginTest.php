<?php
/**
 * Tests for reading Product Vendors' admins value.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\VendorImport;

use MerchBoard\Modules\WooCommerce\VendorImport\ProductVendorsPlugin;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( ProductVendorsPlugin::class )]
final class ProductVendorsPluginTest extends TestCase {

	/**
	 * The forms Product Vendors stores (ADR-074 pkt. 2).
	 *
	 * @return array<string, array{0: mixed, 1: list<int>}>
	 */
	public static function admins(): array {
		return array(
			'list of ints'         => array( array( 4, 9 ), array( 4, 9 ) ),
			'list of strings'      => array( array( '4', ' 9 ' ), array( 4, 9 ) ),
			'comma-separated text' => array( '4, 9,4', array( 4, 9 ) ),
			'one id'               => array( 7, array( 7 ) ),
			'one id as text'       => array( '7', array( 7 ) ),
			'empty text'           => array( '', array() ),
			'missing'              => array( null, array() ),
			'junk is left out'     => array( array( 'x', 0, -3, '2.5', 5 ), array( 5 ) ),
		);
	}

	/**
	 * Each form gives the same user ids.
	 *
	 * @phpstan-param list<int> $expected
	 *
	 * @param mixed $value    The stored value.
	 * @param int[] $expected User ids.
	 */
	#[DataProvider( 'admins' )]
	public function test_the_admins_value_is_read_as_product_vendors_reads_it( mixed $value, array $expected ): void {
		self::assertSame( $expected, ProductVendorsPlugin::admin_ids( $value ) );
	}
}
