<?php
/**
 * Tests for the backfill pages, counts and cursor.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfill;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillOutcome;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillRun;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillTally;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntryType;
use MerchBoard\Modules\WooCommerce\Sales\SalesFailureCode;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerWrite;
use MerchBoard\Tests\Unit\Support\FakeBackfillOrders;
use MerchBoard\Tests\Unit\Support\FakeBackfillSync;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass( SalesBackfill::class )]
#[CoversClass( SalesBackfillRun::class )]
#[CoversClass( SalesBackfillTally::class )]
#[CoversClass( SalesBackfillOutcome::class )]
#[CoversClass( SalesBackfillStatus::class )]
final class SalesBackfillTest extends TestCase {

	private FakeBackfillSync $sync;

	private FakeBackfillOrders $orders;

	private SalesBackfill $backfill;

	protected function setUp(): void {
		parent::setUp();
		$this->sync     = new FakeBackfillSync();
		$this->orders   = new FakeBackfillOrders();
		$this->backfill = new SalesBackfill( $this->sync, $this->orders );

		$this->orders->orders = array(
			5  => 'completed',
			3  => 'processing',
			9  => 'on-hold',
			11 => 'completed',
			12 => 'processing',
		);
	}

	public function test_a_page_registers_its_orders_oldest_first_and_advances_the_cursor(): void {
		$run = SalesBackfillRun::start( array( 'processing', 'completed' ), 2, 7 );

		$next = $this->backfill->run_page( $run );

		self::assertSame( array( 3, 5 ), $this->sync->registered );
		self::assertSame( array( array( array( 'processing', 'completed' ), 0, 2 ) ), $this->orders->requests );
		self::assertSame( 2, $next->offset );
		self::assertFalse( $next->complete, 'A full page: more may follow.' );
		self::assertSame( 2, $next->tally->scanned );
		self::assertSame( 2, $next->tally->count( SalesBackfillStatus::Registered ) );
		self::assertSame( $run->run_id, $next->run_id );
		self::assertSame( 7, $next->trigger_user_id );
	}

	public function test_the_last_page_completes_the_run(): void {
		$run = SalesBackfillRun::start( array( 'processing', 'completed' ), 2, null );

		$run = $this->backfill->run_page( $run );
		$run = $this->backfill->run_page( $run );
		$run = $this->backfill->run_page( $run );

		self::assertTrue( $run->complete );
		self::assertSame( 4, $run->offset );
		self::assertSame( array( 3, 5, 11, 12 ), $this->sync->registered, 'on-hold does not count: never queried.' );
		self::assertSame( 4, $run->tally->count( SalesBackfillStatus::Registered ) );
	}

	public function test_an_empty_last_page_completes_the_run(): void {
		$run = SalesBackfillRun::start( array( 'processing', 'completed' ), 4, null );

		$run = $this->backfill->run_page( $run );
		self::assertFalse( $run->complete );

		$run = $this->backfill->run_page( $run );
		self::assertTrue( $run->complete );
		self::assertSame( 4, $run->tally->scanned );
	}

	public function test_a_page_that_cannot_be_read_leaves_the_cursor_unchanged(): void {
		$run                   = SalesBackfillRun::start( array( 'processing', 'completed' ), 2, null );
		$this->orders->fail_on = 1;

		try {
			$this->backfill->run_page( $run );
			self::fail( 'Expected RuntimeException.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'Orders could not be read.', $e->getMessage() );
		}

		self::assertSame( array(), $this->sync->registered );
		self::assertSame( 0, $run->offset );
	}

	public function test_failed_and_skipped_orders_are_counted_and_the_page_continues(): void {
		$this->sync->outcomes = array(
			3  => SalesBackfillOutcome::failed( SalesFailureCode::LockTimeout ),
			5  => SalesBackfillOutcome::of( SalesBackfillStatus::HasHistory ),
			11 => SalesBackfillOutcome::failed( SalesFailureCode::LockTimeout ),
			12 => SalesBackfillOutcome::failed( SalesFailureCode::UnresolvedRefundItem ),
		);

		$run = $this->backfill->run_page( SalesBackfillRun::start( array( 'processing', 'completed' ), 10, null ) );

		self::assertSame( array( 3, 5, 11, 12 ), $this->sync->registered );
		self::assertSame( 3, $run->tally->count( SalesBackfillStatus::Failed ) );
		self::assertSame( 1, $run->tally->count( SalesBackfillStatus::HasHistory ) );
		self::assertSame(
			array(
				'lock_timeout'           => 2,
				'unresolved_refund_item' => 1,
			),
			$run->tally->failures
		);
		self::assertTrue( $run->complete );
	}

	public function test_the_dry_run_previews_every_page_and_registers_nothing(): void {
		$this->sync->outcomes = array( 5 => SalesBackfillOutcome::of( SalesBackfillStatus::NotCounted ) );
		$progress             = array();

		$tally = $this->backfill->dry_run(
			array( 'processing', 'completed' ),
			3,
			static function ( int $scanned ) use ( &$progress ): void {
				$progress[] = $scanned;
			}
		);

		self::assertSame( array( 3, 5, 11, 12 ), $this->sync->previewed );
		self::assertSame( array(), $this->sync->registered, 'The dry run never registers.' );
		self::assertSame( array( 3, 4 ), $progress );
		self::assertSame( 4, $tally->scanned );
		self::assertSame( 3, $tally->count( SalesBackfillStatus::Registered ) );
		self::assertSame( 1, $tally->count( SalesBackfillStatus::NotCounted ) );
		self::assertSame( 3, $tally->rows['sale'] );
	}

	public function test_the_dry_run_rejects_an_invalid_page_size(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->backfill->dry_run( array( 'completed' ), 0 );
	}

	public function test_registered_outcomes_count_rows_per_type(): void {
		$outcome = SalesBackfillOutcome::registered( new SalesLedgerWrite() );

		self::assertSame( SalesBackfillStatus::Registered, $outcome->status );
		self::assertSame(
			array_fill_keys( array_map( static fn( SalesEntryType $t ): string => $t->value, SalesEntryType::cases() ), 0 ),
			$outcome->rows
		);
		self::assertNull( $outcome->failure );
		self::assertSame( SalesFailureCode::DbError, SalesBackfillOutcome::failed( SalesFailureCode::DbError )->failure );
	}

	public function test_the_tally_holds_counts_only(): void {
		$tally = SalesBackfillTally::empty()
			->with( SalesBackfillOutcome::of( SalesBackfillStatus::Missing ) )
			->with( SalesBackfillOutcome::failed( SalesFailureCode::DbError ) );

		self::assertSame(
			array(
				'scanned'             => 2,
				'registered'          => 0,
				'skipped_history'     => 0,
				'skipped_not_counted' => 0,
				'skipped_no_creator'  => 0,
				'skipped_missing'     => 1,
				'skipped_busy'        => 0,
				'failed'              => 1,
				'rows'                => array(
					'sale'       => 0,
					'refund'     => 0,
					'reversal'   => 0,
					'correction' => 0,
				),
				'failures'            => array( 'db_error' => 1 ),
			),
			$tally->to_array()
		);
		self::assertEquals( $tally, SalesBackfillTally::from_array( $tally->to_array() ) );
	}

	public function test_the_run_round_trips_through_its_payload(): void {
		$run  = SalesBackfillRun::start( array( 'processing', 'awaiting_shipment' ), 25, 4 );
		$next = $run->advanced( 25, $run->tally->with( SalesBackfillOutcome::of( SalesBackfillStatus::Registered ) ) );

		$copy = SalesBackfillRun::from_array( $next->to_array() );

		self::assertSame( $next->to_array(), $copy->to_array() );
		self::assertSame( array( 'processing', 'awaiting_shipment' ), $copy->statuses );
		self::assertSame( 25, $copy->offset );
		self::assertFalse( $copy->complete, 'A payload is always a page still to run.' );
		self::assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $copy->run_id->value );
	}

	public function test_a_new_run_drops_denied_and_duplicate_statuses(): void {
		$run = SalesBackfillRun::start( array( 'completed', 'trash', 'pending', 'completed' ), 50, 0 );

		self::assertSame( array( 'completed' ), $run->statuses );
		self::assertNull( $run->trigger_user_id, 'User 0 is no user.' );
		self::assertSame( 0, $run->offset );
	}

	/**
	 * Payloads that are not a run.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_payloads(): array {
		$valid = SalesBackfillRun::start( array( 'completed' ), 50, null )->to_array();

		return array(
			'not an array'      => array( 'run' ),
			'no run id'         => array( array_diff_key( $valid, array( 'run_id' => 1 ) ) ),
			'bad run id'        => array( array( 'run_id' => 'x' ) + $valid ),
			'negative offset'   => array( array( 'offset' => -1 ) + $valid ),
			'batch too large'   => array( array( 'batch_size' => 201 ) + $valid ),
			'batch zero'        => array( array( 'batch_size' => 0 ) + $valid ),
			'no statuses'       => array( array( 'statuses' => array() ) + $valid ),
			'invalid status'    => array( array( 'statuses' => array( 'Completed' ) ) + $valid ),
			'only denied'       => array( array( 'statuses' => array( 'pending' ) ) + $valid ),
			'bad user'          => array( array( 'trigger_user_id' => 0 ) + $valid ),
			'bad tally'         => array( array( 'tally' => array( 'scanned' => -1 ) ) + $valid ),
			'bad failure code'  => array( array( 'tally' => array( 'failures' => array( 'nope' => 1 ) ) + $valid['tally'] ) + $valid ),
			'bad failure count' => array( array( 'tally' => array( 'failures' => array( 'db_error' => 0 ) ) + $valid['tally'] ) + $valid ),
			'missing count'     => array( array( 'tally' => array_diff_key( $valid['tally'], array( 'failed' => 1 ) ) ) + $valid ),
		);
	}

	#[DataProvider( 'invalid_payloads' )]
	public function test_invalid_payloads_are_rejected( mixed $payload ): void {
		$this->expectException( InvalidArgumentException::class );

		SalesBackfillRun::from_array( $payload );
	}
}
