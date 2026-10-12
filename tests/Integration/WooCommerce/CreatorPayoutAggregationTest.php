<?php
/**
 * Earned creator payout with settlement start and holding period on a real database (ADR-054).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitSummary;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesProfit;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntryType;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Modules\WooCommerce\Sales\SalesTaxRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * No WooCommerce is loaded (ADR-038 Q4): snapshots come from the real
 * recorder, ledger rows and their tax from the real synchronizer, with the
 * sale, refund and processing times set by each test. Every aggregate is
 * compared with the sum of SalesProfit::payout_for_entry() (RealizedPayout,
 * ADR-049) over the rows that D1 and D2 select, computed here in PHP, so the
 * SQL and the rules can never drift apart. Synthetic ids only.
 */
final class CreatorPayoutAggregationTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private CreatorMarkupRepository $markups;

	private CreatorVatRepository $vat;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesRepository $ledger;

	private SalesProfit $profit;

	private LedgerCreatorPayouts $payouts;

	private DateTimeImmutable $clock;

	private int $creator;

	private int $other;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Payout A', CreatorStatus::Active )->id;
		$this->other   = $creators->create( 'Payout B', CreatorStatus::Active )->id;

		$audit          = $this->service( AuditTrail::class );
		$transaction    = $this->service( Transaction::class );
		$this->posts    = new ProductPosts( $this->wpdb );
		$this->markups  = new CreatorMarkupRepository( $this->wpdb );
		$this->vat      = new CreatorVatRepository( $this->wpdb );
		$snapshots      = new SalesLinePricingRepository( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );
		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $this->markups, new ProductMarkupRepository( $this->wpdb ) ), $snapshots, new RecordingLogger(), $this->vat );
		$this->profit   = new SalesProfit( $snapshots, new SalesTaxRepository( $this->wpdb ) );
		$this->payouts  = new LedgerCreatorPayouts( $this->wpdb );
		$this->reader   = new FakeOrderReader();
		$this->ledger   = new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) );
		$this->clock    = self::at( '2026-10-09 12:00:00' );
		$this->sync     = new SalesSynchronizer(
			$this->ledger,
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			fn(): DateTimeImmutable => $this->clock
		);
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_every_aggregate_equals_the_sum_of_the_selected_row_payouts(): void {
		$product = $this->product( '99.99' );
		$cheap   = $this->product( '0.05' );

		// Order 1: not VAT registered, paid early, one unit refunded later; a line of creator B as well.
		$this->configure( '16.67', false );
		$this->recorder->record( 3011, 3001, $product, 0, 'DKK' );
		$lines = array( $this->line( 3011, $product, 3, '239.976', '59.994' ), $this->line( 3012, $product, 1, '79.992', '19.998', $this->other ) );
		$this->order( 3001, $lines, '2026-09-01 10:05:00' );
		$this->order( 3001, $lines, '2026-09-01 10:05:00', array( self::refund( 7401, 3011, -1, '-79.992', '-19.998', '2026-10-09 13:00:00' ) ) );

		// Order 2: VAT registered, paid inside the holding period, partly refunded the next day.
		$this->configure( '20', true );
		$this->recorder->record( 3021, 3002, $product, 0, 'DKK' );
		$line = $this->line( 3021, $product, 2, '159.984', '39.996' );
		$this->order( 3002, array( $line ), '2026-10-15 09:00:00' );
		$this->order( 3002, array( $line ), '2026-10-15 09:00:00', array( self::refund( 7402, 3021, -1, '-79.992', '-19.998', '2026-10-16 13:00:00' ) ) );

		// Order 3: paid early, cancelled, counted again later (cycle 2), with rounding-heavy amounts.
		$this->configure( '33.33', false );
		$this->recorder->record( 3031, 3003, $cheap, 0, 'DKK' );
		$line = $this->line( 3031, $cheap, 1, '0.04', '0.01' );
		$this->order( 3003, array( $line ), '2026-09-01 10:05:00' );
		$this->clock = self::at( '2026-10-01 08:00:00' );
		$this->order( 3003, array( $line ), '2026-09-01 10:05:00', array(), 'cancelled' );
		$this->clock = self::at( '2026-10-10 08:00:00' );
		$this->order( 3003, array( $line ), '2026-09-01 10:05:00' );

		$rows = $this->ledger_rows();
		self::assertGreaterThanOrEqual( 7, count( $rows ), 'Sales in two cycles, refunds and a reversal.' );
		self::assertNotContains( null, array_column( $rows, 'payout' ), 'Every row is known here.' );

		$starts = array( null, self::at( '2026-09-15 00:00:00' ), self::at( '2026-10-12 00:00:00' ) );
		$times  = array( self::at( '2026-10-20 12:00:00' ), self::at( '2026-11-30 12:00:00' ) );

		foreach ( $starts as $start ) {
			foreach ( $times as $now ) {
				foreach ( array( LedgerCreatorPayouts::DEFAULT_HOLDING_DAYS, 0, 30 ) as $days ) {
					$label    = ( null === $start ? 'no start' : $start->format( 'Y-m-d' ) ) . ', now ' . $now->format( 'Y-m-d' ) . ', ' . $days . ' days';
					$expected = self::expected( $rows, $start, $now, $days );
					$actual   = $this->payouts->earned( $this->creator, $start, $now, $days );

					if ( null === $expected ) {
						self::assertSame( array(), $actual, $label );
						continue;
					}

					self::assertSame( ProfitSummary::COMPLETE, $actual['DKK']->status(), $label );
					self::assertSame( $expected, $actual['DKK']->amount_units(), $label );
				}
			}
		}
	}

	public function test_d1_rows_follow_the_sale_of_their_cycle(): void {
		$product = $this->product( '100' );
		$this->configure( '20', true );

		// A sale before the start and its refund after the start: both left out.
		$this->recorder->record( 3111, 3101, $product, 0, 'DKK' );
		$line = $this->line( 3111, $product, 1, '80', '20' );
		$this->order( 3101, array( $line ), '2026-09-01 10:05:00', array( self::refund( 7411, 3111, -1, '-80', '-20', '2026-09-20 13:00:00' ) ) );

		// A sale before the start, cancelled before and counted again after the start: only cycle 2 counts.
		$this->recorder->record( 3121, 3102, $product, 0, 'DKK' );
		$line = $this->line( 3121, $product, 1, '80', '20' );
		$this->order( 3102, array( $line ), '2026-09-01 10:05:00' );
		$this->clock = self::at( '2026-09-10 08:00:00' );
		$this->order( 3102, array( $line ), '2026-09-01 10:05:00', array(), 'cancelled' );
		$this->clock = self::at( '2026-09-20 08:00:00' );
		$this->order( 3102, array( $line ), '2026-09-01 10:05:00' );

		$now   = self::at( '2026-11-30 12:00:00' );
		$start = self::at( '2026-09-15 00:00:00' );

		self::assertSame( '20.0000', $this->payouts->earned( $this->creator, $start, $now, 14 )['DKK']->to_array()['amount'], 'Only the sale of cycle 2.' );
		self::assertSame( '20.0000', $this->payouts->earned( $this->creator, null, $now, 14 )['DKK']->to_array()['amount'], 'Whole history: 20 − 20 + 20 − 20 + 20.' );
		self::assertSame( array(), $this->payouts->earned( $this->creator, self::at( '2026-09-25 00:00:00' ), $now, 14 ), 'No sale on or after the start: nothing earned.' );
	}

	public function test_d2_negative_rows_count_at_once_positive_rows_after_the_holding_period(): void {
		$product = $this->product( '100' );
		$this->configure( '20', true );
		$this->recorder->record( 3211, 3201, $product, 0, 'DKK' );
		$line = $this->line( 3211, $product, 2, '160', '40' );
		$this->order( 3201, array( $line ), '2026-10-15 09:00:00', array( self::refund( 7421, 3211, -1, '-80', '-20', '2026-10-16 13:00:00' ) ) );

		$held = $this->payouts->earned( $this->creator, null, self::at( '2026-10-20 12:00:00' ), 14 )['DKK'];
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'complete', 'amount' => '-20.0000' ), $held->to_array(), 'The refund counts while its sale is held: temporarily lower, never too high.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( '20.0000', $this->payouts->earned( $this->creator, null, self::at( '2026-10-29 09:00:01' ), 14 )['DKK']->to_array()['amount'], 'Released once 14 days have passed since the sale.' );
		self::assertSame( '-20.0000', $this->payouts->earned( $this->creator, null, self::at( '2026-10-29 09:00:00' ), 14 )['DKK']->to_array()['amount'], 'Exactly 14 days: still held (occurred_at < now − holding period).' );
		self::assertSame( '20.0000', $this->payouts->earned( $this->creator, null, self::at( '2026-10-20 12:00:00' ), 0 )['DKK']->to_array()['amount'], 'No holding period.' );
		self::assertSame( array(), $this->payouts->earned( $this->creator, null, self::at( '2026-10-15 08:00:00' ), 14 ), 'Before the sale: nothing.' );
	}

	public function test_the_payout_rule_rounds_like_creator_share(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 3311, 3301, $product, 0, 'DKK' );
		$this->configure( '20', true );
		$this->recorder->record( 3312, 3301, $product, 0, 'DKK' );
		$this->configure( '16.67', false );
		$this->recorder->record( 3313, 3301, $product, 0, 'DKK' );
		$this->order( 3301, array( $this->line( 3311, $product, 1, '80', '20' ), $this->line( 3312, $product, 1, '80', '20' ), $this->line( 3313, $product, 1, '0.4', '0.1' ) ), '2026-09-01 10:05:00' );

		// 20 × 0.8 = 16, 20, and 0.50 × 16.67 % = 0.08335 → 0.08, × 0.8 = 0.064 → 0.06.
		$earned = $this->payouts->earned( $this->creator, null, self::at( '2026-11-30 12:00:00' ), 14 )['DKK'];
		self::assertSame( '36.0600', $earned->to_array()['amount'] );
		self::assertSame( self::expected( $this->ledger_rows(), null, self::at( '2026-11-30 12:00:00' ), 14 ), $earned->amount_units() );
	}

	public function test_unknown_rows_block_never_0_and_never_skipped(): void {
		$product = $this->product( '100' );
		$this->configure( '20', true );
		$this->recorder->record( 3411, 3401, $product, 0, 'DKK' );
		$this->order( 3401, array( $this->line( 3411, $product, 1, '80', '20' ) ), '2026-09-01 10:05:00' );
		$now = self::at( '2026-11-30 12:00:00' );
		self::assertSame( '20.0000', $this->payouts->earned( $this->creator, null, $now, 14 )['DKK']->to_array()['amount'] );

		// A line without snapshot, still inside the holding period: it blocks all the same.
		$this->order( 3402, array( $this->line( 3421, $product, 1, '80', '20' ) ), '2026-11-29 10:05:00' );
		$blocked = $this->payouts->earned( $this->creator, null, $now, 14 )['DKK'];
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'incomplete', 'amount' => null ), $blocked->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 1, 1 ), array( $blocked->known_rows, $blocked->unknown_rows ) );
		self::assertSame( ProfitSummary::UNKNOWN, $this->payouts->earned( $this->creator, self::at( '2026-09-15 00:00:00' ), $now, 14 )['DKK']->status(), 'With a start only the unknown line is in the window.' );
	}

	public function test_a_profit_without_vat_status_has_an_unknown_payout(): void {
		$product = $this->product( '100' );
		$this->configure( '20', true );
		$this->recorder->record( 3511, 3501, $product, 0, 'DKK' );
		$this->order( 3501, array( $this->line( 3511, $product, 1, '80', '20' ) ), '2026-09-01 10:05:00' );
		// As a snapshot taken before schema version 7: the share is known, the VAT status is not.
		self::assertSame( 1, $this->wpdb->update( $this->table( Tables::SALES_LINE_PRICING ), array( 'vat_registered' => null ), array( 'order_item_id' => 3511 ) ) );

		$earned = $this->payouts->earned( $this->creator, null, self::at( '2026-11-30 12:00:00' ), 14 )['DKK'];
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'unknown', 'amount' => null ), $earned->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertNull( $this->profit->payout_for_entry( $this->ledger_rows()[0]['entry'] ) );
		self::assertNotNull( $this->profit->for_entry( $this->ledger_rows()[0]['entry'] ), 'The profit itself is known.' );
	}

	public function test_a_row_without_its_sale_is_unknown_only_with_a_start(): void {
		$product = $this->product( '100' );
		$this->configure( '20', true );
		$this->recorder->record( 3611, 3601, $product, 0, 'DKK' );
		$this->order( 3601, array( $this->line( 3611, $product, 2, '160', '40' ) ), '2026-09-01 10:05:00', array( self::refund( 7461, 3611, -1, '-80', '-20', '2026-10-01 13:00:00' ) ) );
		// Only possible by damage: the sale row of the cycle is gone, its refund remains.
		self::assertSame( 1, $this->wpdb->delete( $this->table( Tables::SALES ), array( 'event_key' => 's:3611:1' ) ) );
		$now = self::at( '2026-11-30 12:00:00' );

		self::assertSame( '-20.0000', $this->payouts->earned( $this->creator, null, $now, 14 )['DKK']->to_array()['amount'], 'Without a start no sale row is needed.' );
		$with_start = $this->payouts->earned( $this->creator, self::at( '2026-09-15 00:00:00' ), $now, 14 )['DKK'];
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'unknown', 'amount' => null ), $with_start->to_array(), 'The refund cannot be placed: unknown, not left out.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_only_the_creators_rows_and_a_valid_holding_period(): void {
		$product = $this->product( '100' );
		$this->configure( '20', true );
		$this->markups->save( $this->other, new MarkupBps( 9000 ) );
		$this->recorder->record( 3711, 3701, $product, 0, 'DKK' );
		$this->order( 3701, array( $this->line( 3711, $product, 1, '80', '20' ), $this->line( 3712, $product, 1, '80', '20', $this->other ) ), '2026-09-01 10:05:00' );
		$now = self::at( '2026-11-30 12:00:00' );

		self::assertSame( array( 'DKK' ), array_keys( $this->payouts->earned( $this->creator, null, $now, 14 ) ) );
		self::assertSame( '20.0000', $this->payouts->earned( $this->creator, null, $now, 14 )['DKK']->to_array()['amount'] );
		self::assertSame( ProfitSummary::UNKNOWN, $this->payouts->earned( $this->other, null, $now, 14 )['DKK']->status(), 'B\'s line has no snapshot.' );
		self::assertSame( array(), $this->payouts->earned( 999999, null, $now, 14 ) );
		self::assertSame( array(), $this->payouts->earned( 0, null, $now, 14 ) );

		$this->expectException( InvalidArgumentException::class );
		$this->payouts->earned( $this->creator, null, $now, -1 );
	}

	/**
	 * Earned payout by D1 and D2 in PHP, or null when no row is selected.
	 *
	 * @param list<array{entry: SalesEntry, payout: int|null}> $rows         Rows of the creator.
	 * @param DateTimeImmutable|null                           $start        Settlement start.
	 * @param DateTimeImmutable                                $now          Now.
	 * @param int                                              $holding_days Holding period.
	 */
	private static function expected( array $rows, ?DateTimeImmutable $start, DateTimeImmutable $now, int $holding_days ): ?int {
		$released = $now->sub( new DateInterval( 'P' . $holding_days . 'D' ) );
		$sales    = array();
		$sum      = null;

		foreach ( $rows as $row ) {
			if ( SalesEntryType::Sale === $row['entry']->type ) {
				$sales[ $row['entry']->order_item_id . ':' . $row['entry']->cycle ] = $row['entry']->occurred_at;
			}
		}

		foreach ( $rows as $row ) {
			$entry  = $row['entry'];
			$payout = (int) $row['payout'];
			$sale   = $sales[ $entry->order_item_id . ':' . $entry->cycle ];

			if ( null !== $start && $sale < $start ) {
				continue;
			}

			if ( $payout < 0 ? $entry->occurred_at <= $now : $entry->occurred_at < $released ) {
				$sum = (int) $sum + $payout;
			}
		}

		return $sum;
	}

	/**
	 * Sets the creator's markup and VAT status.
	 *
	 * @param string $percent        Markup.
	 * @param bool   $vat_registered VAT status.
	 */
	private function configure( string $percent, bool $vat_registered ): void {
		$this->markups->save( $this->creator, MarkupBps::from_percent( $percent ) );
		$this->vat->save( $this->creator, $vat_registered );
	}

	/**
	 * Sets an order and synchronizes it.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param int                 $order_id Order.
	 * @param OrderLineSnapshot[] $lines    Lines.
	 * @param string              $paid     Payment time (UTC), the time of the first sale.
	 * @param RefundSnapshot[]    $refunds  Refunds.
	 * @param string              $status   Status.
	 */
	private function order( int $order_id, array $lines, string $paid, array $refunds = array(), string $status = 'processing' ): void {
		$this->reader->orders[ $order_id ] = array(
			'status'    => $status,
			'currency'  => 'DKK',
			'date_paid' => $paid,
			'lines'     => $lines,
			'refunds'   => $refunds,
		);
		$this->sync->sync( $order_id );
	}

	/**
	 * Every ledger row of creator A with its payout (SalesProfit).
	 *
	 * @return list<array{entry: SalesEntry, payout: int|null}>
	 */
	private function ledger_rows(): array {
		$rows = array();

		foreach ( $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE creator_id = %d ORDER BY order_id', $this->table( Tables::SALES ), $this->creator ) ) as $order_id ) {
			foreach ( $this->ledger->entries_for_order( (int) $order_id ) as $entry ) {
				if ( $entry->creator_id === $this->creator ) {
					$rows[] = array(
						'entry'  => $entry,
						'payout' => $this->profit->payout_for_entry( $entry ),
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * An order line with WooCommerce' split into net excl. VAT and tax.
	 *
	 * @param int      $item     Order item.
	 * @param int      $product  Product.
	 * @param int      $quantity Quantity.
	 * @param string   $net      Amount excl. VAT.
	 * @param string   $tax      Actual tax.
	 * @param int|null $creator  Owner when the ledger reads the order (default: creator A).
	 */
	private function line( int $item, int $product, int $quantity, string $net, string $tax, ?int $creator = null ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, $product, 0, 'MB-PAY line', $quantity, SalesAmount::parse( $net ), $creator ?? $this->creator, SalesAmount::parse( $tax ) );
	}

	/**
	 * A refund of one line with its own tax.
	 *
	 * @param int    $id       Refund id.
	 * @param int    $item     Refunded order item.
	 * @param int    $quantity Quantity (≤ 0).
	 * @param string $net      Amount excl. VAT (≤ 0).
	 * @param string $tax      Refund line tax (≤ 0).
	 * @param string $time     Refund time (UTC).
	 */
	private static function refund( int $id, int $item, int $quantity, string $net, string $tax, string $time ): RefundSnapshot {
		return new RefundSnapshot( $id, self::at( $time ), array( new RefundLineSnapshot( $item, $quantity, SalesAmount::parse( $net ), SalesAmount::parse( $tax ) ) ) );
	}

	/**
	 * A product of creator A with a regular price.
	 *
	 * @param string $price Regular price.
	 */
	private function product( string $price ): int {
		$id = $this->posts->add( 'MB-PAY product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $id, '_regular_price', $price );
		$this->posts->meta( $id, '_sale_price', '' );
		$this->posts->meta( $id, '_price', $price );

		return $id;
	}

	/**
	 * A UTC time.
	 *
	 * @param string $time MySQL datetime.
	 */
	private static function at( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}
}
