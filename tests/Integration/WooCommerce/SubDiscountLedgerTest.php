<?php
/**
 * The Twitch subscriber discount in the sales ledger on a real database (ADR-106 pkt. 7).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Migrations\AddSalesSubDiscount;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
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
 * A share product of 200.00 incl. 25 % VAT at a 25 % markup (profit 50.00).
 * The subscriber discount is given in the paid basis (incl. VAT), as the
 * order line meta carries it. No WooCommerce is loaded; the order reader is
 * the test reader, as in CreatorShareLedgerTest.
 */
final class SubDiscountLedgerTest extends IntegrationTestCase {

	private const ORDER = 1270;

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesRepository $ledger;

	private SalesProfit $profit;

	private CreatorVatRepository $vat;

	private int $creator;

	private int $clock = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Sub A', CreatorStatus::Active )->id;

		$audit       = $this->service( AuditTrail::class );
		$transaction = $this->service( Transaction::class );
		$snapshots   = new SalesLinePricingRepository( $this->wpdb );
		$markups     = new CreatorMarkupRepository( $this->wpdb );
		$this->vat   = new CreatorVatRepository( $this->wpdb );
		$this->posts = new ProductPosts( $this->wpdb );
		$links       = new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );

		$markups->save( $this->creator, MarkupBps::from_percent( '25' ) );
		$this->vat->save( $this->creator, false );

		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), $snapshots, new RecordingLogger(), $this->vat );
		$this->profit   = new SalesProfit( $snapshots, new SalesTaxRepository( $this->wpdb ) );
		$this->reader   = new FakeOrderReader();
		$this->ledger   = new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) );
		$this->sync     = new SalesSynchronizer(
			$this->ledger,
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-10 12:00:' . str_pad( (string) ( ++$this->clock % 60 ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) )
		);
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_the_creator_pays_the_whole_discount_and_php_equals_sql(): void {
		$product = $this->product();
		$this->recorder->record( 1271, self::ORDER, $product, 0, 'DKK' );
		$this->recorder->record( 1272, self::ORDER, $product, 0, 'DKK' );

		// 1271: 10 % subscriber discount (20.00) → paid 180.00. 1272: no discount → paid 200.00.
		$this->sell( array( $this->line( 1271, 1, '144', '36', '20' ), $this->line( 1272, 1, '160', '40', null ) ) );

		self::assertSame( array( '30.0000', '50.0000' ), $this->formatted( $this->profits() ), 'The share of 200.00 less the 20.00; the line without a discount is unchanged.' );
		self::assertSame( array( '24.0000', '40.0000' ), $this->formatted( $this->payouts() ), 'Not VAT registered: the profit without the sale\'s VAT (30 × 144 / 180); the share of the net otherwise.' );
		self::assertSame( array( '20.0000', null ), $this->stored_sub_discounts() );

		$sql = ( new LedgerCreatorProfits( $this->wpdb ) )->by_order_line( $this->creator, array( self::ORDER ) );
		self::assertSame( 300000, $sql[1271]['profit']->amount_units(), 'SQL equals PHP.' );
		self::assertSame( 500000, $sql[1272]['profit']->amount_units() );

		$earned = ( new LedgerCreatorPayouts( $this->wpdb ) )->earned( $this->creator, null, new DateTimeImmutable( '2027-01-01', new DateTimeZone( 'UTC' ) ), 0 );
		self::assertSame( 640000, $earned['DKK']->amount_units(), 'SQL payout equals PHP (24 + 40).' );
	}

	public function test_a_discount_above_the_profit_never_makes_it_negative(): void {
		$product = $this->product();
		$this->recorder->record( 1273, self::ORDER, $product, 0, 'DKK' );

		$this->sell( array( $this->line( 1273, 1, '112', '28', '60' ) ) );

		self::assertSame( array( '0.0000' ), $this->formatted( $this->profits() ) );
		self::assertSame( array( '0.0000' ), $this->formatted( $this->payouts() ) );
	}

	public function test_refunds_in_parts_end_at_zero_and_keep_the_calculation(): void {
		$product = $this->product();
		$this->vat->save( $this->creator, true );
		$this->recorder->record( 1274, self::ORDER, $product, 0, 'DKK' );
		$line = $this->line( 1274, 2, '288', '72', '40' );

		$this->sell( array( $line ) );
		self::assertSame( array( '60.0000' ), $this->formatted( $this->profits() ), '2 × 50 less 40.' );

		$first = self::refund( 7301, 1274, -1, '-144', '-36' );
		$this->sell( array( $line ), array( $first ) );
		self::assertSame( '-30.0000', $this->formatted( $this->profits() )[1], 'Half the paid amount takes back half the profit.' );

		// The coupon is gone from the order now (an admin edit): the line keeps its calculation.
		$this->sell( array( $this->line( 1274, 2, '288', '72', null ) ), array( $first, self::refund( 7302, 1274, -1, '-144', '-36' ) ) );

		self::assertSame( 0, array_sum( $this->profits() ) );
		self::assertSame( 0, array_sum( $this->payouts() ) );
		self::assertSame( array( '40.0000', '0.0000', '0.0000' ), $this->stored_sub_discounts() );

		$sql = ( new LedgerCreatorProfits( $this->wpdb ) )->by_order_line( $this->creator, array( self::ORDER ) );
		self::assertSame( 0, $sql[1274]['profit']->amount_units() );
	}

	public function test_migration_49_adds_the_column_once_and_it_is_null_by_default(): void {
		( new AddSalesSubDiscount() )->up( $this->wpdb );
		( new AddSalesSubDiscount() )->up( $this->wpdb );

		$column = $this->wpdb->get_row( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $this->table( Tables::SALES_TAX ), 'sub_discount' ), ARRAY_A );

		self::assertIsArray( $column );
		self::assertSame( array( 'decimal(19,4)', 'YES', null ), array( strtolower( $column['Type'] ), $column['Null'], $column['Default'] ) );
	}

	public function test_without_the_discount_the_rows_are_unchanged(): void {
		$product = $this->product();
		$this->recorder->record( 1275, self::ORDER, $product, 0, 'DKK' );

		$this->sell( array( $this->line( 1275, 1, '144', '36', null ) ) );

		self::assertSame( array( '45.0000' ), $this->formatted( $this->profits() ), 'A company discount is shared as before.' );
		self::assertSame( array( null ), $this->stored_sub_discounts() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT designer_profit FROM %i WHERE order_id = %d', $this->table( Tables::SALES_TAX ), self::ORDER ) ) );
	}

	/**
	 * Sets the order and synchronizes the ledger.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param OrderLineSnapshot[] $lines   Lines.
	 * @param RefundSnapshot[]    $refunds Refunds.
	 */
	private function sell( array $lines, array $refunds = array() ): void {
		$this->reader->orders[ self::ORDER ] = array(
			'status'   => 'processing',
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => $refunds,
		);
		$this->sync->sync( self::ORDER );
	}

	/**
	 * Realized profit per ledger row.
	 *
	 * @return list<int|null>
	 */
	private function profits(): array {
		return array_map( fn( SalesEntry $entry ): ?int => $this->profit->for_entry( $entry ), $this->ledger->entries_for_order( self::ORDER ) );
	}

	/**
	 * Payout per ledger row.
	 *
	 * @return list<int|null>
	 */
	private function payouts(): array {
		return array_map( fn( SalesEntry $entry ): ?int => $this->profit->payout_for_entry( $entry ), $this->ledger->entries_for_order( self::ORDER ) );
	}

	/**
	 * The stored subscriber discount per row, in row order.
	 *
	 * @return list<string|null>
	 */
	private function stored_sub_discounts(): array {
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT t.sub_discount FROM %i s JOIN %i t ON t.event_key = s.event_key WHERE s.order_id = %d ORDER BY s.id', $this->table( Tables::SALES ), $this->table( Tables::SALES_TAX ), self::ORDER ) );

		return array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $rows );
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
	 * An order line with its subscriber discount (paid basis), as the reader gives it.
	 *
	 * @param int         $item     Order item.
	 * @param int         $quantity Quantity.
	 * @param string      $net      Amount excl. VAT after discounts.
	 * @param string      $tax      Actual tax.
	 * @param string|null $sub      Subscriber discount incl. VAT, null for none.
	 */
	private function line( int $item, int $quantity, string $net, string $tax, ?string $sub ): OrderLineSnapshot {
		$product = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT product_id FROM %i WHERE order_item_id = %d', $this->table( Tables::SALES_LINE_PRICING ), $item ) );

		return new OrderLineSnapshot( $item, $product, 0, 'MB-T43 line', $quantity, SalesAmount::parse( $net ), $this->creator, SalesAmount::parse( $tax ), sub_discount: null === $sub ? null : SalesAmount::parse( $sub ) );
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
		return new RefundSnapshot( $id, new DateTimeImmutable( '2026-11-10 13:00:00', new DateTimeZone( 'UTC' ) ), array( new RefundLineSnapshot( $item, $quantity, SalesAmount::parse( $net ), SalesAmount::parse( $tax ) ) ) );
	}

	/**
	 * A share product of 200.00 incl. VAT owned by the creator.
	 */
	private function product(): int {
		$id = $this->posts->add( 'MB-T43 product', 'publish', array( (string) $this->creator ) );
		$this->posts->meta( $id, '_regular_price', '200' );
		$this->posts->meta( $id, '_sale_price', '' );
		$this->posts->meta( $id, '_price', '200' );

		return $id;
	}
}
