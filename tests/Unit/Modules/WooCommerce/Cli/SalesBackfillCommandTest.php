<?php
/**
 * Tests for `wp merchboard sales-backfill`.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Cli;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Cli\SalesBackfillCommand;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfill;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillOutcome;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillScheduler;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesFailureCode;
use MerchBoard\Tests\Unit\Support\FakeBackfillOrders;
use MerchBoard\Tests\Unit\Support\FakeBackfillSync;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingCliOutput;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass( SalesBackfillCommand::class )]
final class SalesBackfillCommandTest extends TestCase {

	private FakeJobQueue $queue;

	private FakeBackfillSync $sync;

	private FakeBackfillOrders $orders;

	private RecordingCliOutput $out;

	private SalesBackfillScheduler $scheduler;

	private SalesBackfillCommand $command;

	private int $user = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->queue     = new FakeJobQueue();
		$this->sync      = new FakeBackfillSync();
		$this->orders    = new FakeBackfillOrders();
		$this->out       = new RecordingCliOutput();
		$counted         = new CountedOrderStatuses( static fn(): array => array( 'processing', 'completed' ) );
		$this->scheduler = new SalesBackfillScheduler( $this->queue, $counted );
		$this->command   = new SalesBackfillCommand( new SalesBackfill( $this->sync, $this->orders ), $this->scheduler, $counted, $this->out );

		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->user );
		Functions\stubTranslationFunctions();

		$this->orders->orders = array(
			1 => 'completed',
			2 => 'processing',
			3 => 'completed',
			4 => 'on-hold',
		);
	}

	public function test_dry_run_prints_counts_only_and_queues_nothing(): void {
		$this->sync->outcomes = array(
			2 => SalesBackfillOutcome::failed( SalesFailureCode::UnresolvedRefundItem ),
			3 => SalesBackfillOutcome::of( SalesBackfillStatus::HasHistory ),
		);

		$this->command->dry_run( array(), array( 'batch-size' => '2' ) );

		self::assertSame(
			array(
				'line: Counted statuses: processing, completed',
				'line: Scanned 2 orders…',
				'line: Scanned 3 orders…',
				'line: Orders scanned: 3',
				'line: Would register: 1',
				'line: Skipped, already in the ledger: 1',
				'line: Skipped, not counted: 0',
				'line: Skipped, no creator: 0',
				'line: Skipped, not found: 0',
				'line: Skipped, busy: 0',
				'line: Rows that would be written: sale: 1, refund: 0, reversal: 0, correction: 0',
				'line: Failed: 1 (unresolved_refund_item: 1)',
				'success: Dry run finished. Nothing was written.',
			),
			$this->out->output
		);
		self::assertSame( array( 1, 2, 3 ), $this->sync->previewed );
		self::assertSame( array(), $this->sync->registered );
		self::assertSame( array(), $this->queue->queued, 'The dry run runs inline.' );
	}

	public function test_a_dry_run_that_cannot_read_the_orders_fails(): void {
		$this->orders->fail_on = 1;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'The dry run stopped: Orders could not be read.' );

		$this->command->dry_run( array(), array() );
	}

	public function test_start_queues_a_run_and_shows_its_id(): void {
		$this->user = 5;

		$this->command->start( array(), array( 'batch-size' => '25' ) );

		$payload = $this->queue->pending_payloads()[0];
		self::assertIsArray( $payload );
		self::assertSame( 25, $payload['batch_size'] );
		self::assertSame( 5, $payload['trigger_user_id'], 'The WP-CLI --user is the trigger user.' );
		self::assertSame( 'line: Counted statuses: processing, completed', $this->out->output[0] );
		self::assertSame( 'success: Backfill run ' . $payload['run_id'] . ' queued (25 orders per page). The summary is written to the audit log when it finishes.', $this->out->output[1] );
		self::assertSame( array(), $this->sync->registered, 'The real backfill never runs inline.' );
	}

	public function test_start_without_a_user_has_no_trigger_user(): void {
		$this->command->start( array(), array() );

		$payload = $this->queue->pending_payloads()[0];
		self::assertIsArray( $payload );
		self::assertNull( $payload['trigger_user_id'] );
		self::assertSame( 50, $payload['batch_size'] );
	}

	public function test_start_is_refused_while_a_run_is_active(): void {
		$this->command->start( array(), array() );

		try {
			$this->command->start( array(), array() );
			self::fail( 'Expected an error.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'already pending or running', $e->getMessage() );
		}

		self::assertCount( 1, $this->queue->queued );
	}

	public function test_resume_and_status(): void {
		$this->command->status( array(), array() );
		self::assertSame( 'line: No backfill run is active, and none can be resumed.', end( $this->out->output ) );

		try {
			$this->command->resume( array(), array() );
			self::fail( 'Expected an error.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'There is no stopped backfill run to resume.', $e->getMessage() );
		}

		$this->command->start( array(), array( 'batch-size' => '2' ) );
		$this->command->status( array(), array() );
		self::assertSame( 'line: A backfill run is pending or running.', end( $this->out->output ) );

		// The page is cancelled (deactivation): the run can be resumed.
		$this->queue->cancel_all( SalesBackfillScheduler::HOOK );
		$seen = count( $this->out->output );
		$this->command->status( array(), array() );
		$line = (string) ( $this->out->output[ $seen ] ?? '' );
		self::assertStringStartsWith( 'line: Backfill run ', $line );
		self::assertStringContainsString( 'stopped after 0 orders and can be resumed', $line );

		$this->command->resume( array(), array() );
		self::assertStringEndsWith( 'resumed at order 1.', (string) end( $this->out->output ) );
		self::assertCount( 1, $this->queue->pending );
	}

	/**
	 * Invalid arguments and the message.
	 *
	 * @return array<string, array{string, array<mixed>, array<mixed>, string}>
	 */
	public static function invalid_arguments(): array {
		return array(
			'positional argument'   => array( 'start', array( 'now' ), array(), 'This command takes no positional arguments.' ),
			'unknown option'        => array( 'start', array(), array( 'from' => '2026-01-01' ), 'Unknown option: --from' ),
			'no --to'               => array( 'dry_run', array(), array( 'to' => '2026-01-01' ), 'Unknown option: --to' ),
			'batch size zero'       => array( 'start', array(), array( 'batch-size' => '0' ), '--batch-size must be a whole number from 1 to 200.' ),
			'batch size too large'  => array( 'dry_run', array(), array( 'batch-size' => '201' ), '--batch-size must be a whole number from 1 to 200.' ),
			'batch size text'       => array( 'start', array(), array( 'batch-size' => 'ten' ), '--batch-size must be a whole number from 1 to 200.' ),
			'batch size negative'   => array( 'start', array(), array( 'batch-size' => '-5' ), '--batch-size must be a whole number from 1 to 200.' ),
			'batch size on resume'  => array( 'resume', array(), array( 'batch-size' => '5' ), 'Unknown option: --batch-size' ),
			'option on status'      => array( 'status', array(), array( 'orders' => '1' ), 'Unknown option: --orders' ),
			'reprocess not offered' => array( 'start', array(), array( 'order' => '12' ), 'Unknown option: --order' ),
		);
	}

	/**
	 * Invalid arguments end the command before anything is queued or read.
	 *
	 * @param string       $method     Subcommand method.
	 * @param array<mixed> $args       Positional arguments.
	 * @param array<mixed> $assoc_args Options.
	 * @param string       $message    Error message.
	 */
	#[DataProvider( 'invalid_arguments' )]
	public function test_invalid_arguments_are_rejected( string $method, array $args, array $assoc_args, string $message ): void {
		try {
			$this->command->{$method}( $args, $assoc_args );
			self::fail( 'Expected an error.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( $message, $e->getMessage() );
		}

		self::assertSame( array(), $this->queue->queued );
		self::assertSame( array(), $this->orders->requests );
	}

	public function test_an_unavailable_queue_is_reported(): void {
		$this->queue->is_available = false;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Action Scheduler is not available, so nothing was queued.' );

		$this->command->start( array(), array() );
	}
}
