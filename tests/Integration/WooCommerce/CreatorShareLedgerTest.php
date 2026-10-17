<?php
/**
 * Creator share snapshots, the sales ledger and realized profit and payout on a real database (ADR-049).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkup;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesProfit;
use MerchBoard\Modules\WooCommerce\Pricing\SnapshotStatus;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
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
 * No WooCommerce is loaded (ADR-038 Q4): the snapshot is taken by the real
 * recorder, exactly as the `woocommerce_new_order_item` hook calls it; the
 * ledger rows and their actual tax come from the real synchronizer with a
 * test order reader that carries WooCommerce' line and refund tax. A sale
 * price of 100.00 incl. 25 % VAT is sold as 80.00 excl. VAT + 20.00 tax, as
 * WooCommerce splits it. Order and order item ids are synthetic.
 */
final class CreatorShareLedgerTest extends IntegrationTestCase {

	private const ORDER = 970;

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private SalesLinePricingRepository $snapshots;

	private CreatorMarkupRepository $markups;

	private ProductMarkupRepository $product_markups;

	private CreatorVatRepository $vat;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesRepository $ledger;

	private SalesProfit $profit;

	private RecordingLogger $logger;

	private int $creator;

	private int $other;

	private int $clock = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Andel A', CreatorStatus::Active )->id;
		$this->other   = $creators->create( 'Andel B', CreatorStatus::Active )->id;

		$audit                 = $this->service( AuditTrail::class );
		$transaction           = $this->service( Transaction::class );
		$this->posts           = new ProductPosts( $this->wpdb );
		$this->snapshots       = new SalesLinePricingRepository( $this->wpdb );
		$this->markups         = new CreatorMarkupRepository( $this->wpdb );
		$this->product_markups = new ProductMarkupRepository( $this->wpdb );
		$this->vat             = new CreatorVatRepository( $this->wpdb );
		$this->logger          = new RecordingLogger();
		$links                 = new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );
		$this->recorder        = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $this->markups, $this->product_markups ), $this->snapshots, $this->logger, $this->vat );
		$this->profit          = new SalesProfit( $this->snapshots, new SalesTaxRepository( $this->wpdb ) );
		$this->reader          = new FakeOrderReader();
		$this->ledger          = new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) );
		$this->sync            = new SalesSynchronizer(
			$this->ledger,
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
		);
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_the_locked_examples_with_the_markup_and_vat_status_of_each_line(): void {
		$product = $this->product( '100' );
		$cheap   = $this->product( '200' );

		$this->configure( '20', false );
		$this->recorder->record( 971, self::ORDER, $product, 0, 'DKK' );
		$this->configure( '20', true );
		$this->recorder->record( 972, self::ORDER, $product, 0, 'DKK' );
		$this->configure( '10', false );
		$this->recorder->record( 973, self::ORDER, $cheap, 0, 'DKK' );
		$this->configure( '10', true );
		$this->recorder->record( 974, self::ORDER, $cheap, 0, 'DKK' );
		$this->configure( '0', false );
		$this->recorder->record( 975, self::ORDER, $product, 0, 'DKK' );

		$this->sell(
			array(
				$this->line( 971, $product, 1, '80', '20' ),
				$this->line( 972, $product, 1, '80', '20' ),
				$this->line( 973, $cheap, 1, '160', '40' ),
				$this->line( 974, $cheap, 1, '160', '40' ),
				$this->line( 975, $product, 1, '80', '20' ),
			)
		);

		self::assertSame( array( '20.0000', '20.0000', '20.0000', '20.0000', '0.0000' ), $this->formatted( $this->profits() ) );
		self::assertSame( array( '20.0000', '20.0000', '20.0000', '20.0000', '0.0000' ), $this->formatted( $this->payouts() ), 'ADR-063: payout = profit for both VAT statuses.' );
		self::assertSame( array( '100', '', '100' ), $this->prices_of( $product ), 'The WooCommerce price is never changed.' );
		self::assertSame( array( '200', '', '200' ), $this->prices_of( $cheap ) );
	}

	public function test_the_snapshot_records_share_source_precision_and_vat_status(): void {
		$product = $this->product( '100' );
		$this->configure( '16.67', false );

		$this->recorder->record( 976, self::ORDER, $product, 0, 'DKK' );
		$row = $this->raw_snapshot( 976 );

		self::assertSame( array( 'share', '1667', 'creator', '2', '0', (string) $this->creator ), array( $row['status'], $row['share_bps'], $row['share_source'], $row['price_decimals'], $row['vat_registered'], $row['creator_id'] ) );
		self::assertNull( $row['rule'] );
		self::assertNull( $row['price_unit'], 'No price is stored: the sale price comes from the ledger row.' );

		// 99.99 incl. VAT × 16.67 % = 16.668333 → 16.67; payout = profit (ADR-063).
		$this->sell( array( $this->line( 976, $product, 1, '79.992', '19.998' ) ) );
		self::assertSame( array( '16.6700' ), $this->formatted( $this->profits() ) );
		self::assertSame( array( '16.6700' ), $this->formatted( $this->payouts() ) );
	}

	public function test_a_product_markup_replaces_the_creator_markup_for_simple_products_and_variations(): void {
		$this->configure( '20', false );
		$simple    = $this->product( '100' );
		$parent    = $this->product( '', 'variable' );
		$variation = $this->posts->add( 'MB-T22F variation', 'publish', array(), 'product_variation', $parent );
		$this->prices( $variation, '200' );
		$this->product_markups->save( new ProductMarkup( $simple, $this->creator, new MarkupBps( 5000 ) ) );
		$this->product_markups->save( new ProductMarkup( $parent, $this->creator, new MarkupBps( 1000 ) ) );

		$this->recorder->record( 977, self::ORDER, $simple, 0, 'DKK' );
		$this->recorder->record( 978, self::ORDER, $parent, $variation, 'DKK' );
		$this->sell(
			array(
				$this->line( 977, $simple, 1, '80', '20' ),
				$this->line( 978, $parent, 1, '160', '40', null, $variation ),
			)
		);

		self::assertSame( MarkupSource::Product, $this->snapshots->find( 977 )?->share_source );
		self::assertSame( $variation, $this->snapshots->find( 978 )?->variation_id );
		self::assertSame( array( '50.0000', '20.0000' ), $this->formatted( $this->profits() ) );
		self::assertSame( array( '50.0000', '20.0000' ), $this->formatted( $this->payouts() ) );
	}

	public function test_history_never_changes_when_markup_vat_status_price_or_owner_change(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 979, self::ORDER, $product, 0, 'DKK' );
		$this->sell( array( $this->line( 979, $product, 1, '80', '20' ) ) );
		$row     = $this->raw_snapshot( 979 );
		$ledger  = $this->rows( Tables::SALES );
		$profits = $this->profits();
		$payouts = $this->payouts();

		$this->configure( '50', true );
		$this->product_markups->save( new ProductMarkup( $product, $this->creator, new MarkupBps( 9000 ) ) );
		update_post_meta( $product, '_regular_price', '999' );
		update_post_meta( $product, '_price', '999' );
		$this->wpdb->update( $this->wpdb->postmeta, array( 'meta_value' => (string) $this->other ), array( 'post_id' => $product, 'meta_key' => ProductCreatorLink::META_KEY ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		( new CreatorRepository( $this->wpdb ) )->set_status( $this->creator, CreatorStatus::Inactive );
		$this->wpdb->delete( $this->wpdb->posts, array( 'ID' => $product ) );
		wp_cache_flush();
		$this->sync->sync( self::ORDER );

		self::assertSame( $row, $this->raw_snapshot( 979 ) );
		self::assertSame( $ledger, $this->rows( Tables::SALES ) );
		self::assertSame( $profits, $this->profits() );
		self::assertSame( $payouts, $this->payouts() );
		self::assertSame( array( '20.0000' ), $this->formatted( $profits ) );
		self::assertSame( array( '20.0000' ), $this->formatted( $payouts ) );
	}

	public function test_refunds_corrections_reversals_and_deletion_have_the_right_signs(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 980, self::ORDER, $product, 0, 'DKK' );
		$line = $this->line( 980, $product, 3, '240', '60' );

		$this->sell( array( $line ) );
		$this->sell( array( $line ), array( self::refund( 7201, 980, -1, '-80', '-20' ) ) );
		self::assertSame( array( '60.0000', '-20.0000' ), $this->formatted( $this->profits() ) );
		self::assertSame( array( '60.0000', '-20.0000' ), $this->formatted( $this->payouts() ) );

		// A partial amount refund of 25.00 incl. VAT.
		$refunds = array( self::refund( 7201, 980, -1, '-80', '-20' ), self::refund( 7202, 980, 0, '-20', '-5' ) );
		$this->sell( array( $line ), $refunds );
		self::assertSame( '-5.0000', $this->formatted( $this->profits() )[2] );
		self::assertSame( '-5.0000', $this->formatted( $this->payouts() )[2] );

		// The partial refund is deleted: the correction restores it.
		$this->set_order( array( $line ), array( $refunds[0] ) );
		$this->sync->sync( self::ORDER, 7202 );
		self::assertSame( '5.0000', $this->formatted( $this->payouts() )[3] );
		self::assertSame( 400000, array_sum( $this->payouts() ), '2 units remain: 2 × 20.00.' );

		// Cancelled: the reversal mirrors the remaining amount and tax.
		$this->sell( array( $line ), array( $refunds[0] ), 'cancelled' );
		self::assertSame( 0, array_sum( $this->profits() ) );
		self::assertSame( 0, array_sum( $this->payouts() ) );

		// Counted again, then the order is deleted permanently.
		$this->sell( array( $line ), array( $refunds[0] ) );
		$this->sync->order_deleting( self::ORDER );
		unset( $this->reader->orders[ self::ORDER ] );
		self::assertSame( 0, array_sum( $this->payouts() ) );
		self::assertNotContains( null, $this->payouts() );
		self::assertNotNull( $this->snapshots->find( 980 ), 'A deleted order never deletes its snapshot.' );
	}

	public function test_a_full_refund_gives_zero_profit_and_payout_with_odd_rounding(): void {
		$product = $this->product( '99.99' );
		$this->configure( '16.67', false );
		$this->recorder->record( 981, self::ORDER, $product, 0, 'DKK' );
		$this->vat->save( $this->creator, true );
		$this->recorder->record( 982, self::ORDER, $product, 0, 'DKK' );

		$lines = array( $this->line( 981, $product, 1, '79.992', '19.998' ), $this->line( 982, $product, 1, '79.992', '19.998' ) );
		$this->sell( $lines );
		$this->sell( $lines, array( self::refund( 7203, 981, -1, '-79.992', '-19.998' ), self::refund( 7204, 982, -1, '-79.992', '-19.998' ) ) );

		self::assertCount( 4, $this->entries(), 'Two sales and two refunds.' );
		self::assertSame( array( 166700, 166700, -166700, -166700 ), $this->profits() );
		self::assertSame( array( 166700, 166700, -166700, -166700 ), $this->payouts() );

		self::assertSame( 0, array_sum( $this->profits() ) );
		self::assertSame( 0, array_sum( $this->payouts() ) );
		self::assertNotContains( null, $this->payouts() );
	}

	public function test_a_store_without_taxes_uses_the_net_amount_as_sale_price(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 983, self::ORDER, $product, 0, 'DKK' );

		$this->sell( array( $this->line( 983, $product, 1, '100', '0' ) ) );

		self::assertSame( array( '20.0000' ), $this->formatted( $this->profits() ) );
		self::assertSame( array( '20.0000' ), $this->formatted( $this->payouts() ) );
	}

	public function test_unknown_cases(): void {
		$product = $this->product( '100' );
		$orphan  = $this->product( '100', 'simple', 0 );
		$this->configure( '20', false );
		$this->recorder->record( 984, self::ORDER, $product, 0, 'DKK' );
		$this->recorder->record( 985, self::ORDER, $product, 0, 'DKK' );
		$this->recorder->record( 986, self::ORDER, $orphan, 0, 'DKK' );

		$this->sell(
			array(
				$this->line( 984, $product, 1, '80', null ),                   // Tax unknown (a row from before schema version 7).
				$this->line( 985, $product, 1, '80', '20', $this->other ),     // Owner changed before the sale was counted.
				$this->line( 987, $product, 1, '80', '20' ),                   // No snapshot.
			)
		);

		$by_item = array();

		foreach ( $this->entries() as $entry ) {
			$by_item[ $entry->order_item_id ] = array( $this->profit->for_entry( $entry ), $this->profit->payout_for_entry( $entry ) );
		}

		self::assertSame( array( 984 => array( null, null ), 985 => array( null, null ), 987 => array( null, null ) ), $by_item ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertNull( $this->snapshots->find( 986 ), 'A product without a creator gets no snapshot.' );
	}

	public function test_an_unknown_tax_stays_unknown_through_corrections_and_reversals_never_0(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 995, self::ORDER, $product, 0, 'DKK' );
		$line    = $this->line( 995, $product, 2, '160', null );
		$refunds = array( self::refund( 7205, 995, -1, '-80', '-20' ) );

		// Sale with unknown tax, then a refund with its own known tax.
		$this->sell( array( $line ), $refunds );
		// The refund is deleted: the correction mirrors the refund row's stored tax.
		$this->set_order( array( $line ) );
		$this->sync->sync( self::ORDER, 7205 );
		// Cancelled: the reversal needs the line's tax balance, which is unknown.
		$this->sell( array( $line ), array(), 'cancelled' );

		$keys    = array_map( static fn( SalesEntry $e ): string => $e->event_key, $this->entries() );
		$payouts = array_combine( $keys, $this->payouts() );
		$profits = array_combine( $keys, $this->profits() );

		self::assertSame( array( 's:995:1', 'r:995:7205', 'c-rd:995:7205', 'v:995:1' ), $keys );
		self::assertNull( $profits['s:995:1'], 'Sale without tax: unknown, never 0.' );
		self::assertNull( $payouts['s:995:1'] );
		self::assertSame( -200000, $payouts['r:995:7205'], 'The refund has its own known tax.' );
		self::assertSame( 200000, $payouts['c-rd:995:7205'], 'The correction mirrors it with the opposite sign.' );
		self::assertNull( $profits['v:995:1'], 'The reversal of an unknown tax balance is unknown, never 0.' );
		self::assertNull( $payouts['v:995:1'] );

		$taxed = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT event_key FROM %i WHERE order_id = %d ORDER BY event_key', $this->table( Tables::SALES_TAX ), self::ORDER ) );
		self::assertSame( array( 'c-rd:995:7205', 'r:995:7205' ), $taxed, 'No tax row is guessed for the sale or the reversal.' );
	}

	public function test_a_missing_vat_status_makes_only_the_payout_unknown(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 996, self::ORDER, $product, 0, 'DKK' );
		$this->wpdb->update( $this->table( Tables::SALES_LINE_PRICING ), array( 'vat_registered' => null ), array( 'order_item_id' => 996 ) );

		$this->sell( array( $this->line( 996, $product, 1, '80', '20' ) ), array( self::refund( 7206, 996, -1, '-80', '-20' ) ) );

		self::assertSame( array( 200000, -200000 ), $this->profits() );
		self::assertSame( array( null, null ), $this->payouts(), 'Never a 0 or a guessed status.' );
	}

	public function test_currency_mismatch_and_legacy_snapshots_are_unknown(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 988, self::ORDER, $product, 0, 'DKK' );
		$this->sell( array( $this->line( 988, $product, 1, '80', '20' ) ), array(), 'processing', 'EUR' );
		self::assertSame( array( null ), $this->profits(), 'Never converted.' );

		// A snapshot of the withdrawn model (before schema version 8).
		$this->wpdb->insert(
			$this->table( Tables::SALES_LINE_PRICING ),
			array(
				'order_item_id'  => 989,
				'order_id'       => self::ORDER + 1,
				'creator_id'     => $this->creator,
				'product_id'     => $product,
				'variation_id'   => 0,
				'status'         => 'priced',
				'currency'       => 'DKK',
				'rule'           => 'default',
				'markup_bps'     => 2000,
				'base_unit'      => '100.0000',
				'profit_unit'    => '20.0000',
				'price_unit'     => '120.0000',
				'captured_at'    => '2026-10-08 10:00:00',
				'vat_registered' => 0,
			)
		);
		$this->reader->orders[ self::ORDER + 1 ] = array(
			'status'   => 'processing',
			'currency' => 'DKK',
			'lines'    => array( $this->line( 989, $product, 1, '96', '24' ) ),
			'refunds'  => array(),
		);
		$this->sync->sync( self::ORDER + 1 );
		$legacy = $this->ledger->entries_for_order( self::ORDER + 1 );

		self::assertSame( SnapshotStatus::Priced, $this->snapshots->find( 989 )?->status );
		self::assertNull( $this->profit->for_entry( $legacy[0] ), 'The old markup on a base price is never reinterpreted.' );
		self::assertNull( $this->profit->payout_for_entry( $legacy[0] ) );
		self::assertSame( '120.0000', $this->raw_snapshot( 989 )['price_unit'], 'The old snapshot stays unchanged.' );
	}

	public function test_lines_with_a_foreign_variation_get_no_snapshot_and_failures_are_logged(): void {
		$this->configure( '20', false );
		$product   = $this->product( '100' );
		$parent    = $this->product( '', 'variable' );
		$variation = $this->posts->add( 'MB-T22F variation', 'publish', array(), 'product_variation', $parent );

		self::assertNull( $this->recorder->record( 990, self::ORDER, $product, $variation, 'DKK' ), 'The variation belongs to another product.' );
		self::assertNull( $this->recorder->record( 991, self::ORDER, 999999, 0, 'DKK' ) );

		self::assertNotNull( $this->recorder->record( 992, self::ORDER, $product, 0, 'DKK' ) );
		self::assertNull( $this->recorder->record( 992, self::ORDER, $product, 0, 'DKK' ), 'The first snapshot wins.' );

		self::assertNull( $this->recorder->record( 993, self::ORDER, $product, 0, 'dkk' ), 'Invalid currency: logged, no snapshot.' );
		self::assertCount( 1, $this->logger->at( 'error' ) );
		self::assertSame( 1, $this->count_rows( Tables::SALES_LINE_PRICING ) );
	}

	public function test_the_ledger_and_its_tax_stay_unchanged_and_idempotent(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 994, self::ORDER, $product, 0, 'DKK' );
		$this->sell( array( $this->line( 994, $product, 1, '80', '20' ) ) );

		$rows = $this->rows( Tables::SALES );
		self::assertSame( '80.0000', $rows[0]['net_amount'], 'The ledger keeps the amount excl. VAT.' );

		foreach ( array( 'profit', 'payout', 'vat', 'tax', 'tax_amount', 'vat_registered', 'share_bps' ) as $column ) {
			self::assertArrayNotHasKey( $column, $rows[0] );
		}

		$this->sync->sync( self::ORDER );
		$this->sync->sync( self::ORDER );
		self::assertSame( $rows, $this->rows( Tables::SALES ) );
		self::assertSame( 1, $this->count_rows( Tables::SALES_TAX ), 'One tax row; never a second.' );
		self::assertSame( 1, $this->count_rows( Tables::SALES_LINE_PRICING ) );
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
	 * Sets the order and synchronizes the ledger.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param OrderLineSnapshot[] $lines    Lines.
	 * @param RefundSnapshot[]    $refunds  Refunds.
	 * @param string              $status   Status.
	 * @param string              $currency Currency.
	 */
	private function sell( array $lines, array $refunds = array(), string $status = 'processing', string $currency = 'DKK' ): void {
		$this->set_order( $lines, $refunds, $status, $currency );
		$this->sync->sync( self::ORDER );
	}

	/**
	 * Sets the order in the test reader.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param OrderLineSnapshot[] $lines    Lines.
	 * @param RefundSnapshot[]    $refunds  Refunds.
	 * @param string              $status   Status.
	 * @param string              $currency Currency.
	 */
	private function set_order( array $lines, array $refunds = array(), string $status = 'processing', string $currency = 'DKK' ): void {
		$this->reader->orders[ self::ORDER ] = array(
			'status'   => $status,
			'currency' => $currency,
			'lines'    => $lines,
			'refunds'  => $refunds,
		);
	}

	/**
	 * Ledger rows of the order, in insertion order.
	 *
	 * @return list<SalesEntry>
	 */
	private function entries(): array {
		return $this->ledger->entries_for_order( self::ORDER );
	}

	/**
	 * Realized profit per ledger row.
	 *
	 * @return list<int|null>
	 */
	private function profits(): array {
		return array_map( fn( SalesEntry $entry ): ?int => $this->profit->for_entry( $entry ), $this->entries() );
	}

	/**
	 * Payout per ledger row.
	 *
	 * @return list<int|null>
	 */
	private function payouts(): array {
		return array_map( fn( SalesEntry $entry ): ?int => $this->profit->payout_for_entry( $entry ), $this->entries() );
	}

	/**
	 * Amounts as decimals ('' for unknown).
	 *
	 * @param list<int|null> $units Amounts.
	 * @return list<string>
	 */
	private function formatted( array $units ): array {
		return array_map( static fn( ?int $u ): string => null === $u ? '' : SalesAmount::format( $u ), $units );
	}

	/**
	 * The stored snapshot row.
	 *
	 * @param int $order_item_id Order item.
	 * @return array<string, mixed>
	 */
	private function raw_snapshot( int $order_item_id ): array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE order_item_id = %d', $this->table( Tables::SALES_LINE_PRICING ), $order_item_id ), ARRAY_A );
		self::assertIsArray( $row );

		return $row;
	}

	/**
	 * Regular, sale and active price as stored.
	 *
	 * @param int $id Product.
	 * @return array{string, string, string}
	 */
	private function prices_of( int $id ): array {
		wp_cache_flush();

		return array( (string) get_post_meta( $id, '_regular_price', true ), (string) get_post_meta( $id, '_sale_price', true ), (string) get_post_meta( $id, '_price', true ) );
	}

	/**
	 * An order line with WooCommerce' split into net excl. VAT and tax.
	 *
	 * @param int         $item      Order item.
	 * @param int         $product   Parent product.
	 * @param int         $quantity  Quantity.
	 * @param string      $net       Amount excl. VAT.
	 * @param string|null $tax       Actual tax, null for unknown.
	 * @param int|null    $creator   Owner when the ledger reads the order (default: creator A).
	 * @param int         $variation Variation.
	 */
	private function line( int $item, int $product, int $quantity, string $net, ?string $tax, ?int $creator = null, int $variation = 0 ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, $product, $variation, 'MB-T22F line', $quantity, SalesAmount::parse( $net ), $creator ?? $this->creator, null === $tax ? null : SalesAmount::parse( $tax ) );
	}

	/**
	 * A refund of one line with its own tax.
	 *
	 * @param int    $id       Refund id.
	 * @param int    $item     Refunded order item.
	 * @param int    $quantity Quantity (≤ 0).
	 * @param string $net      Amount excl. VAT (≤ 0).
	 * @param string $tax      Refund line tax (≤ 0).
	 */
	private static function refund( int $id, int $item, int $quantity, string $net, string $tax ): RefundSnapshot {
		return new RefundSnapshot( $id, new DateTimeImmutable( '2026-10-09 13:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( $item, $quantity, SalesAmount::parse( $net ), SalesAmount::parse( $tax ) ) ) );
	}

	/**
	 * A product with a price (incl. VAT) and an owner.
	 *
	 * @param string   $price Regular price.
	 * @param string   $type  Product type.
	 * @param int|null $owner Creator, 0 for none (default: creator A).
	 */
	private function product( string $price, string $type = 'simple', ?int $owner = null ): int {
		$owner = $owner ?? $this->creator;
		$id    = $this->posts->add( 'MB-T22F product', 'publish', $owner > 0 ? array( (string) $owner ) : array() );
		$this->prices( $id, $price );

		if ( 'simple' !== $type ) {
			$this->posts->meta( $id, PostMetaPricingProductStore::TYPE_KEY, $type );
		}

		return $id;
	}

	/**
	 * WooCommerce' price meta without a sale price.
	 *
	 * @param int    $id    Post id.
	 * @param string $price Regular price.
	 */
	private function prices( int $id, string $price ): void {
		$this->posts->meta( $id, '_regular_price', $price );
		$this->posts->meta( $id, '_sale_price', '' );
		$this->posts->meta( $id, '_price', $price );
	}
}
