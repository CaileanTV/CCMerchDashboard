<?php
/**
 * The creator's order lines read from the sales ledger (ADR-039 P2) against
 * a real MySQL/MariaDB.
 *
 * The ledger is filled by the real synchronization (repository, planner,
 * lock) with a test reader (no WooCommerce, ADR-038 Q4), so the rows are the
 * ones production writes. Rows for single event keys are stored directly
 * where one key must be isolated.
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
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLine;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLines;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

final class SalesOrderLinesTest extends IntegrationTestCase {

	private const MINE   = 3;
	private const THEIRS = 4;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesOrderLines $lines;

	private int $clock = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->reader = new FakeOrderReader();
		$this->sync   = new SalesSynchronizer(
			new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) ),
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-30 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
		);
		$this->lines  = new SalesOrderLines( $this->wpdb );
	}

	public function test_every_p2_scenario_gives_its_quantity_and_markers(): void {
		// Sale of 2.
		$this->place( 101, 'completed', array( self::line( 1011, 2 ) ) );

		// Partial refund of 1.
		$this->place( 102, 'completed', array( self::line( 1021, 2 ) ) );
		$this->place( 102, 'completed', array( self::line( 1021, 2 ) ), array( self::refund( 9021, 1021, 1 ) ) );

		// Full refund: WooCommerce moves the order to `refunded` (not counted).
		$this->place( 103, 'completed', array( self::line( 1031, 2 ) ) );
		$this->place( 103, 'refunded', array( self::line( 1031, 2 ) ), array( self::refund( 9031, 1031, 2 ) ) );

		// Not counted any more (cancelled after the sale).
		$this->place( 104, 'completed', array( self::line( 1041, 2 ) ) );
		$this->place( 104, 'cancelled', array( self::line( 1041, 2 ) ) );

		// Partial refund, then not counted.
		$this->place( 105, 'completed', array( self::line( 1051, 2 ) ) );
		$this->place( 105, 'completed', array( self::line( 1051, 2 ) ), array( self::refund( 9051, 1051, 1 ) ) );
		$this->place( 105, 'cancelled', array( self::line( 1051, 2 ) ), array( self::refund( 9051, 1051, 1 ) ) );

		// Reopened after cancellation.
		$this->place( 106, 'completed', array( self::line( 1061, 2 ) ) );
		$this->place( 106, 'cancelled', array( self::line( 1061, 2 ) ) );
		$this->place( 106, 'processing', array( self::line( 1061, 2 ) ) );

		// Partial refund, not counted, reopened.
		$refund = array( self::refund( 9071, 1071, 1 ) );
		$this->place( 107, 'completed', array( self::line( 1071, 2 ) ) );
		$this->place( 107, 'completed', array( self::line( 1071, 2 ) ), $refund );
		$this->place( 107, 'cancelled', array( self::line( 1071, 2 ) ), $refund );
		$this->place( 107, 'processing', array( self::line( 1071, 2 ) ), $refund );

		// Refund after the order stopped counting (closed_cycle_refund).
		$this->place( 108, 'completed', array( self::line( 1081, 2 ) ) );
		$this->place( 108, 'cancelled', array( self::line( 1081, 2 ) ) );
		$this->place( 108, 'cancelled', array( self::line( 1081, 2 ) ), array( self::refund( 9081, 1081, 1 ) ) );

		// Refund deleted on the open cycle (refund_deleted).
		$this->place( 109, 'completed', array( self::line( 1091, 2 ) ) );
		$this->place( 109, 'completed', array( self::line( 1091, 2 ) ), array( self::refund( 9091, 1091, 1 ) ) );
		$this->place( 109, 'completed', array( self::line( 1091, 2 ) ), array(), 9091 );

		// Line removed from a counted order (line_removed).
		$this->place( 110, 'completed', array( self::line( 1101, 2 ), self::line( 1102, 1 ) ) );
		$this->place( 110, 'completed', array( self::line( 1102, 1 ) ) );

		self::assertSame( array( 's:1081:1', 'v:1081:1', 'r:1081:9081', 'c-ro:1081:9081' ), $this->keys( 108 ) );
		self::assertSame( array( 's:1091:1', 'r:1091:9091', 'c-rd:1091:9091' ), $this->keys( 109 ) );
		self::assertSame( array( 's:1101:1', 's:1102:1', 'c-l:1101:1:1' ), $this->keys( 110 ) );

		$states = $this->states( self::MINE );
		ksort( $states );

		self::assertSame(
			array(
				1011 => array( 2, false, true ),
				1021 => array( 1, true, true ),
				1031 => array( 0, true, false ),
				1041 => array( 0, false, false ),
				1051 => array( 0, true, false ),
				1061 => array( 2, false, true ),
				1071 => array( 1, true, true ),
				1081 => array( 0, true, false ),
				1091 => array( 2, false, true ),
				1101 => array( 0, false, true ),
				1102 => array( 1, false, true ),
			),
			$states
		);
		self::assertSame( 11, $this->lines->count( self::MINE ) );
	}

	public function test_only_the_creators_own_lines_are_read(): void {
		// One order with a line of each creator, and an order of the other creator alone.
		$this->place( 201, 'completed', array( self::line( 2011, 2 ), self::line( 2012, 5, self::THEIRS ) ) );
		$this->place( 202, 'completed', array( self::line( 2021, 1, self::THEIRS ) ) );

		self::assertSame( array( 2011 ), array_keys( $this->states( self::MINE ) ) );
		self::assertSame( array( 2021, 2012 ), array_keys( $this->states( self::THEIRS ) ), 'Same date: higher item id first.' );
		self::assertSame( 1, $this->lines->count( self::MINE ) );
		self::assertSame( 2, $this->lines->count( self::THEIRS ) );
		self::assertSame( array(), $this->page( 99 ) );
		self::assertSame( 0, $this->lines->count( 99 ) );
		self::assertSame( array(), $this->page( 0 ) );
		self::assertSame( 0, $this->lines->count( 0 ) );
	}

	public function test_several_rows_of_a_line_become_one_line_with_the_summed_quantity(): void {
		$refunds = array( self::refund( 9301, 3011, 1 ), self::refund( 9302, 3011, 2 ) );
		$this->place( 301, 'completed', array( self::line( 3011, 5 ) ) );
		$this->place( 301, 'completed', array( self::line( 3011, 5 ) ), $refunds );
		$this->place( 301, 'completed', array( self::line( 3011, 7 ) ), $refunds );

		self::assertSame( array( 's:3011:1', 'r:3011:9301', 'r:3011:9302', 'c-l:3011:1:1' ), $this->keys( 301 ) );

		$page = $this->page( self::MINE );
		self::assertCount( 1, $page );
		self::assertSame( 301, $page[0]->order_id );
		self::assertSame( 3011, $page[0]->order_item_id );
		self::assertSame( 4, $page[0]->quantity, '5 - 1 - 2 + 2' );
		self::assertTrue( $page[0]->refunded );
		self::assertTrue( $page[0]->counted );
	}

	public function test_the_first_sale_row_gives_the_date_and_the_product_snapshot(): void {
		$this->place( 401, 'completed', array( self::line( 4011, 2 ) ), array(), null, '2026-08-15 08:30:00' );
		$this->place( 401, 'cancelled', array( self::line( 4011, 2 ) ) );
		$this->place( 401, 'processing', array( new OrderLineSnapshot( 4011, 21, 22, 'Renamed', 2, SalesAmount::parse( '100' ), self::MINE ) ) );

		self::assertSame( array( 's:4011:1', 'v:4011:1', 's:4011:2' ), $this->keys( 401 ) );

		$line = $this->page( self::MINE )[0];
		self::assertSame( '2026-08-15 08:30:00', $line->date->format( 'Y-m-d H:i:s' ) );
		self::assertSame( 'UTC', $line->date->getTimezone()->getName() );
		self::assertSame( array( 20, 0, 'Line 4011' ), array( $line->product_id, $line->variation_id, $line->item_name ) );
	}

	public function test_lines_are_sorted_by_date_descending_then_item_id_descending(): void {
		$this->place( 501, 'completed', array( self::line( 5011, 1 ) ), array(), null, '2026-03-01 10:00:00' );
		$this->place( 502, 'completed', array( self::line( 5021, 1 ), self::line( 5022, 1 ) ), array(), null, '2026-05-01 10:00:00' );
		$this->place( 503, 'completed', array( self::line( 5031, 1 ) ), array(), null, '2026-04-01 10:00:00' );
		$this->place( 504, 'completed', array( self::line( 5041, 1 ) ), array(), null, '2026-05-01 10:00:00' );

		self::assertSame( array( 5041, 5022, 5021, 5031, 5011 ), array_keys( $this->states( self::MINE ) ) );
	}

	public function test_pages_and_total(): void {
		foreach ( array( 1, 2, 3, 4, 5 ) as $day ) {
			$this->place( 600 + $day, 'completed', array( self::line( 6000 + $day, 1 ) ), array(), null, '2026-06-0' . $day . ' 10:00:00' );
		}

		$ids = static fn( array $page ): array => array_map( static fn( SalesOrderLine $line ): int => $line->order_item_id, $page );

		self::assertSame( array( 6005, 6004 ), $ids( $this->page( self::MINE, 1, 2 ) ) );
		self::assertSame( array( 6003, 6002 ), $ids( $this->page( self::MINE, 2, 2 ) ) );
		self::assertSame( array( 6001 ), $ids( $this->page( self::MINE, 3, 2 ) ) );
		self::assertSame( array(), $this->page( self::MINE, 4, 2 ) );
		self::assertSame( 5, $this->lines->count( self::MINE ) );
	}

	/**
	 * Single event keys, stored directly: only `refund` rows without their
	 * `c-rd` correction count; `c-ro` and `c-rdo` never change the marker.
	 */
	public function test_the_refund_marker_only_looks_at_refund_rows_and_their_refund_deleted_corrections(): void {
		$this->ledger(
			701,
			7011,
			array(
				's:7011:1'       => 2,
				'r:7011:9701'    => -1,
				'c-ro:7011:9701' => 1,
			)
		);
		$this->ledger(
			702,
			7021,
			array(
				's:7021:1'        => 2,
				'r:7021:9702'     => -1,
				'c-rdo:7021:9709' => 1,
			)
		);
		$this->ledger(
			703,
			7031,
			array(
				's:7031:1'        => 2,
				'r:7031:9703'     => -1,
				'c-rd:7031:9703'  => 1,
				'c-rdo:7031:9703' => -1,
			)
		);
		$this->ledger(
			704,
			7041,
			array(
				's:7041:1'       => 2,
				'r:7041:9704'    => -1,
				'r:7041:9705'    => -1,
				'c-rd:7041:9704' => 1,
			)
		);
		$this->ledger(
			705,
			7051,
			array(
				's:7051:1'        => 2,
				'c-ro:7051:9706'  => 1,
				'c-rdo:7051:9706' => -1,
			)
		);

		$states = $this->states( self::MINE );

		self::assertTrue( $states[7011][1], 'c-ro does not remove the marker.' );
		self::assertTrue( $states[7021][1], 'c-rdo does not remove the marker.' );
		self::assertFalse( $states[7031][1], 'c-rd removes it; c-rdo does not bring it back.' );
		self::assertTrue( $states[7041][1], 'One of two refunds still applies.' );
		self::assertFalse( $states[7051][1], 'Corrections without a refund row are no refund.' );
	}

	public function test_counted_follows_open_and_close_rows_of_the_order(): void {
		$this->ledger( 801, 8011, array( 's:8011:1' => 1 ), array( '1:open' ) );
		$this->ledger(
			802,
			8021,
			array(
				's:8021:1' => 1,
				'v:8021:1' => -1,
			),
			array( '1:open', '1:close' )
		);
		$this->ledger(
			803,
			8031,
			array(
				's:8031:1' => 1,
				'v:8031:1' => -1,
				's:8031:2' => 1,
			),
			array( '1:open', '1:close', '2:open' )
		);
		$this->ledger( 804, 8041, array( 's:8041:1' => 1 ), array() );

		$states = $this->states( self::MINE );

		self::assertTrue( $states[8011][2] );
		self::assertFalse( $states[8021][2] );
		self::assertTrue( $states[8031][2] );
		self::assertFalse( $states[8041][2], 'No cycle rows: not counted.' );
	}

	public function test_only_the_ledger_tables_are_read_and_no_woocommerce_function_is_needed(): void {
		$this->place( 901, 'completed', array( self::line( 9011, 1 ) ) );
		$queries = array();
		$record  = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;

			return $query;
		};

		add_filter( 'query', $record );
		$this->lines->count( self::MINE );
		$this->page( self::MINE );
		remove_filter( 'query', $record );

		self::assertFalse( function_exists( 'wc_get_order' ), 'No WooCommerce here: a lookup would fail.' );
		self::assertCount( 3, $queries, 'count, page and the cycles of the page.' );

		$prefix = $this->wpdb->prefix;

		foreach ( $queries as $query ) {
			preg_match_all( '/`?(' . preg_quote( $prefix, '/' ) . '\w+)`?/', $query, $tables );
			self::assertNotSame( array(), $tables[1], $query );

			foreach ( $tables[1] as $table ) {
				self::assertContains( $table, array( $this->table( Tables::SALES ), $this->table( Tables::SALES_CYCLES ) ), $query );
			}
		}
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
	 * @param string|null         $date_paid      Payment date (UTC).
	 */
	private function place( int $order_id, string $status, array $lines, array $refunds = array(), ?int $deleted_refund = null, ?string $date_paid = null ): void {
		$order = array(
			'status'   => $status,
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => $refunds,
		);

		if ( null !== $date_paid ) {
			$order['date_paid'] = $date_paid;
		} elseif ( isset( $this->reader->orders[ $order_id ]['date_paid'] ) ) {
			$order['date_paid'] = $this->reader->orders[ $order_id ]['date_paid'];
		}

		$this->reader->orders[ $order_id ] = $order;
		$this->sync->sync( $order_id, $deleted_refund );
	}

	/**
	 * Stores ledger and cycle rows directly.
	 *
	 * @phpstan-param list<string> $cycles
	 *
	 * @param int                $order_id Order id.
	 * @param int                $item_id  Order item id.
	 * @param array<string, int> $rows     Event key => quantity.
	 * @param string[]           $cycles   "cycle:event" rows.
	 */
	private function ledger( int $order_id, int $item_id, array $rows, array $cycles = array( '1:open' ) ): void {
		$types = array(
			's'     => array( 'sale', null ),
			'v'     => array( 'reversal', null ),
			'r'     => array( 'refund', null ),
			'c-ro'  => array( 'correction', 'closed_cycle_refund' ),
			'c-rd'  => array( 'correction', 'refund_deleted' ),
			'c-rdo' => array( 'correction', 'closed_cycle_refund_deleted' ),
		);

		foreach ( $rows as $key => $quantity ) {
			$parts             = explode( ':', $key );
			[ $type, $reason ] = $types[ $parts[0] ];
			$refund            = in_array( $parts[0], array( 's', 'v' ), true ) ? 0 : (int) $parts[2];
			$cycle             = in_array( $parts[0], array( 's', 'v' ), true ) ? (int) $parts[2] : 1;
			$this->wpdb->insert(
				$this->table( Tables::SALES ),
				array(
					'event_key'         => $key,
					'creator_id'        => self::MINE,
					'order_id'          => $order_id,
					'order_item_id'     => $item_id,
					'cycle'             => $cycle,
					'refund_id'         => $refund,
					'product_id'        => 20,
					'variation_id'      => 0,
					'item_name'         => 'Line ' . $item_id,
					'type'              => $type,
					'correction_reason' => $reason,
					'quantity'          => $quantity,
					'net_amount'        => (string) ( 50 * $quantity ) . '.0000',
					'currency'          => 'DKK',
					'occurred_at'       => '2026-07-01 10:00:00',
					'created_at'        => '2026-07-01 10:00:00',
				)
			);
		}

		foreach ( $cycles as $event ) {
			[ $cycle, $name ] = explode( ':', $event );
			$this->wpdb->insert(
				$this->table( Tables::SALES_CYCLES ),
				array(
					'order_id'    => $order_id,
					'cycle'       => (int) $cycle,
					'event'       => $name,
					'occurred_at' => '2026-07-01 10:00:00',
					'created_at'  => '2026-07-01 10:00:00',
				)
			);
		}
	}

	/**
	 * `[quantity, refunded, counted]` by order item id, in page order.
	 *
	 * @param int $creator_id Creator id.
	 * @return array<int, array{int, bool, bool}>
	 */
	private function states( int $creator_id ): array {
		$states = array();

		foreach ( $this->page( $creator_id, 1, 100 ) as $line ) {
			$states[ $line->order_item_id ] = array( $line->quantity, $line->refunded, $line->counted );
		}

		return $states;
	}

	/**
	 * One page of the creator's lines.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $page       Page.
	 * @param int $per_page   Page size.
	 * @return list<SalesOrderLine>
	 */
	private function page( int $creator_id, int $page = 1, int $per_page = 20 ): array {
		return $this->lines->page( $creator_id, new Pagination( $page, $per_page ) );
	}

	/**
	 * Event keys of an order in insertion order.
	 *
	 * @param int $order_id Order id.
	 * @return list<string>
	 */
	private function keys( int $order_id ): array {
		return array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT event_key FROM %i WHERE order_id = %d ORDER BY id', $this->table( Tables::SALES ), $order_id ) ) );
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
