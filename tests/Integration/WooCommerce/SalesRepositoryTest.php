<?php
/**
 * Sales ledger storage, lock and transaction on a real MySQL/MariaDB.
 *
 * Failures and races are produced by the database itself (a trigger that
 * SIGNALs an error, a second connection, KILL, several processes), not by
 * test doubles.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesCorrectionReason;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleEvent;
use MerchBoard\Modules\WooCommerce\Sales\SalesConnectionChangedException;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleEventType;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntryType;
use MerchBoard\Modules\WooCommerce\Sales\SalesFailureCode;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerState;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerWrite;
use MerchBoard\Modules\WooCommerce\Sales\SalesLockTimeoutException;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Modules\WooCommerce\Sales\SalesWriteResult;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use RuntimeException;
use Throwable;
use wpdb;

final class SalesRepositoryTest extends IntegrationTestCase {

	private const ORDER = 7;

	/**
	 * Start of the ledger's row SELECT, as SalesRepository::entries_for_*() prepare it (ENTRY_COLUMNS).
	 */
	private const LEDGER_SELECT = 'SELECT id, event_key, creator_id, order_id, order_item_id, cycle, refund_id, product_id, variation_id, item_name, type, correction_reason, quantity, net_amount, currency, occurred_at, created_at FROM ';

	/**
	 * Start of the cycle SELECT, as SalesRepository::cycle_state() prepares it.
	 */
	private const CYCLES_SELECT = 'SELECT id, order_id, cycle, event, occurred_at, created_at FROM ';

	/**
	 * Start of the tax SELECT, as SalesRepository::taxes_for_order() prepares it.
	 */
	private const TAX_SELECT = 'SELECT event_key, tax_amount FROM ';

	/**
	 * Second database connection, when a test opened one.
	 *
	 * @var wpdb|null
	 */
	private ?wpdb $other = null;

	/**
	 * The `query` filter of fail_ledger_reads(), removed in tearDown().
	 *
	 * @var Closure|null
	 */
	private ?Closure $query_filter = null;

	/**
	 * Every statement while fail_ledger_reads() is active.
	 *
	 * @var list<string>
	 */
	private array $statements = array();

	/**
	 * Ledger SELECTs that fail_ledger_reads() made fail.
	 *
	 * @var int
	 */
	private int $failed_reads = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
	}

	protected function tearDown(): void {
		if ( null !== $this->query_filter ) {
			remove_filter( 'query', $this->query_filter );
			$this->query_filter = null;
		}

		$this->wpdb->query( 'DROP TRIGGER IF EXISTS merchboard_test_fail' );

		if ( null !== $this->other ) {
			$this->other->close();
			$this->other = null;
		}

		parent::tearDown();
	}

	public function test_event_key_is_unique_in_the_database(): void {
		$row = array(
			'event_key'     => 's:10:1',
			'creator_id'    => 3,
			'order_id'      => self::ORDER,
			'order_item_id' => 10,
			'cycle'         => 1,
			'product_id'    => 20,
			'type'          => 'sale',
			'quantity'      => 1,
			'net_amount'    => '10',
			'currency'      => 'DKK',
			'occurred_at'   => '2026-09-28 10:00:00',
			'created_at'    => '2026-09-28 10:00:00',
		);
		$this->wpdb->suppress_errors( true );

		self::assertSame( 1, $this->wpdb->insert( $this->table( Tables::SALES ), $row ) );
		self::assertFalse( $this->wpdb->insert( $this->table( Tables::SALES ), array_merge( $row, array( 'order_id' => 8 ) ) ), 'Same key, even for another order.' );
		self::assertSame( 1, $this->count_rows( Tables::SALES ) );
	}

	public function test_order_cycle_event_is_unique_in_the_database(): void {
		$row = array(
			'order_id'    => self::ORDER,
			'cycle'       => 1,
			'event'       => 'open',
			'occurred_at' => '2026-09-28 10:00:00',
			'created_at'  => '2026-09-28 10:00:00',
		);
		$this->wpdb->suppress_errors( true );

		self::assertSame( 1, $this->wpdb->insert( $this->table( Tables::SALES_CYCLES ), $row ) );
		self::assertFalse( $this->wpdb->insert( $this->table( Tables::SALES_CYCLES ), $row ) );
		self::assertSame( 1, $this->wpdb->insert( $this->table( Tables::SALES_CYCLES ), array_merge( $row, array( 'event' => 'close' ) ) ) );
		self::assertSame( 1, $this->wpdb->insert( $this->table( Tables::SALES_CYCLES ), array_merge( $row, array( 'cycle' => 2 ) ) ) );
		self::assertSame( 3, $this->count_rows( Tables::SALES_CYCLES ) );
	}

	public function test_all_columns_round_trip(): void {
		$occurred = new DateTimeImmutable( '2026-09-28 12:30:15', new DateTimeZone( 'Europe/Copenhagen' ) );
		$entry    = $this->entry(
			'c-rd:10:100',
			array(
				'type'         => SalesEntryType::Correction,
				'reason'       => SalesCorrectionReason::RefundDeleted,
				'refund_id'    => 100,
				'variation_id' => 21,
				'item_name'    => 'Hættetrøje – sort 🎸',
				'quantity'     => 1,
				'net_amount'   => '123456789012345.1234',
				'occurred_at'  => $occurred,
			)
		);

		$result = $this->write( self::ORDER, array( $entry ) );

		self::assertSame( array( 'c-rd:10:100' ), $result->inserted_entries );
		$stored = $this->repository()->entries_for_order( self::ORDER )[0];
		self::assertNotNull( $stored->id );
		self::assertNotNull( $stored->created_at );
		self::assertSame( '2026-09-28 10:30:15', $stored->occurred_at->format( 'Y-m-d H:i:s' ), 'Stored as UTC.' );
		self::assertSame( 'UTC', $stored->occurred_at->getTimezone()->getName() );
		self::assertSame( 'Hættetrøje – sort 🎸', $stored->item_name );
		self::assertSame( '123456789012345.1234', $stored->net_amount, 'DECIMAL keeps every digit.' );
		self::assertSame( SalesCorrectionReason::RefundDeleted, $stored->correction_reason );
		self::assertSame( array( 3, self::ORDER, 10, 1, 100, 20, 21, 1, 'DKK' ), array( $stored->creator_id, $stored->order_id, $stored->order_item_id, $stored->cycle, $stored->refund_id, $stored->product_id, $stored->variation_id, $stored->quantity, $stored->currency ) );
		self::assertSame( 'refund_deleted', $this->rows( Tables::SALES )[0]['correction_reason'] );

		$this->write( self::ORDER, array( $this->entry( 's:11:1' ) ) );
		self::assertNull( $this->rows( Tables::SALES )[1]['correction_reason'], 'No reason for a sale.' );
	}

	public function test_repeated_event_key_is_a_recorded_no_op(): void {
		$this->write( self::ORDER, array( $this->entry( 's:10:1', array( 'net_amount' => '100' ) ) ) );
		$before = $this->rows( Tables::SALES );

		$result = $this->write( self::ORDER, array( $this->entry( 's:10:1', array( 'net_amount' => '999' ) ), $this->entry( 's:11:1' ) ) );

		self::assertSame( array( 's:11:1' ), $result->inserted_entries );
		self::assertSame( array( 's:10:1' ), $result->skipped_entries );
		self::assertSame( $before[0], $this->rows( Tables::SALES )[0], 'The stored row is never changed.' );
		self::assertSame( 2, $this->count_rows( Tables::SALES ) );
		self::assertTrue( $this->repository()->event_exists( 's:10:1' ) );
		self::assertFalse( $this->repository()->event_exists( 's:10:2' ) );
	}

	public function test_one_order_item_in_several_cycles(): void {
		$result = $this->write(
			self::ORDER,
			array(
				$this->entry( 's:10:1', array( 'net_amount' => '100' ) ),
				$this->entry(
					'v:10:1',
					array(
						'type'       => SalesEntryType::Reversal,
						'quantity'   => -2,
						'net_amount' => '-100',
					)
				),
				$this->entry(
					's:10:2',
					array(
						'cycle'      => 2,
						'net_amount' => '100',
					)
				),
			),
			array( $this->cycle( 1, SalesCycleEventType::Open ), $this->cycle( 1, SalesCycleEventType::Close ), $this->cycle( 2, SalesCycleEventType::Open ) )
		);

		self::assertSame( 3, $result->inserted_cycle_events );
		self::assertSame(
			array( 's:10:1', 'v:10:1', 's:10:2' ),
			array_map( static fn( SalesEntry $e ): string => $e->event_key, $this->repository()->entries_for_order_item( self::ORDER, 10 ) )
		);

		$cycles = $this->repository()->cycle_state( self::ORDER );
		self::assertSame( 2, $cycles->active_cycle() );
		self::assertTrue( $cycles->is_closed( 1 ) );
		self::assertSame( 3, $cycles->next_cycle() );
	}

	public function test_several_refunds_and_their_corrections_of_one_line(): void {
		$refund = array(
			'type'       => SalesEntryType::Refund,
			'refund_id'  => 100,
			'quantity'   => -1,
			'net_amount' => '-30',
		);

		$result = $this->write(
			self::ORDER,
			array(
				$this->entry( 's:10:1' ),
				$this->entry( 'r:10:100', $refund ),
				$this->entry( 'r:10:101', array_merge( $refund, array( 'refund_id' => 101 ) ) ),
				$this->entry( 'c-ro:10:100', $this->correction( SalesCorrectionReason::ClosedCycleRefund, 100, '30' ) ),
				$this->entry( 'c-rd:10:100', $this->correction( SalesCorrectionReason::RefundDeleted, 100, '30' ) ),
				$this->entry( 'c-rdo:10:100', $this->correction( SalesCorrectionReason::ClosedCycleRefundDeleted, 100, '-30' ) ),
				$this->entry( 'c-l:10:1:1', $this->correction( SalesCorrectionReason::LineChanged, 0, '-5' ) ),
				$this->entry( 'c-l:10:1:2', $this->correction( SalesCorrectionReason::LineRemoved, 0, '-65' ) ),
			)
		);

		self::assertCount( 8, $result->inserted_entries, 'Same order line and refund, no conflict.' );
		self::assertSame( array(), $result->skipped_entries );
		self::assertSame(
			array( 'r:10:100', 'c-ro:10:100', 'c-rd:10:100', 'c-rdo:10:100' ),
			array_map( static fn( SalesEntry $e ): string => $e->event_key, $this->repository()->entries_for_refund( 100 ) )
		);
		self::assertSame( array( 'r:10:101' ), array_map( static fn( SalesEntry $e ): string => $e->event_key, $this->repository()->entries_for_refund( 101 ) ) );
	}

	public function test_lookups_by_order_item_and_refund_ignore_other_orders(): void {
		$refund = array(
			'type'       => SalesEntryType::Refund,
			'refund_id'  => 100,
			'quantity'   => -1,
			'net_amount' => '-30',
		);
		$this->write( self::ORDER, array( $this->entry( 's:10:1' ), $this->entry( 'r:10:100', $refund ) ), array( $this->cycle( 1, SalesCycleEventType::Open ) ) );
		$this->write(
			8,
			array(
				$this->entry( 's:30:1', array( 'order_id' => 8, 'order_item_id' => 30 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				$this->entry( 'r:30:200', array_merge( $refund, array( 'order_id' => 8, 'order_item_id' => 30, 'refund_id' => 200 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			)
		);
		$repository = $this->repository();

		self::assertSame( array( 's:10:1', 'r:10:100' ), array_map( static fn( SalesEntry $e ): string => $e->event_key, $repository->entries_for_order( self::ORDER ) ) );
		self::assertSame( array( 's:30:1', 'r:30:200' ), array_map( static fn( SalesEntry $e ): string => $e->event_key, $repository->entries_for_order( 8 ) ) );
		self::assertSame( array(), $repository->entries_for_order_item( 8, 10 ), 'The order line of another order.' );
		self::assertSame( 8, $repository->entries_for_refund( 200 )[0]->order_id, 'The order of a refund, from the ledger alone.' );
		self::assertSame( array(), $repository->entries_for_refund( 999 ) );
		self::assertSame( 0, $repository->cycle_state( 8 )->latest_cycle() );
		self::assertSame( 1, $repository->cycle_state( self::ORDER )->active_cycle() );

		$state = $repository->state( self::ORDER );
		self::assertCount( 2, $state->entries );
		self::assertTrue( $state->has_event( 'r:10:100' ) );
	}

	public function test_open_and_close_events_are_idempotent(): void {
		$this->write( self::ORDER, array(), array( $this->cycle( 1, SalesCycleEventType::Open ) ) );

		$result = $this->write( self::ORDER, array(), array( $this->cycle( 1, SalesCycleEventType::Open ), $this->cycle( 1, SalesCycleEventType::Close ) ) );

		self::assertSame( 1, $result->inserted_cycle_events );
		self::assertSame( 1, $result->skipped_cycle_events );
		self::assertSame( array( 'open', 'close' ), array_column( $this->rows( Tables::SALES_CYCLES ), 'event' ) );
		self::assertNull( $this->repository()->cycle_state( self::ORDER )->active_cycle() );
	}

	public function test_planner_gets_the_state_and_an_empty_plan_writes_nothing(): void {
		$this->write( self::ORDER, array( $this->entry( 's:10:1' ) ), array( $this->cycle( 1, SalesCycleEventType::Open ) ) );
		$repository = $this->repository();
		$seen       = null;

		$result = $repository->with_order_lock(
			self::ORDER,
			static function () use ( $repository, &$seen ): SalesWriteResult {
				return $repository->write(
					self::ORDER,
					static function ( SalesLedgerState $state ) use ( &$seen ): SalesLedgerWrite {
						$seen = $state;
						return new SalesLedgerWrite();
					}
				);
			}
		);

		self::assertInstanceOf( SalesLedgerState::class, $seen );
		self::assertSame( 1, $seen->cycles->active_cycle() );
		self::assertTrue( $seen->has_event( 's:10:1' ) );
		self::assertEquals( new SalesWriteResult( array(), array(), 0, 0 ), $result );
	}

	public function test_failed_insert_rolls_back_every_row_of_the_write(): void {
		$this->write( self::ORDER, array( $this->entry( 's:9:1' ) ), array( $this->cycle( 1, SalesCycleEventType::Open ) ) );
		$before_sales  = $this->rows( Tables::SALES );
		$before_cycles = $this->rows( Tables::SALES_CYCLES );
		$this->execute(
			"CREATE TRIGGER merchboard_test_fail BEFORE INSERT ON %i FOR EACH ROW BEGIN IF NEW.event_key = 's:12:1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END",
			$this->table( Tables::SALES )
		);

		try {
			$this->write(
				self::ORDER,
				array( $this->entry( 's:10:1' ), $this->entry( 's:11:1' ), $this->entry( 's:12:1' ) ),
				array( $this->cycle( 1, SalesCycleEventType::Close ), $this->cycle( 2, SalesCycleEventType::Open ) )
			);
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not store the sales entry.', $e->getMessage(), 'A real error is not reported as a duplicate.' );
		}

		self::assertSame( $before_sales, $this->rows( Tables::SALES ), 'Neither s:10:1 nor s:11:1 survived.' );
		self::assertSame( $before_cycles, $this->rows( Tables::SALES_CYCLES ), 'No cycle event survived.' );
	}

	public function test_a_failed_ledger_read_is_a_database_error_not_an_empty_history(): void {
		$refund = array(
			'type'       => SalesEntryType::Refund,
			'refund_id'  => 100,
			'quantity'   => -1,
			'net_amount' => '-30',
		);
		$this->write( self::ORDER, array( $this->entry( 's:10:1' ), $this->entry( 'r:10:100', $refund ) ) );
		$before     = $this->rows( Tables::SALES );
		$repository = $this->repository();
		$this->fail_ledger_reads();

		foreach ( array(
			'order'      => static fn() => $repository->entries_for_order( self::ORDER ),
			'order item' => static fn() => $repository->entries_for_order_item( self::ORDER, 10 ),
			'refund'     => static fn() => $repository->entries_for_refund( 100 ),
		) as $case => $read ) {
			try {
				$read();
				self::fail( 'Expected DatabaseException: ' . $case );
			} catch ( DatabaseException $e ) {
				self::assertSame( 'Could not read the sales ledger.', $e->getMessage(), $case );
			}
		}

		self::assertSame( 3, $this->failed_reads, 'Exactly the three ledger SELECTs failed.' );
		self::assertSame( $before, $this->rows( Tables::SALES ), 'Nothing changed.' );
	}

	public function test_a_failed_ledger_read_in_the_synchronization_is_db_error_and_rolls_back(): void {
		$reader = new FakeOrderReader();
		$marker = new RecordingFailureMarker();
		$logger = new RecordingLogger();
		$sync   = new SalesSynchronizer( $this->repository(), new SalesPlanner(), $reader, $marker, $logger, static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-29 12:00:00', new DateTimeZone( 'UTC' ) ) );

		$reader->orders[ self::ORDER ] = array(
			'status'   => 'completed',
			'currency' => 'DKK',
			'lines'    => array( self::line( 11 ) ),
			'refunds'  => array(),
		);
		$sync->sync( self::ORDER );
		self::assertSame( array( 's:11:1' ), array_column( $this->rows( Tables::SALES ), 'event_key' ) );

		// A new line: the next synchronization would write s:12:1.
		$reader->orders[ self::ORDER ]['lines'][] = self::line( 12 );
		$before                                   = array( $this->rows( Tables::SALES ), $this->rows( Tables::SALES_CYCLES ), $this->count_rows( Tables::SALES_TAX ) );
		$this->fail_ledger_reads();

		$sync->sync( self::ORDER );

		// R0 ran first inside the transaction; the ledger read right after it failed.
		$start = array_search( 'START TRANSACTION', $this->statements, true );
		self::assertIsInt( $start );
		self::assertStringContainsString( "option_name = '" . Migrator::VERSION_OPTION . "' LOCK IN SHARE MODE", $this->statements[ $start + 1 ], 'R0: the version row.' );
		self::assertStringContainsString( '`' . $this->table( Tables::G1_STATE ) . '`', $this->statements[ $start + 2 ], 'R0: the activation row.' );
		self::assertStringEndsWith( 'LOCK IN SHARE MODE', $this->statements[ $start + 2 ] );
		self::assertStringStartsWith( self::LEDGER_SELECT . '`' . $this->table( Tables::SALES ) . '`', $this->statements[ $start + 3 ], 'Then the ledger read.' );
		self::assertSame( 1, $this->failed_reads );
		self::assertContains( 'ROLLBACK', array_slice( $this->statements, $start + 4 ) );
		self::assertNotContains( 'COMMIT', array_slice( $this->statements, $start + 4 ) );

		self::assertSame( $before, array( $this->rows( Tables::SALES ), $this->rows( Tables::SALES_CYCLES ), $this->count_rows( Tables::SALES_TAX ) ), 'Rolled back: no new row.' );
		self::assertSame( array( 'clear:7', 'record:7:db_error' ), $marker->calls );
		self::assertSame(
			array(
				array(
					'order_id' => self::ORDER,
					'code'     => 'db_error',
				),
			),
			array_column( $logger->at( 'error' ), 'context' )
		);

		// Readable again: the next synchronization writes the new line.
		self::assertNotNull( $this->query_filter );
		remove_filter( 'query', $this->query_filter );
		$this->query_filter = null;
		$sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1', 's:12:1' ), array_column( $this->rows( Tables::SALES ), 'event_key' ) );
		self::assertSame( array( 'clear:7', 'record:7:db_error', 'clear:7' ), $marker->calls );
	}

	public function test_failed_cycle_and_tax_reads_are_database_errors_and_empty_reads_still_work(): void {
		$reader = new FakeOrderReader();
		self::order( $reader, 'completed', array( self::line( 11, '25' ) ) );
		$this->synchronizer( $reader, new RecordingFailureMarker(), new RecordingLogger() )->sync( self::ORDER );
		self::assertSame( 1, $this->count_rows( Tables::SALES_CYCLES ) );
		self::assertSame( 1, $this->count_rows( Tables::SALES_TAX ) );
		$repository = $this->repository();
		$before     = $this->ledger_tables();

		foreach ( array(
			'cycles' => array( self::CYCLES_SELECT . '`' . $this->table( Tables::SALES_CYCLES ) . '`', static fn() => $repository->cycle_state( self::ORDER ), 'Could not read the sales cycles.' ),
			'tax'    => array( self::TAX_SELECT . '`' . $this->table( Tables::SALES_TAX ) . '`', static fn() => $repository->taxes_for_order( self::ORDER ), 'Could not read the sales tax.' ),
		) as $case => list( $select, $read, $message ) ) {
			$this->fail_reads( $select );

			try {
				$read();
				self::fail( 'Expected DatabaseException: ' . $case );
			} catch ( DatabaseException $e ) {
				self::assertSame( $message, $e->getMessage(), $case );
			}

			self::assertSame( 1, $this->failed_reads, $case );
			$this->allow_reads();
		}

		self::assertSame( $before, $this->ledger_tables(), 'Nothing changed.' );

		// Readable again; an order without rows is an empty state, not an error.
		self::assertSame( 1, $repository->cycle_state( self::ORDER )->active_cycle() );
		self::assertSame( array( 's:11:1' => 250000 ), $repository->taxes_for_order( self::ORDER ) );
		self::assertSame( 0, $repository->cycle_state( 8 )->latest_cycle() );
		self::assertSame( array(), $repository->taxes_for_order( 8 ) );
	}

	public function test_a_failed_cycle_read_before_the_lock_is_db_error_not_a_new_order(): void {
		$reader = new FakeOrderReader();
		$marker = new RecordingFailureMarker();
		$logger = new RecordingLogger();
		$sync   = $this->synchronizer( $reader, $marker, $logger );
		self::order( $reader, 'completed', array( self::line( 11 ) ) );
		$sync->sync( self::ORDER );

		// Cancelled: the synchronization would close cycle 1.
		self::order( $reader, 'cancelled', array( self::line( 11 ) ) );
		$before = $this->ledger_tables();
		$this->fail_reads( self::CYCLES_SELECT . '`' . $this->table( Tables::SALES_CYCLES ) . '`' );

		$sync->sync( self::ORDER );

		self::assertSame( 1, $this->failed_reads, 'Only the preflight read ran.' );
		self::assertNotContains( 'START TRANSACTION', $this->statements, 'Stopped before the lock and the transaction.' );
		self::assertSame( $before, $this->ledger_tables() );
		self::assertSame( array( 'clear:7', 'record:7:db_error' ), $marker->calls, 'Recorded, never cleared as a success.' );
		self::assertSame(
			array(
				array(
					'order_id' => self::ORDER,
					'code'     => 'db_error',
				),
			),
			array_column( $logger->at( 'error' ), 'context' )
		);

		$this->allow_reads();
		$sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1', 'v:11:1' ), array_column( $this->rows( Tables::SALES ), 'event_key' ) );
		self::assertSame( array( '1:open', '1:close' ), $this->cycle_events() );
		self::assertSame( array( 'clear:7', 'record:7:db_error', 'clear:7' ), $marker->calls );
	}

	public function test_a_failed_cycle_read_in_the_transaction_is_db_error_and_rolls_back(): void {
		$reader = new FakeOrderReader();
		$marker = new RecordingFailureMarker();
		$logger = new RecordingLogger();
		$sync   = $this->synchronizer( $reader, $marker, $logger );
		self::order( $reader, 'completed', array( self::line( 11 ) ) );
		$sync->sync( self::ORDER );

		// A new line: the next synchronization would write s:12:1.
		self::order( $reader, 'completed', array( self::line( 11 ), self::line( 12 ) ) );
		$before = $this->ledger_tables();
		$this->fail_reads( self::CYCLES_SELECT . '`' . $this->table( Tables::SALES_CYCLES ) . '`', true );

		$sync->sync( self::ORDER );

		$start = $this->start_after_r0();
		self::assertStringStartsWith( self::LEDGER_SELECT, $this->statements[ $start + 3 ], 'state(): the rows first.' );
		self::assertStringStartsWith( self::CYCLES_SELECT, $this->statements[ $start + 4 ], 'Then the cycles, which fail.' );
		self::assertSame( 1, $this->failed_reads );
		self::assertContains( 'ROLLBACK', array_slice( $this->statements, $start + 5 ) );
		self::assertNotContains( 'COMMIT', array_slice( $this->statements, $start + 5 ) );
		self::assertSame( $before, $this->ledger_tables(), 'Rolled back: no new row.' );
		self::assertSame( array( 'clear:7', 'record:7:db_error' ), $marker->calls );
		self::assertSame( array( 'db_error' ), array_column( array_column( $logger->at( 'error' ), 'context' ), 'code' ) );

		$this->allow_reads();
		$sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1', 's:12:1' ), array_column( $this->rows( Tables::SALES ), 'event_key' ) );
	}

	public function test_a_failed_tax_read_in_the_transaction_writes_no_row_without_its_tax(): void {
		$reader = new FakeOrderReader();
		$marker = new RecordingFailureMarker();
		$logger = new RecordingLogger();
		$sync   = $this->synchronizer( $reader, $marker, $logger );
		self::order( $reader, 'completed', array( self::line( 11, '25' ) ) );
		$sync->sync( self::ORDER );

		// Cancelled: the reversal's tax comes from the stored tax.
		self::order( $reader, 'cancelled', array( self::line( 11, '25' ) ) );
		$before = $this->ledger_tables();
		$this->fail_reads( self::TAX_SELECT . '`' . $this->table( Tables::SALES_TAX ) . '`', true );

		$sync->sync( self::ORDER );

		$start = $this->start_after_r0();
		self::assertStringStartsWith( self::LEDGER_SELECT, $this->statements[ $start + 3 ] );
		self::assertStringStartsWith( self::CYCLES_SELECT, $this->statements[ $start + 4 ] );
		self::assertStringStartsWith( self::TAX_SELECT, $this->statements[ $start + 5 ], 'Then the tax, which fails.' );
		self::assertSame( 1, $this->failed_reads );
		self::assertContains( 'ROLLBACK', array_slice( $this->statements, $start + 6 ) );
		self::assertNotContains( 'COMMIT', array_slice( $this->statements, $start + 6 ) );
		self::assertSame( $before, $this->ledger_tables(), 'Rolled back: no reversal, no cycle event, no tax row.' );
		self::assertSame( array( 'clear:7', 'record:7:db_error' ), $marker->calls );
		self::assertSame( array( 'db_error' ), array_column( array_column( $logger->at( 'error' ), 'context' ), 'code' ) );

		$this->allow_reads();
		$sync->sync( self::ORDER );

		self::assertSame( array( 's:11:1', 'v:11:1' ), array_column( $this->rows( Tables::SALES ), 'event_key' ) );
		self::assertSame(
			array(
				's:11:1' => 250000,
				'v:11:1' => -250000,
			),
			$this->repository()->taxes_for_order( self::ORDER ),
			'The reversal has its tax.'
		);
	}

	public function test_a_failed_cycle_read_when_the_order_is_deleted_is_logged_without_a_marker(): void {
		$reader = new FakeOrderReader();
		$marker = new RecordingFailureMarker();
		$logger = new RecordingLogger();
		$sync   = $this->synchronizer( $reader, $marker, $logger );
		self::order( $reader, 'completed', array( self::line( 11 ) ) );
		$sync->sync( self::ORDER );
		$before = $this->ledger_tables();
		$this->fail_reads( self::CYCLES_SELECT . '`' . $this->table( Tables::SALES_CYCLES ) . '`' );

		$sync->order_deleting( self::ORDER );

		self::assertSame( 1, $this->failed_reads );
		self::assertNotContains( 'START TRANSACTION', $this->statements );
		self::assertSame( $before, $this->ledger_tables() );
		self::assertSame( array( 'clear:7' ), $marker->calls, 'No marker for an order being deleted.' );
		self::assertSame(
			array(
				array(
					'order_id' => self::ORDER,
					'code'     => 'db_error',
				),
			),
			array_column( $logger->at( 'error' ), 'context' )
		);

		$this->allow_reads();
		$sync->order_deleting( self::ORDER );

		self::assertSame( array( 's:11:1', 'v:11:1' ), array_column( $this->rows( Tables::SALES ), 'event_key' ) );
		self::assertSame( array( '1:open', '1:close' ), $this->cycle_events() );
	}

	public function test_a_failed_cycle_read_in_backfill_and_preview_is_db_error(): void {
		$reader = new FakeOrderReader();
		$marker = new RecordingFailureMarker();
		$logger = new RecordingLogger();
		$sync   = $this->synchronizer( $reader, $marker, $logger );
		self::order( $reader, 'completed', array( self::line( 11 ) ) );
		$sync->sync( self::ORDER );
		$before = $this->ledger_tables();
		$this->fail_reads( self::CYCLES_SELECT . '`' . $this->table( Tables::SALES_CYCLES ) . '`' );

		$preview = $sync->preview( self::ORDER );

		self::assertSame( SalesBackfillStatus::Failed, $preview->status );
		self::assertSame( SalesFailureCode::DbError, $preview->failure );
		self::assertSame( array( 'clear:7' ), $marker->calls, 'The dry run records nothing.' );
		self::assertSame( array(), $logger->at( 'error' ) );

		$backfill = $sync->backfill( self::ORDER );

		self::assertSame( SalesBackfillStatus::Failed, $backfill->status );
		self::assertSame( SalesFailureCode::DbError, $backfill->failure );
		self::assertNotContains( 'START TRANSACTION', $this->statements );
		self::assertSame( $before, $this->ledger_tables() );
		self::assertSame( array( 'clear:7', 'record:7:db_error' ), $marker->calls );
		self::assertSame( array( 'db_error' ), array_column( array_column( $logger->at( 'error' ), 'context' ), 'code' ) );

		$this->allow_reads();

		self::assertSame( SalesBackfillStatus::HasHistory, $sync->backfill( self::ORDER )->status, 'Readable again: the order has history.' );
	}

	public function test_planner_exception_and_row_of_another_order_roll_back(): void {
		$repository = $this->repository();

		try {
			$repository->with_order_lock(
				self::ORDER,
				fn() => $repository->write(
					self::ORDER,
					fn(): SalesLedgerWrite => new SalesLedgerWrite( array( $this->entry( 's:10:1' ), $this->entry( 's:30:1', array( 'order_id' => 8 ) ) ) )
				)
			);
			self::fail( 'Expected InvalidArgumentException.' );
		} catch ( Throwable $e ) {
			// Thrown by the planner's write, passed on through the lock.
			self::assertInstanceOf( InvalidArgumentException::class, $e );
			self::assertSame( 'A planned sales ledger row belongs to another order.', $e->getMessage() );
		}

		try {
			$repository->with_order_lock(
				self::ORDER,
				fn() => $repository->write(
					self::ORDER,
					static function (): SalesLedgerWrite {
						throw new RuntimeException( 'Planner failed.' );
					}
				)
			);
			self::fail( 'Expected RuntimeException.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'Planner failed.', $e->getMessage() );
		}

		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertFalse( $this->lock_is_used( self::ORDER ), 'Released after both failures.' );
	}

	public function test_writes_require_the_lock_of_the_same_order(): void {
		$repository = $this->repository();
		$plan       = static fn(): SalesLedgerWrite => new SalesLedgerWrite();

		foreach ( array(
			'write without lock'  => static fn() => $repository->write( self::ORDER, $plan ),
			'write, other order'  => static fn() => $repository->with_order_lock( 8, static fn() => $repository->write( self::ORDER, $plan ) ),
			'insert without lock' => fn() => $repository->insert_entry( $this->entry( 's:10:1' ) ),
			'cycle without lock'  => fn() => $repository->insert_cycle_event( $this->cycle( 1, SalesCycleEventType::Open ) ),
			'nested lock'         => static fn() => $repository->with_order_lock( self::ORDER, static fn() => $repository->with_order_lock( 8, static fn() => null ) ),
		) as $case => $call ) {
			try {
				$call();
				self::fail( 'Expected DatabaseException: ' . $case );
			} catch ( DatabaseException $e ) {
				self::assertStringContainsString( 'lock', $e->getMessage(), $case );
			}
		}

		self::assertSame( 0, $this->count_rows( Tables::SALES ) );
		self::assertFalse( $this->lock_is_used( self::ORDER ) );
	}

	public function test_lock_is_held_during_the_work_and_released_afterwards(): void {
		$repository = $this->repository();
		$other      = $this->other_connection();
		$name       = $repository->lock_name( self::ORDER );
		$own_id     = (string) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );

		$holder = $repository->with_order_lock( self::ORDER, fn(): string => (string) $other->get_var( $other->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) ) );

		self::assertSame( $own_id, $holder );
		self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) ), 'Released.' );

		try {
			$repository->with_order_lock(
				self::ORDER,
				static function (): void {
					throw new RuntimeException( 'Work failed.' );
				}
			);
		} catch ( RuntimeException ) {
			self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) ), 'Released in finally.' );
		}
	}

	public function test_lock_held_by_another_connection_times_out_without_running_the_work(): void {
		$repository = $this->repository( 1 );
		$other      = $this->other_connection();
		$name       = $repository->lock_name( self::ORDER );
		$ran        = false;

		self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );

		$start = microtime( true );
		try {
			$repository->with_order_lock(
				self::ORDER,
				static function () use ( &$ran ): void {
					$ran = true;
				}
			);
			self::fail( 'Expected SalesLockTimeoutException.' );
		} catch ( SalesLockTimeoutException ) {
			self::assertGreaterThanOrEqual( 0.9, microtime( true ) - $start, 'Waited for the timeout.' );
		}

		self::assertFalse( $ran );
		self::assertSame( 'ok', $this->repository( 0 )->with_order_lock( 8, static fn(): string => 'ok' ), 'Other orders are not blocked.' );

		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		self::assertSame( 'ok', $repository->with_order_lock( self::ORDER, static fn(): string => 'ok' ), 'Free again.' );
	}

	public function test_lock_names_are_deterministic_short_and_site_specific(): void {
		$repository = $this->repository();
		$other      = $this->other_connection();
		$other->set_prefix( 'other_' );

		self::assertSame( $repository->lock_name( 7 ), $this->repository()->lock_name( 7 ) );
		self::assertNotSame( $repository->lock_name( 7 ), $repository->lock_name( 8 ) );
		self::assertLessThanOrEqual( 64, strlen( $repository->lock_name( PHP_INT_MAX ) ) );
		self::assertMatchesRegularExpression( '/^merchboard_sales:[0-9a-f]{12}:7$/', $repository->lock_name( 7 ) );
		self::assertNotSame( $repository->lock_name( 7 ), ( new SalesRepository( $other, new Transaction( $other, new TransactionSupport( $other ) ) ) )->lock_name( 7 ), 'Another table prefix.' );
	}

	public function test_lost_race_is_recognised_as_duplicate_despite_the_transaction_snapshot(): void {
		$repository = $this->repository();
		$other      = $this->other_connection();
		$table      = $this->table( Tables::SALES_CYCLES );
		$sales      = $this->table( Tables::SALES );

		// A writer outside the lock (as after a lost connection) commits the
		// same identities after this transaction took its snapshot.
		$result = $repository->with_order_lock(
			self::ORDER,
			fn(): SalesWriteResult => $repository->write(
				self::ORDER,
				function ( SalesLedgerState $state ) use ( $other, $table, $sales ): SalesLedgerWrite {
					self::assertSame( 0, $state->cycles->latest_cycle() );
					$other->insert(
						$table,
						array(
							'order_id'    => self::ORDER,
							'cycle'       => 1,
							'event'       => 'open',
							'occurred_at' => '2026-09-28 10:00:00',
							'created_at'  => '2026-09-28 10:00:00',
						)
					);
					$other->insert(
						$sales,
						array(
							'event_key'     => 's:10:1',
							'creator_id'    => 3,
							'order_id'      => self::ORDER,
							'order_item_id' => 10,
							'cycle'         => 1,
							'product_id'    => 20,
							'type'          => 'sale',
							'quantity'      => 2,
							'net_amount'    => '100',
							'currency'      => 'DKK',
							'occurred_at'   => '2026-09-28 10:00:00',
							'created_at'    => '2026-09-28 10:00:00',
						)
					);

					return new SalesLedgerWrite( array( $this->entry( 's:10:1' ) ), array( $this->cycle( 1, SalesCycleEventType::Open ) ) );
				}
			)
		);

		self::assertSame( array( 's:10:1' ), $result->skipped_entries );
		self::assertSame( 1, $result->skipped_cycle_events );
		self::assertSame( 1, $this->count_rows( Tables::SALES ) );
		self::assertSame( 1, $this->count_rows( Tables::SALES_CYCLES ) );
	}

	public function test_changed_connection_is_detected_and_a_new_sync_converges(): void {
		$repository = $this->repository();
		$other      = $this->other_connection();
		$own_id     = (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$this->wpdb->suppress_errors( true );

		try {
			$repository->with_order_lock(
				self::ORDER,
				fn(): SalesWriteResult => $repository->write(
					self::ORDER,
					function () use ( $other, $own_id ): SalesLedgerWrite {
						// The server drops this connection mid-transaction; wpdb reconnects silently.
						$kill = $other->prepare( 'KILL %d', $own_id );
						self::assertIsString( $kill );
						$other->query( $kill );
						usleep( 200000 );

						return new SalesLedgerWrite( array( $this->entry( 's:10:1' ) ), array( $this->cycle( 1, SalesCycleEventType::Open ) ) );
					}
				)
			);
			self::fail( 'Expected SalesConnectionChangedException.' );
		} catch ( Throwable $e ) {
			// Thrown by write() inside the lock's closure (ADR-038, Q2: a type of its own).
			self::assertInstanceOf( SalesConnectionChangedException::class, $e );
			self::assertSame( 'The database connection changed while the sales ledger lock was held.', $e->getMessage() );
		}

		self::assertNotSame( $own_id, (int) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' ), 'wpdb reconnected.' );
		self::assertFalse( $this->lock_is_used( self::ORDER ), 'The lock died with the connection.' );
		// wpdb repeated the INSERT in the new session (autocommit); the
		// CONNECTION_ID() condition made it write nothing there.
		self::assertSame( 0, $this->count_rows( Tables::SALES_CYCLES ), 'Nothing written outside the transaction.' );
		self::assertSame( 0, $this->count_rows( Tables::SALES ) );

		$result = $this->write( self::ORDER, array( $this->entry( 's:10:1' ) ), array( $this->cycle( 1, SalesCycleEventType::Open ) ) );

		self::assertSame( 2, count( $result->inserted_entries ) + count( $result->skipped_entries ) + $result->inserted_cycle_events + $result->skipped_cycle_events );
		self::assertSame( 1, $this->count_rows( Tables::SALES ), 'Converged: exactly one row per identity.' );
		self::assertSame( 1, $this->count_rows( Tables::SALES_CYCLES ) );
	}

	public function test_concurrent_requests_open_a_cycle_exactly_once(): void {
		$start     = microtime( true ) + 4;
		$processes = array();
		$outputs   = array();

		for ( $i = 0; $i < 4; $i++ ) {
			$process = proc_open(
				array( PHP_BINARY, __DIR__ . '/../Fixtures/sales-lock-contender.php', (string) $start, (string) self::ORDER ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			self::assertIsResource( $process, 'Contender process could not be started.' );
			$processes[] = $process;
			$outputs[]   = $pipes;
		}

		$results = array();

		foreach ( $processes as $i => $process ) {
			$results[] = trim( (string) stream_get_contents( $outputs[ $i ][1] ) ) . trim( (string) stream_get_contents( $outputs[ $i ][2] ) );
			proc_close( $process );
		}

		sort( $results );
		// "inserted:skipped": one request wrote both rows; the others waited for
		// the lock, saw the committed cycle and planned nothing (no duplicate).
		self::assertSame( array( '0:0', '0:0', '0:0', '2:0' ), $results, implode( ', ', $results ) );
		self::assertSame( 1, $this->count_rows( Tables::SALES ) );
		self::assertSame( 1, $this->count_rows( Tables::SALES_CYCLES ) );
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
	 * From now on the ledger's row SELECT, and only it, fails with a real SQL
	 * error (a table that does not exist); every statement is recorded. The
	 * filter is removed in tearDown(), also when the test fails.
	 */
	private function fail_ledger_reads(): void {
		$this->fail_reads( self::LEDGER_SELECT . '`' . $this->table( Tables::SALES ) . '`' );
	}

	/**
	 * From now on every statement that starts with $select (and only those)
	 * fails with a real SQL error (a table that does not exist); with
	 * $in_transaction, only inside a transaction (after START TRANSACTION,
	 * before COMMIT or ROLLBACK). Every statement is recorded. The filter is
	 * removed in tearDown(), also when the test fails.
	 *
	 * @param string $select         Start of the statement to fail.
	 * @param bool   $in_transaction Whether to fail it only inside a transaction.
	 */
	private function fail_reads( string $select, bool $in_transaction = false ): void {
		$open               = false;
		$this->query_filter = function ( string $sql ) use ( $select, $in_transaction, &$open ): string {
			$this->statements[] = $sql;

			if ( 'START TRANSACTION' === $sql ) {
				$open = true;
			} elseif ( 'COMMIT' === $sql || 'ROLLBACK' === $sql ) {
				$open = false;
			}

			if ( ! str_starts_with( $sql, $select ) || ( $in_transaction && ! $open ) ) {
				return $sql;
			}

			++$this->failed_reads;

			return 'SELECT * FROM merchboard_no_such_table';
		};
		add_filter( 'query', $this->query_filter );
		$this->wpdb->suppress_errors( true );
	}

	/**
	 * Removes the filter of fail_reads() and resets what it recorded.
	 */
	private function allow_reads(): void {
		self::assertNotNull( $this->query_filter );
		remove_filter( 'query', $this->query_filter );
		$this->query_filter = null;
		$this->statements   = array();
		$this->failed_reads = 0;
	}

	/**
	 * A synchronizer on the test connection with a fixed clock.
	 *
	 * @param FakeOrderReader        $reader Reader.
	 * @param RecordingFailureMarker $marker Marker.
	 * @param RecordingLogger        $logger Logger.
	 */
	private function synchronizer( FakeOrderReader $reader, RecordingFailureMarker $marker, RecordingLogger $logger ): SalesSynchronizer {
		return new SalesSynchronizer( $this->repository(), new SalesPlanner(), $reader, $marker, $logger, static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-29 12:00:00', new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Order 7 in the reader with the given status and lines.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 *
	 * @param FakeOrderReader     $reader Reader.
	 * @param string              $status Status.
	 * @param OrderLineSnapshot[] $lines  Lines.
	 */
	private static function order( FakeOrderReader $reader, string $status, array $lines ): void {
		$reader->orders[ self::ORDER ] = array(
			'status'   => $status,
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => array(),
		);
	}

	/**
	 * Every row of the ledger, cycle and tax tables (read with statements fail_reads() never matches).
	 *
	 * @return array{sales: list<array<string, string|null>>, cycles: list<array<string, string|null>>, tax: mixed}
	 */
	private function ledger_tables(): array {
		return array(
			'sales'  => $this->rows( Tables::SALES ),
			'cycles' => $this->rows( Tables::SALES_CYCLES ),
			'tax'    => $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT event_key, tax_amount, currency FROM %i ORDER BY event_key', $this->table( Tables::SALES_TAX ) ), ARRAY_A ),
		);
	}

	/**
	 * Cycle events as "cycle:event" in insertion order.
	 *
	 * @return list<string>
	 */
	private function cycle_events(): array {
		return array_map( static fn( array $row ): string => $row['cycle'] . ':' . $row['event'], $this->rows( Tables::SALES_CYCLES ) );
	}

	/**
	 * Index of START TRANSACTION in the recorded statements, after asserting
	 * that R0 (version row, then activation row) came right after it.
	 */
	private function start_after_r0(): int {
		$start = array_search( 'START TRANSACTION', $this->statements, true );
		self::assertIsInt( $start );
		self::assertStringContainsString( "option_name = '" . Migrator::VERSION_OPTION . "' LOCK IN SHARE MODE", $this->statements[ $start + 1 ], 'R0: the version row.' );
		self::assertStringContainsString( '`' . $this->table( Tables::G1_STATE ) . '`', $this->statements[ $start + 2 ], 'R0: the activation row.' );
		self::assertStringEndsWith( 'LOCK IN SHARE MODE', $this->statements[ $start + 2 ] );

		return $start;
	}

	/**
	 * A line of product 20 by creator 3 (synchronizer tests).
	 *
	 * @param int         $item Item id.
	 * @param string|null $tax  Actual line tax, null when unknown.
	 */
	private static function line( int $item, ?string $tax = null ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, 20, 0, 'Line ' . $item, 2, SalesAmount::parse( '100' ), 3, null === $tax ? null : SalesAmount::parse( $tax ) );
	}

	/**
	 * Writes rows for an order under its lock.
	 *
	 * @phpstan-param list<SalesEntry> $entries
	 * @phpstan-param list<SalesCycleEvent> $events
	 *
	 * @param int               $order_id Order.
	 * @param SalesEntry[]      $entries  Rows.
	 * @param SalesCycleEvent[] $events   Cycle events.
	 */
	private function write( int $order_id, array $entries, array $events = array() ): SalesWriteResult {
		$repository = $this->repository();

		return $repository->with_order_lock(
			$order_id,
			static fn(): SalesWriteResult => $repository->write( $order_id, static fn(): SalesLedgerWrite => new SalesLedgerWrite( $entries, $events ) )
		);
	}

	/**
	 * A sale of order 7 with overrides.
	 *
	 * @param string               $key Event key.
	 * @param array<string, mixed> $o   Overrides.
	 */
	private function entry( string $key, array $o = array() ): SalesEntry {
		$type     = $o['type'] ?? SalesEntryType::Sale;
		$reason   = $o['reason'] ?? null;
		$occurred = $o['occurred_at'] ?? new DateTimeImmutable( '2026-09-28 10:00:00', new DateTimeZone( 'UTC' ) );

		self::assertInstanceOf( SalesEntryType::class, $type );
		self::assertTrue( null === $reason || $reason instanceof SalesCorrectionReason );
		self::assertInstanceOf( DateTimeImmutable::class, $occurred );

		return new SalesEntry(
			$key,
			3,
			(int) ( $o['order_id'] ?? self::ORDER ),
			(int) ( $o['order_item_id'] ?? 10 ),
			(int) ( $o['cycle'] ?? 1 ),
			(int) ( $o['refund_id'] ?? 0 ),
			20,
			(int) ( $o['variation_id'] ?? 0 ),
			(string) ( $o['item_name'] ?? 'T-shirt' ),
			$type,
			$reason,
			(int) ( $o['quantity'] ?? 2 ),
			(string) ( $o['net_amount'] ?? '100.0000' ),
			'DKK',
			$occurred
		);
	}

	/**
	 * Overrides for a correction.
	 *
	 * @param SalesCorrectionReason $reason    Reason.
	 * @param int                   $refund_id Refund, or 0.
	 * @param string                $amount    Signed amount.
	 * @return array<string, mixed>
	 */
	private function correction( SalesCorrectionReason $reason, int $refund_id, string $amount ): array {
		return array(
			'type'       => SalesEntryType::Correction,
			'reason'     => $reason,
			'refund_id'  => $refund_id,
			'quantity'   => 0,
			'net_amount' => $amount,
		);
	}

	/**
	 * A cycle event of order 7.
	 *
	 * @param int                 $cycle Cycle.
	 * @param SalesCycleEventType $type  Event.
	 */
	private function cycle( int $cycle, SalesCycleEventType $type ): SalesCycleEvent {
		return new SalesCycleEvent( self::ORDER, $cycle, $type, new DateTimeImmutable( '2026-09-28 10:00:00', new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * A second, independent connection with the test site's prefix.
	 */
	private function other_connection(): wpdb {
		$this->other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->other->set_prefix( $this->wpdb->prefix );

		return $this->other;
	}

	/**
	 * Whether any connection holds the order's lock (checked from a fresh connection).
	 *
	 * @param int $order_id Order.
	 */
	private function lock_is_used( int $order_id ): bool {
		$check = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$used  = $check->get_var( $check->prepare( 'SELECT IS_USED_LOCK(%s)', $this->repository()->lock_name( $order_id ) ) );
		$check->close();

		return null !== $used;
	}
}
