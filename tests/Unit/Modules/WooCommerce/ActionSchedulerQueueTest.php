<?php
/**
 * Tests for the Action Scheduler job queue.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\ActionSchedulerQueue;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass( ActionSchedulerQueue::class )]
final class ActionSchedulerQueueTest extends TestCase {

	public function test_it_is_unavailable_before_action_scheduler_has_initialized(): void {
		Functions\when( 'did_action' )->justReturn( 0 );

		self::assertFalse( ( new ActionSchedulerQueue() )->available() );
	}

	public function test_without_action_scheduler_nothing_is_called_or_queued(): void {
		Functions\expect( 'as_enqueue_async_action' )->never();
		Functions\expect( 'as_schedule_single_action' )->never();
		Functions\expect( 'as_has_scheduled_action' )->never();
		$queue = new ActionSchedulerQueue( static fn(): bool => false );

		self::assertFalse( $queue->enqueue( 'merchboard_reconcile_product', array( array( 'product_id' => 1 ) ) ) );
		self::assertFalse( $queue->has_pending( 'merchboard_reconcile_product', array() ) );
	}

	public function test_jobs_go_into_the_merchboard_group(): void {
		$queue = new ActionSchedulerQueue( static fn(): bool => true );
		$args  = array( array( 'product_id' => 1 ) );

		Functions\expect( 'as_enqueue_async_action' )->once()->with( 'merchboard_reconcile_product', $args, 'merchboard' )->andReturn( 5 );
		$scheduled = array();
		Functions\expect( 'as_schedule_single_action' )->once()->andReturnUsing(
			static function ( int $timestamp, string $hook, array $job_args, string $group ) use ( &$scheduled ): int {
				$scheduled = array( $timestamp, $hook, $job_args, $group );

				return 6;
			}
		);
		Functions\expect( 'as_has_scheduled_action' )->once()->with( 'merchboard_reconcile_product', $args, 'merchboard' )->andReturn( true );

		self::assertTrue( $queue->enqueue( 'merchboard_reconcile_product', $args ) );
		$before = time();
		self::assertTrue( $queue->enqueue( 'merchboard_reconcile_run', $args, 60 ) );
		self::assertSame( array( 'merchboard_reconcile_run', $args, 'merchboard' ), array_slice( $scheduled, 1 ) );
		self::assertGreaterThanOrEqual( $before + 60, $scheduled[0] );
		self::assertLessThanOrEqual( time() + 60, $scheduled[0] );
		self::assertTrue( $queue->has_pending( 'merchboard_reconcile_product', $args ) );
	}

	public function test_a_refused_or_failing_enqueue_is_reported_as_not_queued(): void {
		$queue = new ActionSchedulerQueue( static fn(): bool => true );
		Functions\expect( 'as_enqueue_async_action' )->twice()->andReturnValues( array( 0, 7 ) );
		Functions\expect( 'as_schedule_single_action' )->once()->andThrow( new RuntimeException( 'Store down.' ) );

		self::assertFalse( $queue->enqueue( 'hook', array() ) );
		self::assertTrue( $queue->enqueue( 'hook', array() ) );
		self::assertFalse( $queue->enqueue( 'hook', array(), 30 ) );
	}
}
