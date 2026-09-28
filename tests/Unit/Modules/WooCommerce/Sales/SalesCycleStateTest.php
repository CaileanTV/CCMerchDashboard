<?php
/**
 * Tests for reading the cycle state of an order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleEvent;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleEventType;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleState;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SalesCycleState::class )]
#[CoversClass( SalesCycleEvent::class )]
final class SalesCycleStateTest extends TestCase {

	public function test_order_without_cycles(): void {
		$state = new SalesCycleState( 7, array() );

		self::assertSame( 0, $state->latest_cycle() );
		self::assertNull( $state->active_cycle() );
		self::assertSame( 1, $state->next_cycle() );
	}

	public function test_open_closed_and_reopened_cycles(): void {
		$open_1  = self::event( 1, SalesCycleEventType::Open );
		$close_1 = self::event( 1, SalesCycleEventType::Close );
		$open_2  = self::event( 2, SalesCycleEventType::Open );

		self::assertSame( 1, ( new SalesCycleState( 7, array( $open_1 ) ) )->active_cycle() );

		$closed = new SalesCycleState( 7, array( $open_1, $close_1 ) );
		self::assertNull( $closed->active_cycle() );
		self::assertTrue( $closed->is_closed( 1 ) );
		self::assertSame( 2, $closed->next_cycle() );

		$reopened = new SalesCycleState( 7, array( $open_1, $close_1, $open_2 ) );
		self::assertSame( 2, $reopened->active_cycle() );
		self::assertSame( 2, $reopened->latest_cycle() );
		self::assertFalse( $reopened->is_closed( 2 ) );
		self::assertTrue( $reopened->has( 1, SalesCycleEventType::Close ) );
	}

	public function test_rejects_events_of_another_order(): void {
		$this->expectException( InvalidArgumentException::class );

		new SalesCycleState( 8, array( self::event( 1, SalesCycleEventType::Open ) ) );
	}

	public function test_rejects_cycle_zero(): void {
		$this->expectException( InvalidArgumentException::class );

		self::event( 0, SalesCycleEventType::Open );
	}

	/**
	 * A cycle event of order 7.
	 *
	 * @param int                 $cycle Cycle.
	 * @param SalesCycleEventType $type  Event.
	 */
	private static function event( int $cycle, SalesCycleEventType $type ): SalesCycleEvent {
		return new SalesCycleEvent( 7, $cycle, $type, new DateTimeImmutable( '2026-09-28 10:00:00' ) );
	}
}
