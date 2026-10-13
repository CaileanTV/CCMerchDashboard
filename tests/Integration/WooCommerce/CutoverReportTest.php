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
use MerchBoard\Core\Database\Migrator;
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
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use WP_CLI;
use WP_CLI\ExitException;
use WP_CLI\Loggers\Execution;

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
			\merchboard_cutover_read( $this->wpdb, 2, fn() => $this->wpdb->get_results( 'SELECT * FROM merchboard_cutover_missing_table' ) );
			self::fail( 'A read error must abort the report.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'Read 2 of 5 failed.', $e->getMessage(), 'The read only, not the query or the database message.' );
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
	 * The five reads in their order: the query that marks each, where a comma breaks it, and HPOS.
	 *
	 * @return array<string, array{int, string, string, bool}>
	 */
	public static function reads(): array {
		return array(
			'1 ledger rows'           => array( 1, 'AS known_row', 'SELECT ', false ),
			'2 sale rows'             => array( 2, 'MIN(occurred_at)', 'SELECT ', false ),
			'3 payout totals'         => array( 3, 'SUM(amount)', 'SELECT ', false ),
			'4 latest settlement'     => array( 4, 'MAX(issued_at)', 'SELECT ', false ),
			'5 paid dates, post meta' => array( 5, "meta_key IN ('_date_paid'", 'post_id IN (', false ),
			'5 paid dates, HPOS'      => array( 5, 'date_paid_gmt IS NOT NULL', 'order_id IN (', true ),
		);
	}

	#[DataProvider( 'reads' )]
	public function test_a_failed_read_ends_the_report_with_exit_code_1_and_no_sql_order_ids_or_output( int $read, string $marker, string $needle, bool $hpos ): void {
		$this->small_ledger();

		if ( $hpos ) {
			$table = $this->wpdb->prefix . 'wc_order_operational_data';
			$this->execute( 'CREATE TABLE %i (id bigint(20) unsigned auto_increment primary key, order_id bigint(20) unsigned NULL, date_paid_gmt datetime NULL)', $table );
			$this->wpdb->insert( $table, array( 'order_id' => 9401, 'date_paid_gmt' => '2020-05-01 10:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		}

		// Without the report's suppression, wpdb logs a failed query and prints it (WP_DEBUG_DISPLAY in this suite).
		$this->wpdb->suppress_errors( false );
		$probe = $this->capture( fn() => $this->wpdb->query( 'SELECT ,9401' ) );
		self::assertStringContainsString( 'SELECT ,9401', $probe['log'], 'The error log probe works.' );
		self::assertStringContainsString( 'SELECT ,9401', $probe['stdout'], 'The output probe works.' );

		$before   = $this->checksums();
		$breaking = static fn( string $query ): string => str_contains( $query, $marker ) ? str_replace( $needle, $needle . ',', $query ) : $query;
		$run      = $this->run_report( array( '2020-06-01' ), $breaking );

		self::assertSame( 1, $run['exit'] );
		self::assertSame( "Error: Read {$read} of 5 failed. The report is aborted; nothing was printed.\n", $run['stderr'], 'Only the read: no SQL, no database message, no order id.' );
		self::assertSame( '', $run['stdout'], 'No report line, and nothing printed by wpdb.' );
		self::assertSame( '', $run['log'], 'Nothing in the error log.' );
		self::assertFalse( $this->wpdb->suppress_errors, "wpdb's own setting is restored." );

		// It was a real database error, and its message quotes the query (read 5: from the order ids on).
		$errors = $GLOBALS['EZSQL_ERROR'] ?? null;
		self::assertIsArray( $errors );
		$error = end( $errors );
		self::assertIsArray( $error );
		self::assertStringContainsString( $marker, (string) $error['query'] );
		self::assertStringContainsString( 5 === $read ? '9401, 9402, 9403)' : 'SQL syntax', (string) $error['error_str'] );

		// The reads stop at the failed one; the transaction ends with ROLLBACK; nothing was written.
		$markers = array( 'AS known_row', 'MIN(occurred_at)', 'SUM(amount)', 'MAX(issued_at)', $hpos ? 'date_paid_gmt IS NOT NULL' : "meta_key IN ('_date_paid'" );
		self::assertSame( 'START TRANSACTION READ ONLY', $run['queries'][0] ?? null );
		self::assertSame( 'ROLLBACK', end( $run['queries'] ) );

		foreach ( array_slice( $markers, $read ) as $later ) {
			self::assertSame( array(), array_values( array_filter( $run['queries'], static fn( string $query ): bool => str_contains( $query, $later ) ) ), "No read after read {$read}." );
		}

		self::assertSame( $before, $this->checksums(), 'Every table unchanged.' );
		$this->assert_no_open_transaction();
	}

	public function test_an_invalid_date_or_an_older_schema_ends_with_exit_code_1_before_any_read(): void {
		$date = $this->run_report( array( '2026/10/01' ) );
		update_option( Migrator::VERSION_OPTION, '10' );
		$schema = $this->run_report( array( '2026-10-01' ) );

		self::assertSame( array( 1, '', "Error: The deploy date must be YYYY-MM-DD.\n" ), array( $date['exit'], $date['stdout'], $date['stderr'] ) );
		self::assertSame( array( 1, '', "Error: Merch Dashboard with schema 11 or later must be active.\n" ), array( $schema['exit'], $schema['stdout'], $schema['stderr'] ) );

		foreach ( array( $date, $schema ) as $run ) {
			self::assertSame( array(), array_values( array_filter( $run['queries'], fn( string $query ): bool => ! str_contains( $query, $this->wpdb->options ) ) ), 'No transaction and no read; at most an option lookup.' );
			self::assertSame( '', $run['log'] );
		}
	}

	public function test_the_report_prints_every_section_and_changes_nothing(): void {
		$this->small_ledger();
		$this->wpdb->suppress_errors( true );
		$before = $this->checksums();
		$run    = $this->run_report( array( '2020-06-01' ) );

		self::assertSame( array( 0, '', '' ), array( $run['exit'], $run['stderr'], $run['log'] ) );
		self::assertTrue( $this->wpdb->suppress_errors, "wpdb's own setting is restored." );
		self::assertSame( $before, $this->checksums(), 'Every table unchanged.' );
		$this->assert_no_open_transaction();

		// One transaction, the five reads in order, no write.
		self::assertSame( 'START TRANSACTION READ ONLY', $run['queries'][0] ?? null );
		self::assertSame( 'ROLLBACK', end( $run['queries'] ) );
		$at = array();

		foreach ( array( 'AS known_row', 'MIN(occurred_at)', 'SUM(amount)', 'MAX(issued_at)', "meta_key IN ('_date_paid'" ) as $marker ) {
			$found = array_keys( array_filter( $run['queries'], static fn( string $query ): bool => str_contains( $query, $marker ) ) );
			self::assertCount( 1, $found, $marker );
			$at[] = $found[0];
		}

		$sorted = array_values( array_unique( $at ) );
		sort( $sorted );
		self::assertSame( $sorted, $at, 'Reads 1–5 in order.' );
		self::assertSame( array(), array_values( array_filter( $run['queries'], static fn( string $query ): bool => 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK)\b/i', $query ) ) ), 'No write.' );

		// The deploy day: 9402 and 9403 are unmarked; 9403's refund neutralises its sale (rule b).
		$deploy = array_merge(
			$this->report_block( '1a first sale row of the order', 2, 2, '20.0000', 1 ),
			$this->report_block( '1b sale row of the cycle (reopened = new)', 2, 2, '20.0000', 1 ),
			$this->report_block( '2  WooCommerce paid date (no date = unmarked)', 2, 2, '20.0000', 1 )
		);
		// Every later cut-over: nothing is unmarked, except 9403 without a paid date under definition 2.
		$later = array_merge(
			$this->report_block( '1a first sale row of the order', 0, 0, '50.0000', 0 ),
			$this->report_block( '1b sale row of the cycle (reopened = new)', 0, 0, '50.0000', 0 ),
			$this->report_block( '2  WooCommerce paid date (no date = unmarked)', 1, 1, '50.0000', 1 )
		);
		$lines = explode( "\n", $run['stdout'] );

		self::assertMatchesRegularExpression( '/^Merch Dashboard 24\.5 cut-over report v2 \(read only\)\. Now \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC, holding period 14 days\.$/', $lines[0] );
		self::assertMatchesRegularExpression( '/^== start of the last completed month \d{4}-\d\d-01 \(site time\): cut-over \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC$/', $lines[23] );
		self::assertSame(
			array_merge(
				array( 'Orders in the ledger: 3, without a sale row: 0, without a WooCommerce paid date: 1, reopened (more than one cycle): 0', '', '== deploy day 2020-06-01 (UTC): cut-over 2020-06-01 00:00:00 UTC' ),
				$deploy,
				array( '' ),
				$later,
				array( '', '== latest settlement document: none, not applicable', '' )
			),
			array_merge( array_slice( $lines, 1, 22 ), array_slice( $lines, 24 ) ),
			'Every section and definition; the last line ends with a newline.'
		);
		self::assertDoesNotMatchRegularExpression( '/\b94\d\d\b|Cutover [ABC]/', $run['stdout'], 'No order id and no creator name.' );

		// With a settlement document, its section is printed in full.
		$this->wpdb->insert(
			$this->table( \MerchBoard\Core\Database\Tables::SETTLEMENT_DOCUMENTS ),
			array(
				'document_number'     => 'AFR-999998',
				'document_series'     => 'AFR',
				'document_type'       => 'settlement',
				'creator_id'          => $this->creators['A'],
				'currency'            => 'DKK',
				'period_to'           => '2026-01-01 00:00:00',
				'profit_amount'       => '0',
				'commission_amount'   => '0',
				'opening_balance'     => '0',
				'payout_amount'       => '0',
				'closing_balance'     => '0',
				'calculation_version' => 'test',
				'template_version'    => 1,
				'snapshot'            => '{}',
				'issued_at'           => '2026-01-01 00:00:00',
			)
		);
		$run = $this->run_report( array( '2020-06-01' ) );

		self::assertSame( array( 0, '' ), array( $run['exit'], $run['stderr'] ) );
		self::assertSame( array_merge( array( '== latest settlement document: cut-over 2026-01-01 00:00:00 UTC' ), $later, array( '' ) ), array_slice( explode( "\n", $run['stdout'] ), 43 ) );
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

	/**
	 * 9401 (A, before the cut-over 2020-06-01), 9402 (A and B, after) and 9403 (A, after, refunded in full,
	 * no WooCommerce paid date). A earns 40.00, B 10.00. Dates long past, so today's clock changes nothing.
	 */
	private function small_ledger(): void {
		$this->order( 9401, array( 'A' ), '2020-05-01 10:00:00' );
		$this->order( 9402, array( 'A', 'B' ), '2020-07-01 10:00:00' );
		$i9403 = $this->order( 9403, array( 'A' ), '2020-07-01 10:00:00' );
		$this->order( 9403, array( 'A' ), '2020-07-01 10:00:00', array( $this->refund( 7403, '2020-07-05 10:00:00', $i9403[0], '-80', '-20' ) ), $i9403 );

		$paid_dates = array(
			9401 => '2020-05-01 10:00:00',
			9402 => '2020-07-01 10:00:00',
			9403 => null,
		);

		foreach ( $paid_dates as $order => $paid ) {
			delete_post_meta( $order, '_date_paid' );

			if ( null !== $paid ) {
				add_post_meta( $order, '_date_paid', (string) ( new DateTimeImmutable( $paid, new DateTimeZone( 'UTC' ) ) )->getTimestamp() );
			}
		}
	}

	/**
	 * The lines of one definition with one currency (DKK, creators A and B, no payouts).
	 *
	 * @param string $name     Definition.
	 * @param int    $orders   Unmarked orders.
	 * @param int    $creators Their creators.
	 * @param string $after    Earned and available after.
	 * @param int    $refunds  Unmarked orders with 9403's refund (each neutralised).
	 * @return list<string>
	 */
	private function report_block( string $name, int $orders, int $creators, string $after, int $refunds ): array {
		return array(
			"  -- {$name}: unmarked orders with unsettled rows {$orders}, creators {$creators}, currencies 1",
			'     DKK: creators 2 (unknown balance 0, not in the sums)',
			"       earned/released before 50.0000, after {$after}",
			"       available before 50.0000, after {$after}",
			'       pending payouts newly uncovered 0 (0.0000), shortfall 0.0000',
			"       unmarked orders with refunds/reversals {$refunds} ({$refunds} rows, " . ( $refunds ? '-20.0000' : '0.0000' ) . "), of which neutralised by rule b {$refunds}; unmarked orders with a negative net 0 (0.0000)",
		);
	}

	/**
	 * Runs the report as `wp eval-file` does, with WP-CLI's own exit capture (as WP_CLI::runcommand()).
	 *
	 * @phpstan-param list<string> $args
	 * @phpstan-param (callable(string): string)|null $alter
	 *
	 * @param string[]      $args  Arguments.
	 * @param callable|null $alter Changes a query before it runs.
	 * @return array{exit: int, stdout: string, stderr: string, log: string, queries: list<string>}
	 */
	private function run_report( array $args, ?callable $alter = null ): array {
		$logger  = new Execution();
		$slot    = new ReflectionProperty( WP_CLI::class, 'logger' );
		$capture = new ReflectionProperty( WP_CLI::class, 'capture_exit' );
		$queries = array();
		$record  = static function ( string $query ) use ( &$queries, $alter ): string {
			$query     = null === $alter ? $query : $alter( $query );
			$queries[] = $query;

			return $query;
		};
		$exit    = 0;

		$slot->setValue( null, $logger );
		$capture->setValue( null, true );
		add_filter( 'query', $record );

		try {
			$output = $this->capture(
				function () use ( $args, &$exit ): void {
					try {
						\merchboard_cutover_main( $this->wpdb, $args );
					} catch ( ExitException $e ) {
						$exit = $e->getCode();
					}
				}
			);
		} finally {
			remove_filter( 'query', $record );
			$capture->setValue( null, false );
			$slot->setValue( null, null );
		}

		return array(
			'exit'    => $exit,
			'stdout'  => $output['stdout'],
			'stderr'  => $logger->stderr,
			'log'     => $output['log'],
			'queries' => $queries,
		);
	}

	/**
	 * What a callback prints and writes to PHP's error log.
	 *
	 * @param callable $run Callback.
	 * @return array{stdout: string, log: string}
	 */
	private function capture( callable $run ): array {
		$log      = (string) tempnam( sys_get_temp_dir(), 'mb245' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test process only; restored below.
		ob_start();

		try {
			$run();
		} finally {
			$stdout = (string) ob_get_clean();
			ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the test log.
			$written = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary file.
			wp_delete_file( $log );
		}

		return array(
			'stdout' => $stdout,
			'log'    => $written,
		);
	}

	/**
	 * CHECKSUM TABLE of every table of the test site.
	 *
	 * @return array<string, string>
	 */
	private function checksums(): array {
		$sums = array();

		foreach ( $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->wpdb->prefix ) . '%' ) ) as $table ) {
			$sums[ (string) $table ] = (string) $this->wpdb->get_var( $this->wpdb->prepare( 'CHECKSUM TABLE %i', (string) $table ), 1 );
		}

		return $sums;
	}

	/**
	 * No transaction is left open: a write is not refused (READ ONLY) and survives a ROLLBACK.
	 */
	private function assert_no_open_transaction(): void {
		self::assertNotFalse( $this->wpdb->insert( $this->wpdb->options, array( 'option_name' => 'merchboard_cutover_probe', 'option_value' => 'x' ) ), 'No read-only transaction left open.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->query( 'ROLLBACK' );
		self::assertSame( 'x', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->wpdb->options, 'merchboard_cutover_probe' ) ), 'No transaction left open.' );
		$this->wpdb->delete( $this->wpdb->options, array( 'option_name' => 'merchboard_cutover_probe' ) );
	}
}
