<?php
/**
 * Tests for the only writer of designer products (ADR-080 pkt. 6–7).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey\Functions;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Shop\GuardedProduct;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;
use MerchBoard\Tests\Unit\Support\RecordingDesignerGuard;
use MerchBoard\Tests\Unit\Support\WcProductStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass( WooCommerceDesignerWriter::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceDesignerWriterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-product.php';

		WcProductStorage::$products = array();
		WcProductStorage::$meta     = array();
		WcProductStorage::$setters  = array();
		WcProductStorage::$synced   = array();

		Functions\when( 'wc_tax_enabled' )->justReturn( true );
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );
	}

	public function test_the_price_is_written_on_the_designer_product_s_own_variations_only(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Tee' );
		WcProductStorage::add( 11, array(), 'variation', 10 );
		WcProductStorage::add( 12, array(), 'variation', 10 );
		WcProductStorage::set( 12, array( 'regular_price' => '200.00', 'sale_price' => '180', 'price' => '180' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		WcProductStorage::add( 20, array(), 'variable', 0, 'Legacy' );
		WcProductStorage::add( 21, array(), 'variation', 20 );

		$this->writer()->set_prices( new GuardedProduct( 3, 10, 7 ), 2000000, 2 );

		self::assertSame( array( array( 11, 'regular_price', '200.00' ) ), WcProductStorage::$setters, 'Only the variation whose price differs; never a sale price, never another product.' );
		self::assertSame( '180', WcProductStorage::$products[12]['sale_price'] ?? null, 'The company\'s sale price is left alone.' );
		self::assertSame( array( 10 ), WcProductStorage::$synced );
	}

	public function test_nothing_is_written_when_the_store_enters_prices_excluding_vat(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Tee' );
		WcProductStorage::add( 11, array(), 'variation', 10 );
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );

		try {
			$this->writer()->set_prices( new GuardedProduct( 3, 10, 7 ), 2000000, 2 );
			self::fail( 'Base price and profit are incl. VAT.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::PRICES_EXCL_TAX, $e->reason() );
		}

		self::assertSame( array(), WcProductStorage::$setters );
	}

	public function test_a_product_that_is_not_variable_is_refused(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Tee' );

		$this->expectException( DesignerException::class );
		$this->writer()->set_prices( new GuardedProduct( 3, 10, 7 ), 2000000, 2 );
	}

	public function test_a_draft_created_earlier_is_claimed_again_instead_of_a_second_product(): void {
		$guard = new RecordingDesignerGuard( null );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 10 ) );

		$proof = ( new WooCommerceDesignerWriter( $guard ) )->create_draft( 3, 'Tee' );

		self::assertSame( 10, $proof->wc_product_id );
		self::assertSame( array( 'for_row 3', 'token 3', 'claim 3 10' ), $guard->calls );
	}

	public function test_a_row_with_its_product_gets_the_same_product(): void {
		$proof = new GuardedProduct( 3, 10, 7 );
		$guard = new RecordingDesignerGuard( $proof );

		self::assertSame( $proof, ( new WooCommerceDesignerWriter( $guard ) )->create_draft( 3, 'Tee' ) );
		self::assertSame( array( 'for_row 3' ), $guard->calls, 'No new draft, no claim.' );
	}

	/**
	 * A writer whose guard is not used by set_prices().
	 */
	private function writer(): WooCommerceDesignerWriter {
		return new WooCommerceDesignerWriter( new RecordingDesignerGuard( null ) );
	}
}
