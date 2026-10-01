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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

	public function test_any_pending_or_running_job_of_a_hook_is_found_whatever_its_arguments(): void {
		$queue = new ActionSchedulerQueue( static fn(): bool => true );
		Functions\expect( 'as_has_scheduled_action' )->once()->with( 'merchboard_sales_backfill', null, 'merchboard' )->andReturn( true );

		self::assertTrue( $queue->has_any( 'merchboard_sales_backfill' ) );
		self::assertFalse( ( new ActionSchedulerQueue( static fn(): bool => false ) )->has_any( 'merchboard_sales_backfill' ) );
	}

	public function test_cancelling_uses_the_hook_only(): void {
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( 'merchboard_sales_backfill' );

		( new ActionSchedulerQueue( static fn(): bool => true ) )->cancel_all( 'merchboard_sales_backfill' );
		( new ActionSchedulerQueue( static fn(): bool => false ) )->cancel_all( 'merchboard_sales_backfill' );
	}

	public function test_a_failing_cancel_is_ignored(): void {
		Functions\expect( 'as_unschedule_all_actions' )->once()->andThrow( new RuntimeException( 'Store down.' ) );

		( new ActionSchedulerQueue( static fn(): bool => true ) )->cancel_all( 'merchboard_sales_backfill' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The latest failed or cancelled action is resumable unless a later one completed.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_latest_stopped_job_is_resumable_unless_a_later_one_completed(): void {
		require_once dirname( __DIR__, 3 ) . '/Fixtures/action-scheduler-action.php';

		$queue   = new ActionSchedulerQueue( static fn(): bool => true );
		$stopped = array( 12 => new \ActionScheduler_Action( 'merchboard_sales_backfill', array( array( 'offset' => 4 ) ) ) );
		$queries = array();
		$results = array( $stopped, array( 10 => new \ActionScheduler_Action( 'merchboard_sales_backfill' ) ), $stopped, array( 15 => new \ActionScheduler_Action( 'merchboard_sales_backfill' ) ), array() );

		Functions\when( 'as_get_scheduled_actions' )->alias(
			static function ( array $args ) use ( &$queries, &$results ): array {
				$queries[] = $args;

				return (array) array_shift( $results );
			}
		);

		self::assertSame( array( array( 'offset' => 4 ) ), $queue->resumable( 'merchboard_sales_backfill' ), 'Failed after the last completed one.' );
		self::assertNull( $queue->resumable( 'merchboard_sales_backfill' ), 'A later job completed.' );
		self::assertNull( $queue->resumable( 'merchboard_sales_backfill' ), 'Nothing stopped.' );

		self::assertSame(
			array(
				'hook'     => 'merchboard_sales_backfill',
				'group'    => 'merchboard',
				'status'   => array( 'failed', 'canceled' ),
				'orderby'  => 'action_id',
				'order'    => 'DESC',
				'per_page' => 1,
			),
			$queries[0]
		);
		self::assertSame( array( 'complete' ), $queries[1]['status'] );
		self::assertCount( 5, $queries, 'Nothing stopped: no second query.' );
	}
}
