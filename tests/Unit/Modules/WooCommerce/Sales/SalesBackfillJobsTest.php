<?php
/**
 * Tests for the backfill's Action Scheduler chain.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfill;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillJobs;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillOutcome;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillRun;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillScheduler;
use MerchBoard\Modules\WooCommerce\Sales\SalesFailureCode;
use MerchBoard\Tests\Unit\Support\FakeBackfillOrders;
use MerchBoard\Tests\Unit\Support\FakeBackfillSync;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SalesBackfillScheduler::class )]
#[CoversClass( SalesBackfillJobs::class )]
final class SalesBackfillJobsTest extends TestCase {

	private const HOOK = 'merchboard_sales_backfill';

	private FakeDatabase $db;

	private FakeJobQueue $queue;

	private FakeBackfillSync $sync;

	private FakeBackfillOrders $orders;

	private RecordingLogger $logger;

	private SalesBackfillScheduler $scheduler;

	private SalesBackfillJobs $jobs;

	protected function setUp(): void {
		parent::setUp();
		$this->db        = new FakeDatabase();
		$this->queue     = new FakeJobQueue();
		$this->sync      = new FakeBackfillSync();
		$this->orders    = new FakeBackfillOrders();
		$this->logger    = new RecordingLogger();
		$this->scheduler = new SalesBackfillScheduler( $this->queue, new CountedOrderStatuses( static fn(): array => array( 'processing', 'completed' ) ) );
		$this->jobs      = new SalesBackfillJobs(
			new SalesBackfill( $this->sync, $this->orders ),
			$this->scheduler,
			new AuditTrail( new AuditRepository( $this->db ) ),
			$this->logger
		);

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-09-30 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		foreach ( range( 1, 5 ) as $id ) {
			$this->orders->orders[ $id * 10 ] = 1 === $id % 2 ? 'completed' : 'processing';
		}

		$this->orders->orders[99] = 'on-hold';
	}

	public function test_start_queues_the_first_page_with_the_cursor_in_the_payload(): void {
		[ $outcome, $run ] = $this->scheduler->start( 2, 7 );

		self::assertSame( SalesBackfillScheduler::STARTED, $outcome );
		self::assertNotNull( $run );
		self::assertSame( array( self::HOOK ), $this->queue->pending_hooks() );
		self::assertSame(
			array(
				'run_id'          => $run->run_id->value,
				'statuses'        => array( 'processing', 'completed' ),
				'batch_size'      => 2,
				'offset'          => 0,
				'tally'           => $run->tally->to_array(),
				'trigger_user_id' => 7,
			),
			$this->queue->pending_payloads()[0]
		);
	}

	public function test_only_one_run_at_a_time(): void {
		$this->scheduler->start( 2, null );

		self::assertSame( array( SalesBackfillScheduler::ALREADY_RUNNING, null ), $this->scheduler->start( 2, null ) );
		self::assertSame( array( SalesBackfillScheduler::ALREADY_RUNNING, null ), $this->scheduler->resume() );
		self::assertCount( 1, $this->queue->queued );

		// Also while its page is running.
		$this->queue->pending = array();
		$this->queue->running = self::HOOK;
		self::assertTrue( $this->scheduler->active() );
		self::assertSame( SalesBackfillScheduler::ALREADY_RUNNING, $this->scheduler->start( 2, null )[0] );
	}

	public function test_without_a_queue_nothing_starts(): void {
		$this->queue->is_available = false;

		self::assertSame( array( SalesBackfillScheduler::QUEUE_UNAVAILABLE, null ), $this->scheduler->start( 50, null ) );
		self::assertSame( array( SalesBackfillScheduler::QUEUE_UNAVAILABLE, null ), $this->scheduler->resume() );

		$this->queue->is_available = true;
		$this->queue->refuse       = true;
		self::assertSame( array( SalesBackfillScheduler::NOT_QUEUED, null ), $this->scheduler->start( 50, null ) );
	}

	public function test_the_chain_runs_page_by_page_and_records_one_summary(): void {
		[ , $run ] = $this->scheduler->start( 2, 7 );

		$jobs = $this->drain();

		self::assertSame( 3, $jobs, 'Five orders in pages of two.' );
		self::assertSame( array( 10, 20, 30, 40, 50 ), $this->sync->registered, 'Every counted order once, oldest first.' );
		self::assertSame( array(), $this->queue->pending, 'No backfill job is left.' );
		self::assertSame( array(), $this->queue->failed );

		$audit = $this->db->rows( 'merchboard_audit_log' );
		self::assertCount( 1, $audit );
		self::assertSame( 'sales_backfill_completed', $audit[0]['action'] );
		self::assertNull( $audit[0]['actor_user_id'], 'System work: no actor.' );
		self::assertNull( $audit[0]['creator_id'] );
		self::assertSame(
			array(
				'run_id'              => $run?->run_id->value,
				'trigger_user_id'     => 7,
				'scanned'             => 5,
				'registered'          => 5,
				'skipped_history'     => 0,
				'skipped_not_counted' => 0,
				'skipped_no_creator'  => 0,
				'skipped_missing'     => 0,
				'skipped_busy'        => 0,
				'failed'              => 0,
				'rows'                => array(
					'sale'       => 5,
					'refund'     => 0,
					'reversal'   => 0,
					'correction' => 0,
				),
				'failures'            => array(),
			),
			json_decode( (string) $audit[0]['context'], true )
		);
	}

	public function test_order_failures_are_counted_in_the_summary_without_a_retry(): void {
		$this->sync->outcomes = array(
			20 => SalesBackfillOutcome::failed( SalesFailureCode::NegativeBalance ),
			40 => SalesBackfillOutcome::failed( SalesFailureCode::NegativeBalance ),
		);
		$this->scheduler->start( 50, null );

		$this->drain();

		self::assertSame( array( 10, 20, 30, 40, 50 ), $this->sync->registered, 'Each order once: no retry.' );
		$context = json_decode( (string) $this->db->rows( 'merchboard_audit_log' )[0]['context'], true );
		self::assertSame( 2, $context['failed'] );
		self::assertSame( array( 'negative_balance' => 2 ), $context['failures'] );
		self::assertArrayNotHasKey( 'trigger_user_id', $context );
	}

	public function test_a_failed_page_stops_the_run_without_retry_or_summary_and_can_be_resumed(): void {
		[ , $run ]             = $this->scheduler->start( 2, null );
		$this->orders->fail_on = 2;

		$this->drain();

		self::assertSame( array( 10, 20 ), $this->sync->registered );
		self::assertCount( 1, $this->queue->failed, 'The page job is marked failed.' );
		self::assertSame( array(), $this->queue->pending, 'No automatic retry.' );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ), 'No summary for a stopped run.' );
		self::assertSame( 'Sales backfill page failed; the run stopped and can be resumed.', $this->logger->at( 'error' )[0]['message'] );
		self::assertSame( 2, $this->logger->at( 'error' )[0]['context']['offset'] );

		$stopped = $this->scheduler->resumable();
		self::assertNotNull( $stopped );
		self::assertSame( $run?->run_id->value, $stopped->run_id->value );
		self::assertSame( 2, $stopped->offset, 'The cursor did not advance past the failed page.' );

		[ $outcome, $resumed ] = $this->scheduler->resume();
		self::assertSame( SalesBackfillScheduler::RESUMED, $outcome );
		self::assertSame( 2, $resumed?->offset );

		$this->drain();

		self::assertSame( array( 10, 20, 30, 40, 50 ), $this->sync->registered, 'The failed page runs once more, nothing twice.' );
		self::assertCount( 1, $this->db->rows( 'merchboard_audit_log' ) );
		self::assertNull( $this->scheduler->resumable(), 'A finished run cannot be resumed.' );
		self::assertSame( array( SalesBackfillScheduler::NOTHING_TO_RESUME, null ), $this->scheduler->resume() );
	}

	public function test_a_next_page_that_cannot_be_queued_fails_the_job_and_is_resumable(): void {
		$this->scheduler->start( 2, null );
		$this->queue->refuse = true;

		$this->drain();

		self::assertCount( 1, $this->queue->failed );
		self::assertSame( 0, $this->scheduler->resumable()?->offset, 'Resume repeats the page (idempotent).' );

		$this->queue->refuse = false;
		$this->scheduler->resume();
		$this->drain();

		self::assertSame( array( 10, 20, 10, 20, 30, 40, 50 ), $this->sync->registered, 'The repeated page is harmless: the backfill is idempotent.' );
		self::assertCount( 1, $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_a_failed_summary_fails_the_last_page_and_is_resumable(): void {
		$this->scheduler->start( 50, null );
		$this->db->fail_writes_to = array( 'merchboard_audit_log' );

		$this->drain();

		self::assertCount( 1, $this->queue->failed );
		self::assertNotNull( $this->scheduler->resumable() );

		$this->db->fail_writes_to = array();
		$this->scheduler->resume();
		$this->drain();

		self::assertCount( 1, $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_cancelled_pages_can_be_resumed(): void {
		$this->scheduler->start( 2, null );
		$this->queue->run_next( fn( string $hook, array $args ) => $this->jobs->run( $args[0] ) );

		// Deactivation.
		$this->queue->cancel_all( self::HOOK );

		self::assertSame( array(), $this->queue->pending );
		self::assertFalse( $this->scheduler->active() );
		self::assertSame( 2, $this->scheduler->resumable()?->offset );

		$this->scheduler->resume();
		$this->drain();

		self::assertSame( array( 10, 20, 30, 40, 50 ), $this->sync->registered );
		self::assertCount( 1, $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_an_invalid_payload_is_logged_and_dropped(): void {
		$this->jobs->run( array( 'run_id' => 'x' ) );

		self::assertSame( array( 'Invalid sales backfill job dropped.' ), array_column( $this->logger->at( 'error' ), 'message' ) );
		self::assertSame( array(), $this->queue->pending );
		self::assertSame( array(), $this->sync->registered );
	}

	public function test_a_resumable_payload_that_is_invalid_is_ignored(): void {
		$this->queue->history[] = array(
			'hook'   => self::HOOK,
			'args'   => array( array( 'run_id' => 'broken' ) ),
			'status' => 'failed',
		);

		self::assertNull( $this->scheduler->resumable() );
		self::assertSame( SalesBackfillScheduler::NOTHING_TO_RESUME, $this->scheduler->resume()[0] );
	}

	public function test_the_summary_holds_the_run_id_and_counts_only(): void {
		$run = SalesBackfillRun::start( array( 'completed' ), 50, null );

		self::assertSame( array( 'run_id', 'scanned', 'registered', 'skipped_history', 'skipped_not_counted', 'skipped_no_creator', 'skipped_missing', 'skipped_busy', 'failed', 'rows', 'failures' ), array_keys( SalesBackfillJobs::summary( $run ) ) );
	}

	public function test_the_callback_is_hooked(): void {
		$this->jobs->register();

		self::assertSame( 10, has_action( self::HOOK, array( $this->jobs, 'run' ) ) );
	}

	/**
	 * Runs the queued jobs like Action Scheduler.
	 */
	private function drain(): int {
		return $this->queue->drain(
			function ( string $hook, array $args ): void {
				self::assertSame( self::HOOK, $hook );
				$this->jobs->run( $args[0] );
			}
		);
	}
}
