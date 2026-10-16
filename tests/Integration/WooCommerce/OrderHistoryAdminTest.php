<?php
/**
 * Admin order history of the sales ledger on a real database (ADR-051).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\OrderHistoryActions;
use MerchBoard\Modules\WooCommerce\Admin\OrderHistoryEraseException;
use MerchBoard\Modules\WooCommerce\Admin\OrderHistoryEraser;
use MerchBoard\Modules\WooCommerce\Admin\OrderHistoryPage;
use MerchBoard\Modules\WooCommerce\Admin\OrderStatusLookup;
use MerchBoard\Modules\WooCommerce\Admin\PayoutsPage;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardAvailability;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviderException;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardSnapshot;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardStatus;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\PayoutGiftCardOrders;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfits;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitGap;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitSummary;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistory;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistoryDetails;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistoryFilter;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistoryRow;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Modules\WooCommerce\Sales\SalesTaxRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeGiftCardProvider;
use MerchBoard\Tests\Integration\Support\FakeOrderRegistryGiftCardProvider;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Integration\Support\RecordingSalesCacheInvalidator;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use RuntimeException;
use WP_User;

/**
 * No WooCommerce is loaded (ADR-038 Q4): snapshots come from the real
 * recorder and ledger rows with their tax from the real synchronizer.
 * Orders:
 *
 * - 3001: two lines of creator A with snapshots and tax, one refunded (complete);
 * - 3002: a legacy order: no snapshot and no stored tax (unknown);
 * - 3003: one line with history and one created before its product had a creator (incomplete);
 * - 3004: creator B's order (complete).
 *
 * The page's WooCommerce lookup is replaced (no WooCommerce in this suite).
 */
final class OrderHistoryAdminTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private OrderHistory $history;

	private OrderHistoryDetails $details;

	private SalesRepository $ledger;

	private RecordingSalesCacheInvalidator $cache;

	/**
	 * WooCommerce state per order for the lookup: 'trash', 'deleted' or absent (active).
	 *
	 * @var array<int, string>
	 */
	private array $woo = array();

	private int $alpha;

	private int $beta;

	private FakeGiftCardProvider $fake;

	/**
	 * The fake with the optional order lookup (null = the plain fake is used).
	 *
	 * @var FakeOrderRegistryGiftCardProvider|null
	 */
	private ?FakeOrderRegistryGiftCardProvider $registry = null;

	/**
	 * Logger of the page, when a test reads it (null = a fresh one per page).
	 *
	 * @var RecordingLogger|null
	 */
	private ?RecordingLogger $page_logger = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		add_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		wp_set_current_user( 1 );

		$creators    = new CreatorRepository( $this->wpdb );
		$this->alpha = $creators->create( 'History Alpha', CreatorStatus::Active )->id;
		$this->beta  = $creators->create( 'History Beta', CreatorStatus::Active )->id;
		$markups     = new CreatorMarkupRepository( $this->wpdb );
		$markups->save( $this->alpha, new MarkupBps( 2000 ) );
		$markups->save( $this->beta, new MarkupBps( 1000 ) );

		$audit          = $this->service( AuditTrail::class );
		$transaction    = $this->service( Transaction::class );
		$snapshots      = new SalesLinePricingRepository( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );
		$this->posts    = new ProductPosts( $this->wpdb );
		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), $snapshots, new RecordingLogger(), new CreatorVatRepository( $this->wpdb ) );
		$this->reader   = new FakeOrderReader();
		$this->cache    = new RecordingSalesCacheInvalidator();
		$ledger         = new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ), SalesRepository::LOCK_TIMEOUT, $this->cache );
		$this->ledger   = $ledger;
		$this->sync     = new SalesSynchronizer( $ledger, new SalesPlanner(), $this->reader, new RecordingFailureMarker(), new RecordingLogger(), static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$this->history  = new OrderHistory( $this->wpdb );
		$this->details  = new OrderHistoryDetails( $ledger, $snapshots, new SalesTaxRepository( $this->wpdb ) );

		$tee = $this->product( 'Tee', $this->alpha );
		$cap = $this->product( 'Cap', $this->alpha );
		$mug = $this->product( 'Mug', $this->beta );
		$old = $this->product( 'Late', null );

		// 3001: complete, with a refund.
		$this->recorder->record( 30011, 3001, $tee, 0, 'DKK' );
		$this->recorder->record( 30012, 3001, $cap, 0, 'DKK' );
		$lines = array( $this->line( 30011, $tee, 2, '160', '40', $this->alpha ), $this->line( 30012, $cap, 1, '80', '20', $this->alpha ) );
		$this->order( 3001, $lines );
		$this->order( 3001, $lines, array( new RefundSnapshot( 7401, new DateTimeImmutable( '2026-10-09 13:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( 30011, -1, SalesAmount::parse( '-80' ), SalesAmount::parse( '-20' ) ) ) ) ) );

		// 3002: legacy (no snapshot recorded, no stored tax).
		$this->order( 3002, array( $this->line( 30021, $tee, 1, '80', null, $this->alpha ) ) );

		// 3003: one line with history, one created before its product had a creator.
		$this->recorder->record( 30031, 3003, $tee, 0, 'DKK' );
		self::assertNull( $this->recorder->record( 30032, 3003, $old, 0, 'DKK' ) );
		$this->posts->meta( $old, ProductCreatorLink::META_KEY, (string) $this->alpha );
		$this->order( 3003, array( $this->line( 30031, $tee, 1, '80', '20', $this->alpha ), $this->line( 30032, $old, 1, '80', '20', $this->alpha ) ) );

		// 3004: creator B.
		$this->recorder->record( 30041, 3004, $mug, 0, 'DKK' );
		$this->order( 3004, array( $this->line( 30041, $mug, 1, '80', '20', $this->beta ) ) );
		$this->cache->calls = array();
		$this->fake         = new FakeGiftCardProvider();
	}

	protected function tearDown(): void {
		remove_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		remove_all_filters( 'query' );
		$_GET = array();
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_each_order_is_one_row_with_its_events_lines_tax_and_profit_status(): void {
		$rows = $this->by_order( $this->history->page( new OrderHistoryFilter(), new Pagination( 1, 20 ) ) );

		self::assertSame( 4, $this->history->count( new OrderHistoryFilter() ) );
		self::assertSame( array( 3001, 3002, 3003, 3004 ), array_keys( $rows ) );

		$complete = $rows[3001];
		self::assertSame( array( 3, 2, 2 ), array( $complete->events, $complete->lines, $complete->sales ), 'Two sales and a refund are three events of one order.' );
		self::assertSame( '160.0000', $complete->net_amount );
		self::assertSame( 3, $complete->taxed_rows );
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'complete', 'amount' => '40.0000' ), $complete->profit->to_array(), '(200 + 100 - 100) × 20 %.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( $this->alpha ), $complete->creator_ids );

		self::assertSame( ProfitSummary::UNKNOWN, $rows[3002]->profit->status() );
		self::assertSame( 0, $rows[3002]->taxed_rows );
		self::assertSame( ProfitSummary::INCOMPLETE, $rows[3003]->profit->status() );
		self::assertNull( $rows[3003]->profit->amount_units(), 'Never a partial sum.' );
		self::assertSame( array( 1, 1 ), array( $rows[3003]->profit->known_rows, $rows[3003]->profit->unknown_rows ) );
	}

	public function test_the_list_status_agrees_with_the_creator_endpoints_aggregation(): void {
		$profits = new LedgerCreatorProfits( $this->wpdb );

		foreach ( $this->by_order( $this->history->page( new OrderHistoryFilter( null, $this->alpha ), new Pagination( 1, 20 ) ) ) as $order_id => $row ) {
			$lines = array_filter( $profits->by_order_line( $this->alpha, array( $order_id ) ), static fn( array $line ): bool => $line['order_id'] === $order_id );
			$sum   = array_reduce( $lines, static fn( ?ProfitSummary $carry, array $line ): ProfitSummary => null === $carry ? $line['profit'] : $carry->plus( $line['profit'] ) );

			self::assertInstanceOf( ProfitSummary::class, $sum );
			self::assertEquals( $sum, $row->profit, 'Order ' . $order_id );
		}

		self::assertInstanceOf( CreatorProfits::class, $profits );
	}

	public function test_pagination_and_filters_run_on_the_server(): void {
		self::assertSame( array( 3004, 3003 ), $this->order_ids( new OrderHistoryFilter(), 1, 2 ), 'Newest first, then by order id.' );
		self::assertSame( array( 3002, 3001 ), $this->order_ids( new OrderHistoryFilter(), 2, 2 ) );
		self::assertSame( array(), $this->history->page( new OrderHistoryFilter(), new Pagination( 3, 2 ) ) );

		self::assertSame( array( 3002 ), $this->order_ids( new OrderHistoryFilter( 3002 ) ) );
		self::assertSame( array( 3004 ), $this->order_ids( new OrderHistoryFilter( null, $this->beta ) ) );
		self::assertSame( array( 3003, 3002, 3001 ), $this->order_ids( new OrderHistoryFilter( null, $this->alpha ) ) );
		self::assertSame( array( 3004, 3001 ), $this->order_ids( new OrderHistoryFilter( null, null, ProfitSummary::COMPLETE ) ) );
		self::assertSame( array( 3003 ), $this->order_ids( new OrderHistoryFilter( null, null, ProfitSummary::INCOMPLETE ) ) );
		self::assertSame( array( 3002 ), $this->order_ids( new OrderHistoryFilter( null, null, ProfitSummary::UNKNOWN ) ) );
		self::assertSame( 1, $this->history->count( new OrderHistoryFilter( null, $this->alpha, ProfitSummary::UNKNOWN ) ) );
		self::assertSame( 0, $this->history->count( new OrderHistoryFilter( 999999 ) ) );
		self::assertSame( array( 3004, 3003, 3001 ), $this->order_ids( new OrderHistoryFilter( null, null, OrderHistoryFilter::WITH_PROFIT_DATA ) ), 'Complete and incomplete, not unknown.' );
		self::assertSame( array( 3003, 3001 ), $this->order_ids( new OrderHistoryFilter( null, $this->alpha, OrderHistoryFilter::WITH_PROFIT_DATA ) ) );
	}

	public function test_orders_without_profit_data_are_hidden_by_default_and_shown_on_request_without_deleting(): void {
		$before = $this->counts();

		$default = $this->render( array() );
		self::assertStringContainsString( '3 orders', $default );
		self::assertStringNotContainsString( 'order=3002', $default, 'The legacy order is hidden.' );
		self::assertStringContainsString( '1 order without profit data is hidden', $default );
		self::assertStringContainsString( 'Nothing is deleted.', $default );
		self::assertStringContainsString( 'status=all', $default, 'A link shows every order.' );

		$all = $this->render( array( 'status' => 'all' ) );
		self::assertStringContainsString( '4 orders', $all );
		self::assertStringContainsString( 'order=3002', $all );
		self::assertStringNotContainsString( 'without profit data is hidden', $all );

		$beta = $this->render( array( 'creator' => (string) $this->beta ) );
		self::assertStringNotContainsString( 'without profit data is hidden', $beta, 'Nothing hidden for a creator without such orders.' );

		self::assertStringContainsString( 'Merch Dashboard has no stored history for order #424242.', $this->render( array( 'order' => '424242' ) ) );
		self::assertStringContainsString( 'Order #3002', $this->render( array( 'order' => '3002' ) ), 'A hidden order can still be opened.' );
		self::assertEquals( new OrderHistoryFilter( null, null, OrderHistoryFilter::WITH_PROFIT_DATA ), OrderHistoryPage::parameters( array() )['filter'] );
		self::assertEquals( new OrderHistoryFilter(), OrderHistoryPage::parameters( array( 'status' => 'all' ) )['filter'] );
		self::assertSame( array( 'status' => 'all' ), OrderHistoryPage::parameters( array( 'status' => 'all' ) )['values'], 'Kept in the pagination links.' );
		self::assertSame( $before, $this->counts(), 'Hiding writes and deletes nothing.' );
		self::assertSame( array( 3002 ), $this->order_ids( new OrderHistoryFilter( null, null, ProfitSummary::UNKNOWN ) ), 'The ledger still has the order.' );
	}

	public function test_the_details_diagnose_each_event_with_its_reason(): void {
		$order = $this->details->for_order( 3003 );
		self::assertNotNull( $order );

		$by_item = array();
		foreach ( $order['events'] as $event ) {
			$by_item[ $event['entry']->order_item_id ] = $event['diagnosis'];
		}

		self::assertSame( 200000, $by_item[30031]->profit );
		self::assertNull( $by_item[30031]->gap );
		self::assertNull( $by_item[30032]->profit );
		self::assertSame( ProfitGap::NoSnapshot, $by_item[30032]->gap, 'Line created before the product had a creator.' );
		self::assertSame( array( 30031, 30032 ), array_keys( $order['lines'] ) );

		$legacy = $this->details->for_order( 3002 );
		self::assertNotNull( $legacy );
		self::assertSame( ProfitGap::NoSnapshot, $legacy['events'][0]['diagnosis']->gap );
		self::assertNull( $legacy['events'][0]['tax'], 'No stored tax either.' );

		$complete = $this->details->for_order( 3001 );
		self::assertSame( array( 'sale', 'sale', 'refund' ), array_map( static fn( array $e ): string => $e['entry']->type->value, $complete['events'] ?? array() ) );
		self::assertSame( array( 3, 1 ), array( count( $complete['events'] ?? array() ), count( $complete['cycles']->events ?? array() ) ) );
		self::assertNull( $this->details->for_order( 999999 ) );
	}

	public function test_missing_tax_legacy_mismatched_and_unreadable_snapshots_have_their_own_reason(): void {
		// A row with a snapshot but without stored tax.
		$this->wpdb->delete( $this->table( Tables::SALES_TAX ), array( 'order_item_id' => 30041 ) );
		self::assertSame( ProfitGap::NoTax, $this->details->for_order( 3004 )['events'][0]['diagnosis']->gap ?? null );

		// A snapshot of the withdrawn model, one of another creator and an unreadable one.
		$this->order( 3005, array( $this->line( 30051, 20, 1, '80', '20', $this->alpha ) ) );
		$this->order( 3006, array( $this->line( 30061, 20, 1, '80', '20', $this->alpha ) ) );
		$this->order( 3007, array( $this->line( 30071, 20, 1, '80', '20', $this->alpha ) ) );
		$this->snapshot( 30051, 3005, $this->alpha, 'priced', null );
		$this->snapshot( 30061, 3006, $this->beta, 'share', 2000 );
		$this->snapshot( 30071, 3007, $this->alpha, 'share', 20000 );

		self::assertSame( ProfitGap::LegacySnapshot, $this->details->for_order( 3005 )['events'][0]['diagnosis']->gap ?? null );
		self::assertSame( ProfitGap::SnapshotMismatch, $this->details->for_order( 3006 )['events'][0]['diagnosis']->gap ?? null );
		self::assertSame( ProfitGap::InvalidSnapshot, $this->details->for_order( 3007 )['events'][0]['diagnosis']->gap ?? null );
		self::assertSame( ProfitSummary::UNKNOWN, $this->by_order( $this->history->page( new OrderHistoryFilter( 3007 ), new Pagination( 1, 20 ) ) )[3007]->profit->status(), 'An out-of-range share is unknown in the list too.' );
	}

	public function test_the_page_renders_orders_and_details_without_customer_data_and_writes_nothing(): void {
		$before = $this->counts();

		$list = $this->render( array( 'status' => 'all' ) );
		foreach ( array( '#3001', '#3002', '#3003', '#3004', 'History Alpha', 'History Beta', 'Complete', 'Incomplete', 'Unknown', '4 orders', '3 events / 2 lines', '3 of 3 rows', '0 of 1 rows', '1 known, 1 without data' ) as $text ) {
			self::assertStringContainsString( $text, $list, $text );
		}

		$order = $this->render( array( 'order' => '3003' ) );
		self::assertStringContainsString( 'Order #3003', $order );
		self::assertStringContainsString( esc_html( OrderHistoryPage::gap_text( ProfitGap::NoSnapshot ) ), $order );
		self::assertStringContainsString( 'Pending payment', $order, 'WooCommerce status through the lookup.' );
		self::assertStringContainsString( 's:30031:1', $order );

		$filtered = $this->render(
			array(
				'status'  => 'unknown',
				'creator' => (string) $this->alpha,
			)
		);
		self::assertStringContainsString( '#3002', $filtered );
		self::assertStringNotContainsString( '#3001', $filtered );

		foreach ( array( $list, $order ) as $html ) {
			foreach ( array( 'MB line', 'billing', 'email', '@' ) as $forbidden ) {
				self::assertStringNotContainsString( $forbidden, $html, 'No item names or customer data.' );
			}
		}

		self::assertSame( $before, $this->counts(), 'Rendering writes nothing.' );
	}

	public function test_settled_rows_show_their_stored_document_and_link_the_payout_only_with_the_payout_capability(): void {
		$keys = array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT event_key FROM %i WHERE order_id = 3001 ORDER BY id', $this->table( Tables::SALES ) ) ) );
		self::assertCount( 3, $keys );
		$this->settle( 'AFR-000001', 77, array_slice( $keys, 0, 2 ), 3001 );
		$before = $this->counts();
		$link   = '<a href="' . esc_url( PayoutsPage::url( array( 'payout' => 77 ) ) ) . '">AFR-000001</a>';

		$list = $this->render( array( 'status' => 'all' ) );
		self::assertStringContainsString( '<th scope="col">Settlement</th>', $list );
		self::assertStringContainsString( '<td>2 of 3 rows<br>' . $link . '</td></tr>', $list, 'Order 3001: two of its three rows are in the document.' );
		self::assertSame( 3, substr_count( $list, '<td>Not settled</td></tr>' ), 'The other three orders.' );

		$order = $this->render( array( 'order' => '3001' ) );
		self::assertSame( 2, substr_count( $order, '<td>' . $link . '</td></tr>' ) );
		self::assertSame( 1, substr_count( $order, '<td>Not settled</td></tr>' ), 'The refund is not settled yet.' );

		$manager = $this->create_user( 'mb-history-manager' );
		( new WP_User( $manager ) )->add_cap( Capabilities::MANAGE_CREATORS );
		wp_set_current_user( $manager );

		foreach ( array( $this->render( array( 'status' => 'all' ) ), $this->render( array( 'order' => '3001' ) ) ) as $html ) {
			self::assertStringContainsString( 'AFR-000001', $html, 'The document number is visible.' );
			self::assertStringNotContainsString( 'page=' . PayoutsPage::SLUG, $html, 'No payout link without merchboard_manage_payouts.' );
		}

		self::assertSame( $before, $this->counts(), 'Rendering writes nothing.' );
	}

	public function test_with_a_creator_filter_the_settlement_column_counts_only_that_creators_rows(): void {
		// 3005: one line of each creator, each settled in its creator's own document.
		$tee = $this->product( 'Tee 3005', $this->alpha );
		$mug = $this->product( 'Mug 3005', $this->beta );
		$this->recorder->record( 30051, 3005, $tee, 0, 'DKK' );
		$this->recorder->record( 30052, 3005, $mug, 0, 'DKK' );
		$this->order( 3005, array( $this->line( 30051, $tee, 1, '80', '20', $this->alpha ), $this->line( 30052, $mug, 1, '80', '20', $this->beta ) ) );
		$key = fn( int $creator ): string => (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT event_key FROM %i WHERE order_id = 3005 AND creator_id = %d', $this->table( Tables::SALES ), $creator ) );
		$this->settle( 'AFR-000001', 77, array( $key( $this->alpha ) ), 3005 );
		$this->settle( 'AFR-000002', 78, array( $key( $this->beta ) ), 3005, $this->beta );
		$row = static fn( string $html ): string => 1 === preg_match( '/#3005<\/a>.*?<\/tr>/s', $html, $match ) ? $match[0] : '';

		$all = $row( $this->render( array( 'status' => 'all' ) ) );
		self::assertStringContainsString( '<td>2 of 2 rows<br>', $all, 'Both creators\' rows without a filter.' );
		self::assertStringContainsString( 'AFR-000001', $all );
		self::assertStringContainsString( 'AFR-000002', $all );

		foreach ( array( array( $this->alpha, 'AFR-000001', 'AFR-000002' ), array( $this->beta, 'AFR-000002', 'AFR-000001' ) ) as list( $creator, $own, $other ) ) {
			$html = $row(
				$this->render(
					array(
						'status'  => 'all',
						'creator' => (string) $creator,
					)
				)
			);
			self::assertStringContainsString( '<td>1 of 1 rows<br>', $html, 'Only the filtered creator\'s row, never "2 of 1".' );
			self::assertStringContainsString( $own, $html );
			self::assertStringNotContainsString( $other, $html, 'Not the other creator\'s document.' );
		}
	}

	public function test_without_settlement_data_the_settlement_column_is_not_shown(): void {
		$page = new OrderHistoryPage( $this->history, $this->details, $this->lookup(), new CreatorRepository( $this->wpdb ), new RecordingLogger() );

		$views = array(
			'#3001'         => array( 'status' => 'all' ),
			'Ledger events' => array( 'order' => '3001' ),
		);

		foreach ( $views as $shown => $get ) {
			$_GET = $get;
			$html = '';
			ob_start();

			try {
				$page->render();
			} finally {
				$html = (string) ob_get_clean();
				$_GET = array();
			}

			self::assertStringContainsString( $shown, $html, 'The view is rendered.' );
			self::assertStringNotContainsString( '>Settlement</th>', $html );
			self::assertStringNotContainsString( 'Not settled', $html );
		}
	}

	public function test_g1_an_order_the_plugin_recorded_is_labelled_never_as_a_confirmed_payout_status(): void {
		$registry                             = $this->with_registry();
		$payout                               = $this->gift_card_payout( '601' );
		$this->fake->cards['MB-HISTORY-CARD'] = new GiftCardSnapshot( '601', 200000, 0, 'DKK', GiftCardStatus::Active, null, true );
		$registry->orders                     = array( '601' => array( 3001 ) );
		// An E2 assessment exists; the plugin's record neither shows nor changes it.
		$this->wpdb->insert( $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), array( 'order_id' => 3001, 'status' => 'not_paid', 'updated_at' => '2026-10-10 12:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$before = $this->counts();
		$rows   = $this->by_order( $this->history->page( new OrderHistoryFilter(), new Pagination( 1, 20 ) ) );
		$label  = 'Registered by gift card plugin — payout status not confirmed';

		$list = $this->render( array( 'status' => 'all' ) );
		self::assertSame( 1, substr_count( $list, $label ), 'Only order 3001.' );
		self::assertStringContainsString( '#3001</a><br><span class="description">Pending payment</span><br><strong>' . $label . '</strong>', $list );
		self::assertStringNotContainsString( 'does not support', $list );
		self::assertStringNotContainsString( 'could not be checked', $list );

		$order = $this->render( array( 'order' => '3001' ) );
		self::assertStringContainsString( '<strong>' . $label . '</strong>: <a href="' . esc_url( PayoutsPage::url( array( 'payout' => $payout ) ) ) . '">Payout #' . $payout . '</a>', $order );
		self::assertStringContainsString( 'not decided yet (G1)', $order );
		self::assertStringNotContainsString( 'not_paid', $order, 'The assessment is not mixed into the plugin record.' );
		self::assertStringNotContainsString( $label, $this->render( array( 'order' => '3004' ) ), 'Another order.' );
		self::assertStringNotContainsString( 'MB-HISTORY-CARD', $list . $order, 'Never the code.' );

		$manager = $this->create_user( 'mb-history-g1' );
		( new WP_User( $manager ) )->add_cap( Capabilities::MANAGE_CREATORS );
		wp_set_current_user( $manager );
		self::assertStringContainsString( '<strong>' . $label . '</strong>: Payout #' . $payout . '</p>', $this->render( array( 'order' => '3001' ) ), 'The payout id without a link.' );
		wp_set_current_user( 1 );

		self::assertSame( $before, $this->counts(), 'Rendering writes nothing.' );
		self::assertEquals( $rows, $this->by_order( $this->history->page( new OrderHistoryFilter(), new Pagination( 1, 20 ) ) ), 'The ledger and its profit are unchanged.' );
	}

	public function test_g1_an_adapter_without_the_order_lookup_is_shown_as_unsupported(): void {
		$this->gift_card_payout( '601' );
		$this->fake->cards['MB-HISTORY-CARD'] = new GiftCardSnapshot( '601', 200000, 0, 'DKK', GiftCardStatus::Active, null, true );
		$before                               = $this->counts();

		$list  = $this->render( array( 'status' => 'all' ) );
		$order = $this->render( array( 'order' => '3001' ) );

		foreach ( array( $list, $order ) as $html ) {
			self::assertStringContainsString( 'Gift card plugin does not support order registration lookup', $html );
			self::assertStringNotContainsString( 'Gift card order registration could not be checked', $html );
			self::assertStringNotContainsString( 'Registered by gift card plugin', $html );
		}

		self::assertStringContainsString( 'Events / lines', $list, 'The list itself is rendered.' );
		self::assertSame( $before, $this->counts() );
	}

	public function test_g1_a_lookup_that_cannot_be_done_is_shown_as_not_checked(): void {
		$this->gift_card_payout( '601' );
		$registry                             = $this->with_registry();
		$this->fake->cards['MB-HISTORY-CARD'] = new GiftCardSnapshot( '601', 200000, 0, 'DKK', GiftCardStatus::Active, null, true );
		$registry->orders                     = array( '601' => array( 3001 ) );

		foreach ( array(
			'plugin inactive' => function (): void {
				$this->fake->availability = GiftCardAvailability::unavailable( GiftCardAvailability::INACTIVE );
			},
			'lookup failed'   => static function () use ( $registry ): void {
				$registry->registry_fail = new GiftCardProviderException( GiftCardProviderException::READ_FAILED );
			},
		) as $label => $arrange ) {
			$this->fake->availability = GiftCardAvailability::available( '1.0.0' );
			$registry->registry_fail  = null;
			$arrange();

			foreach ( array( $this->render( array( 'status' => 'all' ) ), $this->render( array( 'order' => '3001' ) ) ) as $html ) {
				self::assertStringContainsString( 'Gift card order registration could not be checked', $html, $label );
				self::assertStringNotContainsString( 'does not support', $html, $label );
				self::assertStringNotContainsString( 'Registered by gift card plugin', $html, $label );
			}
		}

		$this->fake->availability = GiftCardAvailability::available( '1.0.0' );
		$registry->registry_fail  = null;
		$registry->registry       = GiftCardAvailability::unavailable( GiftCardAvailability::SELF_TEST_FAILED, '1.0.0' );
		self::assertStringContainsString( 'Gift card plugin does not support order registration lookup', $this->render( array( 'order' => '3001' ) ), 'The plugin lacks the record: unsupported, not failed.' );
	}

	public function test_g1_the_history_does_not_depend_on_g1_activation_or_cut_off(): void {
		$this->gift_card_payout( '601' );
		$registry                             = $this->with_registry();
		$this->fake->cards['MB-HISTORY-CARD'] = new GiftCardSnapshot( '601', 200000, 0, 'DKK', GiftCardStatus::Active, null, true );
		$registry->orders                     = array( '601' => array( 3001 ) );
		$label                                = '<strong>Registered by gift card plugin — payout status not confirmed</strong>';

		self::assertStringContainsString( $label, $this->render( array( 'status' => 'all' ) ), 'G1 not activated.' );
		self::assertStringContainsString( $label, $this->render( array( 'order' => '3001' ) ), 'G1 not activated.' );

		// Activated with a cut-off after the order (2026-10-09): the order is before the cut-off.
		self::assertSame( 1, $this->execute( "UPDATE %i SET cutoff_utc = %s, activated_at = '2026-08-31 00:00:00', activated_by = 1 WHERE id = 1", $this->table( Tables::G1_STATE ), '2027-01-01 00:00:00' ) );
		self::assertStringContainsString( $label, $this->render( array( 'status' => 'all' ) ), 'Activated, the order before the cut-off.' );
		self::assertStringContainsString( $label, $this->render( array( 'order' => '3001' ) ), 'Activated, the order before the cut-off.' );
	}

	public function test_g1_unreadable_payouts_are_not_checked_and_the_ledger_is_still_shown(): void {
		$this->gift_card_payout( '601' );
		$this->fake->cards['MB-HISTORY-CARD'] = new GiftCardSnapshot( '601', 200000, 0, 'DKK', GiftCardStatus::Active, null, true );
		$logger                               = new RecordingLogger();
		$this->page_logger                    = $logger;
		// Only the read of the registered payout cards fails.
		add_filter( 'query', static fn( string $query ): string => str_contains( $query, 'gift_card_provider IS NOT NULL' ) ? 'SELECT * FROM merchboard_no_such_table' : $query );
		$this->wpdb->suppress_errors( true );

		$list  = $this->render( array( 'status' => 'all' ) );
		$order = $this->render( array( 'order' => '3001' ) );

		self::assertStringContainsString( '#3001</a>', $list, 'The ledger list is shown.' );
		self::assertStringContainsString( '#3004</a>', $list );
		self::assertStringContainsString( 'Ledger events', $order, 'The order\'s ledger is shown.' );
		$this->assert_not_checked( array( $list, $order ) );
		self::assertSame( array_fill( 0, 2, array( 'error', 'The payout gift cards could not be read for the order history.', array( 'error' => DatabaseException::class ) ) ), $this->page_log() );
		self::assertStringNotContainsString( 'MB-HISTORY-CARD', (string) wp_json_encode( $logger->entries ) );
	}

	public function test_g1_an_unexpected_adapter_error_is_not_checked_and_logged_by_class_only(): void {
		$this->gift_card_payout( '601' );
		$registry                             = $this->with_registry();
		$this->fake->cards['MB-HISTORY-CARD'] = new GiftCardSnapshot( '601', 200000, 0, 'DKK', GiftCardStatus::Active, null, true );
		$registry->orders                     = array( '601' => array( 3001 ) );
		$registry->registry_fail              = new RuntimeException( 'boom MB-HISTORY-CARD 3001' );
		$logger                               = new RecordingLogger();
		$this->page_logger                    = $logger;

		$list  = $this->render( array( 'status' => 'all' ) );
		$order = $this->render( array( 'order' => '3001' ) );

		self::assertStringContainsString( '#3001</a>', $list, 'The ledger list is shown.' );
		self::assertStringContainsString( 'Ledger events', $order, 'The order\'s ledger is shown.' );
		$this->assert_not_checked( array( $list, $order ) );
		self::assertSame( array_fill( 0, 2, array( 'error', 'The payout gift cards could not be read for the order history.', array( 'error' => RuntimeException::class ) ) ), $this->page_log() );
		self::assertStringNotContainsString( 'boom', (string) wp_json_encode( $logger->entries ), 'Never the exception\'s message.' );
	}

	public function test_a_ledger_error_is_still_shown_as_an_order_history_error(): void {
		$this->gift_card_payout( '601' );
		$this->page_logger = new RecordingLogger();
		$ledger            = $this->table( Tables::SALES );
		add_filter( 'query', static fn( string $query ): string => str_starts_with( ltrim( $query ), 'SELECT' ) && str_contains( $query, $ledger ) ? 'SELECT * FROM merchboard_no_such_table' : $query );
		$this->wpdb->suppress_errors( true );

		// The list (OrderHistory throws on a failed query); the gift card lookup is never reached.
		$html = $this->render( array( 'status' => 'all' ) );

		self::assertStringContainsString( 'The order history could not be read. Please try again later.', $html );
		self::assertStringNotContainsString( 'Gift card order registration could not be checked', $html );
		self::assertSame( array( array( 'error', 'The order history could not be read.', array( 'error' => DatabaseException::class ) ) ), $this->page_log() );

		// The order's page: only its ledger row SELECT (SalesRepository::entries_for_order()) fails.
		remove_all_filters( 'query' );
		$select = 'SELECT id, event_key, creator_id, order_id, order_item_id, cycle, refund_id, product_id, variation_id, item_name, type, correction_reason, quantity, net_amount, currency, occurred_at, created_at FROM `' . $ledger . '`';
		$failed = 0;
		add_filter(
			'query',
			static function ( string $query ) use ( $select, &$failed ): string {
				if ( ! str_starts_with( $query, $select ) ) {
					return $query;
				}

				++$failed;

				return 'SELECT * FROM merchboard_no_such_table';
			}
		);

		$order = $this->render( array( 'order' => '3001' ) );

		self::assertSame( 1, $failed, 'Only the order\'s ledger read failed.' );
		self::assertStringContainsString( 'The order history could not be read. Please try again later.', $order );
		self::assertStringNotContainsString( 'has no stored history', $order, 'A read error is never shown as a missing history.' );
		self::assertStringNotContainsString( 'Ledger events', $order );
		self::assertStringNotContainsString( 'Gift card order registration could not be checked', $order );
		self::assertSame( array_fill( 0, 2, array( 'error', 'The order history could not be read.', array( 'error' => DatabaseException::class ) ) ), $this->page_log() );
	}

	public function test_the_page_offers_no_deletion_or_other_write_action(): void {
		$before = $this->counts();

		// The list and an order that is still active in WooCommerce: no write action at all.
		foreach ( array( array(), array( 'status' => 'all' ), array( 'order' => '3001' ), array( 'order' => '3002' ) ) as $get ) {
			$html = $this->render( $get );

			self::assertDoesNotMatchRegularExpression( '/method=["\']post|admin-post\.php|_wpnonce|name=["\']action["\']/i', $html, 'Only the GET filter form.' );
			self::assertDoesNotMatchRegularExpression( '/<button[^>]*>\s*(Delete|Remove|Erase|Purge)\b/i', $html, 'No deletion control.' );
			self::assertStringContainsString( 'The sales ledger, profit snapshots and stored tax are append-only. The one exception', $html );
		}

		self::assertStringContainsString( 'can only be deleted when the WooCommerce order is in the trash or deleted', $this->render( array( 'order' => '3001' ) ) );
		self::assertSame( $before, $this->counts() );
	}

	public function test_invalid_parameters_are_ignored_with_a_notice(): void {
		$html = $this->render(
			array(
				'order_id' => '1 OR 1=1',
				'creator'  => '-3',
				'status'   => 'everything',
				'paged'    => 'abc',
			)
		);

		self::assertStringContainsString( 'Some filter values were invalid and have been ignored.', $html );
		self::assertStringContainsString( '3 orders', $html, 'Only the default (orders with profit data) was applied.' );

		$parameters = OrderHistoryPage::parameters(
			array(
				'order'    => '0',
				'order_id' => '3001',
				'status'   => 'complete',
				'paged'    => '2',
			)
		);
		self::assertNull( $parameters['order'] );
		self::assertSame( array( 'order' ), $parameters['invalid'] );
		self::assertEquals( new OrderHistoryFilter( 3001, null, 'complete' ), $parameters['filter'] );
		self::assertSame( 2, $parameters['page'] );
		self::assertStringContainsString( 'Merch Dashboard has no stored history for order #424242.', $this->render( array( 'order' => '424242' ) ) );
	}

	public function test_an_admin_deletes_only_the_trashed_orders_history_in_one_audited_transaction(): void {
		$this->woo[3001] = 'trash';
		$others          = $this->order_rows( array( 3002, 3003, 3004 ) );
		$audit_before    = $this->count_rows( Tables::AUDIT_LOG );

		$result = $this->eraser()->erase( 3001 );

		self::assertSame(
			array(
				'ledger_rows'       => 3,
				'cycle_events'      => 1,
				'tax_rows'          => 3,
				'snapshots'         => 2,
				'creator_ids'       => array( $this->alpha ),
				'woocommerce_order' => 'trash',
			),
			$result
		);
		self::assertSame( array( 0, 0, 0, 0 ), $this->order_rows( array( 3001 ) ), 'Nothing of the order is left.' );
		self::assertSame( $others, $this->order_rows( array( 3002, 3003, 3004 ) ), 'Other orders are untouched.' );
		self::assertNull( $this->details->for_order( 3001 ) );
		self::assertSame( array( 3004, 3003, 3002 ), $this->order_ids( new OrderHistoryFilter() ) );
		self::assertSame( array( array( $this->alpha ) ), $this->cache->calls, 'The creator\'s sales statistics are recomputed.' );

		self::assertSame( $audit_before + 1, $this->count_rows( Tables::AUDIT_LOG ) );
		$audit = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT action, actor_user_id, creator_id, context FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ) ), ARRAY_A );
		self::assertIsArray( $audit );
		self::assertSame( AuditAction::OrderHistoryDeleted->value, $audit['action'] );
		self::assertSame( '1', $audit['actor_user_id'] );
		self::assertNull( $audit['creator_id'] );
		self::assertSame(
			array(
				'order_id'          => 3001,
				'woocommerce_order' => 'trash',
				'ledger_rows'       => 3,
				'cycle_events'      => 1,
				'tax_rows'          => 3,
				'snapshots'         => 2,
				'creator_ids'       => array( $this->alpha ),
			),
			json_decode( (string) $audit['context'], true ),
			'Counts and ids only: no amounts, no customer data.'
		);

		// The trashed order is skipped by the synchronization (ADR-038 Q8) and its permanent deletion adds nothing.
		self::assertArrayHasKey( 3001, $this->reader->orders );
		$this->reader->orders[3001] = array( 'status' => 'trash' ) + $this->reader->orders[3001];
		$this->sync->sync( 3001 );
		unset( $this->reader->orders[3001] );
		$this->sync->order_deleting( 3001 );
		self::assertSame( array( 0, 0, 0, 0 ), $this->order_rows( array( 3001 ) ), 'Not registered again.' );
	}

	public function test_a_deleted_woocommerce_order_can_be_erased_but_an_active_one_or_one_without_history_cannot(): void {
		$this->woo[3002] = 'deleted';
		self::assertSame( 'deleted', $this->eraser()->erase( 3002 )['woocommerce_order'] );

		$before            = $this->counts();
		$this->woo[424242] = 'deleted';

		$expected = array(
			3004   => OrderHistoryEraseException::ORDER_ACTIVE,
			424242 => OrderHistoryEraseException::NO_HISTORY,
		);

		foreach ( $expected as $order_id => $reason ) {
			try {
				$this->eraser()->erase( $order_id );
				self::fail( 'Expected a rejection.' );
			} catch ( OrderHistoryEraseException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		self::assertSame( $before, $this->counts(), 'Nothing deleted and nothing audited.' );
	}

	public function test_a_failing_audit_record_rolls_the_deletion_back(): void {
		$this->woo[3001] = 'trash';
		$before          = $this->counts();
		add_filter(
			'query',
			static fn( string $query ): string => str_starts_with( ltrim( $query ), 'INSERT' ) && str_contains( $query, Tables::AUDIT_LOG ) ? 'INSERT INTO merchboard_no_such_table VALUES (1)' : $query
		);

		$suppressed = $this->wpdb->suppress_errors( true );

		try {
			$this->eraser()->erase( 3001 );
			self::fail( 'Expected a failure.' );
		} catch ( OrderHistoryEraseException $e ) {
			self::assertSame( OrderHistoryEraseException::FAILED, $e->reason() );
		} finally {
			$this->wpdb->suppress_errors( $suppressed );
			remove_all_filters( 'query' );
		}

		self::assertSame( $before, $this->counts(), 'The deletes were rolled back with the audit record.' );
		self::assertNotNull( $this->details->for_order( 3001 ) );
		self::assertSame( array(), $this->cache->calls, 'A rolled-back deletion invalidates nothing.' );
	}

	public function test_the_request_needs_the_capability_a_matching_nonce_and_the_typed_order_id(): void {
		$this->woo[3001] = 'trash';
		$actions         = new OrderHistoryActions( $this->eraser() );
		$nonce           = wp_create_nonce( OrderHistoryActions::nonce_action( 3001 ) );
		$before          = $this->counts();

		foreach ( array( wp_create_nonce( OrderHistoryActions::nonce_action( 3004 ) ), 'invalid', '' ) as $wrong ) {
			$this->assert_refused(
				static fn() => $actions->handle(
					array(
						'order_id'         => '3001',
						'confirm_order_id' => '3001',
						'_wpnonce'         => $wrong,
					)
				),
				'expired'
			);
		}

		foreach ( array( '3004', '', ' 3001x' ) as $typed ) {
			$url = $actions->handle(
				array(
					'order_id'         => '3001',
					'confirm_order_id' => $typed,
					'_wpnonce'         => $nonce,
				)
			);
			self::assertStringContainsString( 'erase_error=not_confirmed', $url );
		}

		self::assertSame( $before, $this->counts() );

		$editor = $this->create_user( 'mb-t22-history-editor' );
		( new WP_User( $editor ) )->set_role( 'editor' );
		wp_set_current_user( $editor );
		$this->assert_refused(
			static fn() => $actions->handle(
				array(
					'order_id'         => '3001',
					'confirm_order_id' => '3001',
					'_wpnonce'         => $nonce,
				)
			),
			'not allowed'
		);

		try {
			$this->eraser()->erase( 3001 );
			self::fail( 'Expected a rejection.' );
		} catch ( OrderHistoryEraseException $e ) {
			self::assertSame( OrderHistoryEraseException::NOT_AUTHORIZED, $e->reason() );
		}

		self::assertSame( $before, $this->counts() );

		wp_set_current_user( 1 );
		$url = $actions->handle(
			array(
				'order_id'         => '3001',
				'confirm_order_id' => '3001',
				'_wpnonce'         => $nonce,
			)
		);
		self::assertStringContainsString( 'erased=3001', $url );
		self::assertNull( $this->details->for_order( 3001 ) );
		self::assertStringContainsString( 'The stored history of order #3001 was deleted.', $this->render( array( 'erased' => '3001' ) ) );
		self::assertStringContainsString(
			'the typed order ID did not match',
			$this->render(
				array(
					'order'       => '3002',
					'erase_error' => 'not_confirmed',
				)
			)
		);
	}

	public function test_the_delete_form_is_offered_only_for_a_trashed_or_deleted_order_and_only_with_the_capability(): void {
		$this->woo[3001] = 'trash';
		$html            = $this->render( array( 'order' => '3001' ) );

		self::assertMatchesRegularExpression( '/<form method="post" action="[^"]*admin-post\.php">/', $html );
		self::assertStringContainsString( 'value="' . OrderHistoryActions::ACTION . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $html );
		self::assertStringContainsString( 'name="confirm_order_id"', $html );
		self::assertStringContainsString( 'This cannot be undone.', $html );

		$admin = wp_get_current_user();
		$admin->add_cap( Capabilities::DELETE_ORDER_HISTORY, false );

		try {
			self::assertFalse( current_user_can( Capabilities::DELETE_ORDER_HISTORY ) );
			$without = $this->render( array( 'order' => '3001' ) );
			self::assertStringNotContainsString( 'admin-post.php', $without );
			self::assertStringNotContainsString( 'Delete stored history', $without );
		} finally {
			$admin->remove_cap( Capabilities::DELETE_ORDER_HISTORY );
		}
	}

	public function test_users_without_the_capability_are_refused(): void {
		$editor = $this->create_user( 'mb-history-editor' );
		( new WP_User( $editor ) )->set_role( 'editor' );
		wp_set_current_user( $editor );

		try {
			$this->render( array() );
			self::fail( 'Refused expected.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'not allowed to view the order history', $e->getMessage() );
		}

		wp_set_current_user( 0 );

		try {
			$this->render( array() );
			self::fail( 'Refused expected.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'not allowed', $e->getMessage() );
		}
	}

	public function test_the_page_is_a_submenu_of_merch_dashboard_with_the_creators_capability(): void {
		global $submenu;
		wp_set_current_user( 1 );
		$this->page()->add_menu();

		$entries = array_values( array_filter( (array) ( $submenu['merchboard'] ?? array() ), static fn( array $entry ): bool => OrderHistoryPage::SLUG === ( $entry[2] ?? null ) ) );
		self::assertCount( 1, $entries );
		self::assertSame( 'merchboard_manage_creators', $entries[0][1] );
	}

	/**
	 * The page with a lookup that does not need WooCommerce.
	 */
	private function page(): OrderHistoryPage {
		return new OrderHistoryPage( $this->history, $this->details, $this->lookup(), new CreatorRepository( $this->wpdb ), $this->page_logger ?? new RecordingLogger(), new SettlementRepository( $this->wpdb ), new PayoutGiftCardOrders( new PayoutRepository( $this->wpdb ), new GiftCardProviders( array( $this->registry ?? $this->fake ) ), new RecordingLogger() ) );
	}

	/**
	 * The page's log as [level, message, context].
	 *
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function page_log(): array {
		self::assertNotNull( $this->page_logger );

		return array_map( static fn( array $entry ): array => array( $entry['level'], $entry['message'], $entry['context'] ), $this->page_logger->entries );
	}

	/**
	 * Requires "could not be checked", never the plugin's record or "unsupported".
	 *
	 * @phpstan-param list<string> $views
	 *
	 * @param string[] $views Rendered HTML.
	 */
	private function assert_not_checked( array $views ): void {
		foreach ( $views as $html ) {
			self::assertStringContainsString( 'Gift card order registration could not be checked', $html );
			self::assertStringNotContainsString( 'The order history could not be read', $html );
			self::assertStringNotContainsString( 'Registered by gift card plugin', $html );
			self::assertStringNotContainsString( 'does not support', $html );
		}
	}

	/**
	 * Uses the fake with the optional order lookup from now on.
	 */
	private function with_registry(): FakeOrderRegistryGiftCardProvider {
		$registry       = new FakeOrderRegistryGiftCardProvider( $this->fake );
		$this->registry = $registry;

		return $registry;
	}

	/**
	 * A completed gift card payout of creator A with a registered card (synthetic row).
	 *
	 * @param string $card Card id in the fake provider.
	 * @return int Payout id.
	 */
	private function gift_card_payout( string $card ): int {
		self::assertSame(
			1,
			$this->wpdb->insert(
				$this->table( Tables::PAYOUTS ),
				array(
					'creator_id'            => $this->alpha,
					'currency'              => 'DKK',
					'amount'                => '20',
					'price_decimals'        => 2,
					'method'                => 'gift_card',
					'status'                => 'completed',
					'idempotency_key'       => 'key-history-g1',
					'requested_at'          => '2026-10-10 10:00:00',
					'decided_at'            => '2026-10-10 11:00:00',
					'updated_at'            => '2026-10-10 11:00:00',
					'gift_card_provider'    => 'fake',
					'gift_card_external_id' => $card,
				)
			)
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Stores a settlement document with rows for ledger events (synthetic;
	 * the issuing itself is tested in SettlementDocumentsTest).
	 *
	 * @phpstan-param list<string> $event_keys
	 *
	 * @param string   $number     Document number.
	 * @param int      $payout_id  Payout.
	 * @param string[] $event_keys Settled events.
	 * @param int      $order_id   Their order.
	 * @param int|null $creator_id The document's creator; null = creator A.
	 */
	private function settle( string $number, int $payout_id, array $event_keys, int $order_id, ?int $creator_id = null ): void {
		$this->wpdb->insert(
			$this->table( Tables::SETTLEMENT_DOCUMENTS ),
			array(
				'document_number'      => $number,
				'document_series'      => 'AFR',
				'document_type'        => 'settlement',
				'creator_id'           => $creator_id ?? $this->alpha,
				'payout_id'            => $payout_id,
				'settlement_payout_id' => $payout_id,
				'currency'             => 'DKK',
				'period_to'            => '2026-10-10 00:00:00',
				'profit_amount'        => '0',
				'commission_amount'    => '0',
				'opening_balance'      => '0',
				'payout_amount'        => '0',
				'closing_balance'      => '0',
				'calculation_version'  => 'test',
				'template_version'     => 1,
				'snapshot'             => '{}',
				'issued_at'            => '2026-10-10 00:00:00',
			)
		);
		$document = (int) $this->wpdb->insert_id;
		self::assertGreaterThan( 0, $document );

		foreach ( $event_keys as $i => $key ) {
			self::assertSame(
				1,
				$this->wpdb->insert(
					$this->table( Tables::SETTLEMENT_ROWS ),
					array(
						'event_key'      => $key,
						'document_id'    => $document,
						'order_id'       => $order_id,
						'order_item_id'  => $i + 1,
						'product_id'     => 1,
						'type'           => 'sale',
						'quantity'       => 1,
						'net_amount'     => '0',
						'tax_amount'     => '0',
						'share_bps'      => 0,
						'vat_registered' => 1,
						'price_decimals' => 2,
						'profit_amount'  => '0',
						'payout_amount'  => '0',
						'occurred_at'    => '2026-10-09 12:00:00',
					)
				)
			);
		}
	}

	/**
	 * The eraser on the test's ledger, with the lookup.
	 */
	private function eraser(): OrderHistoryEraser {
		return new OrderHistoryEraser( $this->ledger, $this->lookup(), $this->service( AuditTrail::class ), new RecordingLogger() );
	}

	/**
	 * A WooCommerce lookup without WooCommerce: every order is "Pending
	 * payment" unless the test put it in the trash or deleted it.
	 */
	private function lookup(): OrderStatusLookup {
		return new class( $this->woo ) extends OrderStatusLookup {
			/**
			 * Constructor.
			 *
			 * @param array<int, string> $woo States.
			 */
			public function __construct( private array $woo ) {
			}

			/**
			 * A fixed WooCommerce state.
			 *
			 * @param int $order_id Order id.
			 * @return array{exists: bool, number: string, status: string, edit_url: string|null}
			 */
			public function find( int $order_id ): array {
				return array(
					'exists'   => 'deleted' !== ( $this->woo[ $order_id ] ?? null ),
					'number'   => (string) $order_id,
					'status'   => 'trash' === ( $this->woo[ $order_id ] ?? null ) ? 'Trash' : 'Pending payment',
					'edit_url' => null,
				);
			}

			/**
			 * The state the test set.
			 *
			 * @param int $order_id Order id.
			 * @return 'trash'|'deleted'|null
			 */
			public function erasable_state( int $order_id ): ?string {
				$state = $this->woo[ $order_id ] ?? null;

				return 'trash' === $state || 'deleted' === $state ? $state : null;
			}
		};
	}

	/**
	 * Rows of the given orders in the ledger, cycle, tax and snapshot tables.
	 *
	 * @param int[] $order_ids Orders.
	 * @return list<int>
	 */
	private function order_rows( array $order_ids ): array {
		$in = implode( ',', array_map( 'intval', $order_ids ) );

		return array_map(
			fn( string $table ): int => (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table( $table )} WHERE order_id IN ({$in})" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Test helper: table names and integer ids only.
			array( Tables::SALES, Tables::SALES_CYCLES, Tables::SALES_TAX, Tables::SALES_LINE_PRICING )
		);
	}

	/**
	 * Asserts that a request is refused with wp_die.
	 *
	 * @param callable $request Request.
	 * @param string   $message Part of the message.
	 */
	private function assert_refused( callable $request, string $message ): void {
		try {
			$request();
			self::fail( 'Expected a refusal.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( $message, $e->getMessage() );
		}
	}

	/**
	 * Renders the page as the current user with GET parameters.
	 *
	 * @param array<string, string> $get GET parameters.
	 */
	private function render( array $get ): string {
		$_GET = $get;
		ob_start();

		try {
			$this->page()->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Rows by order id.
	 *
	 * @phpstan-param list<OrderHistoryRow> $rows
	 *
	 * @param OrderHistoryRow[] $rows Rows.
	 * @return array<int, OrderHistoryRow>
	 */
	private function by_order( array $rows ): array {
		$result = array();

		foreach ( $rows as $row ) {
			$result[ $row->order_id ] = $row;
		}

		ksort( $result );

		return $result;
	}

	/**
	 * Order ids of a page, in page order.
	 *
	 * @param OrderHistoryFilter $filter   Filter.
	 * @param int                $page     Page.
	 * @param int                $per_page Page size.
	 * @return list<int>
	 */
	private function order_ids( OrderHistoryFilter $filter, int $page = 1, int $per_page = 20 ): array {
		return array_map( static fn( OrderHistoryRow $row ): int => $row->order_id, $this->history->page( $filter, new Pagination( $page, $per_page ) ) );
	}

	/**
	 * Row counts of every ledger table.
	 *
	 * @return list<int>
	 */
	private function counts(): array {
		return array( $this->count_rows( Tables::SALES ), $this->count_rows( Tables::SALES_CYCLES ), $this->count_rows( Tables::SALES_TAX ), $this->count_rows( Tables::SALES_LINE_PRICING ), $this->count_rows( Tables::AUDIT_LOG ), $this->count_rows( Tables::PAYOUTS ), $this->count_rows( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), $this->count_rows( Tables::ORDER_GIFT_CARD_PAYOUTS ), $this->count_rows( Tables::ORDER_GIFT_CARD_ATTEMPTS ) );
	}

	/**
	 * A raw snapshot row.
	 *
	 * @param int      $item      Order item.
	 * @param int      $order_id  Order.
	 * @param int      $creator   Creator.
	 * @param string   $status    Status.
	 * @param int|null $share_bps Share.
	 */
	private function snapshot( int $item, int $order_id, int $creator, string $status, ?int $share_bps ): void {
		$this->wpdb->insert(
			$this->table( Tables::SALES_LINE_PRICING ),
			array(
				'order_item_id'  => $item,
				'order_id'       => $order_id,
				'creator_id'     => $creator,
				'product_id'     => 20,
				'variation_id'   => 0,
				'status'         => $status,
				'currency'       => 'DKK',
				'captured_at'    => '2026-09-01 10:00:00',
				'share_bps'      => $share_bps,
				'share_source'   => null === $share_bps ? null : 'creator',
				'price_decimals' => null === $share_bps ? null : 2,
				'vat_registered' => 0,
			)
		);
	}

	/**
	 * Sets an order and synchronizes it.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param int                 $order_id Order.
	 * @param OrderLineSnapshot[] $lines    Lines.
	 * @param RefundSnapshot[]    $refunds  Refunds.
	 */
	private function order( int $order_id, array $lines, array $refunds = array() ): void {
		$this->reader->orders[ $order_id ] = array(
			'status'   => 'processing',
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => $refunds,
		);
		$this->sync->sync( $order_id );
	}

	/**
	 * An order line.
	 *
	 * @param int         $item     Order item.
	 * @param int         $product  Product.
	 * @param int         $quantity Quantity.
	 * @param string      $net      Net amount.
	 * @param string|null $tax      Tax, null for unknown.
	 * @param int         $creator  Ledger creator.
	 */
	private function line( int $item, int $product, int $quantity, string $net, ?string $tax, int $creator ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, $product, 0, 'MB line', $quantity, SalesAmount::parse( $net ), $creator, null === $tax ? null : SalesAmount::parse( $tax ) );
	}

	/**
	 * A product with a price and an owner (null: no creator).
	 *
	 * @param string   $name  Name.
	 * @param int|null $owner Creator.
	 */
	private function product( string $name, ?int $owner ): int {
		$id = $this->posts->add( 'MB-T22H ' . $name, 'publish', null === $owner ? array() : array( (string) $owner ) );
		$this->posts->meta( $id, '_regular_price', '100' );
		$this->posts->meta( $id, '_price', '100' );

		return $id;
	}
}
