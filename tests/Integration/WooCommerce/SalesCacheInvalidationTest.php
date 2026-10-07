<?php
/**
 * Invalidation of the sales statistics cache by SalesRepository::write()
 * (ADR-040 pkt. 10) through every ledger write path, against a real
 * MySQL/MariaDB and WordPress' object cache.
 *
 * The ledger is written by the real synchronization (repository, planner,
 * lock, transaction) with a test reader (no WooCommerce, ADR-038 Q4). The
 * repository gets RecordingSalesCacheInvalidator, which records each call and
 * passes it on to the real SalesCache.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Integration\Support\RecordingSalesCacheInvalidator;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use RuntimeException;

final class SalesCacheInvalidationTest extends IntegrationTestCase {

	private const MINE   = 3;
	private const THEIRS = 4;

	private FakeOrderReader $reader;

	private RecordingSalesCacheInvalidator $invalidator;

	private SalesRepository $repository;

	private SalesSynchronizer $sync;

	private SalesCache $cache;

	private int $clock = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->reader      = new FakeOrderReader();
		$this->cache       = new SalesCache();
		$this->invalidator = new RecordingSalesCacheInvalidator( $this->cache );
		$this->repository  = new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ), SalesRepository::LOCK_TIMEOUT, $this->invalidator );
		$this->sync        = new SalesSynchronizer(
			$this->repository,
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-30 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
		);
	}

	public function test_generations_start_at_1_and_change_on_a_committed_sale(): void {
		self::assertSame( SalesCache::INITIAL_GENERATION, $this->cache->generation( self::MINE ) );

		$this->place( 101, 'completed', array( self::line( 1011, 2 ) ) );

		self::assertNotSame( SalesCache::INITIAL_GENERATION, $this->cache->generation( self::MINE ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $this->cache->generation( self::MINE ) );
		self::assertSame( SalesCache::INITIAL_GENERATION, $this->cache->generation( self::THEIRS ), 'Another creator is untouched.' );
	}

	public function test_one_write_with_several_rows_of_a_creator_invalidates_it_once(): void {
		$this->place( 101, 'completed', array( self::line( 1011, 2 ), self::line( 1012, 1 ), self::line( 1013, 3 ) ) );

		self::assertSame( 3, $this->count_rows( Tables::SALES ) );
		self::assertSame( array( array( self::MINE ) ), $this->invalidator->calls );
	}

	public function test_one_write_with_two_creators_invalidates_both_once(): void {
		$before = $this->cache->generation( self::THEIRS );
		$this->place( 101, 'completed', array( self::line( 1011, 2 ), self::line( 1012, 1, self::THEIRS ), self::line( 1013, 1 ), self::line( 1014, 1, self::THEIRS ) ) );

		self::assertCount( 1, $this->invalidator->calls );
		$creators = $this->invalidator->calls[0];
		sort( $creators );
		self::assertSame( array( self::MINE, self::THEIRS ), $creators );
		self::assertNotSame( $before, $this->cache->generation( self::THEIRS ) );
	}

	public function test_every_write_gives_a_new_generation(): void {
		$seen = array( $this->cache->generation( self::MINE ) );

		foreach ( array( 101, 102, 103 ) as $order ) {
			$this->place( $order, 'completed', array( self::line( $order * 10, 1 ) ) );
			$seen[] = $this->cache->generation( self::MINE );
		}

		self::assertCount( 4, array_unique( $seen ) );
	}

	public function test_refunds_reversals_reopening_and_corrections_invalidate(): void {
		// Refund.
		$this->place( 101, 'completed', array( self::line( 1011, 2 ) ) );
		$this->expect_invalidation( fn() => $this->place( 101, 'completed', array( self::line( 1011, 2 ) ), array( self::refund( 9011, 1011, 1 ) ) ), 'r:1011:9011' );

		// Reversal (cancelled) and a new sale cycle (reopened).
		$this->place( 102, 'completed', array( self::line( 1021, 2 ) ) );
		$this->expect_invalidation( fn() => $this->place( 102, 'cancelled', array( self::line( 1021, 2 ) ) ), 'v:1021:1' );
		$this->expect_invalidation( fn() => $this->place( 102, 'processing', array( self::line( 1021, 2 ) ) ), 's:1021:2' );

		// Correction: refund after the order stopped counting (refund + closed_cycle_refund in one write).
		$this->place( 103, 'completed', array( self::line( 1031, 2 ) ) );
		$this->place( 103, 'cancelled', array( self::line( 1031, 2 ) ) );
		$this->expect_invalidation( fn() => $this->place( 103, 'cancelled', array( self::line( 1031, 2 ) ), array( self::refund( 9031, 1031, 1 ) ) ), 'c-ro:1031:9031' );

		// Correction: refund deleted.
		$this->place( 104, 'completed', array( self::line( 1041, 2 ) ) );
		$this->place( 104, 'completed', array( self::line( 1041, 2 ) ), array( self::refund( 9041, 1041, 1 ) ) );
		$this->expect_invalidation( fn() => $this->place( 104, 'completed', array( self::line( 1041, 2 ) ), array(), 9041 ), 'c-rd:1041:9041' );

		// Correction: an order line edited in WooCommerce (line_changed).
		$this->place( 106, 'completed', array( self::line( 1061, 2 ) ) );
		$this->expect_invalidation( fn() => $this->place( 106, 'completed', array( new OrderLineSnapshot( 1061, 20, 0, 'Line 1061', 2, SalesAmount::parse( '80' ), self::MINE ) ) ), 'c-l:1061:1:1' );

		// Correction: line removed from a counted order.
		$this->place( 105, 'completed', array( self::line( 1051, 2 ), self::line( 1052, 1 ) ) );
		$this->expect_invalidation( fn() => $this->place( 105, 'completed', array( self::line( 1052, 1 ) ) ), 'c-l:1051:1:1' );
	}

	public function test_a_permanently_deleted_order_invalidates(): void {
		$this->place( 101, 'completed', array( self::line( 1011, 2 ) ) );

		$this->expect_invalidation(
			function (): void {
				$this->sync->order_deleting( 101 );
				unset( $this->reader->orders[101] );
			},
			'v:1011:1'
		);
	}

	public function test_the_backfill_invalidates_and_its_dry_run_does_not(): void {
		$this->reader->orders[201] = array(
			'status'   => 'completed',
			'currency' => 'DKK',
			'lines'    => array( self::line( 2011, 1 ), self::line( 2012, 1, self::THEIRS ) ),
			'refunds'  => array(),
		);

		self::assertSame( SalesBackfillStatus::Registered, $this->sync->preview( 201 )->status );
		self::assertSame( array(), $this->invalidator->calls, 'The dry run writes nothing.' );

		self::assertSame( SalesBackfillStatus::Registered, $this->sync->backfill( 201 )->status );
		self::assertCount( 1, $this->invalidator->calls );
		self::assertEqualsCanonicalizing( array( self::MINE, self::THEIRS ), $this->invalidator->calls[0] );
	}

	public function test_synchronizations_without_new_ledger_rows_do_not_invalidate(): void {
		$this->place( 101, 'completed', array( self::line( 1011, 2 ) ) );
		$generation               = $this->cache->generation( self::MINE );
		$this->invalidator->calls = array();

		// The same state again (repeated hook), a change between counted statuses
		// and a renamed product (catalog prices and stock are not read at all).
		$this->place( 101, 'completed', array( self::line( 1011, 2 ) ) );
		$this->place( 101, 'processing', array( self::line( 1011, 2 ) ) );
		$this->place( 101, 'processing', array( new OrderLineSnapshot( 1011, 20, 0, 'Renamed tee', 2, SalesAmount::parse( '100' ), self::MINE ) ) );
		// An order that does not count yet.
		$this->place( 103, 'pending', array( self::line( 1031, 1 ) ) );

		self::assertSame( 1, $this->count_rows( Tables::SALES ), 'No new ledger row.' );
		self::assertSame( array(), $this->invalidator->calls );
		self::assertSame( $generation, $this->cache->generation( self::MINE ) );
	}

	public function test_a_rolled_back_write_does_not_invalidate(): void {
		$this->place( 101, 'completed', array( self::line( 1011, 2 ) ) );
		$generation               = $this->cache->generation( self::MINE );
		$this->invalidator->calls = array();
		$rows                     = $this->count_rows( Tables::SALES );

		try {
			$this->repository->with_order_lock(
				101,
				fn() => $this->repository->write(
					101,
					static function (): never {
						throw new RuntimeException( 'Planning failed.' );
					}
				)
			);
			self::fail( 'The write should have failed.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'Planning failed.', $e->getMessage() );
		}

		self::assertSame( $rows, $this->count_rows( Tables::SALES ) );
		self::assertSame( array(), $this->invalidator->calls );
		self::assertSame( $generation, $this->cache->generation( self::MINE ) );
	}

	public function test_a_lost_or_corrupt_generation_falls_back_to_the_initial_one(): void {
		$this->place( 101, 'completed', array( self::line( 1011, 2 ) ) );

		wp_cache_set( 'generation:' . self::MINE, 'not-a-token', SalesCache::GROUP );
		$corrupt = $this->cache->generation( self::MINE );
		wp_cache_delete( 'generation:' . self::MINE, SalesCache::GROUP );
		$lost = $this->cache->generation( self::MINE );

		self::assertSame( array( SalesCache::INITIAL_GENERATION, SalesCache::INITIAL_GENERATION ), array( $corrupt, $lost ) );
	}

	/**
	 * Runs a synchronization that must write the event key and invalidate
	 * the creator exactly once.
	 *
	 * @param callable(): void $work      Synchronization.
	 * @param string           $event_key Ledger row it writes.
	 */
	private function expect_invalidation( callable $work, string $event_key ): void {
		$before                   = $this->cache->generation( self::MINE );
		$this->invalidator->calls = array();

		$work();

		self::assertTrue( $this->repository->event_exists( $event_key ), $event_key );
		self::assertSame( array( array( self::MINE ) ), $this->invalidator->calls, $event_key );
		self::assertNotSame( $before, $this->cache->generation( self::MINE ), $event_key );
	}

	/**
	 * Synchronizes an order with the given state.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param int                 $order_id       Order id.
	 * @param string              $status         Status.
	 * @param OrderLineSnapshot[] $lines          Lines.
	 * @param RefundSnapshot[]    $refunds        Current refunds.
	 * @param int|null            $deleted_refund Refund known to be deleted.
	 */
	private function place( int $order_id, string $status, array $lines, array $refunds = array(), ?int $deleted_refund = null ): void {
		$this->reader->orders[ $order_id ] = array(
			'status'   => $status,
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => $refunds,
		);
		$this->sync->sync( $order_id, $deleted_refund );
	}

	/**
	 * A line of product 20.
	 *
	 * @param int $item     Item id.
	 * @param int $quantity Quantity.
	 * @param int $creator  Creator.
	 */
	private static function line( int $item, int $quantity, int $creator = self::MINE ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, 20, 0, 'Line ' . $item, $quantity, SalesAmount::parse( (string) ( 50 * $quantity ) ), $creator );
	}

	/**
	 * A refund of one line.
	 *
	 * @param int $id       Refund id.
	 * @param int $item     Refunded item id.
	 * @param int $quantity Refunded quantity (positive).
	 */
	private static function refund( int $id, int $item, int $quantity ): RefundSnapshot {
		return new RefundSnapshot(
			$id,
			new DateTimeImmutable( '2026-09-02 09:00:00', new DateTimeZone( 'UTC' ) ),
			array( new RefundLineSnapshot( $item, -$quantity, SalesAmount::parse( (string) ( -50 * $quantity ) ) ) )
		);
	}
}
