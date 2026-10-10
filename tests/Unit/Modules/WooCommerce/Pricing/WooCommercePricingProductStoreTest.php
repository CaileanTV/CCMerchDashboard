<?php
/**
 * Tests for the pricing product access through WooCommerce CRUD.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Pricing\PricingUnitRecord;
use MerchBoard\Modules\WooCommerce\Pricing\WooCommercePricingProductStore;
use MerchBoard\Tests\Unit\Support\WcProductStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every test runs in its own process: the WooCommerce stand-in defines global
 * classes and functions. Reading never writes (ADR-049).
 */
#[CoversClass( WooCommercePricingProductStore::class )]
#[CoversClass( PricingUnitRecord::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommercePricingProductStoreTest extends TestCase {

	private WooCommercePricingProductStore $store;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-product.php';

		WcProductStorage::$products  = array();
		WcProductStorage::$meta      = array();
		WcProductStorage::$setters   = array();
		WcProductStorage::$fail_save = false;

		Functions\when( 'get_woocommerce_currency' )->justReturn( 'DKK' );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );

		$this->store = new WooCommercePricingProductStore();
	}

	public function test_reads_a_simple_product_with_its_three_prices_kept_apart(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Tee', '', 'draft' );
		WcProductStorage::set( 10, array( 'regular_price' => '120', 'sale_price' => '96', 'price' => '96' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$unit = $this->store->find( 10 );

		self::assertNotNull( $unit );
		self::assertSame( 10, $unit->product_id() );
		self::assertSame( 0, $unit->variation_id() );
		self::assertSame( 'draft', $unit->status );
		self::assertSame( 'draft', $unit->parent_status );
		self::assertSame( '120', $unit->regular_price );
		self::assertSame( 1200000, $unit->regular_units() );
		self::assertSame( 960000, $unit->sale_units() );
		self::assertSame( 960000, $unit->active_units() );
		self::assertTrue( $unit->supported() );
		self::assertFalse( $unit->trashed() );
		self::assertSame( 'DKK', $this->store->currency() );
		self::assertSame( 2, $this->store->decimals() );
	}

	public function test_reads_a_variation_with_its_own_prices_and_the_parent_status(): void {
		WcProductStorage::add( 20, array(), 'variable', 0, 'Hoodie', '', 'trash' );
		WcProductStorage::add( 21, array(), 'variation', 20, 'Hoodie - L' );
		WcProductStorage::set( 21, array( 'regular_price' => '300', 'price' => '300' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$unit = $this->store->find( 21 );

		self::assertSame( 20, $unit?->product_id() );
		self::assertSame( 21, $unit->variation_id() );
		self::assertSame( 3000000, $unit->regular_units() );
		self::assertNull( $unit->sale_units() );
		self::assertTrue( $unit->trashed(), 'The trashed parent blocks its variations.' );
	}

	public function test_unsupported_types_and_missing_products(): void {
		WcProductStorage::add( 30, array(), 'variable' );
		WcProductStorage::add( 31, array(), 'grouped' );
		WcProductStorage::add( 32, array(), 'external' );
		WcProductStorage::add( 33, array(), 'variation', 999 );
		WcProductStorage::add( 34, array(), 'simple', 0, '', '', 'auto-draft' );

		foreach ( array( 30, 31, 32, 33, 34 ) as $id ) {
			self::assertFalse( $this->store->find( $id )?->supported() ?? true, (string) $id );
		}

		self::assertSame( '', $this->store->find( 33 )?->parent_status, 'Missing parent.' );
		self::assertNull( $this->store->find( 404 ) );
		self::assertNull( $this->store->find( 0 ) );
	}

	public function test_reading_never_sets_or_saves_anything(): void {
		WcProductStorage::add( 10 );
		WcProductStorage::set( 10, array( 'regular_price' => '120', 'sale_price' => '96', 'price' => '96' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->store->find( 10 );
		$this->store->variation_ids( 10 );

		self::assertSame( array(), WcProductStorage::$setters, 'No setter is called.' );
		self::assertSame( '120', WcProductStorage::$products[10]['regular_price'] ?? null );
		self::assertSame( '96', WcProductStorage::$products[10]['sale_price'] ?? null );
	}

	public function test_the_store_has_no_write_method(): void {
		$methods = array_map( static fn( ReflectionMethod $m ): string => $m->getName(), ( new ReflectionClass( PricingProductStore::class ) )->getMethods() );

		sort( $methods );
		self::assertSame( array( 'currency', 'decimals', 'find', 'variation_ids' ), $methods, 'ADR-049: Merch Dashboard never writes a WooCommerce price.' );
	}
}
