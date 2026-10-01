<?php
/**
 * Integration tests for the sales ledger backfill (ROADMAP fase 18, trin 7).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfill;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillJobs;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillScheduler;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\FakeBackfillOrders;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use wpdb;

/**
 * The backfill on a real database with a test reader instead of WooCommerce
 * (ADR-038, Q4): only orders without ledger history are registered, through
 * the same lock, transaction, planner, failure marker and log as a live sync.
 */
final class SalesBackfillTest extends IntegrationTestCase {

	private FakeOrderReader $reader;

	private RecordingFailureMarker $marker;

	private RecordingLogger $logger;

	private SalesSynchronizer $sync;

	private int $clock = 0;

	private ?wpdb $other = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( 'merchboard_settings' );
		$this->reader = new FakeOrderReader();
		$this->marker = new RecordingFailureMarker();
		$this->logger = new RecordingLogger();
		$this->sync   = $this->synchronizer();
	}

	protected function tearDown(): void {
		if ( null !== $this->other ) {
			$this->other->close();
			$this->other = null;
		}

		parent::tearDown();
	}

	public function test_a_historical_counted_order_gets_cycle_1_with_its_refunds_and_historical_dates(): void {
		$this->order(
			7,
			'completed',
			array( self::line( 11, 3, '150' ) ),
			array( new RefundSnapshot( 100, new DateTimeImmutable( '2026-09-02 09:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-50' ) ) ) ) )
		);

		$outcome = $this->sync->backfill( 7 );

		self::assertSame( SalesBackfillStatus::Registered, $outcome->status );
		self::assertSame(
			array(
				'sale'       => 1,
				'refund'     => 1,
				'reversal'   => 0,
				'correction' => 0,
			),
			$outcome->rows
		);
		self::assertSame(
			array(
				array( 'sale', '150.0000', '2026-09-01 10:05:00' ),
				array( 'refund', '-50.0000', '2026-09-02 09:00:00' ),
			),
			array_map( static fn( array $row ): array => array( $row['type'], $row['net_amount'], $row['occurred_at'] ), $this->rows( Tables::SALES ) ),
			'Cycle 1 as gross + refunds, with date_paid and the refund date (the existing planner).'
		);
		self::assertSame( array( '1:open' ), $this->cycles() );
		self::assertSame( array( 'clear:7' ), $this->marker->calls, 'Success clears a marker, like a live sync.' );
	}

	public function test_existing_ledger_history_is_never_changed(): void {
		$this->order( 7, 'completed', array( self::line( 11, 1, '100' ) ) );
		$this->sync->sync( 7 );
		$before = $this->rows( Tables::SALES );

		// WooCommerce changed since (not synchronized yet): the backfill still does not touch the order.
		$this->order( 7, 'cancelled', array( self::line( 11, 1, '100' ) ) );

		self::assertSame( SalesBackfillStatus::HasHistory, $this->sync->backfill( 7 )->status );
		self::assertSame( SalesBackfillStatus::HasHistory, $this->sync->preview( 7 )->status );
		self::assertSame( $before, $this->rows( Tables::SALES ), 'No reversal, no recalculation: that is the next live sync, not the backfill.' );
		self::assertSame( array( '1:open' ), $this->cycles() );
	}

	public function test_orders_that_do_not_count_now_or_have_no_creator_get_no_rows(): void {
		$this->order( 7, 'cancelled', array( self::line( 11, 1, '100' ) ) );
		$this->order( 8, 'completed', array( self::line( 12, 1, '100', null ) ) );

		self::assertSame( SalesBackfillStatus::NotCounted, $this->sync->backfill( 7 )->status );
		self::assertSame( SalesBackfillStatus::NoCreator, $this->sync->backfill( 8 )->status );
		self::assertSame( SalesBackfillStatus::Missing, $this->sync->backfill( 404 )->status );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
	}

	public function test_a_second_backfill_writes_nothing(): void {
		$this->order( 7, 'processing', array( self::line( 11, 2, '100' ) ) );

		self::assertSame( SalesBackfillStatus::Registered, $this->sync->backfill( 7 )->status );
		$rows = $this->rows( Tables::SALES );

		self::assertSame( SalesBackfillStatus::HasHistory, $this->sync->backfill( 7 )->status );
		self::assertSame( $rows, $this->rows( Tables::SALES ) );
	}

	public function test_an_order_registered_meanwhile_by_a_live_sync_is_left_alone(): void {
		$this->order( 7, 'completed', array( self::line( 11, 1, '100' ) ) );
		$live = $this->synchronizer();
		$done = false;

		// Between the backfill's check and its lock, a live hook registers the order.
		$this->reader->on_read = static function ( int $order_id ) use ( $live, &$done ): void {
			if ( ! $done ) {
				$done = true;
				$live->sync( $order_id );
			}
		};

		$outcome = $this->sync->backfill( 7 );

		self::assertSame( SalesBackfillStatus::HasHistory, $outcome->status );
		self::assertSame( array( 's:11:1' ), array_column( $this->rows( Tables::SALES ), 'event_key' ), 'Only the live registration.' );
	}

	public function test_a_planning_failure_sets_the_marker_and_logs_ids_only(): void {
		$this->order(
			7,
			'completed',
			array( self::line( 11, 1, '100' ) ),
			array( new RefundSnapshot( 100, new DateTimeImmutable( '2026-09-02 09:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( 99, -1, SalesAmount::parse( '-10' ) ) ) ) )
		);

		$outcome = $this->sync->backfill( 7 );

		self::assertSame( SalesBackfillStatus::Failed, $outcome->status );
		self::assertSame( 'unresolved_refund_item', $outcome->failure?->value );
		self::assertSame( 0, $this->count_rows( Tables::SALES ), 'A failure writes nothing.' );
		self::assertSame( array( 'record:7:unresolved_refund_item' ), $this->marker->calls );
		self::assertSame( 'Sales ledger synchronization failed.', $this->logger->entries[0]['message'] );
		self::assertSame( 7, $this->logger->entries[0]['context']['order_id'] );
		self::assertSame( 'unresolved_refund_item', $this->logger->entries[0]['context']['code'] );
	}

	public function test_the_preview_takes_no_lock_writes_nothing_and_sets_no_marker(): void {
		$this->order( 7, 'completed', array( self::line( 11, 2, '100' ) ) );
		$this->order( 8, 'completed', array( self::line( 12, 1, '10' ) ), array( new RefundSnapshot( 101, new DateTimeImmutable( '2026-09-02 09:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( 98, -1, SalesAmount::parse( '-1' ) ) ) ) ) );
		$other = $this->other_connection();
		self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $this->repository()->lock_name( 7 ) ) ) );

		$preview = $this->sync->preview( 7 );
		$failed  = $this->sync->preview( 8 );

		self::assertSame( SalesBackfillStatus::Registered, $preview->status, 'No lock needed while another connection holds it.' );
		self::assertSame( 1, $preview->rows['sale'] );
		self::assertSame( 'unresolved_refund_item', $failed->failure?->value );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
		self::assertSame( array(), $this->marker->calls, 'No failure marker in a dry run.' );
		self::assertSame( array(), $this->logger->entries, 'No log entry in a dry run.' );

		// The real registration needs the lock.
		$outcome = $this->synchronizer( 1 )->backfill( 7 );
		self::assertSame( 'lock_timeout', $outcome->failure?->value );
		self::assertSame( array( 'record:7:lock_timeout' ), $this->marker->calls );
	}

	public function test_a_run_registers_page_by_page_records_the_summary_and_a_second_run_writes_nothing(): void {
		$this->order( 7, 'completed', array( self::line( 11, 1, '100' ) ) );
		$this->order( 8, 'processing', array( self::line( 12, 2, '40' ) ) );
		$this->order( 9, 'completed', array( self::line( 13, 1, '30', null ) ) );
		$this->order( 10, 'completed', array( self::line( 14, 1, '20' ) ) );
		$this->sync->sync( 10 );
		$history = $this->rows( Tables::SALES );

		$orders         = new FakeBackfillOrders();
		$orders->orders = array(
			7  => 'completed',
			8  => 'processing',
			9  => 'completed',
			10 => 'completed',
		);
		$queue          = new FakeJobQueue();
		$scheduler      = new SalesBackfillScheduler( $queue, new CountedOrderStatuses() );
		$jobs           = new SalesBackfillJobs( new SalesBackfill( $this->sync, $orders ), $scheduler, $this->service( AuditTrail::class ), $this->logger );
		$drain          = static fn(): int => $queue->drain( static fn( string $hook, array $args ) => $jobs->run( $args[0] ) );

		self::assertSame( SalesBackfillScheduler::STARTED, $scheduler->start( 2, 1 )[0] );
		self::assertSame( 3, $drain() );

		self::assertSame( array( 's:14:1', 's:11:1', 's:12:1' ), array_column( $this->rows( Tables::SALES ), 'event_key' ) );
		self::assertSame( array_slice( $this->rows( Tables::SALES ), 0, 1 ), $history, 'The order with history is unchanged.' );
		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertCount( 1, $audit );
		self::assertSame( 'sales_backfill_completed', $audit[0]['action'] );
		self::assertNull( $audit[0]['actor_user_id'] );
		$context = json_decode( (string) $audit[0]['context'], true );
		self::assertSame( 4, $context['scanned'] );
		self::assertSame( 2, $context['registered'] );
		self::assertSame( 1, $context['skipped_history'] );
		self::assertSame( 1, $context['skipped_no_creator'] );
		self::assertSame( 1, $context['trigger_user_id'] );
		self::assertSame( 2, $context['rows']['sale'] );
		self::assertSame( array(), $context['failures'] );
		self::assertArrayNotHasKey( 'net_amount', $context );
		self::assertSame( array(), $queue->pending, 'No backfill job is left.' );

		$rows = $this->rows( Tables::SALES );
		$scheduler->start( 50, null );
		$drain();

		self::assertSame( $rows, $this->rows( Tables::SALES ), 'Idempotent: a second run writes no ledger row.' );
		$second = json_decode( (string) $this->rows( Tables::AUDIT_LOG )[1]['context'], true );
		self::assertSame( 0, $second['registered'] );
		self::assertSame( 3, $second['skipped_history'] );
	}

	/**
	 * A synchronizer on the test connection with a stepping clock.
	 *
	 * @param int $timeout Lock timeout.
	 */
	private function synchronizer( int $timeout = 5 ): SalesSynchronizer {
		return new SalesSynchronizer(
			$this->repository( $timeout ),
			new SalesPlanner(),
			$this->reader,
			$this->marker,
			$this->logger,
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-30 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Repository on the test connection.
	 *
	 * @param int $timeout Lock timeout.
	 */
	private function repository( int $timeout = 5 ): SalesRepository {
		return new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ), $timeout );
	}

	/**
	 * Sets an order in the test reader.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param int                 $id      Order id.
	 * @param string              $status  Status.
	 * @param OrderLineSnapshot[] $lines   Lines.
	 * @param RefundSnapshot[]    $refunds Refunds.
	 */
	private function order( int $id, string $status, array $lines, array $refunds = array() ): void {
		$this->reader->orders[ $id ] = array(
			'status'   => $status,
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => $refunds,
		);
	}

	/**
	 * A line of product 20.
	 *
	 * @param int      $item     Item id.
	 * @param int      $quantity Quantity.
	 * @param string   $net      Net amount.
	 * @param int|null $creator  Creator.
	 */
	private static function line( int $item, int $quantity, string $net, ?int $creator = 3 ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, 20, 0, 'Line ' . $item, $quantity, SalesAmount::parse( $net ), $creator );
	}

	/**
	 * Cycle events as "cycle:event" in insertion order.
	 *
	 * @return list<string>
	 */
	private function cycles(): array {
		return array_map( static fn( array $row ): string => $row['cycle'] . ':' . $row['event'], $this->rows( Tables::SALES_CYCLES ) );
	}

	/**
	 * A second connection with the test site's prefix.
	 */
	private function other_connection(): wpdb {
		$this->other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->other->set_prefix( $this->wpdb->prefix );

		return $this->other;
	}
}
