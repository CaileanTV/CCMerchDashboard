<?php
/**
 * Settlement of designer products on a real database (ADR-080 pkt. 8).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\ProductDesigner;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrations\AddSalesDesignerAmounts;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfitDiscount;
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
 * Designer rows next to legacy rows, through the real recorder, synchronizer
 * and ledger: the amounts stored with each row's tax are exactly
 * RealizedProfit and RealizedPayout, every SQL sum equals the sum of those
 * in PHP (ADR-050), and outside the company's own campaigns the company
 * keeps the base price whatever the discount. No WooCommerce is loaded; the
 * designer price comes from a fixed source, and DesignerPrices is checked on
 * the designer's own tables. Synthetic ids only.
 */
final class DesignerSettlementTest extends IntegrationTestCase {

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private SalesLinePricingRepository $snapshots;

	private CreatorMarkupRepository $markups;

	private CreatorVatRepository $vat;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private SalesRepository $ledger;

	private SalesProfit $profit;

	private MarkupResolver $resolver;

	private RecordingLogger $logger;

	/**
	 * Designer prices by product id.
	 *
	 * @var array<int, DesignerPrice>
	 */
	private array $prices = array();

	private int $creator;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators      = new CreatorRepository( $this->wpdb );
		$this->creator = $creators->create( 'Designer A', CreatorStatus::Active )->id;

		$this->posts     = new ProductPosts( $this->wpdb );
		$this->markups   = new CreatorMarkupRepository( $this->wpdb );
		$this->vat       = new CreatorVatRepository( $this->wpdb );
		$this->snapshots = new SalesLinePricingRepository( $this->wpdb );
		$links           = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$this->resolver  = new MarkupResolver( $links, $this->markups, new ProductMarkupRepository( $this->wpdb ) );
		$this->recorder  = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), $this->resolver, $this->snapshots, new RecordingLogger(), $this->vat, $this->source() );
		$this->profit    = new SalesProfit( $this->snapshots, new SalesTaxRepository( $this->wpdb ) );
		$this->logger    = new RecordingLogger();
		$this->reader    = new FakeOrderReader();
		$this->ledger    = new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) );
		$this->sync      = new SalesSynchronizer(
			$this->ledger,
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			$this->logger,
			static fn(): DateTimeImmutable => self::at( '2026-10-09 12:00:00' )
		);
	}

	protected function tearDown(): void {
		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_migration_36_adds_the_designer_amounts_to_the_sales_tax_once(): void {
		( new AddSalesDesignerAmounts() )->up( $this->wpdb );

		$columns = array();

		foreach ( (array) $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( Tables::SALES_TAX ) ), ARRAY_A ) as $column ) {
			$columns[ $column['Field'] ] = array( $column['Type'], $column['Null'] );
		}

		self::assertSame( array( 'decimal(19,4)', 'YES' ), $columns['designer_profit'] );
		self::assertSame( array( 'decimal(19,4)', 'YES' ), $columns['designer_payout'] );
		// MySQL 8 reports integer types without a display width.
		self::assertSame( array( 'bigint unsigned', 'YES' ), array( str_replace( 'bigint(20)', 'bigint', $columns['campaign_id'][0] ), $columns['campaign_id'][1] ) );
		self::assertSame( 38, (int) get_option( 'merchboard_db_version' ) );
	}

	/**
	 * Base 150 + profit 50 = 200 incl. 25 % VAT (net 160 + tax 40 per unit).
	 */
	public function test_designer_rows_store_their_amounts_and_every_sql_sum_equals_php(): void {
		foreach ( array( false, true ) as $vat_registered ) {
			$this->vat->save( $this->creator, $vat_registered );
			$this->markups->save( $this->creator, MarkupBps::from_percent( '20' ) );

			$designer = $this->designer_product( '150', '50' );
			$legacy   = $this->product( '100' );
			$base     = $vat_registered ? 5000 : 4000;

			// No discount, two units; a legacy line in the same order.
			$this->recorder->record( $base + 11, $base + 1, $designer, 0, 'DKK' );
			$this->recorder->record( $base + 12, $base + 1, $legacy, 0, 'DKK' );
			$this->order( $base + 1, array( $this->line( $base + 11, $designer, 2, '320', '80' ), $this->line( $base + 12, $legacy, 1, '80', '20' ) ) );

			// A coupon of 20 incl. VAT, refunded later.
			$this->recorder->record( $base + 21, $base + 2, $designer, 0, 'DKK' );
			$line = $this->line( $base + 21, $designer, 1, '144', '36' );
			$this->order( $base + 2, array( $line ) );
			$this->order( $base + 2, array( $line ), array( self::refund( $base + 201, $base + 21, -1, '-144', '-36' ) ) );

			// A coupon of 80: paid below the base price, profit 0.
			$this->recorder->record( $base + 31, $base + 3, $designer, 0, 'DKK' );
			$this->order( $base + 3, array( $this->line( $base + 31, $designer, 1, '96', '24' ) ) );

			// Three units with a rounding-heavy discount: 599.99 paid, 149.99 above the base price.
			$this->recorder->record( $base + 41, $base + 4, $designer, 0, 'DKK' );
			$this->order( $base + 4, array( $this->line( $base + 41, $designer, 3, '479.992', '119.998' ) ) );

			// A partial amount-only refund of 15: it takes back the profit in proportion (review 30.15).
			$this->recorder->record( $base + 51, $base + 5, $designer, 0, 'DKK' );
			$line = $this->line( $base + 51, $designer, 1, '160', '40' );
			$this->order( $base + 5, array( $line ), array( self::refund( $base + 501, $base + 51, 0, '-12', '-3' ) ) );
		}

		$rows = $this->ledger_rows();
		self::assertGreaterThanOrEqual( 14, count( $rows ) );
		self::assertNotContains( null, array_column( $rows, 'profit' ), 'Every row is known.' );

		$designer_rows = 0;

		foreach ( $rows as $row ) {
			$entry    = $row['entry'];
			$snapshot = $this->snapshots->find( $entry->order_item_id );
			$stored   = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT designer_profit, designer_payout FROM %i WHERE event_key = %s', $this->table( Tables::SALES_TAX ), $entry->event_key ), ARRAY_A );

			self::assertIsArray( $stored );

			if ( null !== $snapshot && SnapshotStatus::Designer === $snapshot->status ) {
				++$designer_rows;
				self::assertSame( SalesAmount::format( (int) $row['profit'] ), $stored['designer_profit'], $entry->event_key );
				self::assertSame( SalesAmount::format( (int) $row['payout'] ), $stored['designer_payout'], $entry->event_key );

				// Outside a campaign the company keeps the base price of the units it was paid for, and the creator never goes below 0.
				$paid    = abs( SalesAmount::add( SalesAmount::parse( $entry->net_amount ), $row['tax'] ) );
				$profit  = abs( (int) $row['profit'] );
				$minimum = min( $paid, abs( $entry->quantity ) * (int) $snapshot->base_units );
				if ( 0 !== $entry->quantity ) {
					self::assertGreaterThanOrEqual( $minimum, $paid - $profit, $entry->event_key );
					self::assertLessThanOrEqual( abs( $entry->quantity ) * (int) $snapshot->profit_units, $profit, $entry->event_key );
				}
			} else {
				self::assertSame( array( null, null ), array( $stored['designer_profit'], $stored['designer_payout'] ), 'A legacy row stores no designer amounts.' );
			}
		}

		self::assertGreaterThanOrEqual( 12, $designer_rows );

		$profit_sum = array_sum( array_column( $rows, 'profit' ) );
		$payout_sum = array_sum( array_column( $rows, 'payout' ) );
		$profits    = new LedgerCreatorProfits( $this->wpdb );
		$totals     = $profits->totals( $this->creator, self::at( '2026-01-01 00:00:00' ), self::at( '2027-01-01 00:00:00' ) );

		self::assertSame( $profit_sum, $totals['DKK']->amount_units() );
		self::assertSame( $payout_sum, ( new LedgerCreatorPayouts( $this->wpdb ) )->earned( $this->creator, null, self::at( '2027-01-01 00:00:00' ), 0 )['DKK']->amount_units() );

		$by_line = $profits->by_order_line( $this->creator, array_values( array_unique( array_map( static fn( array $row ): int => $row['entry']->order_id, $rows ) ) ) );

		foreach ( $by_line as $item => $line ) {
			$expected = array_sum( array_column( array_filter( $rows, static fn( array $row ): bool => $row['entry']->order_item_id === $item ), 'profit' ) );
			self::assertSame( $expected, $line['profit']->amount_units(), 'Order line ' . $item );
		}

		$unsettled = ( new LedgerCreatorPayouts( $this->wpdb ) )->unsettled_rows( $this->creator, 'DKK', null, self::at( '2027-01-01 00:00:00' ), 0 );
		self::assertIsArray( $unsettled );

		$expected = array();

		foreach ( $rows as $row ) {
			$expected[ $row['entry']->event_key ] = array( $row['profit'], $row['payout'] );
		}

		foreach ( $unsettled as $row ) {
			self::assertSame( $expected[ $row['event_key'] ], array( $row['profit_units'], $row['payout_units'] ), $row['event_key'] );
		}
	}

	public function test_the_examples_of_the_settlement_table(): void {
		$this->vat->save( $this->creator, false );
		$designer = $this->designer_product( '150', '50' );

		$this->recorder->record( 6011, 6001, $designer, 0, 'DKK' );
		$this->order( 6001, array( $this->line( 6011, $designer, 1, '144', '36' ) ) );
		$this->recorder->record( 6021, 6002, $designer, 0, 'DKK' );
		$this->order( 6002, array( $this->line( 6021, $designer, 1, '96', '24' ) ) );

		$profits = new LedgerCreatorProfits( $this->wpdb );

		self::assertSame( '30.0000', $profits->by_order_line( $this->creator, array( 6001 ) )[6011]['profit']->to_array()['amount'], 'Coupon 20: the company keeps 150, the creator 30.' );
		self::assertSame( '0.0000', $profits->by_order_line( $this->creator, array( 6002 ) )[6021]['profit']->to_array()['amount'], 'Coupon 80: the creator gets 0, never negative.' );
		self::assertSame( '24.0000', ( new LedgerCreatorPayouts( $this->wpdb ) )->earned( $this->creator, null, self::at( '2027-01-01 00:00:00' ), 0 )['DKK']->to_array()['amount'], 'Not VAT registered: 30 × 144 / 180.' );
	}

	public function test_refunds_take_back_the_profit_in_proportion_and_a_line_sold_without_vat_compares_without_vat(): void {
		$this->vat->save( $this->creator, true );
		$designer = $this->designer_product( '150', '50' );
		$line     = $this->line( 7011, $designer, 1, '160', '40' );

		// Sold at 200 (+50), half refunded as an amount only (-25), then the unit (-25): the line ends at 0.
		$this->recorder->record( 7011, 7001, $designer, 0, 'DKK' );
		$this->order( 7001, array( $line ) );
		$this->order( 7001, array( $line ), array( self::refund( 7101, 7011, 0, '-80', '-20' ) ) );
		$this->order( 7001, array( $line ), array( self::refund( 7101, 7011, 0, '-80', '-20' ), self::refund( 7102, 7011, -1, '-80', '-20' ) ) );

		$profits = new LedgerCreatorProfits( $this->wpdb );
		self::assertSame( array( '50.0000', '-25.0000', '-25.0000' ), $this->stored_profits( 7011 ) );
		self::assertSame( '0.0000', $profits->by_order_line( $this->creator, array( 7001 ) )[7011]['profit']->to_array()['amount'] );

		// An export at the full price (160 without VAT): base 120 and profit 40 without VAT, so the creator gets 40.
		$this->recorder->record( 7021, 7002, $designer, 0, 'DKK' );
		$export = $this->line( 7021, $designer, 1, '160', '0', 2500 );
		$this->order( 7002, array( $export ) );
		// With a coupon of 20 (140 without VAT): 140 − 120 = 20.
		$this->recorder->record( 7031, 7003, $designer, 0, 'DKK' );
		$this->order( 7003, array( $this->line( 7031, $designer, 1, '140', '0', 2500 ) ) );
		// The export refunded in full: back to 0.
		$this->order( 7002, array( $export ), array( self::refund( 7201, 7021, -1, '-160', '0' ) ) );

		self::assertSame( array( '40.0000', '-40.0000' ), $this->stored_profits( 7021 ) );
		self::assertSame( array( '20.0000' ), $this->stored_profits( 7031 ) );

		// PHP reads what is stored, so every sum equals PHP.
		foreach ( $this->ledger_rows() as $row ) {
			self::assertSame( $this->stored_profits( $row['entry']->order_item_id, $row['entry']->event_key ), array( SalesAmount::format( (int) $row['profit'] ) ) );
		}
	}

	public function test_a_designer_product_in_another_currency_is_unknown_and_never_a_share(): void {
		$this->vat->save( $this->creator, true );
		$this->markups->save( $this->creator, MarkupBps::from_percent( '20' ) );
		$designer = $this->designer_product( '150', '50' );

		self::assertNull( $this->recorder->record( 6111, 6101, $designer, 0, 'EUR' ) );
		self::assertNull( $this->snapshots->find( 6111 ), 'No snapshot: the share model never applies to a designer product.' );
	}

	public function test_the_creator_code_discounts_the_profit_in_proportion_to_the_price(): void {
		$designer  = $this->designer_product( '150', '50' );
		$discounts = new CreatorProfitDiscount( $this->resolver, $this->source() );

		self::assertTrue( $discounts->owns( $designer, $this->creator ) );
		self::assertFalse( $discounts->owns( $designer, $this->creator + 1 ) );
		self::assertSame( 50.0, $discounts->per_unit( $designer, $this->creator, 200.0 ), 'Incl. VAT: exactly the profit, so the company keeps 150.' );
		self::assertSame( 40.0, $discounts->per_unit( $designer, $this->creator, 160.0 ), 'Excl. VAT: the profit excl. VAT.' );
		self::assertSame( 45.0, $discounts->per_unit( $designer, $this->creator, 180.0 ), 'Below the list price (only the company sets one): its share of the profit.' );
		self::assertNull( $discounts->per_unit( $designer, $this->creator + 1, 200.0 ) );
	}

	public function test_designer_prices_reads_the_live_version_and_its_base_product(): void {
		$prices = new DesignerPrices( $this->wpdb, new PostMetaPricingProductStore() );

		$percent = $this->designer_rows( 9101, '149.95', 'percent', '20' );
		$fixed   = $this->designer_rows( 9102, '150', 'fixed', '49.995' );

		$price = $prices->price( $percent );
		self::assertNotNull( $price );
		self::assertSame( array( $this->creator, '149.9500', '29.9900', 'DKK' ), array( $price->creator_id, SalesAmount::format( $price->base_units ), SalesAmount::format( $price->profit_units ), $price->currency ) );

		$price = $prices->price( $fixed );
		self::assertNotNull( $price );
		self::assertSame( '200.0000', SalesAmount::format( $price->price_units() ), 'The fixed profit is rounded to the store precision.' );

		self::assertNull( $prices->price( 9199 ), 'Not a designer product: the share model applies.' );

		// A designer product without a live version never falls back to the share model.
		$this->wpdb->update( $this->table( Tables::DESIGNER_PRODUCTS ), array( 'live_version_id' => null ), array( 'wc_product_id' => $fixed ) );
		$this->expectException( DatabaseException::class );
		$prices->price( $fixed );
	}

	/**
	 * A fixed designer price source over $this->prices.
	 */
	private function source(): DesignerPriceSource {
		return new class( $this ) implements DesignerPriceSource {

			/**
			 * Constructor.
			 *
			 * @param DesignerSettlementTest $test Test.
			 */
			public function __construct( private DesignerSettlementTest $test ) {
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
	 * A designer product of creator A.
	 *
	 * @param string $base   Base price incl. VAT.
	 * @param string $profit Profit incl. VAT.
	 */
	private function designer_product( string $base, string $profit ): int {
		$id                  = $this->product( SalesAmount::format( SalesAmount::add( SalesAmount::parse( $base ), SalesAmount::parse( $profit ) ) ) );
		$this->prices[ $id ] = new DesignerPrice( $this->creator, SalesAmount::parse( $base ), SalesAmount::parse( $profit ), 'DKK' );

		return $id;
	}

	/**
	 * A live designer product in the designer's tables.
	 *
	 * @param int    $wc_product_id WooCommerce product.
	 * @param string $base_price    Base price.
	 * @param string $kind          Profit kind.
	 * @param string $value         Profit value.
	 */
	private function designer_rows( int $wc_product_id, string $base_price, string $kind, string $value ): int {
		$now  = '2026-10-28 12:00:00';
		$wpdb = $this->wpdb;

		$wpdb->insert(
			$this->table( Tables::DESIGNER_BASES ),
			array(
				'status'      => 'active',
				'name'        => 'Tee',
				'description' => '',
				'base_price'  => $base_price,
				'currency'    => 'DKK',
				'colors'      => '[]',
				'sizes'       => '[]',
				'views'       => '[]',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$base_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$this->table( Tables::DESIGNER_PRODUCTS ),
			array(
				'creator_id'     => $this->creator,
				'base_id'        => $base_id,
				'wc_product_id'  => $wc_product_id,
				'creation_token' => md5( (string) $wc_product_id ),
				'status'         => 'live',
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$product_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$this->table( Tables::DESIGNER_VERSIONS ),
			array(
				'design_product_id' => $product_id,
				'creator_id'        => $this->creator,
				'number'            => 1,
				'status'            => 'approved',
				'base_version'      => 1,
				'title'             => 'Tee',
				'description'       => '',
				'colors'            => '[]',
				'sizes'             => '[]',
				'sides'             => '[]',
				'design'            => '{}',
				'profit_kind'       => $kind,
				'profit_value'      => $value,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$wpdb->update( $this->table( Tables::DESIGNER_PRODUCTS ), array( 'live_version_id' => (int) $wpdb->insert_id ), array( 'id' => $product_id ) );

		return $wc_product_id;
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
			'status'    => 'processing',
			'currency'  => 'DKK',
			'date_paid' => '2026-09-01 10:05:00',
			'lines'     => $lines,
			'refunds'   => $refunds,
		);
		$this->sync->sync( $order_id );
		self::assertSame( array(), $this->logger->at( 'error' ), 'Order ' . $order_id . ' synchronized without errors.' );
	}

	/**
	 * Every ledger row of creator A with its PHP profit, payout and tax.
	 *
	 * @return list<array{entry: SalesEntry, profit: int|null, payout: int|null, tax: int}>
	 */
	private function ledger_rows(): array {
		$rows  = array();
		$taxes = new SalesTaxRepository( $this->wpdb );

		foreach ( $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE creator_id = %d ORDER BY order_id', $this->table( Tables::SALES ), $this->creator ) ) as $order_id ) {
			foreach ( $this->ledger->entries_for_order( (int) $order_id ) as $entry ) {
				$tax = $taxes->find( $entry->event_key );
				self::assertNotNull( $tax, 'Every row has its tax.' );
				$rows[] = array(
					'entry'  => $entry,
					'profit' => $this->profit->for_entry( $entry ),
					'payout' => $this->profit->payout_for_entry( $entry ),
					'tax'    => $tax['tax'],
				);
			}
		}

		return $rows;
	}

	/**
	 * The stored profits of an order line's rows (or one row), in order.
	 *
	 * @param int         $item      Order item.
	 * @param string|null $event_key One row only.
	 * @return list<string>
	 */
	private function stored_profits( int $item, ?string $event_key = null ): array {
		return array_map( 'strval', (array) $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT t.designer_profit FROM %i t JOIN %i s ON s.event_key = t.event_key WHERE t.order_item_id = %d AND ( %s = \'\' OR t.event_key = %s ) ORDER BY s.id', $this->table( Tables::SALES_TAX ), $this->table( Tables::SALES ), $item, (string) $event_key, (string) $event_key ) ) );
	}

	/**
	 * An order line of creator A.
	 *
	 * @param int      $item     Order item.
	 * @param int      $product  Product.
	 * @param int      $quantity Quantity.
	 * @param string   $net      Amount excl. VAT.
	 * @param string   $tax      Actual tax.
	 * @param int|null $exempt   The VAT rate the line was sold without (basis points).
	 */
	private function line( int $item, int $product, int $quantity, string $net, string $tax, ?int $exempt = null ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, $product, 0, 'MB-DES line', $quantity, SalesAmount::parse( $net ), $this->creator, SalesAmount::parse( $tax ), null, $exempt );
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
		return new RefundSnapshot( $id, self::at( '2026-09-05 13:00:00' ), array( new RefundLineSnapshot( $item, $quantity, SalesAmount::parse( $net ), SalesAmount::parse( $tax ) ) ) );
	}

	/**
	 * A product of creator A.
	 *
	 * @param string $price Regular price.
	 */
	private function product( string $price ): int {
		$id = $this->posts->add( 'MB-DES product', 'publish', array( (string) $this->creator ) );
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
