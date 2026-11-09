<?php
/**
 * Tests for MetaCatalogExclusion.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\GuardedProduct;
use MerchBoard\Modules\ProductDesigner\Shop\MetaCatalogExclusion;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WcProductStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WooCommerce\Facebook\Products;

/**
 * Products made in the designer never reach Meta's catalog (1.7.7); every
 * other product is left to the Meta plugin.
 */
#[CoversClass( MetaCatalogExclusion::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class MetaCatalogExclusionTest extends TestCase {

	private FakeJobQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-product.php';
		WcProductStorage::$products = array();
		WcProductStorage::$meta     = array();
		WcProductStorage::add( 10, array(), 'variable', 0, 'Designer tee' );
		WcProductStorage::add( 11, array(), 'variation', 10 );
		WcProductStorage::add( 20, array(), 'simple', 0, 'Legacy mug' );
		$this->queue = new FakeJobQueue();
	}

	public function test_a_designer_product_and_its_variations_are_never_synced(): void {
		$guard = $this->createMock( DesignerProductGuard::class );
		$guard->expects( self::exactly( 2 ) )->method( 'guard' )->willReturnCallback( static fn( int $id ): ?GuardedProduct => 10 === $id ? new GuardedProduct( 3, 10, 7 ) : null );

		$exclusion = $this->exclusion( $guard );

		self::assertFalse( $exclusion->should_sync( true, wc_get_product( 10 ) ) );
		self::assertFalse( $exclusion->should_sync( true, wc_get_product( 11 ) ), 'A variation follows its designer product (asked once).' );
		self::assertTrue( $exclusion->should_sync( true, wc_get_product( 20 ) ), 'Every other product is the Meta plugin\'s.' );
		self::assertFalse( $exclusion->should_sync( false, wc_get_product( 20 ) ), 'An earlier no stays.' );
		self::assertSame( 'kept', $exclusion->should_sync( 'kept', 'not a product' ) );
	}

	public function test_a_product_that_cannot_be_told_is_kept_out(): void {
		$guard = $this->createMock( DesignerProductGuard::class );
		$guard->method( 'guard' )->willThrowException( new DatabaseException( 'Down.' ) );

		self::assertFalse( $this->exclusion( $guard )->should_sync( true, wc_get_product( 20 ) ) );
	}

	public function test_without_the_meta_plugin_there_is_nothing_to_remove(): void {
		Functions\expect( 'update_option' )->once()->with( MetaCatalogExclusion::OPTION, '1', true );

		$this->exclusion( $this->createMock( DesignerProductGuard::class ) )->run( 0 );
	}

	public function test_the_designer_products_at_meta_have_their_sync_switched_off_once(): void {
		require_once dirname( __DIR__, 3 ) . '/Unit/Support/FakeMetaProducts.php';
		Products::$disabled = array();

		$guard = $this->createMock( DesignerProductGuard::class );
		$guard->method( 'guard' )->willReturnCallback( static fn( int $id ): ?GuardedProduct => 10 === $id ? new GuardedProduct( 3, 10, 7 ) : null );
		$rows = $this->createMock( DesignerProductRepository::class );
		$rows->method( 'shop_batch' )->willReturn(
			array(
				array(
					'id'            => 1,
					'wc_product_id' => 10,
				),
				array(
					'id'            => 2,
					'wc_product_id' => 20,
				),
			)
		);

		Functions\expect( 'update_option' )->once()->with( MetaCatalogExclusion::OPTION, '1', true );

		( new MetaCatalogExclusion( $rows, $guard, $this->queue, new RecordingLogger() ) )->run( 0 );

		self::assertSame( array( 10 ), Products::$disabled, 'Only what the guard proves is a designer product.' );
		self::assertSame( array(), $this->queue->pending_hooks(), 'The last batch: no more jobs.' );
	}

	public function test_the_removal_is_queued_once_until_it_is_done(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$exclusion = $this->exclusion( $this->createMock( DesignerProductGuard::class ) );
		$exclusion->start();
		$exclusion->start();

		self::assertSame( array( MetaCatalogExclusion::HOOK ), $this->queue->pending_hooks() );
	}

	/**
	 * The exclusion with a guard.
	 *
	 * @param DesignerProductGuard $guard Guard.
	 */
	private function exclusion( DesignerProductGuard $guard ): MetaCatalogExclusion {
		return new MetaCatalogExclusion( $this->createMock( DesignerProductRepository::class ), $guard, $this->queue, new RecordingLogger() );
	}
}
