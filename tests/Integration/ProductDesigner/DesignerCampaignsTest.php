<?php
/**
 * The company's campaigns on a real database (ADR-080 pkt. 9).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\ProductDesigner\Admin\CampaignActions;
use MerchBoard\Modules\ProductDesigner\Admin\CampaignsPage;
use MerchBoard\Modules\ProductDesigner\Campaigns\CampaignRepository;
use MerchBoard\Modules\ProductDesigner\Campaigns\CampaignService;
use MerchBoard\Modules\ProductDesigner\Campaigns\DesignerCampaigns;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPrice;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPriceSource;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesProfit;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
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
 * Campaigns through the real recorder, synchronizer, ledger, campaign
 * service and DesignerCampaigns: a covered designer line shares the
 * discount, every later row of the line keeps its campaign (also after the
 * campaign changed), uncovered and legacy lines are unaffected, and the SQL
 * sums equal the PHP calculation (ADR-050). Base 150 + profit 50 = 200 incl.
 * 25 % VAT. No WooCommerce is loaded; synthetic ids only.
 */
final class DesignerCampaignsTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private SalesLinePricingRepository $snapshots;

	private CreatorVatRepository $vat;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesRepository $ledger;

	private SalesProfit $profit;

	private CampaignService $campaigns;

	private RecordingLogger $logger;

	private int $creator;

	/**
	 * Designer prices by product id.
	 *
	 * @var array<int, DesignerPrice>
	 */
	private array $prices = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Campaign A', CreatorStatus::Active )->id;

		$audit           = $this->service( AuditTrail::class );
		$transaction     = $this->service( Transaction::class );
		$this->posts     = new ProductPosts( $this->wpdb );
		$this->vat       = new CreatorVatRepository( $this->wpdb );
		$this->snapshots = new SalesLinePricingRepository( $this->wpdb );
		$markups         = new CreatorMarkupRepository( $this->wpdb );
		$links           = new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );
		$this->recorder  = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), $this->snapshots, new RecordingLogger(), $this->vat, $this->source() );
		$this->profit    = new SalesProfit( $this->snapshots, new SalesTaxRepository( $this->wpdb ) );
		$this->logger    = new RecordingLogger();
		$this->reader    = new FakeOrderReader();
		$this->ledger    = new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) );
		$repository      = new CampaignRepository( $this->wpdb );
		$this->campaigns = new CampaignService( $repository, new CreatorCodeRepository( $this->wpdb ), $audit, $transaction );
		$this->sync      = new SalesSynchronizer(
			$this->ledger,
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			$this->logger,
			null,
			null,
			new DesignerCampaigns( $repository, $this->snapshots )
		);

		$this->vat->save( $this->creator, false );
		$markups->save( $this->creator, MarkupBps::from_percent( '20' ) );
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_a_campaign_coupon_shares_the_discount_and_the_line_keeps_its_campaign(): void {
		$id       = $this->campaigns->create( 'Black Week', self::now( '-P1D' ), self::now( 'P1D' ), "BW20\nother", false );
		$designer = $this->designer_product();

		// Paid 180 with the campaign's code: 50 × 180 / 200 = 45 for the creator.
		$this->recorder->record( 7011, 7001, $designer, 0, 'DKK' );
		$line = $this->line( 7011, $designer, 1, '144', '36' );
		$this->order( 7001, array( $line ), array(), array( 'bw20' ) );

		// The campaign no longer names the code; the refund of the line still takes back 45.
		$this->campaigns->update( $id, 'Black Week', self::now( '-P1D' ), self::now( 'P1D' ), 'other', false );
		$this->order( 7001, array( $line ), array( self::refund( 7401, 7011, -1, '-144', '-36' ) ), array( 'bw20' ) );

		$rows = $this->stored( 7001 );
		self::assertSame( array( array( '45.0000', '36.0000', (string) $id ), array( '-45.0000', '-36.0000', (string) $id ) ), $rows );
		$this->assert_sql_equals_php();
	}

	public function test_sale_prices_are_covered_only_by_a_campaign_that_covers_them(): void {
		$designer = $this->designer_product();

		// A sale price of 180 (subtotal before coupons) without a sale price campaign: the company keeps the base price.
		$this->recorder->record( 7111, 7101, $designer, 0, 'DKK' );
		$this->order( 7101, array( $this->line( 7111, $designer, 1, '144', '36', '180' ) ) );

		$id = $this->campaigns->create( 'Summer sale', self::now( '-P1D' ), self::now( 'P1D' ), '', true );

		$this->recorder->record( 7121, 7102, $designer, 0, 'DKK' );
		$this->order( 7102, array( $this->line( 7121, $designer, 1, '144', '36', '180' ) ) );
		// The list price with a company coupon outside the campaign: not covered.
		$this->recorder->record( 7131, 7103, $designer, 0, 'DKK' );
		$this->order( 7103, array( $this->line( 7131, $designer, 1, '144', '36', '200' ) ), array(), array( 'welcome20' ) );

		self::assertSame( array( array( '30.0000', '24.0000', null ) ), $this->stored( 7101 ) );
		self::assertSame( array( array( '45.0000', '36.0000', (string) $id ) ), $this->stored( 7102 ) );
		self::assertSame( array( array( '30.0000', '24.0000', null ) ), $this->stored( 7103 ) );
		$this->assert_sql_equals_php();
	}

	public function test_a_line_bought_outside_the_period_and_legacy_lines_are_never_covered(): void {
		$this->campaigns->create( 'Next week', self::now( 'P1D' ), self::now( 'P8D' ), 'bw20', true );
		$this->campaigns->create( 'Last week', self::now( '-P8D' ), self::now( '-P1D' ), 'bw20', true );
		$designer = $this->designer_product();
		$legacy   = $this->product( '100' );

		$this->recorder->record( 7211, 7201, $designer, 0, 'DKK' );
		$this->recorder->record( 7212, 7201, $legacy, 0, 'DKK' );
		$this->order( 7201, array( $this->line( 7211, $designer, 1, '144', '36' ), $this->line( 7212, $legacy, 1, '80', '20' ) ), array(), array( 'bw20' ) );

		$this->campaigns->create( 'Now', self::now( '-P1D' ), self::now( 'P1D' ), 'bw20', false );
		$this->recorder->record( 7221, 7202, $legacy, 0, 'DKK' );
		$this->order( 7202, array( $this->line( 7221, $legacy, 1, '80', '20' ) ), array(), array( 'bw20' ) );

		self::assertSame( array( array( '30.0000', '24.0000', null ), array( null, null, null ) ), $this->stored( 7201 ) );
		self::assertSame( array( array( null, null, null ) ), $this->stored( 7202 ), 'A legacy line keeps the share model.' );
		self::assertSame( 200000, $this->profit->for_entry( $this->ledger->entries_for_order( 7202 )[0] ), 'Legacy: 20 % of 100.' );
		$this->assert_sql_equals_php();
	}

	public function test_unknown_coupon_data_writes_nothing_while_a_campaign_could_cover_the_line(): void {
		$this->campaigns->create( 'Now', self::now( '-P1D' ), self::now( 'P1D' ), 'bw20', false );
		$designer = $this->designer_product();

		$this->recorder->record( 7311, 7301, $designer, 0, 'DKK' );
		$this->reader->orders[7301] = array(
			'status'    => 'processing',
			'currency'  => 'DKK',
			'date_paid' => '2026-09-01 10:05:00',
			'lines'     => array( $this->line( 7311, $designer, 1, '144', '36' ) ),
			'refunds'   => array(),
			'coupons'   => null,
		);
		$this->sync->sync( 7301 );

		self::assertSame( array(), $this->ledger->entries_for_order( 7301 ), 'Never read as "no coupon".' );
		self::assertNotSame( array(), $this->logger->at( 'error' ) );
	}

	public function test_the_service_validates_audits_and_keeps_used_campaigns(): void {
		$codes = new CreatorCodeRepository( $this->wpdb );
		$codes->insert( $this->creator, 'cailean10', 901, null, self::now( '-P30D' ) );

		$refusals = array(
			DesignerException::CAMPAIGN_NAME         => fn() => $this->campaigns->create( '  ', self::now( '-P1D' ), self::now( 'P1D' ), 'a', false ),
			DesignerException::CAMPAIGN_PERIOD       => fn() => $this->campaigns->create( 'X', self::now( 'P1D' ), self::now( '-P1D' ), 'a', false ),
			DesignerException::CAMPAIGN_SCOPE        => fn() => $this->campaigns->create( 'X', self::now( '-P1D' ), self::now( 'P1D' ), ' , ', false ),
			DesignerException::CAMPAIGN_CREATOR_CODE => fn() => $this->campaigns->create( 'X', self::now( '-P1D' ), self::now( 'P1D' ), "ok\nCAILEAN10", false ),
			DesignerException::CAMPAIGN_UNKNOWN      => fn() => $this->campaigns->update( 99999, 'X', self::now( '-P1D' ), self::now( 'P1D' ), 'a', false ),
		);

		foreach ( $refusals as $reason => $call ) {
			try {
				$call();
				self::fail( 'Expected a refusal: ' . $reason );
			} catch ( DesignerException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		self::assertSame( 0, $this->count_rows( Tables::DESIGNER_CAMPAIGNS ), 'A refusal stores nothing.' );

		$id = $this->campaigns->create( 'Black Week', self::now( '-P1D' ), self::now( 'P1D' ), 'bw20', false );
		$this->campaigns->update( $id, 'Black Week 2', self::now( '-P1D' ), self::now( 'P2D' ), 'bw20', true );

		$audit = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT action, context FROM %i WHERE action LIKE %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), 'designer_campaign_%' ), ARRAY_A );
		self::assertIsArray( $audit );
		self::assertCount( 2, $audit );
		self::assertSame( array( AuditAction::DesignerCampaignCreated->value, AuditAction::DesignerCampaignChanged->value ), array_column( $audit, 'action' ) );
		$changed = json_decode( (string) $audit[1]['context'], true );
		self::assertIsArray( $changed );
		self::assertSame( array( 'Black Week 2', 'Black Week', true, false ), array( $changed['campaign_name'], $changed['previous_campaign_name'], $changed['sale_prices'], $changed['previous_sale_prices'] ) );
		self::assertArrayNotHasKey( 'previous_coupon_codes', $changed, 'Only what changed.' );

		// Used by a sales row: kept.
		$designer = $this->designer_product();
		$this->recorder->record( 7411, 7401, $designer, 0, 'DKK' );
		$this->order( 7401, array( $this->line( 7411, $designer, 1, '144', '36' ) ), array(), array( 'bw20' ) );

		try {
			$this->campaigns->delete( $id );
			self::fail( 'A used campaign is kept.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::CAMPAIGN_IN_USE, $e->reason() );
		}

		$unused = $this->campaigns->create( 'Unused', self::now( 'P5D' ), self::now( 'P6D' ), 'later', false );
		$this->campaigns->delete( $unused );
		self::assertSame( 1, $this->count_rows( Tables::DESIGNER_CAMPAIGNS ) );

		wp_set_current_user( 0 );

		try {
			$this->campaigns->create( 'X', self::now( '-P1D' ), self::now( 'P1D' ), 'a', false );
			self::fail( 'Only users with merchboard_manage_designer.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::NOT_AUTHORIZED, $e->reason() );
		}
	}

	public function test_the_admin_form_turns_local_times_into_utc_and_reports_refusals(): void {
		update_option( 'timezone_string', 'Europe/Copenhagen' );
		$actions = new CampaignActions( $this->campaigns, new RecordingLogger() );
		$input   = array(
			'_wpnonce'     => wp_create_nonce( CampaignActions::ACTION ),
			'do'           => 'save',
			'campaign_id'  => '0',
			'name'         => 'Black Week',
			'starts_at'    => '2026-11-27T00:00',
			'ends_at'      => '2026-12-01T00:00',
			'coupon_codes' => 'BW20',
			'sale_prices'  => '1',
		);

		$url = $actions->handle( $input );
		self::assertStringContainsString( CampaignsPage::RESULT_ARG . '=created', $url );

		$campaign = ( new CampaignRepository( $this->wpdb ) )->all()[0];
		self::assertSame( array( '2026-11-26 23:00:00', '2026-11-30 23:00:00', array( 'bw20' ), true ), array( $campaign->starts_at->format( 'Y-m-d H:i:s' ), $campaign->ends_at->format( 'Y-m-d H:i:s' ), $campaign->coupon_codes, $campaign->sale_prices ) );

		self::assertStringContainsString( CampaignsPage::RESULT_ARG . '=invalid_time', $actions->handle( array_merge( $input, array( 'starts_at' => '27-11-2026' ) ) ) );
		self::assertStringContainsString( CampaignsPage::RESULT_ARG . '=' . DesignerException::CAMPAIGN_PERIOD, $actions->handle( array_merge( $input, array( 'ends_at' => '2026-11-26T00:00' ) ) ) );
		self::assertSame( 1, $this->count_rows( Tables::DESIGNER_CAMPAIGNS ) );
		delete_option( 'timezone_string' );
	}

	/**
	 * Every SQL sum equals the sum of RealizedProfit/RealizedPayout (ADR-050).
	 */
	private function assert_sql_equals_php(): void {
		$profit = 0;
		$payout = 0;

		foreach ( $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE creator_id = %d', $this->table( Tables::SALES ), $this->creator ) ) as $order_id ) {
			foreach ( $this->ledger->entries_for_order( (int) $order_id ) as $entry ) {
				$profit += (int) $this->profit->for_entry( $entry );
				$payout += (int) $this->profit->payout_for_entry( $entry );
			}
		}

		$totals = ( new LedgerCreatorProfits( $this->wpdb ) )->totals( $this->creator, self::now( '-P3650D' ), self::now( 'P3650D' ) );
		self::assertSame( $profit, $totals['DKK']->amount_units() );
		self::assertSame( $payout, ( new LedgerCreatorPayouts( $this->wpdb ) )->earned( $this->creator, null, self::now( 'P3650D' ), 0 )['DKK']->amount_units() );
	}

	/**
	 * The stored designer profit, payout and campaign of an order's rows, by event key.
	 *
	 * @param int $order_id Order.
	 * @return list<array{0: string|null, 1: string|null, 2: string|null}>
	 */
	private function stored( int $order_id ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT t.designer_profit, t.designer_payout, t.campaign_id FROM %i t JOIN %i r ON r.event_key = t.event_key WHERE t.order_id = %d ORDER BY r.id', $this->table( Tables::SALES_TAX ), $this->table( Tables::SALES ), $order_id ), ARRAY_N );

		return array_map( static fn( array $row ): array => array( $row[0], $row[1], $row[2] ), (array) $rows );
	}

	/**
	 * A fixed designer price source over $this->prices.
	 */
	private function source(): DesignerPriceSource {
		return new class( $this ) implements DesignerPriceSource {

			/**
			 * Constructor.
			 *
			 * @param DesignerCampaignsTest $test Test.
			 */
			public function __construct( private DesignerCampaignsTest $test ) {
			}

			/**
			 * The price of a product.
			 *
			 * @param int $product_id Product.
			 */
			public function price( int $product_id ): ?DesignerPrice {
				return $this->test->designer_price( $product_id );
			}
		};
	}

	/**
	 * The fixed designer price of a product.
	 *
	 * @param int $product_id Product.
	 */
	public function designer_price( int $product_id ): ?DesignerPrice {
		return $this->prices[ $product_id ] ?? null;
	}

	/**
	 * A designer product of creator A: base 150, profit 50.
	 */
	private function designer_product(): int {
		$id                  = $this->product( '200' );
		$this->prices[ $id ] = new DesignerPrice( $this->creator, SalesAmount::parse( '150' ), SalesAmount::parse( '50' ), 'DKK' );

		return $id;
	}

	/**
	 * Sets an order and synchronizes it.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 * @phpstan-param list<string> $coupons
	 *
	 * @param int                 $order_id Order.
	 * @param OrderLineSnapshot[] $lines    Lines.
	 * @param RefundSnapshot[]    $refunds  Refunds.
	 * @param string[]            $coupons  Normalized coupon codes.
	 */
	private function order( int $order_id, array $lines, array $refunds = array(), array $coupons = array() ): void {
		$this->reader->orders[ $order_id ] = array(
			'status'    => 'processing',
			'currency'  => 'DKK',
			'date_paid' => '2026-09-01 10:05:00',
			'lines'     => $lines,
			'refunds'   => $refunds,
			'coupons'   => $coupons,
		);
		$this->sync->sync( $order_id );
		self::assertSame( array(), $this->logger->at( 'error' ), 'Order ' . $order_id . ' synchronized without errors.' );
	}

	/**
	 * An order line of creator A.
	 *
	 * @param int         $item     Order item.
	 * @param int         $product  Product.
	 * @param int         $quantity Quantity.
	 * @param string      $net      Amount excl. VAT.
	 * @param string      $tax      Actual tax.
	 * @param string|null $subtotal Subtotal before coupons incl. VAT; default the list price 200 per unit.
	 */
	private function line( int $item, int $product, int $quantity, string $net, string $tax, ?string $subtotal = null ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, $product, 0, 'MB-CAMP line', $quantity, SalesAmount::parse( $net ), $this->creator, SalesAmount::parse( $tax ), SalesAmount::parse( $subtotal ?? (string) ( 200 * $quantity ) ) );
	}

	/**
	 * A refund of one line.
	 *
	 * @param int    $id       Refund id.
	 * @param int    $item     Refunded order item.
	 * @param int    $quantity Quantity (≤ 0).
	 * @param string $net      Amount excl. VAT (≤ 0).
	 * @param string $tax      Refund line tax (≤ 0).
	 */
	private static function refund( int $id, int $item, int $quantity, string $net, string $tax ): RefundSnapshot {
		return new RefundSnapshot( $id, self::now( 'PT1H' ), array( new RefundLineSnapshot( $item, $quantity, SalesAmount::parse( $net ), SalesAmount::parse( $tax ) ) ) );
	}

	/**
	 * A product of creator A.
	 *
	 * @param string $price Regular price.
	 */
	private function product( string $price ): int {
		$id = $this->posts->add( 'MB-CAMP product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $id, '_regular_price', $price );
		$this->posts->meta( $id, '_sale_price', '' );
		$this->posts->meta( $id, '_price', $price );

		return $id;
	}

	/**
	 * Now (UTC) moved by an ISO 8601 interval, e.g. "P1D" or "-P1D".
	 *
	 * @param string $interval Interval, with a leading "-" to go back.
	 */
	private static function now( string $interval ): DateTimeImmutable {
		$now  = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$step = new DateInterval( ltrim( $interval, '-' ) );

		return str_starts_with( $interval, '-' ) ? $now->sub( $step ) : $now->add( $step );
	}
}
