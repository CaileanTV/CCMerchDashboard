<?php
/**
 * Quality check of bin/mb-245-cutover-report.php (step 24.5, review material) against the plugin's own
 * calculation, on the throwaway integration database only (synthetic data; never Local or production).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
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
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use RuntimeException;

if ( ! defined( 'MERCHBOARD_CUTOVER_NO_MAIN' ) ) {
	define( 'MERCHBOARD_CUTOVER_NO_MAIN', true );
}

require_once dirname( __DIR__, 3 ) . '/bin/mb-245-cutover-report.php';

/**
 * Creators A (20 %), B (10 %) and C (no snapshot: unknown), all VAT registered; products at 100.00
 * incl. VAT (80 + 20). Now = 2026-11-30 12:00 UTC, holding 14 days, cut-over 2026-10-01.
 */
final class CutoverReportTest extends IntegrationTestCase {

	private const NOW = '2026-11-30 12:00:00';

	private const CUTOVER = '2026-10-01 00:00:00';

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private PayoutRequests $requests;

	private PayoutDecisions $decisions;

	/**
	 * Products by creator key.
	 *
	 * @var array<string, int>
	 */
	private array $products = array();

	/**
	 * Creator ids by key.
	 *
	 * @var array<string, int>
	 */
	private array $creators = array();

	private int $item = 50000;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$repo    = new CreatorRepository( $this->wpdb );
		$markups = new CreatorMarkupRepository( $this->wpdb );
		$vat     = new CreatorVatRepository( $this->wpdb );

		foreach ( array( 'A' => 2000, 'B' => 1000, 'C' => 2000 ) as $key => $bps ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->creators[ $key ] = $repo->create( 'Cutover ' . $key, CreatorStatus::Active )->id;
			$markups->save( $this->creators[ $key ], new MarkupBps( $bps ) );
			$vat->save( $this->creators[ $key ], true );
		}

		$transaction    = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$this->posts    = new ProductPosts( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $repo, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $vat );
		$this->reader   = new FakeOrderReader();
		$this->sync     = new SalesSynchronizer( new SalesRepository( $this->wpdb, $transaction ), new SalesPlanner(), $this->reader, new RecordingFailureMarker(), new RecordingLogger(), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) ) );

		foreach ( $this->creators as $key => $id ) {
			$this->products[ $key ] = $this->posts->add( 'MB-245 product ' . $key, 'publish', array( (string) $id ) );
			$this->posts->meta( $this->products[ $key ], '_price', '100' );
		}

		$clock           = static fn(): DateTimeImmutable => new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) );
		$audit           = $this->service( AuditTrail::class );
		$cipher          = new PayoutCipher( base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$payouts         = new PayoutRepository( $this->wpdb );
		$balances        = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $payouts, $clock );
		$documents       = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $payouts, new LedgerCreatorPayouts( $this->wpdb ), $repo, $vat, $audit, $transaction, $clock );
		$this->decisions = new PayoutDecisions( $payouts, $balances, $cipher, $audit, $transaction, $clock, $documents );
		$this->requests  = new PayoutRequests( $payouts, $balances, $cipher, new PostMetaPricingProductStore(), $audit, $transaction, $clock );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		$this->execute( 'DROP TABLE IF EXISTS %i', $this->wpdb->prefix . 'wc_order_operational_data' );
		delete_option( 'woocommerce_custom_orders_table_enabled' );
		parent::tearDown();
	}

	public function test_the_report_matches_the_plugin_today_and_the_simulated_rules(): void {
		// 1. Settled: A's 9101 and 9107 in AFR-000001 (payout 40.00 completed).
		$this->order( 9101, array( 'A' ), '2026-09-01 10:00:00' );
		$i9107 = $this->order( 9107, array( 'A' ), '2026-10-20 10:00:00' );
		$this->decisions->complete( $this->requests->request( $this->creators['A'], 'DKK', '40', 'bank', array( 'registration' => '1234', 'account' => '0012345678' ), 'key-mb245-0001', 1 )->id ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// 2. After the document.
		$this->order( 9102, array( 'B' ), '2026-09-01 10:00:00' );
		$this->order( 9103, array( 'A', 'B' ), '2026-10-20 10:00:00' );
		$i9104 = $this->order( 9104, array( 'A' ), '2026-10-20 10:00:00' );
		$this->order( 9104, array( 'A' ), '2026-10-20 10:00:00', array( $this->refund( 7104, '2026-10-25 10:00:00', $i9104[0], '-80', '-20' ) ), $i9104 );
		$i9105 = $this->order( 9105, array( 'A' ), '2026-10-20 10:00:00' );
		$this->order( 9105, array( 'A' ), '2026-10-20 10:00:00', array( $this->refund( 7105, '2026-10-25 10:00:00', $i9105[0], '-40', '-10' ) ), $i9105 );
		$this->order( 9107, array( 'A' ), '2026-10-20 10:00:00', array( $this->refund( 7107, '2026-11-20 10:00:00', $i9107[0], '-80', '-20' ) ), $i9107 );
		$this->order( 9108, array( 'A' ), '2026-11-25 10:00:00' );
		$this->order( 9109, array( 'C' ), '2026-09-01 10:00:00', array(), null, false );
		// 9110 (B): paid, cancelled and reopened (cycle 2 at the sync clock 2026-10-09).
		$i9110 = $this->order( 9110, array( 'B' ), '2026-09-01 10:00:00' );
		$this->order( 9110, array( 'B' ), '2026-09-01 10:00:00', array(), $i9110, true, 'cancelled' );
		$this->order( 9110, array( 'B' ), '2026-09-01 10:00:00', array(), $i9110, true, 'processing' );

		// 3. A pending payout of 5.00 (A's balance today: 50 − 40 = 10).
		$this->requests->request( $this->creators['A'], 'DKK', '5', 'bank', array( 'registration' => '1234', 'account' => '0012345678' ), 'key-mb245-0002', 1 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// WooCommerce paid dates (legacy post meta): 9103 paid earlier than its ledger row; 9108 has none.
		foreach ( array(
			9101 => '2026-09-01 10:00:00',
			9102 => '2026-09-01 10:00:00',
			9103 => '2026-09-25 10:00:00',
			9104 => '2026-10-20 10:00:00',
			9105 => '2026-10-20 10:00:00',
			9107 => '2026-10-20 10:00:00',
			9109 => '2026-09-01 10:00:00',
			9110 => '2026-09-01 10:00:00',
		) as $order => $paid ) {
			add_post_meta( $order, '_date_paid', (string) ( new DateTimeImmutable( $paid, new DateTimeZone( 'UTC' ) ) )->getTimestamp() );
		}

		$data     = \merchboard_cutover_collect( $this->wpdb );
		$now      = self::NOW;
		$released = '2026-11-16 12:00:00';
		$key      = fn( string $c ): string => $this->creators[ $c ] . '|DKK';

		// Today's rules equal the plugin's own LedgerCreatorPayouts::earned() for every creator.
		$today = \merchboard_cutover_earned( $data, $now, $released, null );
		$clock = new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) );

		foreach ( $this->creators as $c => $id ) {
			$plugin = ( new LedgerCreatorPayouts( $this->wpdb ) )->earned( $id, null, $clock, 14 )['DKK'] ?? null;
			self::assertSame( $plugin?->amount_units(), $today[ $key( $c ) ], "Creator {$c}: the report equals the plugin today." );
		}

		self::assertSame( array( 500000, 300000, null ), array( $today[ $key( 'A' ) ], $today[ $key( 'B' ) ], $today[ $key( 'C' ) ] ), 'A 50.00, B 30.00, C unknown.' );

		$defs = \merchboard_cutover_definitions( $data, self::CUTOVER );

		// 1a: first sale row of the order.
		$r = \merchboard_cutover_option( $data, $now, $released, $defs['1a first sale row of the order'] );
		self::assertSame( array( 5, 2 ), array( $r['orders'], $r['creators'] ), '9103 (A+B, once), 9104, 9105, 9107, 9108; creators A and B.' );
		$dkk = $r['currencies']['DKK'];
		self::assertSame( 3, $dkk['creators'] );
		self::assertSame( 1, $dkk['unknown'], 'C is unknown and not in the sums.' );
		self::assertSame( array( 800000, 400000 ), array( $dkk['earned_before'], $dkk['earned_after'] ), 'A 50 → 20 (settled 9107 sale stays, its refund −20 counts, the rest 0); B 30 → 20.' );
		self::assertSame( array( 350000, 200000 ), array( $dkk['available_before'], $dkk['available_after'] ), 'A 5 → 0 (balance −25); B 30 → 20.' );
		self::assertSame( array( 1, 50000, 250000 ), array( $dkk['newly_uncovered_n'], $dkk['newly_uncovered'], $dkk['shortfall'] ), "A's pending 5.00 becomes uncovered, shortfall 25.00." );
		self::assertSame( array( 3, 3, -500000, 2, 1, -200000 ), array( $dkk['refund_orders'], $dkk['refund_rows'], $dkk['refund_amount'], $dkk['neutralised_orders'], $dkk['negative_net_orders'], $dkk['negative_net_amount'] ), '9104 (net 0) and 9105 (net +10) neutralised; 9107 (refund of a settled sale) −20.' );

		// 1b: per cycle; reopened 9110 (cycle 2 on 2026-10-09) is new.
		$r   = \merchboard_cutover_option( $data, $now, $released, $defs['1b sale row of the cycle (reopened = new)'] );
		$dkk = $r['currencies']['DKK'];
		self::assertSame( array( 6, 2 ), array( $r['orders'], $r['creators'] ), '9110 joins.' );
		self::assertSame( array( 800000, 300000 ), array( $dkk['earned_before'], $dkk['earned_after'] ), "B 30 → 10: 9110's cycle 2 sale (+10) no longer counts." );
		self::assertSame( array( 350000, 100000 ), array( $dkk['available_before'], $dkk['available_after'] ), 'A 5 → 0; B 30 → 10.' );
		self::assertSame( array( 1, 50000, 250000 ), array( $dkk['newly_uncovered_n'], $dkk['newly_uncovered'], $dkk['shortfall'] ), 'As 1a for A.' );
		self::assertSame( array( 3, 3, -500000, 2, 1, -200000 ), array( $dkk['refund_orders'], $dkk['refund_rows'], $dkk['refund_amount'], $dkk['neutralised_orders'], $dkk['negative_net_orders'], $dkk['negative_net_amount'] ), "9110's new cycle has no refund." );

		// 2: WooCommerce paid date; 9103 paid before the cut-over, 9108 without a date is unmarked.
		$r   = \merchboard_cutover_option( $data, $now, $released, $defs['2  WooCommerce paid date (no date = unmarked)'] );
		$dkk = $r['currencies']['DKK'];
		self::assertSame( array( 4, 1 ), array( $r['orders'], $r['creators'] ), '9104, 9105, 9107, 9108; only A.' );
		self::assertSame( array( 800000, 700000 ), array( $dkk['earned_before'], $dkk['earned_after'] ), 'A 50 → 40 (9103 counts), B 30 unchanged.' );
		self::assertSame( array( 350000, 300000 ), array( $dkk['available_before'], $dkk['available_after'] ), 'A 5 → 0; B 30 unchanged.' );
		self::assertSame( array( 1, 50000, 50000 ), array( $dkk['newly_uncovered_n'], $dkk['newly_uncovered'], $dkk['shortfall'] ), 'A balance 40 − 45 = −5.' );
		self::assertSame( array( 3, 3, -500000, 2, 1, -200000 ), array( $dkk['refund_orders'], $dkk['refund_rows'], $dkk['refund_amount'], $dkk['neutralised_orders'], $dkk['negative_net_orders'], $dkk['negative_net_amount'] ), 'The same refunded orders are unmarked.' );

		// The latest settlement is one date across all creators and ignores correction documents.
		$issued = (string) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT issued_at FROM %i WHERE document_type = 'settlement'", $this->table( \MerchBoard\Core\Database\Tables::SETTLEMENT_DOCUMENTS ) ) );
		self::assertSame( $issued, $data['last_settlement'] );
		$this->wpdb->insert(
			$this->table( \MerchBoard\Core\Database\Tables::SETTLEMENT_DOCUMENTS ),
			array(
				'document_number'     => 'AFR-999999',
				'document_series'     => 'AFR',
				'document_type'       => 'correction',
				'creator_id'          => $this->creators['B'],
				'currency'            => 'DKK',
				'period_to'           => '2026-12-01 00:00:00',
				'profit_amount'       => '0',
				'commission_amount'   => '0',
				'opening_balance'     => '0',
				'payout_amount'       => '0',
				'closing_balance'     => '0',
				'calculation_version' => 'test',
				'template_version'    => 1,
				'snapshot'            => '{}',
				'issued_at'           => '2026-12-01 00:00:00',
			)
		);
		self::assertSame( $issued, \merchboard_cutover_collect( $this->wpdb )['last_settlement'], 'A later correction document does not move the date.' );
	}

	public function test_a_settlement_start_excludes_older_sales_and_a_row_without_its_sale_row_is_unknown(): void {
		$this->order( 9201, array( 'A' ), '2026-08-01 10:00:00' );
		$this->order( 9202, array( 'A' ), '2026-10-20 10:00:00' );
		$this->wpdb->update( $this->table( \MerchBoard\Core\Database\Tables::CREATORS ), array( 'payout_start_at' => '2026-09-01 00:00:00' ), array( 'id' => $this->creators['A'] ) );

		$data  = \merchboard_cutover_collect( $this->wpdb );
		$today = \merchboard_cutover_earned( $data, self::NOW, '2026-11-16 12:00:00', null );
		$start = new DateTimeImmutable( '2026-09-01 00:00:00', new DateTimeZone( 'UTC' ) );
		self::assertSame( ( new LedgerCreatorPayouts( $this->wpdb ) )->earned( $this->creators['A'], $start, new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) ), 14 )['DKK']->amount_units(), $today[ $this->creators['A'] . '|DKK' ] );
		self::assertSame( 200000, $today[ $this->creators['A'] . '|DKK' ], 'D1: only 9202.' );

		// Remove 9202's sale row's match: a refund row whose sale row is missing makes the balance unknown.
		$this->execute( "UPDATE %i SET type = 'refund' WHERE order_id = 9202", $this->table( \MerchBoard\Core\Database\Tables::SALES ) );
		$data = \merchboard_cutover_collect( $this->wpdb );
		self::assertNull( \merchboard_cutover_earned( $data, self::NOW, '2026-11-16 12:00:00', null )[ $this->creators['A'] . '|DKK' ] );
	}

	public function test_any_read_error_aborts_and_the_read_only_transaction_refuses_writes(): void {
		$this->wpdb->suppress_errors( true );

		try {
			\merchboard_cutover_read( $this->wpdb, fn() => $this->wpdb->get_results( 'SELECT * FROM merchboard_cutover_missing_table' ) );
			self::fail( 'A read error must abort the report.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'aborted', $e->getMessage() );
		}

		self::assertNotFalse( $this->wpdb->query( 'START TRANSACTION READ ONLY' ) );
		self::assertFalse( $this->wpdb->insert( $this->wpdb->options, array( 'option_name' => 'merchboard_cutover_probe', 'option_value' => 'x' ) ), 'No write inside the report transaction.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->query( 'ROLLBACK' );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->wpdb->options, 'merchboard_cutover_probe' ) ) );
	}

	public function test_paid_dates_are_read_from_hpos_or_post_meta(): void {
		add_post_meta( 9301, '_date_paid', (string) ( new DateTimeImmutable( '2026-09-01 10:00:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp() );
		add_post_meta( 9302, '_paid_date', '2026-09-02 12:00:00' );
		update_option( 'timezone_string', 'Europe/Copenhagen' );
		self::assertSame(
			array(
				9301 => '2026-09-01 10:00:00',
				9302 => '2026-09-02 10:00:00',
			),
			\merchboard_cutover_paid_dates( $this->wpdb, array( 9301, 9302, 9303 ) ),
			'Legacy: timestamp, or old site-time text.'
		);
		delete_option( 'timezone_string' );

		$table = $this->wpdb->prefix . 'wc_order_operational_data';
		$this->execute( 'CREATE TABLE %i (id bigint(20) unsigned auto_increment primary key, order_id bigint(20) unsigned NULL, date_paid_gmt datetime NULL)', $table );
		$this->wpdb->insert( $table, array( 'order_id' => 9301, 'date_paid_gmt' => '2026-09-05 08:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->insert( $table, array( 'order_id' => 9302, 'date_paid_gmt' => null ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		self::assertSame( array( 9301 => '2026-09-05 08:00:00' ), \merchboard_cutover_paid_dates( $this->wpdb, array( 9301, 9302 ) ), 'HPOS: date_paid_gmt only.' );
	}

	/**
	 * Sets an order (one line per creator) and synchronizes it.
	 *
	 * @phpstan-param list<string> $creators
	 * @phpstan-param list<RefundSnapshot> $refunds
	 * @phpstan-param list<int>|null $items
	 *
	 * @param int              $order_id Order.
	 * @param string[]         $creators Creator keys.
	 * @param string           $paid     Paid (UTC).
	 * @param RefundSnapshot[] $refunds  Refunds.
	 * @param int[]|null       $items    Existing item ids (null = new lines).
	 * @param bool             $snapshot Record pricing snapshots for new lines.
	 * @param string           $status   Status.
	 * @return list<int> Item ids.
	 */
	private function order( int $order_id, array $creators, string $paid, array $refunds = array(), ?array $items = null, bool $snapshot = true, string $status = 'processing' ): array {
		$lines = array();
		$ids   = array();

		foreach ( $creators as $i => $c ) {
			$item  = $items[ $i ] ?? ++$this->item;
			$ids[] = $item;

			if ( null === $items && $snapshot ) {
				$this->recorder->record( $item, $order_id, $this->products[ $c ], 0, 'DKK' );
			}

			$lines[] = new OrderLineSnapshot( $item, $this->products[ $c ], 0, 'MB-245 line', 1, SalesAmount::parse( '80' ), $this->creators[ $c ], SalesAmount::parse( '20' ) );
		}

		$this->reader->orders[ $order_id ] = array(
			'status'    => $status,
			'currency'  => 'DKK',
			'date_paid' => $paid,
			'lines'     => $lines,
			'refunds'   => $refunds,
		);
		$this->sync->sync( $order_id );

		return $ids;
	}

	/**
	 * A refund of one line.
	 *
	 * @param int    $id   Refund id.
	 * @param string $at   Time (UTC).
	 * @param int    $item Order item.
	 * @param string $net  Net (negative).
	 * @param string $tax  Tax (negative).
	 */
	private function refund( int $id, string $at, int $item, string $net, string $tax ): RefundSnapshot {
		return new RefundSnapshot( $id, new DateTimeImmutable( $at, new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( $item, -1, SalesAmount::parse( $net ), SalesAmount::parse( $tax ) ) ) );
	}
}
