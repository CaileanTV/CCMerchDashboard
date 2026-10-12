<?php
/**
 * The shared payout row rule of earned() and unsettled_rows() on a real database (K6.1).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
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
 * Characterizes which ledger rows count towards a creator's payout (ADR-054:
 * D1 settlement start, D2 holding period, unknown rows) in earned() and in
 * unsettled_rows() (ADR-060), written before the rule was shared between
 * them and kept unchanged after it (K6.1). Every result is compared with an
 * independent oracle in PHP: RealizedPayout per row (SalesProfit) over
 * exactly the rows that D1, D2 and "known" select, minus the event keys of
 * the settlement rows. "Now" is always a fixed parameter. A few results are
 * checked by hand as well.
 *
 * The ledger: sales, refunds, a reversal and a second cycle, a deleted refund
 * (correction), a changed line (correction), rows on and around the holding
 * and start boundaries to the second, an unknown line, a row without its
 * sale row, two currencies, two creators and one settlement document. Sales
 * of 100.00 incl. VAT at 20 % markup, VAT registered: payout 20.00 a unit.
 * No WooCommerce is loaded. Synthetic ids only.
 */
final class PayoutRowRuleTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private CreatorMarkupRepository $markups;

	private CreatorVatRepository $vat;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesRepository $ledger;

	private SalesProfit $profit;

	private LedgerCreatorPayouts $payouts;

	private PayoutRequests $requests;

	private PayoutDecisions $decisions;

	private DateTimeImmutable $clock;

	/**
	 * Creator ids by key.
	 *
	 * @var array<string, int>
	 */
	private array $creators = array();

	/**
	 * Product ids by creator key.
	 *
	 * @var array<string, int>
	 */
	private array $products = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$repo          = new CreatorRepository( $this->wpdb );
		$this->markups = new CreatorMarkupRepository( $this->wpdb );
		$this->vat     = new CreatorVatRepository( $this->wpdb );
		$this->posts   = new ProductPosts( $this->wpdb );

		foreach ( array( 'A', 'B' ) as $key ) {
			$this->creators[ $key ] = $repo->create( 'Row rule ' . $key, CreatorStatus::Active )->id;
			$this->configure( $key, '20', true );
			$this->products[ $key ] = $this->posts->add( 'MB-K61 product ' . $key, 'publish', array( (string) $this->creators[ $key ] ) );
			$this->posts->meta( $this->products[ $key ], '_regular_price', '100' );
			$this->posts->meta( $this->products[ $key ], '_sale_price', '' );
			$this->posts->meta( $this->products[ $key ], '_price', '100' );
		}

		$audit          = $this->service( AuditTrail::class );
		$transaction    = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$snapshots      = new SalesLinePricingRepository( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $repo, $audit, $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $this->markups, new ProductMarkupRepository( $this->wpdb ) ), $snapshots, new RecordingLogger(), $this->vat );
		$this->profit   = new SalesProfit( $snapshots, new SalesTaxRepository( $this->wpdb ) );
		$this->payouts  = new LedgerCreatorPayouts( $this->wpdb );
		$this->reader   = new FakeOrderReader();
		$this->ledger   = new SalesRepository( $this->wpdb, $transaction );
		$this->clock    = self::at( '2026-09-01 12:00:00' );
		$clock          = fn(): DateTimeImmutable => $this->clock;
		$this->sync     = new SalesSynchronizer( $this->ledger, new SalesPlanner(), $this->reader, new RecordingFailureMarker(), new RecordingLogger(), $clock );

		$cipher          = new PayoutCipher( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$repository      = new PayoutRepository( $this->wpdb );
		$balances        = new PayoutBalances( $this->payouts, $repository, $clock );
		$documents       = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $repository, $this->payouts, $repo, $this->vat, $audit, $transaction, $clock );
		$this->decisions = new PayoutDecisions( $repository, $balances, $cipher, $audit, $transaction, $clock, $documents );
		$this->requests  = new PayoutRequests( $repository, $balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_earned_and_unsettled_rows_equal_the_oracle_for_every_window_time_and_holding_period(): void {
		$this->build();

		$types = array_count_values( array_map( static fn( array $row ): string => $row['entry']->type->value, $this->ledger_rows( 'A' ) ) );
		self::assertSame( array( 'correction', 'refund', 'reversal', 'sale' ), self::sorted_keys( $types ), 'Every row type is in the ledger.' );
		self::assertGreaterThan( 0, count( $this->settled() ), 'Settled rows exist.' );

		$starts = array( null, '2026-08-15 00:00:00', '2026-09-15 00:00:00', '2026-11-01 12:00:00', '2026-11-01 12:00:01' );
		$times  = array( '2026-10-20 12:00:00', '2026-11-03 11:59:59', '2026-11-03 12:00:00', '2026-11-03 12:00:01', '2026-11-15 11:59:59', '2026-11-15 12:00:00', '2026-11-15 12:00:01', '2026-12-31 12:00:00' );
		$checks = 0;

		foreach ( array( 'A', 'B' ) as $creator ) {
			$rows = $this->ledger_rows( $creator );

			foreach ( $starts as $start_text ) {
				$start = null === $start_text ? null : self::at( $start_text );

				foreach ( $times as $time ) {
					$now = self::at( $time );

					foreach ( array( LedgerCreatorPayouts::DEFAULT_HOLDING_DAYS, 0, 30 ) as $days ) {
						$label = $creator . ', start ' . ( $start_text ?? 'none' ) . ', now ' . $time . ', ' . $days . ' days';
						self::assertEquals( self::expected_earned( $rows, $start, $now, $days ), $this->payouts->earned( $this->creators[ $creator ], $start, $now, $days ), 'earned(): ' . $label );

						foreach ( array( 'DKK', 'EUR' ) as $currency ) {
							self::assertSame(
								$this->expected_unsettled( $rows, $currency, $start, $now, $days ),
								self::unsettled_view( $this->payouts->unsettled_rows( $this->creators[ $creator ], $currency, $start, $now, $days ) ),
								'unsettled_rows(): ' . $label . ', ' . $currency
							);
							++$checks;
						}
					}
				}
			}
		}

		self::assertSame( 2 * 5 * 8 * 3 * 2, $checks );
	}

	public function test_hand_checked_boundaries_and_values(): void {
		$this->build();
		$a    = $this->creators['A'];
		$dkk  = fn( string $now, ?string $start = null, int $days = 14 ): ?int => ( $this->payouts->earned( $a, null === $start ? null : self::at( $start ), self::at( $now ), $days )['DKK'] ?? null )?->amount_units();
		$keys = fn( string $now, ?string $start = null ): ?array => self::keys( $this->payouts->unsettled_rows( $a, 'DKK', null === $start ? null : self::at( $start ), self::at( $now ), 14 ) );

		// D2, positive rows: the sale of 4004 (2 units, 2026-11-01 12:00) is held until 14 days have passed.
		self::assertSame( 0, (int) $dkk( '2026-11-15 12:00:00' ) - (int) $dkk( '2026-11-15 11:59:59' ), 'Exactly 14 days: still held.' );
		self::assertSame( 400000, (int) $dkk( '2026-11-15 12:00:01' ) - (int) $dkk( '2026-11-15 12:00:00' ), 'One second later: 40.00 released.' );

		// D2, negative rows: the refund of 4004 (2026-11-03 12:00) counts from its own time.
		self::assertSame( -200000, (int) $dkk( '2026-11-03 12:00:00' ) - (int) $dkk( '2026-11-03 11:59:59' ), 'The refund counts at its own second.' );
		self::assertSame( 0, (int) $dkk( '2026-11-03 12:00:01' ) - (int) $dkk( '2026-11-03 12:00:00' ) );

		// D1: a start on the sale's second includes it, one second later excludes it with its refund.
		self::assertSame( 200000, $dkk( '2026-12-31 12:00:00', '2026-11-01 12:00:00' ), '4004 only: 40.00 − 20.00.' );
		self::assertSame( array(), $this->payouts->earned( $a, self::at( '2026-11-01 12:00:01' ), self::at( '2026-12-31 12:00:00' ), 14 ), 'Nothing in the window; the unknown EUR line is before the start.' );

		// Unknown rows: the EUR line without snapshot blocks, held or not, unless the start leaves it out.
		self::assertSame( ProfitSummary::INCOMPLETE, $this->payouts->earned( $a, null, self::at( '2026-12-31 12:00:00' ), 14 )['EUR']->status() );
		self::assertSame( ProfitSummary::COMPLETE, $this->payouts->earned( $a, self::at( '2026-08-15 00:00:00' ), self::at( '2026-12-31 12:00:00' ), 14 )['EUR']->status() );
		self::assertNull( $this->payouts->unsettled_rows( $a, 'EUR', null, self::at( '2026-12-31 12:00:00' ), 14 ) );
		self::assertNull( $this->payouts->unsettled_rows( $a, 'EUR', null, self::at( '2026-08-01 10:00:00' ), 14 ), 'Unknown while it is still held: null all the same.' );

		// Settled rows are never unsettled; a later refund of a settled sale is.
		self::assertSame( array( 's:14004:1', 'r:14001:7412', 'r:14004:7404' ), $keys( '2026-12-31 12:00:00' ) );
		self::assertSame( array( 'r:14001:7412' ), $keys( '2026-11-03 11:59:59' ), 'Before the refund of 4004 and before the sale is released.' );
		self::assertSame( array(), $keys( '2026-10-20 12:00:00' ), 'Everything released at the settlement is settled.' );

		// A row without its sale row: known without a start, unknown with one (creator B).
		$b = $this->creators['B'];
		self::assertSame( ProfitSummary::COMPLETE, $this->payouts->earned( $b, null, self::at( '2026-12-31 12:00:00' ), 14 )['DKK']->status() );
		self::assertSame( ProfitSummary::UNKNOWN, $this->payouts->earned( $b, self::at( '2026-11-01 00:00:00' ), self::at( '2026-12-31 12:00:00' ), 14 )['DKK']->status() );
		self::assertNull( $this->payouts->unsettled_rows( $b, 'DKK', self::at( '2026-08-15 00:00:00' ), self::at( '2026-12-31 12:00:00' ), 14 ) );
	}

	/**
	 * Builds the ledger described in the class comment.
	 */
	private function build(): void {
		// 4001 (A): two units paid 2026-09-01, one refunded 2026-10-01 (settled) and one 2026-11-02 (not).
		$this->recorder->record( 14001, 4001, $this->products['A'], 0, 'DKK' );
		$this->order( 4001, 14001, 'A', 'DKK', 2, '2026-09-01 10:00:00', array( self::refund( 7401, 14001, '2026-10-01 10:00:00' ) ) );

		// 4002 (A): paid, cancelled 2026-09-10 (reversal), counted again 2026-09-20 (cycle 2).
		$this->recorder->record( 14002, 4002, $this->products['A'], 0, 'DKK' );
		$this->order( 4002, 14002, 'A', 'DKK', 1, '2026-09-01 10:00:00' );
		$this->clock = self::at( '2026-09-10 08:00:00' );
		$this->order( 4002, 14002, 'A', 'DKK', 1, '2026-09-01 10:00:00', array(), 'cancelled' );
		$this->clock = self::at( '2026-09-20 08:00:00' );
		$this->order( 4002, 14002, 'A', 'DKK', 1, '2026-09-01 10:00:00' );

		// 4003 (A): a refund of 2026-09-05 deleted again 2026-09-25 (correction).
		$this->recorder->record( 14003, 4003, $this->products['A'], 0, 'DKK' );
		$this->order( 4003, 14003, 'A', 'DKK', 2, '2026-09-01 10:00:00', array( self::refund( 7403, 14003, '2026-09-05 10:00:00' ) ) );
		$this->clock = self::at( '2026-09-25 08:00:00' );
		$this->order( 4003, 14003, 'A', 'DKK', 2, '2026-09-01 10:00:00' );

		// 4006 (A): not VAT registered at 16.67 % (rounding), the line reduced to one unit 2026-09-26 (correction).
		$this->configure( 'A', '16.67', false );
		$this->recorder->record( 14006, 4006, $this->products['A'], 0, 'DKK' );
		$this->configure( 'A', '20', true );
		$this->order( 4006, 14006, 'A', 'DKK', 2, '2026-09-02 10:00:00' );
		$this->clock = self::at( '2026-09-26 08:00:00' );
		$this->order( 4006, 14006, 'A', 'DKK', 1, '2026-09-02 10:00:00' );

		// EUR (A): 4011 known; 4012 has no snapshot and is unknown, paid before the earliest start.
		$this->recorder->record( 14011, 4011, $this->products['A'], 0, 'EUR' );
		$this->order( 4011, 14011, 'A', 'EUR', 1, '2026-09-01 10:00:00' );
		$this->order( 4012, 14012, 'A', 'EUR', 1, '2026-08-01 10:00:00' );

		// B: 4021 with a refund; 4022's refund stays after its sale row is gone (only possible by damage).
		$this->recorder->record( 14021, 4021, $this->products['B'], 0, 'DKK' );
		$this->order( 4021, 14021, 'B', 'DKK', 2, '2026-09-01 10:00:00', array( self::refund( 7421, 14021, '2026-10-01 10:00:00' ) ) );
		$this->recorder->record( 14022, 4022, $this->products['B'], 0, 'DKK' );
		$this->order( 4022, 14022, 'B', 'DKK', 2, '2026-09-03 10:00:00', array( self::refund( 7422, 14022, '2026-09-10 10:00:00' ) ) );
		self::assertSame( 1, $this->wpdb->delete( $this->table( Tables::SALES ), array( 'event_key' => 's:14022:1' ) ) );

		// AFR-000001 (A, DKK) on 2026-10-20 settles every row released then.
		$this->clock = self::at( '2026-10-20 12:00:00' );
		$payout      = $this->requests->request( $this->creators['A'], 'DKK', '10', 'bank', array( 'registration' => '1234', 'account' => '0012345678' ), 'key-k61-0001', 1 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->decisions->complete( $payout->id );

		// After the document: 4004 (A) on the boundaries, and a second refund of settled 4001.
		$this->clock = self::at( '2026-11-01 12:00:00' );
		$this->recorder->record( 14004, 4004, $this->products['A'], 0, 'DKK' );
		$this->order( 4004, 14004, 'A', 'DKK', 2, '2026-11-01 12:00:00' );
		$this->order( 4004, 14004, 'A', 'DKK', 2, '2026-11-01 12:00:00', array( self::refund( 7404, 14004, '2026-11-03 12:00:00' ) ) );
		$this->order( 4001, 14001, 'A', 'DKK', 2, '2026-09-01 10:00:00', array( self::refund( 7401, 14001, '2026-10-01 10:00:00' ), self::refund( 7412, 14001, '2026-11-02 10:00:00' ) ) );
	}

	/**
	 * Earned payout per currency by D1, D2 and "known" in PHP.
	 *
	 * @param list<array{entry: SalesEntry, payout: int|null, profit: int|null}> $rows         Rows of one creator.
	 * @param DateTimeImmutable|null                                             $start        Settlement start.
	 * @param DateTimeImmutable                                                  $now          Now.
	 * @param int                                                                $holding_days Holding period.
	 * @return array<string, ProfitSummary>
	 */
	private static function expected_earned( array $rows, ?DateTimeImmutable $start, DateTimeImmutable $now, int $holding_days ): array {
		$sums = array();

		foreach ( self::in_window( $rows, $start ) as $row ) {
			$currency          = $row['entry']->currency;
			$sums[ $currency ] = $sums[ $currency ] ?? array( 0, 0, 0 );

			if ( ! $row['known'] ) {
				++$sums[ $currency ][2];
			} elseif ( self::released( (int) $row['payout'], $row['entry']->occurred_at, $now, $holding_days ) ) {
				$sums[ $currency ][0] += (int) $row['payout'];
				++$sums[ $currency ][1];
			}
		}

		$result = array();
		ksort( $sums );

		foreach ( $sums as $currency => $sum ) {
			if ( $sum[1] + $sum[2] > 0 ) {
				$result[ $currency ] = new ProfitSummary( $currency, $sum[0], $sum[1], $sum[2] );
			}
		}

		return $result;
	}

	/**
	 * The released rows of one currency in the window that no settlement row
	 * holds, in ledger order, or null when any of them is unknown.
	 *
	 * @param list<array{entry: SalesEntry, payout: int|null, profit: int|null}> $rows         Rows of one creator.
	 * @param string                                                             $currency     Currency.
	 * @param DateTimeImmutable|null                                             $start        Settlement start.
	 * @param DateTimeImmutable                                                  $now          Now.
	 * @param int                                                                $holding_days Holding period.
	 * @return list<array<string, int|string>>|null
	 */
	private function expected_unsettled( array $rows, string $currency, ?DateTimeImmutable $start, DateTimeImmutable $now, int $holding_days ): ?array {
		$settled  = array_flip( $this->settled() );
		$selected = array();

		foreach ( self::in_window( $rows, $start ) as $row ) {
			$entry = $row['entry'];

			if ( $entry->currency !== $currency || isset( $settled[ $entry->event_key ] ) ) {
				continue;
			}

			if ( ! $row['known'] ) {
				return null;
			}

			if ( self::released( (int) $row['payout'], $entry->occurred_at, $now, $holding_days ) ) {
				$selected[] = $row;
			}
		}

		usort( $selected, static fn( array $a, array $b ): int => array( $a['entry']->occurred_at, $a['entry']->id ) <=> array( $b['entry']->occurred_at, $b['entry']->id ) );

		return array_map(
			static fn( array $row ): array => array(
				'event_key'     => $row['entry']->event_key,
				'order_id'      => $row['entry']->order_id,
				'order_item_id' => $row['entry']->order_item_id,
				'type'          => $row['entry']->type->value,
				'quantity'      => $row['entry']->quantity,
				'net_units'     => SalesAmount::parse( $row['entry']->net_amount ),
				'profit_units'  => (int) $row['profit'],
				'payout_units'  => (int) $row['payout'],
				'occurred_at'   => $row['entry']->occurred_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			),
			$selected
		);
	}

	/**
	 * D1: the rows in the window, each with whether its payout is known (a
	 * row without its sale row can only be placed without a start).
	 *
	 * @param list<array{entry: SalesEntry, payout: int|null, profit: int|null}> $rows  Rows of one creator.
	 * @param DateTimeImmutable|null                                             $start Settlement start.
	 * @return list<array{entry: SalesEntry, payout: int|null, profit: int|null, known: bool}>
	 */
	private static function in_window( array $rows, ?DateTimeImmutable $start ): array {
		$sales  = array();
		$result = array();

		foreach ( $rows as $row ) {
			if ( SalesEntryType::Sale === $row['entry']->type ) {
				$sales[ $row['entry']->order_item_id . ':' . $row['entry']->cycle ] = $row['entry']->occurred_at;
			}
		}

		foreach ( $rows as $row ) {
			$sale = $sales[ $row['entry']->order_item_id . ':' . $row['entry']->cycle ] ?? null;

			if ( null !== $start && null !== $sale && $sale < $start ) {
				continue;
			}

			$result[] = $row + array( 'known' => null !== $row['payout'] && ( null === $start || null !== $sale ) );
		}

		return $result;
	}

	/**
	 * D2: a negative row counts from its own time, a row ≥ 0 once the holding
	 * period has passed.
	 *
	 * @param int               $payout       Payout units.
	 * @param DateTimeImmutable $occurred     The row's time.
	 * @param DateTimeImmutable $now          Now.
	 * @param int               $holding_days Holding period.
	 */
	private static function released( int $payout, DateTimeImmutable $occurred, DateTimeImmutable $now, int $holding_days ): bool {
		return $payout < 0 ? $occurred <= $now : $occurred < $now->sub( new DateInterval( 'P' . $holding_days . 'D' ) );
	}

	/**
	 * The compared fields of unsettled_rows().
	 *
	 * @param list<array<string, mixed>>|null $rows Rows.
	 * @return list<array<string, mixed>>|null
	 */
	private static function unsettled_view( ?array $rows ): ?array {
		if ( null === $rows ) {
			return null;
		}

		$fields = array_flip( array( 'event_key', 'order_id', 'order_item_id', 'type', 'quantity', 'net_units', 'profit_units', 'payout_units', 'occurred_at' ) );

		return array_map( static fn( array $row ): array => array_intersect_key( $row, $fields ), $rows );
	}

	/**
	 * Event keys of unsettled_rows(), or null.
	 *
	 * @param list<array<string, mixed>>|null $rows Rows.
	 * @return list<mixed>|null
	 */
	private static function keys( ?array $rows ): ?array {
		return null === $rows ? null : array_column( $rows, 'event_key' );
	}

	/**
	 * Sorted keys of a count map.
	 *
	 * @param array<string, int> $counts Counts.
	 * @return list<string>
	 */
	private static function sorted_keys( array $counts ): array {
		$keys = array_map( 'strval', array_keys( $counts ) );
		sort( $keys );

		return $keys;
	}

	/**
	 * Event keys of every settlement row.
	 *
	 * @return list<string>
	 */
	private function settled(): array {
		return array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT event_key FROM %i ORDER BY event_key', $this->table( Tables::SETTLEMENT_ROWS ) ) ) );
	}

	/**
	 * Every ledger row of a creator with its payout and profit (SalesProfit).
	 *
	 * @param string $creator Creator key.
	 * @return list<array{entry: SalesEntry, payout: int|null, profit: int|null}>
	 */
	private function ledger_rows( string $creator ): array {
		$rows = array();
		$id   = $this->creators[ $creator ];

		foreach ( $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE creator_id = %d ORDER BY order_id', $this->table( Tables::SALES ), $id ) ) as $order_id ) {
			foreach ( $this->ledger->entries_for_order( (int) $order_id ) as $entry ) {
				if ( $entry->creator_id === $id ) {
					$rows[] = array(
						'entry'  => $entry,
						'payout' => $this->profit->payout_for_entry( $entry ),
						'profit' => $this->profit->for_entry( $entry ),
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * Sets a creator's markup and VAT status.
	 *
	 * @param string $creator        Creator key.
	 * @param string $percent        Markup.
	 * @param bool   $vat_registered VAT status.
	 */
	private function configure( string $creator, string $percent, bool $vat_registered ): void {
		$this->markups->save( $this->creators[ $creator ], MarkupBps::from_percent( $percent ) );
		$this->vat->save( $this->creators[ $creator ], $vat_registered );
	}

	/**
	 * Sets an order of one line (100.00 incl. VAT a unit) and synchronizes it
	 * at the test clock.
	 *
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param int              $order_id Order.
	 * @param int              $item     Order item.
	 * @param string           $creator  Creator key.
	 * @param string           $currency Currency.
	 * @param int              $quantity Quantity.
	 * @param string           $paid     Payment time (UTC).
	 * @param RefundSnapshot[] $refunds  Refunds.
	 * @param string           $status   Status.
	 */
	private function order( int $order_id, int $item, string $creator, string $currency, int $quantity, string $paid, array $refunds = array(), string $status = 'processing' ): void {
		$this->reader->orders[ $order_id ] = array(
			'status'    => $status,
			'currency'  => $currency,
			'date_paid' => $paid,
			'lines'     => array( new OrderLineSnapshot( $item, $this->products[ $creator ], 0, 'MB-K61 line', $quantity, SalesAmount::parse( (string) ( 80 * $quantity ) ), $this->creators[ $creator ], SalesAmount::parse( (string) ( 20 * $quantity ) ) ) ),
			'refunds'   => $refunds,
		);
		$this->sync->sync( $order_id );
	}

	/**
	 * A refund of one unit (80.00 + 20.00 tax).
	 *
	 * @param int    $id   Refund id.
	 * @param int    $item Order item.
	 * @param string $time Refund time (UTC).
	 */
	private static function refund( int $id, int $item, string $time ): RefundSnapshot {
		return new RefundSnapshot( $id, self::at( $time ), array( new RefundLineSnapshot( $item, -1, SalesAmount::parse( '-80' ), SalesAmount::parse( '-20' ) ) ) );
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
