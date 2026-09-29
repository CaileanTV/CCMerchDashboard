<?php
/**
 * Tests for the WooCommerce hooks of the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerHooks;
use MerchBoard\Tests\Unit\Support\RecordingSalesSync;
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_Post;

#[CoversClass( SalesLedgerHooks::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SalesLedgerHooksTest extends TestCase {

	private RecordingSalesSync $sync;

	private SalesLedgerHooks $hooks;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-order.php';
		require_once dirname( __DIR__, 4 ) . '/Fixtures/wp-post.php';
		WcOrderStorage::reset();

		$this->sync  = new RecordingSalesSync();
		$this->hooks = new SalesLedgerHooks( $this->sync );
	}

	public function test_registers_exactly_the_decided_hooks(): void {
		$this->hooks->register();

		self::assertSame( 10, has_action( 'woocommerce_after_order_object_save', array( $this->hooks, 'order_saved' ) ) );
		self::assertSame( 10, has_action( 'woocommerce_before_delete_order', array( $this->hooks, 'order_deleting' ) ) );
		self::assertSame( 5, has_action( 'before_delete_post', array( $this->hooks, 'post_deleting' ) ), 'Before WooCommerce deletes the lines (10).' );
		self::assertSame( 10, has_action( 'deleted_post', array( $this->hooks, 'post_deleted' ) ) );
		self::assertSame( 10, has_action( 'woocommerce_refund_deleted', array( $this->hooks, 'refund_deleted' ) ) );
		self::assertFalse( has_action( 'woocommerce_delete_order_refund' ), 'Not used (ADR-038, Q1).' );
	}

	public function test_order_saves_sync_shop_orders_only_and_skip_the_trash(): void {
		WcOrderStorage::add_order( 7, 'processing' );
		WcOrderStorage::add_order( 8, 'trash' );
		WcOrderStorage::add_order( 9, 'active', array(), null, 'shop_subscription' );
		WcOrderStorage::add_refund( 100, 7 );

		foreach ( array( 7, 8, 9, 100 ) as $id ) {
			$this->hooks->order_saved( wc_get_order( $id ) );
		}

		$this->hooks->order_saved( 7 );
		$this->hooks->order_saved( null );

		self::assertSame( array( 'sync:7' ), $this->sync->calls );
	}

	public function test_order_deletion_maps_both_hooks(): void {
		$this->hooks->order_deleting( 7 );
		$this->hooks->order_deleting( '8' );
		$this->hooks->order_deleting( null );
		$this->hooks->post_deleting( 9, self::post( 9, 'shop_order' ) );
		$this->hooks->post_deleting( 10, self::post( 10, 'shop_order_placehold' ) );
		$this->hooks->post_deleting( 11, self::post( 11, 'product' ) );
		$this->hooks->post_deleting( 12, null );

		self::assertSame( array( 'deleting:7', 'deleting:8', 'deleting:9' ), $this->sync->calls );
	}

	public function test_refund_deletion_passes_the_deleted_refund_id(): void {
		$this->hooks->post_deleted( 100, self::post( 100, 'shop_order_refund', 7 ) );
		$this->hooks->post_deleted( 101, self::post( 101, 'shop_order_refund', 0 ) );
		$this->hooks->post_deleted( 102, self::post( 102, 'shop_order', 0 ) );
		$this->hooks->post_deleted( 103, null );
		$this->hooks->refund_deleted( 104, 8 );
		$this->hooks->refund_deleted( '105', '9' );
		$this->hooks->refund_deleted( null, 9 );

		self::assertSame( array( 'sync:7:-100', 'sync:8:-104', 'sync:9:-105' ), $this->sync->calls );
	}

	/**
	 * A post.
	 *
	 * @param int    $id        Post id.
	 * @param string $type      Post type.
	 * @param int    $parent_id Parent id.
	 */
	private static function post( int $id, string $type, int $parent_id = 0 ): WP_Post {
		return new WP_Post(
			(object) array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_parent' => $parent_id,
			)
		);
	}
}
