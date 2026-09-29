<?php
/**
 * Tests for the planner's input snapshots.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( OrderSnapshot::class )]
#[CoversClass( OrderLineSnapshot::class )]
#[CoversClass( RefundSnapshot::class )]
#[CoversClass( RefundLineSnapshot::class )]
final class OrderSnapshotTest extends TestCase {

	public function test_lines_and_refunds_are_sorted_and_the_known_deleted_refund_is_left_out(): void {
		$date  = new DateTimeImmutable( '2026-09-01 10:00:00' );
		$order = new OrderSnapshot(
			7,
			true,
			true,
			'DKK',
			$date,
			null,
			array( new OrderLineSnapshot( 12, 20, 0, 'B', 1, 1, 3 ), new OrderLineSnapshot( 11, 20, 0, 'A', 1, 1, null ) ),
			array( new RefundSnapshot( 101, $date, array() ), new RefundSnapshot( 100, $date, array() ) ),
			101
		);

		self::assertSame( array( 11, 12 ), array_keys( $order->lines_by_item() ) );
		self::assertSame( array( 100 ), array_keys( $order->current_refunds() ) );
	}

	public function test_deleted_order_has_no_woocommerce_data(): void {
		$order = OrderSnapshot::deleted( 7 );

		self::assertFalse( $order->exists );
		self::assertFalse( $order->is_counted );
		self::assertSame( array(), $order->lines );
	}

	public function test_rejects_inconsistent_snapshots(): void {
		$date     = new DateTimeImmutable( '2026-09-01 10:00:00' );
		$line     = new OrderLineSnapshot( 11, 20, 0, 'A', 1, 1, 3 );
		$rejected = 0;

		foreach ( array(
			'order id 0'              => static fn() => new OrderSnapshot( 0, true, true, 'DKK', $date, null, array(), array() ),
			'lower-case currency'     => static fn() => new OrderSnapshot( 7, true, true, 'dkk', $date, null, array(), array() ),
			'no creation date'        => static fn() => new OrderSnapshot( 7, true, true, 'DKK', null, null, array(), array() ),
			'deleted but counted'     => static fn() => new OrderSnapshot( 7, false, true, '', null, null, array(), array() ),
			'deleted with lines'      => static fn() => new OrderSnapshot( 7, false, false, '', null, null, array( $line ), array() ),
			'duplicate line'          => static fn() => new OrderSnapshot( 7, true, true, 'DKK', $date, null, array( $line, $line ), array() ),
			'deleted refund id 0'     => static fn() => new OrderSnapshot( 7, true, true, 'DKK', $date, null, array(), array(), 0 ),
			'creator without product' => static fn() => new OrderLineSnapshot( 11, 0, 0, 'A', 1, 1, 3 ),
			'refund id 0'             => static fn() => new RefundSnapshot( 0, $date, array() ),
			'refund line twice'       => static fn() => new RefundSnapshot( 100, $date, array( new RefundLineSnapshot( 11, -1, -1 ), new RefundLineSnapshot( 11, -1, -1 ) ) ),
			'negative refunded item'  => static fn() => new RefundLineSnapshot( -1, -1, -1 ),
		) as $case => $build ) {
			try {
				$build();
				self::fail( 'Expected InvalidArgumentException: ' . $case );
			} catch ( InvalidArgumentException ) {
				++$rejected;
			}
		}

		self::assertSame( 11, $rejected );
	}
}
