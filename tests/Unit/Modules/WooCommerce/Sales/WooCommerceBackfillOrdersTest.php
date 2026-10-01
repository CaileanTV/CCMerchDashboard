<?php
/**
 * Tests for the backfill's order pages through WooCommerce CRUD.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Sales\WooCommerceBackfillOrders;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

#[CoversClass( WooCommerceBackfillOrders::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceBackfillOrdersTest extends TestCase {

	public function test_a_page_is_an_id_ascending_crud_query_in_the_prefixed_statuses(): void {
		Functions\expect( 'wc_get_orders' )->once()->with(
			array(
				'type'     => 'shop_order',
				'status'   => array( 'wc-processing', 'wc-awaiting_shipment' ),
				'return'   => 'ids',
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'limit'    => 25,
				'offset'   => 50,
				'paginate' => false,
			)
		)->andReturn( array( 51, '52', 0, 'x', -3 ) );

		self::assertSame( array( 51, 52 ), ( new WooCommerceBackfillOrders() )->page( array( 'processing', 'trash', 'awaiting_shipment' ), 50, 25 ) );
	}

	public function test_nothing_is_queried_without_statuses(): void {
		Functions\expect( 'wc_get_orders' )->never();

		self::assertSame( array(), ( new WooCommerceBackfillOrders() )->page( array( 'trash' ), 0, 25 ) );
		self::assertSame( array(), ( new WooCommerceBackfillOrders() )->page( array( 'completed' ), 0, 0 ) );
	}

	public function test_a_query_that_returns_no_ids_fails_the_page(): void {
		Functions\expect( 'wc_get_orders' )->once()->andReturn( (object) array( 'orders' => array() ) );

		$this->expectException( RuntimeException::class );

		( new WooCommerceBackfillOrders() )->page( array( 'completed' ), 0, 25 );
	}
}
