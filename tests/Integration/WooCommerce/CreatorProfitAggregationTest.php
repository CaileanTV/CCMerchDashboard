<?php
/**
 * Aggregated creator profit per product, order line, order and period on a real database (ADR-050).
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
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkup;
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
 * recorder, ledger rows and their tax from the real synchronizer. Every
 * aggregate of LedgerCreatorProfits is compared with the sum of
 * SalesProfit::for_entry() (RealizedProfit, ADR-049) over the same rows, so
 * the SQL and the PHP formula can never drift apart. Sales are dated
 * 2026-09-01 10:05 by the test reader, refunds 2026-10-09 13:00, corrections and reversals at the clock (2026-10-09 12:00, UTC). Synthetic ids only.
 */
final class CreatorProfitAggregationTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private CreatorMarkupRepository $markups;

	private ProductMarkupRepository $product_markups;

	private CreatorVatRepository $vat;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesRepository $ledger;

	private SalesProfit $profit;

	private LedgerCreatorProfits $profits;

	private int $creator;

	private int $other;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Profit A', CreatorStatus::Active )->id;
		$this->other   = $creators->create( 'Profit B', CreatorStatus::Active )->id;

		$audit                 = $this->service( AuditTrail::class );
		$transaction           = $this->service( Transaction::class );
		$this->posts           = new ProductPosts( $this->wpdb );
		$this->markups         = new CreatorMarkupRepository( $this->wpdb );
		$this->product_markups = new ProductMarkupRepository( $this->wpdb );
		$this->vat             = new CreatorVatRepository( $this->wpdb );
		$snapshots             = new SalesLinePricingRepository( $this->wpdb );
		$links                 = new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );
		$this->recorder        = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $this->markups, $this->product_markups ), $snapshots, new RecordingLogger(), $this->vat );
		$this->profit          = new SalesProfit( $snapshots, new SalesTaxRepository( $this->wpdb ) );
		$this->profits         = new LedgerCreatorProfits( $this->wpdb );
		$this->reader          = new FakeOrderReader();
		$this->ledger          = new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) );
		$this->sync            = new SalesSynchronizer(
			$this->ledger,
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_every_aggregate_equals_the_sum_of_the_row_profits(): void {
		[ $simple, $parent, $variation, $cap ] = $this->catalogue();

		// Order 1: 3 × 99.99 incl. VAT at 16.67 %, not VAT registered; one unit refunded.
		$this->configure( '16.67', false );
		$this->recorder->record( 2011, 2001, $simple, 0, 'DKK' );
		$line = $this->line( 2011, $simple, 3, '239.976', '59.994' );
		$this->order( 2001, array( $line ) );
		$this->order( 2001, array( $line ), array( self::refund( 7301, 2011, -1, '-79.992', '-19.998' ) ) );

		// Order 2: two variations (product markup 50 %) and a cap, VAT registered; cancelled later.
		$this->product_markups->save( new ProductMarkup( $parent, $this->creator, new MarkupBps( 5000 ) ) );
		$this->configure( '20', true );
		$this->recorder->record( 2021, 2002, $parent, $variation, 'DKK' );
		$this->recorder->record( 2022, 2002, $cap, 0, 'DKK' );
		$lines = array( $this->line( 2021, $parent, 2, '0.04', '0.01', $variation ), $this->line( 2022, $cap, 1, '159.996', '39.999' ) );
		$this->order( 2002, $lines );
		$this->order( 2002, $lines, array(), 'cancelled' );

		// Order 3: a refund that is deleted again (correction).
		$this->configure( '33.33', false );
		$this->recorder->record( 2031, 2003, $simple, 0, 'DKK' );
		$line = $this->line( 2031, $simple, 1, '80', '20' );
		$this->order( 2003, array( $line ), array( self::refund( 7302, 2031, 0, '-8', '-2' ) ) );
		$this->reader->orders[2003]['refunds'] = array();
		$this->sync->sync( 2003, 7302 );

		$rows = $this->ledger_rows();
		self::assertGreaterThanOrEqual( 9, count( $rows ), 'Sales, refunds, reversals and a correction.' );
		self::assertNotContains( null, array_column( $rows, 'profit' ), 'Every row is known here.' );

		// Per product (variations under their parent).
		$by_product = $this->profits->by_product( $this->creator, array( $simple, $parent, $cap ) );
		foreach ( array( $simple, $parent, $cap ) as $product ) {
			$expected = self::sum( array_filter( $rows, static fn( array $r ): bool => $r['entry']->product_id === $product ) );
			self::assertSame( $expected, $by_product[ $product ][0]->amount_units(), 'Product ' . $product );
			self::assertSame( ProfitSummary::COMPLETE, $by_product[ $product ][0]->status() );
		}

		// Per order line and per order.
		$by_line = $this->profits->by_order_line( $this->creator, array( 2001, 2002, 2003 ) );
		foreach ( array( 2011, 2021, 2022, 2031 ) as $item ) {
			$expected = self::sum( array_filter( $rows, static fn( array $r ): bool => $r['entry']->order_item_id === $item ) );
			self::assertSame( $expected, $by_line[ $item ]['profit']->amount_units(), 'Line ' . $item );
		}
		self::assertSame( 2002, $by_line[2021]['order_id'] );
		self::assertSame( 0, (int) $by_line[2021]['profit']->plus( $by_line[2022]['profit'] )->amount_units(), 'A cancelled order nets to 0.' );

		// Per period: everything, and the refund hour alone.
		$all = $this->profits->totals( $this->creator, self::at( '2026-08-01 00:00:00' ), self::at( '2026-11-01 00:00:00' ) );
		self::assertSame( self::sum( $rows ), $all['DKK']->amount_units() );
		$refund_hour = $this->profits->totals( $this->creator, self::at( '2026-10-09 13:00:00' ), self::at( '2026-10-09 14:00:00' ) );
		self::assertSame( self::sum( array_filter( $rows, static fn( array $r ): bool => '2026-10-09 13:00:00' === $r['entry']->occurred_at->format( 'Y-m-d H:i:s' ) ) ), $refund_hour['DKK']->amount_units() );
		self::assertSame( array(), $this->profits->totals( $this->creator, self::at( '2026-11-01 00:00:00' ), self::at( '2026-12-01 00:00:00' ) ), 'No rows: no profit, not 0.' );
	}

	public function test_the_locked_examples_and_rounding_through_the_aggregation(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 2041, 2004, $product, 0, 'DKK' );
		$this->configure( '20', true );
		$this->recorder->record( 2042, 2004, $product, 0, 'DKK' );
		$this->configure( '10', false );
		$this->recorder->record( 2043, 2004, $product, 0, 'DKK' );
		$this->order( 2004, array( $this->line( 2041, $product, 1, '80', '20' ), $this->line( 2042, $product, 1, '80', '20' ), $this->line( 2043, $product, 2, '160', '40' ) ) );

		$by_line = $this->profits->by_order_line( $this->creator, array( 2004 ) );

		self::assertSame( array( 200000, 200000, 200000 ), array( $by_line[2041]['profit']->amount_units(), $by_line[2042]['profit']->amount_units(), $by_line[2043]['profit']->amount_units() ), 'Profit, never payout: VAT status does not change it.' );
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'complete', 'amount' => '60.0000' ), $this->profits->by_product( $this->creator, array( $product ) )[ $product ][0]->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_unknown_rows_make_the_sum_incomplete_or_unknown_never_0(): void {
		$product = $this->product( '100' );
		$legacy  = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 2051, 2005, $product, 0, 'DKK' );
		// 2052 has no snapshot (an order line from before phase 22); 2053 has no stored tax.
		$this->recorder->record( 2053, 2006, $legacy, 0, 'DKK' );
		$this->order( 2005, array( $this->line( 2051, $product, 1, '80', '20' ), $this->line( 2052, $product, 1, '80', '20' ) ) );
		$this->order( 2006, array( $this->line( 2053, $legacy, 1, '80', null ) ) );

		$by_product = $this->profits->by_product( $this->creator, array( $product, $legacy ) );
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'incomplete', 'amount' => null ), $by_product[ $product ][0]->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'unknown', 'amount' => null ), $by_product[ $legacy ][0]->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$by_line = $this->profits->by_order_line( $this->creator, array( 2005, 2006 ) );
		self::assertSame( ProfitSummary::COMPLETE, $by_line[2051]['profit']->status() );
		self::assertSame( ProfitSummary::UNKNOWN, $by_line[2052]['profit']->status() );
		self::assertSame( ProfitSummary::INCOMPLETE, $by_line[2051]['profit']->plus( $by_line[2052]['profit'] )->status(), 'The order is incomplete.' );
		self::assertNull( $by_line[2051]['profit']->plus( $by_line[2052]['profit'] )->amount_units() );

		$totals = $this->profits->totals( $this->creator, self::at( '2026-08-01 00:00:00' ), self::at( '2026-11-01 00:00:00' ) );
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'incomplete', 'amount' => null ), $totals['DKK']->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 1, 2 ), array( $totals['DKK']->known_rows, $totals['DKK']->unknown_rows ) );
	}

	public function test_a_line_created_before_its_product_had_a_creator_stays_unknown_after_linking(): void {
		// As product 16 on Merch Dashboard Local: the order line exists while the product has no
		// creator (no snapshot), the product is linked later, and the ledger then counts the line
		// for the creator. The share of that moment is unknown and is never filled in.
		$product = $this->posts->add( 'MB-T22D late link', 'publish', array() );
		$this->posts->meta( $product, '_regular_price', '100' );
		$this->posts->meta( $product, '_price', '100' );
		$this->configure( '16.67', false );

		self::assertNull( $this->recorder->record( 2091, 2009, $product, 0, 'DKK' ), 'No creator: no snapshot.' );

		$this->posts->meta( $product, ProductCreatorLink::META_KEY, (string) $this->creator );
		wp_cache_flush();
		$this->order( 2009, array( $this->line( 2091, $product, 1, '10', '0' ) ) );

		// A line created after the link has its snapshot.
		self::assertNotNull( $this->recorder->record( 2101, 2010, $product, 0, 'DKK' ) );
		$this->order( 2010, array( $this->line( 2101, $product, 1, '10', '0' ) ) );

		$by_line = $this->profits->by_order_line( $this->creator, array( 2009, 2010 ) );
		self::assertSame( ProfitSummary::UNKNOWN, $by_line[2091]['profit']->status(), 'Ledger row with its tax, but no snapshot.' );
		self::assertSame( 16700, $by_line[2101]['profit']->amount_units(), '10.00 × 16.67 % = 1.67.' );

		$totals = $this->profits->totals( $this->creator, self::at( '2026-08-01 00:00:00' ), self::at( '2026-11-01 00:00:00' ) );
		self::assertSame( array( 'currency' => 'DKK', 'status' => 'incomplete', 'amount' => null ), $totals['DKK']->to_array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( 1, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_item_id = 2091', $this->table( Tables::SALES_TAX ) ) ), 'The tax is stored; only the snapshot is missing.' );
	}

	public function test_only_the_creators_own_rows_count_and_products_without_sales_have_none(): void {
		$product = $this->product( '100' );
		$unsold  = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 2061, 2007, $product, 0, 'DKK' );
		// The same order holds a line of the other creator on the same product id.
		$this->markups->save( $this->other, new MarkupBps( 9000 ) );
		$this->order( 2007, array( $this->line( 2061, $product, 1, '80', '20' ), $this->line( 2062, $product, 1, '80', '20', 0, $this->other ) ) );

		$mine = $this->profits->by_product( $this->creator, array( $product, $unsold ) );
		self::assertSame( '20.0000', $mine[ $product ][0]->to_array()['amount'] );
		self::assertArrayNotHasKey( $unsold, $mine, 'No sales: no profit (the API sends null).' );
		self::assertSame( array( 2061 ), array_keys( $this->profits->by_order_line( $this->creator, array( 2007 ) ) ) );
		self::assertSame( array( 2062 ), array_keys( $this->profits->by_order_line( $this->other, array( 2007 ) ) ) );
		self::assertSame( array(), $this->profits->by_product( 999999, array( $product ) ) );
		self::assertSame( array(), $this->profits->by_product( 0, array( $product ) ) );
		self::assertSame( array(), $this->profits->by_order_line( $this->creator, array() ) );
	}

	public function test_history_does_not_change_with_markup_vat_status_or_price(): void {
		$product = $this->product( '100' );
		$this->configure( '20', false );
		$this->recorder->record( 2071, 2008, $product, 0, 'DKK' );
		$this->order( 2008, array( $this->line( 2071, $product, 1, '80', '20' ) ) );
		$before = array(
			$this->profits->by_product( $this->creator, array( $product ) ),
			$this->profits->by_order_line( $this->creator, array( 2008 ) ),
			$this->profits->totals( $this->creator, self::at( '2026-08-01 00:00:00' ), self::at( '2026-11-01 00:00:00' ) ),
		);

		$this->configure( '75', true );
		$this->product_markups->save( new ProductMarkup( $product, $this->creator, new MarkupBps( 9900 ) ) );
		update_post_meta( $product, '_regular_price', '999' );
		update_post_meta( $product, '_price', '999' );
		$this->sync->sync( 2008 );

		self::assertEquals(
			$before,
			array(
				$this->profits->by_product( $this->creator, array( $product ) ),
				$this->profits->by_order_line( $this->creator, array( 2008 ) ),
				$this->profits->totals( $this->creator, self::at( '2026-08-01 00:00:00' ), self::at( '2026-11-01 00:00:00' ) ),
			)
		);
		self::assertSame( '999', get_post_meta( $product, '_regular_price', true ), 'Only the test changed the price; Merch Dashboard never writes it.' );
	}

	/**
	 * A simple product, a variable product with one variation and a cap.
	 *
	 * @return array{int, int, int, int}
	 */
	private function catalogue(): array {
		$simple    = $this->product( '99.99' );
		$parent    = $this->product( '' );
		$variation = $this->posts->add( 'MB-T22D variation', 'publish', array(), 'product_variation', $parent );
		$this->posts->meta( $parent, PostMetaPricingProductStore::TYPE_KEY, 'variable' );
		$this->posts->meta( $variation, '_regular_price', '0.025' );
		$cap = $this->product( '199.995' );

		return array( $simple, $parent, $variation, $cap );
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
	 * @param RefundSnapshot[]    $refunds  Refunds.
	 * @param string              $status   Status.
	 */
	private function order( int $order_id, array $lines, array $refunds = array(), string $status = 'processing' ): void {
		$this->reader->orders[ $order_id ] = array(
			'status'   => $status,
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => $refunds,
		);
		$this->sync->sync( $order_id );
	}

	/**
	 * Every ledger row of the creator with its profit (SalesProfit).
	 *
	 * @return list<array{entry: SalesEntry, profit: int|null}>
	 */
	private function ledger_rows(): array {
		$rows = array();

		foreach ( $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE creator_id = %d ORDER BY order_id', $this->table( Tables::SALES ), $this->creator ) ) as $order_id ) {
			foreach ( $this->ledger->entries_for_order( (int) $order_id ) as $entry ) {
				if ( $entry->creator_id === $this->creator ) {
					$rows[] = array(
						'entry'  => $entry,
						'profit' => $this->profit->for_entry( $entry ),
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * Sum of the rows' profit.
	 *
	 * @param array<array{entry: SalesEntry, profit: int|null}> $rows Rows.
	 */
	private static function sum( array $rows ): int {
		return array_sum( array_map( static fn( array $r ): int => (int) $r['profit'], $rows ) );
	}

	/**
	 * An order line with WooCommerce' split into net excl. VAT and tax.
	 *
	 * @param int         $item      Order item.
	 * @param int         $product   Parent product.
	 * @param int         $quantity  Quantity.
	 * @param string      $net       Amount excl. VAT.
	 * @param string|null $tax       Actual tax, null for unknown.
	 * @param int         $variation Variation.
	 * @param int|null    $creator   Owner when the ledger reads the order (default: creator A).
	 */
	private function line( int $item, int $product, int $quantity, string $net, ?string $tax, int $variation = 0, ?int $creator = null ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, $product, $variation, 'MB-T22D line', $quantity, SalesAmount::parse( $net ), $creator ?? $this->creator, null === $tax ? null : SalesAmount::parse( $tax ) );
	}

	/**
	 * A refund of one line with its own tax, at 13:00.
	 *
	 * @param int    $id       Refund id.
	 * @param int    $item     Refunded order item.
	 * @param int    $quantity Quantity (≤ 0).
	 * @param string $net      Amount excl. VAT (≤ 0).
	 * @param string $tax      Refund line tax (≤ 0).
	 */
	private static function refund( int $id, int $item, int $quantity, string $net, string $tax ): RefundSnapshot {
		return new RefundSnapshot( $id, self::at( '2026-10-09 13:00:00' ), array( new RefundLineSnapshot( $item, $quantity, SalesAmount::parse( $net ), SalesAmount::parse( $tax ) ) ) );
	}

	/**
	 * A product of creator A with a regular price.
	 *
	 * @param string $price Regular price ('' for none).
	 */
	private function product( string $price ): int {
		$id = $this->posts->add( 'MB-T22D product', 'publish', array( (string) $this->creator ) );
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
