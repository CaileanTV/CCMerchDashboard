<?php
/**
 * The "Sales" section on the creator's edit page (ADR-040 pkt. 13, project
 * owner's decision 2026-10-07) on a real database, rendered through the real
 * CreatorsAdminPage. WooCommerce is not loaded: ledger rows are stored
 * directly and read by the real LedgerSalesStatistics through the same
 * CachedSalesReports as `GET /me/sales`. Site time zone Europe/Copenhagen,
 * fixed clock (2026-09-30 12:00 local). The admin is user 1.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CreatorSalesSection;
use MerchBoard\Modules\WooCommerce\Dashboard\CachedSalesReports;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReport;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeSalesProductNames;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use RuntimeException;

final class CreatorSalesSectionTest extends IntegrationTestCase {

	private const NOW = '2026-09-30 10:00:00';

	private CreatorSalesSection $section;

	private SalesCache $cache;

	private CachedSalesReports $reports;

	private Creator $alpha;

	private Creator $beta;

	private int $key = 0;

	private string $timezone_string = '';

	private string $gmt_offset = '';

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->timezone_string = (string) get_option( 'timezone_string' );
		$this->gmt_offset      = (string) get_option( 'gmt_offset' );
		update_option( 'timezone_string', 'Europe/Copenhagen' );
		update_option( 'gmt_offset', '' );
		wp_set_current_user( 1 );

		$creators      = $this->service( CreatorService::class );
		$this->alpha   = $creators->create( 'Sales Alpha', CreatorStatus::Active, null );
		$this->beta    = $creators->create( 'Sales Beta', CreatorStatus::Inactive, null );
		$this->cache   = new SalesCache();
		$this->reports = new CachedSalesReports( new SalesReport( new LedgerSalesStatistics( $this->wpdb ), new FakeSalesProductNames(), new LedgerCreatorProfits( $this->wpdb ) ), $this->cache );
		$this->section = new CreatorSalesSection(
			$this->reports,
			new RecordingLogger(),
			static fn(): DateTimeImmutable => new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) )
		);
	}

	protected function tearDown(): void {
		remove_all_actions( 'merchboard_creators_edit_sections' );
		update_option( 'timezone_string', $this->timezone_string );
		update_option( 'gmt_offset', $this->gmt_offset );
		$_GET = array();
		parent::tearDown();
	}

	public function test_the_section_is_on_the_edit_page_of_the_creator_with_the_default_period(): void {
		$this->row( $this->alpha, 'sale', 101, 2, '200.0000', '2026-09-10 10:00:00' );
		$this->section->register();

		$html = $this->edit_page( $this->alpha );

		self::assertStringContainsString( '<h2 id="merchboard-sales">Sales</h2>', $html );
		self::assertGreaterThan( strpos( $html, '</form>' ), strpos( $html, 'merchboard-sales' ), 'Below the creator form.' );
		self::assertStringContainsString( 'Last 30 days: September 1, 2026 – September 30, 2026', $html );
		self::assertStringContainsString( 'The figures and their definitions are the same as in the creator&#039;s dashboard', $html );
		self::assertSame( array( '200.00 DKK', '2', '1' ), self::totals( $html, 'DKK' ) );
		self::assertSame( 30, substr_count( (string) strstr( $html, 'merchboard-sales-series' ), '<th scope="row">' ), 'One row per day of the 30-day period.' );
	}

	public function test_the_amounts_include_vat_when_every_row_has_its_stored_tax(): void {
		$this->row( $this->alpha, 'sale', 101, 2, '200.0000', '2026-09-10 10:00:00' );
		self::assertSame(
			1,
			$this->wpdb->insert(
				$this->table( Tables::SALES_TAX ),
				array(
					'event_key'     => 'test:' . $this->key,
					'order_id'      => 101,
					'order_item_id' => 1010,
					'tax_amount'    => '50.0000',
					'currency'      => 'DKK',
					'created_at'    => '2026-09-10 10:00:00',
				)
			)
		);

		$html = $this->render( $this->alpha );

		self::assertSame( array( '250.00 DKK', '2', '1' ), self::totals( $html, 'DKK' ) );
		self::assertStringContainsString( '<th scope="col">Sales (incl. VAT)</th>', $html );
		self::assertStringContainsString( '<th scope="row">September 10, 2026</th><td>250.00 DKK</td>', $html );
		self::assertStringNotContainsString( 'VAT is missing', $html );

		// A row without stored tax: the amounts excl. VAT, labelled, with the note.
		$this->row( $this->alpha, 'sale', 102, 1, '80.0000', '2026-09-11 10:00:00' );
		$this->cache->invalidate( array( $this->alpha->id ) );
		$html = $this->render( $this->alpha );

		self::assertSame( array( '280.00 DKK', '3', '2' ), self::totals( $html, 'DKK' ) );
		self::assertStringContainsString( '<th scope="col">Net sales (excl. VAT)</th>', $html );
		self::assertStringContainsString( 'VAT is missing for some older sales in this period, so the amounts are shown excluding VAT.', $html );
	}

	public function test_the_default_period_is_30_local_days_including_today(): void {
		$this->row( $this->alpha, 'sale', 101, 1, '1.0000', '2026-08-31 21:59:59' ); // August 31, 23:59:59 local.
		$this->row( $this->alpha, 'sale', 102, 1, '2.0000', '2026-08-31 22:00:00' ); // September 1, 00:00 local.
		$this->row( $this->alpha, 'sale', 103, 1, '4.0000', '2026-09-30 09:00:00' ); // Today.
		$this->row( $this->alpha, 'sale', 104, 1, '8.0000', '2026-09-30 22:00:00' ); // Tomorrow.

		$html = $this->render( $this->alpha );

		self::assertSame( array( '6.00 DKK', '2', '2' ), self::totals( $html, 'DKK' ) );
		self::assertStringContainsString( '<th scope="row">September 1, 2026</th><td>2.00 DKK</td>', $html );
		self::assertStringContainsString( '<th scope="row">September 30, 2026</th><td>4.00 DKK</td>', $html );
		self::assertStringNotContainsString( 'August 31', $html );
		self::assertStringNotContainsString( 'October 1', $html );
	}

	public function test_net_sales_units_and_orders_follow_adr_040_with_refunds_reversals_and_corrections(): void {
		$this->row( $this->alpha, 'sale', 101, 3, '300.0000', '2026-09-01 10:00:00' );
		$this->row( $this->alpha, 'sale', 101, 1, '50.0000', '2026-09-01 10:00:00', array( 'order_item_id' => 1012 ) );
		$this->row( $this->alpha, 'refund', 101, -1, '-100.0000', '2026-09-02 10:00:00' );
		$this->row( $this->alpha, 'reversal', 102, -2, '-200.0000', '2026-09-03 10:00:00' ); // Sold in August.
		$this->row( $this->alpha, 'correction', 103, 1, '25.5000', '2026-09-04 10:00:00', array( 'correction_reason' => 'refund_deleted' ) );
		$this->row( $this->alpha, 'sale', 102, 2, '200.0000', '2026-08-20 10:00:00' );

		$html = $this->render( $this->alpha );

		// sales 350, adjustments -274.5: net 75.5; units 3 + 1 - 1 - 2 + 1; orders: only 101 has a sale row in the period.
		self::assertSame( array( '75.50 DKK', '2', '1' ), self::totals( $html, 'DKK' ) );
		self::assertStringContainsString( '<th scope="row">September 3, 2026</th><td>-200.00 DKK</td><td>-2</td><td>0</td>', $html );
	}

	public function test_currencies_are_separate_blocks_without_conversion_or_a_total(): void {
		$this->row( $this->alpha, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		$this->row( $this->alpha, 'sale', 102, 2, '30.2500', '2026-09-11 10:00:00', array( 'currency' => 'EUR' ) );
		$this->row( $this->alpha, 'sale', 103, 1, '5.0000', '2026-09-12 10:00:00', array( 'currency' => 'SEK' ) );

		$html = $this->render( $this->alpha );

		self::assertSame( array( '<h3>DKK</h3>', '<h3>EUR</h3>', '<h3>SEK</h3>' ), self::all_matches( '#<h3>[A-Z]{3}</h3>#', $html ) );
		self::assertSame( array( '100.00 DKK', '1', '1' ), self::totals( $html, 'DKK' ) );
		self::assertSame( array( '30.25 EUR', '2', '1' ), self::totals( $html, 'EUR' ) );
		self::assertSame( array( '5.00 SEK', '1', '1' ), self::totals( $html, 'SEK' ) );
		self::assertStringContainsString( 'The figures are shown per currency and never added together.', $html );

		foreach ( array( '135.25', '135,25', '4', 'Total' ) as $sum ) {
			self::assertNotContains( $sum, self::totals( $html, 'DKK' ) );
			self::assertStringNotContainsString( '<td>' . $sum, $html );
		}
	}

	public function test_only_the_edited_creators_sales_and_a_request_creator_id_changes_nothing(): void {
		$this->row( $this->alpha, 'sale', 101, 1, '111.0000', '2026-09-10 10:00:00' );
		$this->row( $this->beta, 'sale', 201, 9, '999.0000', '2026-09-10 10:00:00', array( 'currency' => 'EUR' ) );
		$this->section->register();

		$alpha = $this->edit_page(
			$this->alpha,
			array(
				'creator_id' => (string) $this->beta->id,
				'user_id'    => '1',
				'currency'   => 'EUR',
			)
		);
		$beta  = $this->edit_page( $this->beta );

		self::assertSame( array( '111.00 DKK', '1', '1' ), self::totals( $alpha, 'DKK' ) );
		self::assertStringNotContainsString( '999', $alpha );
		self::assertStringNotContainsString( '<h3>EUR</h3>', $alpha );
		self::assertSame( array( '999.00 EUR', '9', '1' ), self::totals( $beta, 'EUR' ), 'An inactive creator has its sales too.' );
		self::assertStringNotContainsString( '111', $beta );
	}

	public function test_without_sales_there_is_an_empty_state_and_no_zero_figures(): void {
		$this->row( $this->alpha, 'sale', 101, 1, '100.0000', '2026-07-10 10:00:00' );

		$html = $this->render( $this->alpha );

		self::assertStringContainsString( 'No sales in the last 30 days.', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringNotContainsString( '0.00', $html );
	}

	public function test_users_without_the_capability_see_no_sales_and_the_page_refuses_them(): void {
		$this->row( $this->alpha, 'sale', 101, 1, '123.0000', '2026-09-10 10:00:00' );
		$this->section->register();
		$editor = $this->create_user( 'plain_editor' );
		( new \WP_User( $editor ) )->set_role( 'editor' );
		wp_set_current_user( $editor );

		self::assertSame( '', $this->render( $this->alpha ), 'The section renders nothing.' );

		$die = static function (): callable {
			return static function ( mixed $message ): never {
				throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
			};
		};
		add_filter( 'wp_die_handler', $die );

		try {
			$this->edit_page( $this->alpha );
			self::fail( 'The page should refuse the user.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'not allowed to manage creators', $e->getMessage() );
		} finally {
			remove_filter( 'wp_die_handler', $die );
			ob_end_clean();
		}
	}

	public function test_no_customer_data_order_ids_or_product_names(): void {
		$this->row(
			$this->alpha,
			'sale',
			4242,
			1,
			'100.0000',
			'2026-09-10 10:00:00',
			array(
				'order_item_id' => 4343,
				'item_name'     => 'Secret tee',
				'variation_id'  => 77,
			)
		);

		$html = $this->render( $this->alpha );

		foreach ( array( '4242', '4343', 'Secret tee', 'item_name', 'email', 'customer', 'billing', 'address', 'event_key' ) as $leak ) {
			self::assertStringNotContainsString( $leak, $html );
		}
	}

	public function test_no_http_or_rest_request_is_made(): void {
		$this->row( $this->alpha, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		$http   = 0;
		$rest   = 0;
		$count  = static function ( mixed $value ) use ( &$http ): mixed {
			++$http;

			return $value;
		};
		$record = static function ( mixed $value ) use ( &$rest ): mixed {
			++$rest;

			return $value;
		};
		add_filter( 'pre_http_request', $count );
		add_filter( 'rest_pre_dispatch', $record );

		$html = $this->render( $this->alpha );

		remove_filter( 'pre_http_request', $count );
		remove_filter( 'rest_pre_dispatch', $record );
		self::assertStringContainsString( '100.00 DKK', $html );
		self::assertSame( 0, $http, 'No HTTP request (not to /me/sales or anywhere).' );
		self::assertSame( 0, $rest, 'No REST dispatch.' );
	}

	public function test_it_shares_the_cache_entry_of_the_creators_default_sales_and_its_invalidation(): void {
		$this->row( $this->alpha, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		$queries = $this->sales_queries( fn() => $this->render( $this->alpha ) );
		self::assertGreaterThan( 0, $queries, 'A miss reads the ledger.' );

		self::assertSame( 0, $this->sales_queries( fn() => $this->render( $this->alpha ) ), 'A second view is a hit.' );

		// The creator's `GET /me/sales` without parameters: the same key, so a hit.
		$query = SalesQuery::resolve( null, null, SalesInterval::Day, wp_timezone(), new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) ) );
		self::assertSame( 0, $this->sales_queries( fn() => $this->reports->build( $this->alpha->id, $query ) ) );

		// A committed ledger write invalidates the creator (SalesRepository::write()); simulated here.
		$this->row( $this->alpha, 'sale', 102, 1, '50.0000', '2026-09-11 10:00:00' );
		$this->cache->invalidate( array( $this->alpha->id ) );
		$html = '';
		self::assertGreaterThan(
			0,
			$this->sales_queries(
				function () use ( &$html ): void {
					$html = $this->render( $this->alpha );
				}
			)
		);
		self::assertSame( array( '150.00 DKK', '2', '2' ), self::totals( $html, 'DKK' ) );
	}

	public function test_without_the_woocommerce_module_the_page_has_no_sales_section_and_no_error(): void {
		$this->row( $this->alpha, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );

		$html = $this->edit_page( $this->alpha );

		self::assertStringContainsString( 'Sales Alpha', $html );
		self::assertStringNotContainsString( 'merchboard-sales', $html );
		self::assertStringNotContainsString( '100.00', $html );
	}

	/**
	 * Renders the section for a creator.
	 *
	 * @param Creator $creator Creator.
	 */
	private function render( Creator $creator ): string {
		ob_start();
		$this->section->render( $creator );

		return (string) ob_get_clean();
	}

	/**
	 * Renders the real edit page of a creator.
	 *
	 * @param Creator               $creator Creator.
	 * @param array<string, string> $extra   More query parameters.
	 */
	private function edit_page( Creator $creator, array $extra = array() ): string {
		$_GET = array_merge(
			$extra,
			array(
				'page'    => CreatorsAdminPage::SLUG,
				'action'  => 'edit',
				'creator' => (string) $creator->id,
			)
		);
		ob_start();
		$this->service( CreatorsAdminPage::class )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Queries against the sales table while a callback runs.
	 *
	 * @param callable(): mixed $callback Callback.
	 */
	private function sales_queries( callable $callback ): int {
		$table  = $this->table( Tables::SALES );
		$count  = 0;
		$record = static function ( string $query ) use ( $table, &$count ): string {
			if ( str_contains( $query, $table ) ) {
				++$count;
			}

			return $query;
		};
		add_filter( 'query', $record );

		try {
			$callback();
		} finally {
			remove_filter( 'query', $record );
		}

		return $count;
	}

	/**
	 * The totals row of a currency block: [net sales, units, orders].
	 *
	 * @param string $html     Rendered HTML.
	 * @param string $currency Currency code.
	 * @return list<string>
	 */
	private static function totals( string $html, string $currency ): array {
		if ( 1 !== preg_match( '#<h3>' . $currency . '</h3>(?:<p class="description">[^<]*</p>)?<table class="widefat striped merchboard-sales-totals"[^>]*>.*?<tbody><tr><td>(.*?)</td><td>(.*?)</td><td>(.*?)</td></tr>#s', $html, $match ) ) {
			return array();
		}

		return array( $match[1], $match[2], $match[3] );
	}

	/**
	 * Every match of a pattern.
	 *
	 * @param string $pattern Pattern.
	 * @param string $html    HTML.
	 * @return list<string>
	 */
	private static function all_matches( string $pattern, string $html ): array {
		preg_match_all( $pattern, $html, $found );

		return $found[0];
	}

	/**
	 * Stores one ledger row of a creator: product 20, DKK.
	 *
	 * @param Creator              $creator     Creator.
	 * @param string               $type        Type.
	 * @param int                  $order_id    Order id.
	 * @param int                  $quantity    Signed quantity.
	 * @param string               $amount      Signed net amount.
	 * @param string               $occurred_at UTC time.
	 * @param array<string, mixed> $columns     Other columns.
	 */
	private function row( Creator $creator, string $type, int $order_id, int $quantity, string $amount, string $occurred_at, array $columns = array() ): void {
		$inserted = $this->wpdb->insert(
			$this->table( Tables::SALES ),
			array_merge(
				array(
					'event_key'         => 'test:' . ( ++$this->key ),
					'creator_id'        => $creator->id,
					'order_id'          => $order_id,
					'order_item_id'     => $order_id * 10,
					'cycle'             => 1,
					'refund_id'         => 'refund' === $type ? $order_id + 9000 : 0,
					'product_id'        => 20,
					'variation_id'      => 0,
					'item_name'         => 'Line',
					'type'              => $type,
					'correction_reason' => null,
					'quantity'          => $quantity,
					'net_amount'        => $amount,
					'currency'          => 'DKK',
					'occurred_at'       => $occurred_at,
					'created_at'        => '2026-10-01 10:00:00',
				),
				$columns
			)
		);

		self::assertSame( 1, $inserted, $this->wpdb->last_error );
	}
}
