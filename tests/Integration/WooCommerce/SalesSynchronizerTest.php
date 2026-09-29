<?php
/**
 * The sales synchronization against a real MySQL/MariaDB.
 *
 * WooCommerce is not part of the suite (ADR-038, Q4): a test reader supplies
 * the order data; the repository, planner, lock and transaction are real.
 * Failures come from the database itself (a trigger, a second connection,
 * KILL). Scenarios with a real WooCommerce are verified in Merch Dashboard
 * Local (ROADMAP trin 8).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use wpdb;

final class SalesSynchronizerTest extends IntegrationTestCase {

	private const ORDER = 7;

	private FakeOrderReader $reader;

	private RecordingFailureMarker $marker;

	private RecordingLogger $logger;

	private SalesSynchronizer $sync;

	private int $clock = 0;

	private ?wpdb $other = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->reader = new FakeOrderReader();
		$this->marker = new RecordingFailureMarker();
		$this->logger = new RecordingLogger();
		$this->sync   = $this->synchronizer();
	}

	protected function tearDown(): void {
		$this->wpdb->query( 'DROP TRIGGER IF EXISTS merchboard_test_fail' );

		if ( null !== $this->other ) {
			$this->other->close();
			$this->other = null;
		}

		parent::tearDown();
	}

	public function test_order_created_directly_in_a_counted_status_is_registered(): void {
		$this->order( self::ORDER, 'processing', array( self::line( 11, 2, '100' ) ) );

		$this->sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( '1:open' ), $this->cycles() );
		self::assertSame( array( 'clear:7' ), $this->marker->calls, 'Success removes a marker.' );
		self::assertSame( array(), $this->logger->at( 'error' ) );
	}

	public function test_status_transitions_close_and_reopen_cycles(): void {
		$lines = array( self::line( 11, 2, '100' ) );
		$this->order( self::ORDER, 'completed', $lines );
		$this->sync->sync( self::ORDER );

		$this->order( self::ORDER, 'cancelled', $lines );
		$this->sync->sync( self::ORDER );
		$this->order( self::ORDER, 'processing', $lines );
		$this->sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1', 'v:11:1', 's:11:2' ), $this->keys() );
		self::assertSame( array( '1:open', '1:close', '2:open' ), $this->cycles() );
	}

	public function test_cycle_1_opens_at_a_zero_balance(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 1, '0' ) ) );

		$this->sync->sync( self::ORDER );

		self::assertSame( array( '0.0000' ), array_column( $this->rows( Tables::SALES ), 'net_amount' ) );
		self::assertSame( array( '1:open' ), $this->cycles() );
	}

	public function test_partial_and_full_refund_then_deletion_of_a_refund_with_a_stale_cache(): void {
		$lines   = array( self::line( 11, 3, '150' ) );
		$partial = self::refund( 100, array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-50' ) ) ) );
		$rest    = self::refund( 101, array( new RefundLineSnapshot( 11, -2, SalesAmount::parse( '-100' ) ) ) );
		$this->order( self::ORDER, 'completed', $lines );
		$this->sync->sync( self::ORDER );

		$this->order( self::ORDER, 'completed', $lines, array( $partial ) );
		$this->sync->sync( self::ORDER );
		// Full refund: WooCommerce moves the order to `refunded` (not counted).
		$this->order( self::ORDER, 'refunded', $lines, array( $partial, $rest ) );
		$this->sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1', 'r:11:100', 'r:11:101' ), $this->keys(), 'No 0/0 reversal.' );
		self::assertSame( array( '1:open', '1:close' ), $this->cycles() );

		// Refund 100 is deleted, but WooCommerce' cache still lists it.
		$this->sync->sync( self::ORDER, 100 );

		self::assertSame( array( 's:11:1', 'r:11:100', 'r:11:101', 'c-rd:11:100', 'c-rdo:11:100' ), $this->keys() );
		self::assertSame( 0, $this->balance(), 'Not counted: the balance stays 0.' );
	}

	public function test_repeated_hooks_write_nothing_new(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );

		$this->sync->sync( self::ORDER );
		$this->sync->sync( self::ORDER );
		$this->sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( 'clear:7', 'clear:7', 'clear:7' ), $this->marker->calls );
	}

	public function test_preflight_skips_orders_without_work_and_the_trash(): void {
		$this->order( 8, 'pending', array( self::line( 11, 1, '10' ) ) );
		$this->order( 9, 'completed', array( self::line( 12, 1, '10', null ) ) );
		$this->order( 10, 'trash', array( self::line( 13, 1, '10' ) ) );

		foreach ( array( 8, 9, 10, 404 ) as $id ) {
			$this->sync->sync( $id );
		}

		self::assertSame( array( 9 => 1 ), $this->reader->reads, 'Pending: no read; counted without creator lines: one preview read, no lock.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );
		self::assertSame( array( 'clear:8', 'clear:9' ), $this->marker->calls, 'Trash and missing orders: no marker (ADR-038, Q8).' );
	}

	public function test_lock_timeout_is_marked_and_logged_with_ids_only(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$other = $this->other_connection();
		$name  = $this->repository()->lock_name( self::ORDER );
		self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );

		$this->synchronizer( 1 )->sync( self::ORDER );

		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertSame( array( 'record:7:lock_timeout' ), $this->marker->calls );
		self::assertSame(
			array(
				array(
					'level'   => 'error',
					'message' => 'Sales ledger synchronization failed.',
					'context' => array(
						'order_id' => self::ORDER,
						'code'     => 'lock_timeout',
					),
				),
			),
			$this->logger->entries
		);
	}

	public function test_connection_change_is_marked_and_the_next_sync_converges(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$other  = $this->other_connection();
		$own_id = (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$this->wpdb->suppress_errors( true );
		// The second read is the one under the lock (the first is the preflight).
		$this->reader->on_read = static function () use ( $other, $own_id ): void {
			static $reads = 0;

			if ( 2 === ++$reads ) {
				$kill = $other->prepare( 'KILL %d', $own_id );
				self::assertIsString( $kill );
				$other->query( $kill );
				usleep( 200000 );
			}
		};

		$this->sync->sync( self::ORDER );

		self::assertSame( array( 'record:7:connection_changed' ), $this->marker->calls );
		self::assertSame( 0, $this->count_rows( Tables::SALES ), 'Nothing written outside the transaction.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ) );

		$this->reader->on_read = null;
		$this->sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1' ), $this->keys() );
		self::assertSame( array( 'record:7:connection_changed', 'clear:7' ), $this->marker->calls );
	}

	public function test_database_error_rolls_back_every_row_and_is_marked(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ), self::line( 12, 1, '40' ) ) );
		$this->execute(
			"CREATE TRIGGER merchboard_test_fail BEFORE INSERT ON %i FOR EACH ROW BEGIN IF NEW.event_key = 's:12:1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END",
			$this->table( Tables::SALES )
		);

		$this->sync->sync( self::ORDER );

		self::assertSame( 0, $this->count_rows( Tables::SALES ), 's:11:1 was rolled back too.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ), 'The cycle event too.' );
		self::assertSame( array( 'record:7:db_error' ), $this->marker->calls );
	}

	public function test_reader_and_planner_failures_are_marked_without_personal_data(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$this->reader->fail = new InvalidArgumentException( 'Unsupported WooCommerce amount.' );
		$this->sync->sync( self::ORDER );
		$this->reader->fail = null;

		$this->sync->sync( self::ORDER );
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ), array(), 'EUR' );
		$this->sync->sync( self::ORDER );
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ), array( self::refund( 100, array( new RefundLineSnapshot( 99, -1, SalesAmount::parse( '-1' ) ) ) ) ) );
		$this->sync->sync( self::ORDER );

		self::assertSame( array( 'record:7:unexpected', 'clear:7', 'record:7:currency_mismatch', 'record:7:unresolved_refund_item' ), $this->marker->calls );
		self::assertSame( array( 's:11:1' ), $this->keys(), 'Failures write nothing.' );

		foreach ( $this->logger->entries as $entry ) {
			self::assertSame( 'Sales ledger synchronization failed.', $entry['message'] );
			self::assertSame( array(), array_diff( array_keys( $entry['context'] ), array( 'order_id', 'code', 'item_id', 'refund_id' ) ), 'Ids and code only.' );
			self::assertStringNotContainsString( 'Line', (string) wp_json_encode( $entry ), 'No line names.' );
		}

		self::assertSame( 100, $this->logger->entries[2]['context']['refund_id'] ?? null );
	}

	public function test_reentrant_call_is_skipped_and_a_nested_other_order_is_marked(): void {
		$this->order( self::ORDER, 'completed', array( self::line( 11, 2, '100' ) ) );
		$this->order( 8, 'completed', array( self::line( 21, 1, '10' ) ) );
		$sync = $this->sync;

		// The marker's meta save triggers a sync of the same order (as HPOS would).
		$this->marker->on_call = static function ( int $order_id ) use ( $sync ): void {
			$sync->sync( $order_id );
		};
		// While order 7's lock is held, a hook fires for order 8 (ADR-038, Q7).
		$this->reader->on_read = static function ( int $order_id ) use ( $sync ): void {
			static $reads = 0;

			if ( self::ORDER === $order_id && 2 === ++$reads ) {
				$sync->sync( 8 );
			}
		};

		$this->sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1' ), $this->keys(), 'Order 7 succeeded; order 8 wrote nothing.' );
		self::assertSame( array( 'record:8:db_error', 'clear:7' ), $this->marker->calls, 'The nested order was refused by the repository and marked; no retry.' );
		self::assertSame( 2, $this->reader->reads[ self::ORDER ], 'The marker-triggered sync of order 7 was skipped.' );
	}

	public function test_deletion_closes_from_the_ledger_and_never_reopens_in_the_same_request(): void {
		$lines  = array( self::line( 11, 2, '100' ) );
		$refund = array( self::refund( 100, array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-30' ) ) ) ) );
		$this->order( self::ORDER, 'completed', $lines, $refund );
		$this->sync->sync( self::ORDER );
		$reads = $this->reader->reads[ self::ORDER ];

		$this->sync->order_deleting( self::ORDER );
		// WooCommerce deletes the order's refunds while the order still reads as counted.
		$this->sync->sync( self::ORDER, 100 );
		$this->sync->order_deleting( self::ORDER );

		self::assertSame( array( 's:11:1', 'r:11:100', 'v:11:1' ), $this->keys() );
		self::assertSame( array( '1:open', '1:close' ), $this->cycles(), 'No cycle reopened.' );
		self::assertSame( 0, $this->balance() );
		self::assertSame( $reads, $this->reader->reads[ self::ORDER ], 'WooCommerce data is not read during deletion.' );
		self::assertSame( array( 'clear:7' ), $this->marker->calls, 'No marker for an order being deleted.' );

		$this->sync->order_deleting( 404 );
		self::assertSame( array( '1:open', '1:close' ), $this->cycles(), 'An order without a cycle: nothing to close.' );
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
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-29 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
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
	 * @param int                 $id       Order id.
	 * @param string              $status   Status.
	 * @param OrderLineSnapshot[] $lines    Lines.
	 * @param RefundSnapshot[]    $refunds  Refunds.
	 * @param string              $currency Currency.
	 */
	private function order( int $id, string $status, array $lines, array $refunds = array(), string $currency = 'DKK' ): void {
		$this->reader->orders[ $id ] = array(
			'status'   => $status,
			'currency' => $currency,
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
	 * A refund created 2026-09-02.
	 *
	 * @phpstan-param list<RefundLineSnapshot> $lines
	 *
	 * @param int                  $id    Refund id.
	 * @param RefundLineSnapshot[] $lines Lines.
	 */
	private static function refund( int $id, array $lines ): RefundSnapshot {
		return new RefundSnapshot( $id, new DateTimeImmutable( '2026-09-02 09:00:00', new DateTimeZone( 'UTC' ) ), $lines );
	}

	/**
	 * Event keys in insertion order.
	 *
	 * @return list<string|null>
	 */
	private function keys(): array {
		return array_column( $this->rows( Tables::SALES ), 'event_key' );
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
	 * Ledger balance of the order in 1/10000 units.
	 */
	private function balance(): int {
		return array_sum( array_map( static fn( array $row ): int => SalesAmount::parse( (string) $row['net_amount'] ), $this->rows( Tables::SALES ) ) );
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
