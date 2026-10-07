<?php
/**
 * GET /me/sales against the real WordPress REST server, users, roles and
 * database (ADR-040 pkt. 11). WooCommerce is not loaded here: ledger rows are
 * stored directly, the real LedgerSalesStatistics reads them, and the
 * controller is registered by the test with FakeSalesProductNames (the
 * WooCommerce lookup is unit-tested in WooCommerceSalesProductNamesTest). The
 * site time zone is Europe/Copenhagen and the clock is fixed.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Rest;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReport;
use MerchBoard\Modules\WooCommerce\Rest\MeSalesController;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeSalesProductNames;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class MeSalesEndpointTest extends IntegrationTestCase {

	private const ROUTE = '/merchboard/v1/me/sales';

	/**
	 * Fixed "now": 2026-09-30 12:00 in Copenhagen.
	 */
	private const NOW = '2026-09-30 10:00:00';

	private CreatorService $creators;

	private FakeSalesProductNames $names;

	private string $now = self::NOW;

	private int $key = 0;

	private string $timezone_string = '';

	private string $gmt_offset = '';

	/**
	 * Registers the route on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->timezone_string = (string) get_option( 'timezone_string' );
		$this->gmt_offset      = (string) get_option( 'gmt_offset' );
		update_option( 'timezone_string', 'Europe/Copenhagen' );
		update_option( 'gmt_offset', '' );

		$this->creators = $this->service( CreatorService::class );
		$this->names    = new FakeSalesProductNames();
		$controller     = new MeSalesController(
			$this->service( CreatorContext::class ),
			new SalesReport( new LedgerSalesStatistics( $this->wpdb ), $this->names ),
			$this->service( Logger::class ),
			fn(): DateTimeImmutable => new DateTimeImmutable( $this->now, new DateTimeZone( 'UTC' ) )
		);
		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		update_option( 'timezone_string', $this->timezone_string );
		update_option( 'gmt_offset', $this->gmt_offset );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		parent::tearDown();
	}

	public function test_the_route_is_registered_with_a_permission_callback_and_its_args(): void {
		$routes = rest_get_server()->get_routes( 'merchboard/v1' );

		self::assertArrayHasKey( self::ROUTE, $routes );
		self::assertCount( 1, $routes[ self::ROUTE ] );

		$endpoint = $routes[ self::ROUTE ][0];
		self::assertSame( array( 'GET' => true ), $endpoint['methods'] );
		self::assertIsCallable( $endpoint['permission_callback'] ?? null );
		self::assertNotSame( '__return_true', $endpoint['permission_callback'] );
		self::assertSame( array( 'from', 'to', 'interval' ), array_keys( $endpoint['args'] ) );
	}

	public function test_there_is_no_overview_route(): void {
		wp_set_current_user( $this->creator_user( 'mine', 'Mine', CreatorStatus::Active ) );

		self::assertArrayNotHasKey( '/merchboard/v1/me/overview', rest_get_server()->get_routes( 'merchboard/v1' ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/merchboard/v1/me/overview' ) );
		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'rest_no_route', ( (array) $response->get_data() )['code'] ?? null );
	}

	public function test_without_woocommerce_the_plugin_registers_no_route(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		self::assertNotNull( $this->register );
		remove_action( 'rest_api_init', $this->register );
		$this->register            = null;
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		wp_set_current_user( $mine );

		$response = $this->request();

		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'rest_no_route', ( (array) $response->get_data() )['code'] ?? null );
		self::assertSame( 1, $this->count_rows( Tables::SALES ), 'The ledger is untouched.' );
	}

	/**
	 * Users without creator access.
	 *
	 * @return array<string, array{string, int, string}>
	 */
	public static function denied(): array {
		return array(
			'logged out'                   => array( 'logged_out', 401, 'merchboard_unauthenticated' ),
			'subscriber'                   => array( 'subscriber', 403, 'merchboard_forbidden' ),
			'administrator without record' => array( 'admin', 403, 'merchboard_forbidden' ),
			'inactive creator'             => array( 'inactive', 403, 'merchboard_forbidden' ),
			'creator role, no record'      => array( 'no_record', 403, 'merchboard_forbidden' ),
			'active record, no capability' => array( 'no_capability', 403, 'merchboard_forbidden' ),
		);
	}

	/**
	 * Every user without creator access is denied and sees no sales.
	 *
	 * @param string $kind   User kind.
	 * @param int    $status Expected status.
	 * @param string $code   Expected error code.
	 */
	#[DataProvider( 'denied' )]
	public function test_users_without_creator_access_get_no_sales( string $kind, int $status, string $code ): void {
		$bystander = $this->creator_user( 'bystander', 'Bystander', CreatorStatus::Active );
		$this->row( $bystander, 'sale', 101, 1, '123.4500', '2026-09-10 10:00:00', array( 'item_name' => 'Bystander tee' ) );
		wp_set_current_user( $this->user_of_kind( $kind ) );

		$response = $this->request();
		$json     = (string) wp_json_encode( $response->get_data() );

		self::assertSame( $status, $response->get_status() );
		self::assertSame( $code, ( (array) $response->get_data() )['code'] ?? null );
		self::assertStringNotContainsString( 'Bystander tee', $json );
		self::assertStringNotContainsString( '123.45', $json );
		self::assertSame( array(), $this->names->calls, 'Nothing was looked up.' );
	}

	public function test_the_default_period_is_the_last_30_days_by_day_in_the_site_time_zone(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-08-31 21:59:59' ); // August 31, 23:59:59 local: before the period.
		$this->row( $mine, 'sale', 102, 2, '200.0000', '2026-08-31 22:00:00' ); // September 1, 00:00 local.
		$this->row( $mine, 'sale', 103, 1, '50.0000', '2026-09-30 09:00:00' );
		wp_set_current_user( $mine );

		$data = $this->data();

		self::assertSame( array( '2026-09-01', '2026-09-30', 'day', 'Europe/Copenhagen' ), array( $data['from'], $data['to'], $data['interval'], $data['timezone'] ) );
		self::assertCount( 1, $data['currencies'] );

		$dkk = $data['currencies'][0];
		self::assertSame( 'DKK', $dkk['currency'] );
		self::assertSame( '250.0000', $dkk['totals']['net_sales'] );
		self::assertSame( 3, $dkk['totals']['units'] );
		self::assertCount( 30, $dkk['series'] );
		self::assertSame( array( '2026-09-01', '2026-09-01', '200.0000' ), array( $dkk['series'][0]['start'], $dkk['series'][0]['end'], $dkk['series'][0]['net_sales'] ) );
		self::assertSame( array( '2026-09-30', '50.0000' ), array( $dkk['series'][29]['start'], $dkk['series'][29]['net_sales'] ) );
	}

	public function test_today_is_the_local_date(): void {
		$this->now = '2026-09-30 22:30:00'; // October 1, 00:30 in Copenhagen.
		wp_set_current_user( $this->creator_user( 'mine', 'Mine', CreatorStatus::Active ) );

		$data = $this->data();

		self::assertSame( array( '2026-09-02', '2026-10-01' ), array( $data['from'], $data['to'] ) );
		self::assertSame( 'Europe/Copenhagen', $data['timezone'] );
	}

	public function test_only_from_or_only_to(): void {
		wp_set_current_user( $this->creator_user( 'mine', 'Mine', CreatorStatus::Active ) );

		$only_to   = $this->data( array( 'to' => '2026-03-31' ) );
		$only_from = $this->data( array( 'from' => '2026-09-20' ) );

		self::assertSame( array( '2026-03-02', '2026-03-31' ), array( $only_to['from'], $only_to['to'] ), '29 days before to.' );
		self::assertSame( array( '2026-09-20', '2026-09-30' ), array( $only_from['from'], $only_from['to'] ), 'To is today.' );
	}

	public function test_explicit_dates_are_inclusive_local_days(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		// July 1 to July 31 in Copenhagen is [2026-06-30 22:00, 2026-07-31 22:00) UTC.
		$this->row( $mine, 'sale', 101, 1, '1.0000', '2026-06-30 21:59:59' );
		$this->row( $mine, 'sale', 102, 1, '2.0000', '2026-06-30 22:00:00' );
		$this->row( $mine, 'sale', 103, 1, '4.0000', '2026-07-31 21:59:59' );
		$this->row( $mine, 'sale', 104, 1, '8.0000', '2026-07-31 22:00:00' );
		wp_set_current_user( $mine );

		$data = $this->data(
			array(
				'from' => '2026-07-01',
				'to'   => '2026-07-31',
			)
		);

		self::assertSame( array( '2026-07-01', '2026-07-31' ), array( $data['from'], $data['to'] ) );
		self::assertSame( array( '6.0000', 2, 2 ), self::total( $data, 'DKK', array( 'net_sales', 'units', 'orders' ) ) );
		self::assertCount( 31, $data['currencies'][0]['series'] );
	}

	public function test_daily_series_across_daylight_saving_time(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '1.0000', '2026-10-24 21:59:59' ); // October 24, 23:59:59 local (UTC+2).
		$this->row( $mine, 'sale', 102, 1, '2.0000', '2026-10-25 22:59:59' ); // October 25, 23:59:59 local (UTC+1, a 25-hour day).
		$this->row( $mine, 'sale', 103, 1, '4.0000', '2026-10-25 23:00:00' ); // October 26, 00:00 local.
		wp_set_current_user( $mine );

		$data = $this->data(
			array(
				'from' => '2026-10-24',
				'to'   => '2026-10-26',
			)
		);

		self::assertSame(
			array(
				array( '2026-10-24', '2026-10-24', '1.0000' ),
				array( '2026-10-25', '2026-10-25', '2.0000' ),
				array( '2026-10-26', '2026-10-26', '4.0000' ),
			),
			self::series( $data, 'DKK', array( 'start', 'end', 'net_sales' ) )
		);
	}

	public function test_weekly_series_has_local_iso_weeks_and_partial_first_and_last_weeks(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-07-05 21:59:59' ); // Sunday 23:59:59 local.
		$this->row( $mine, 'sale', 102, 1, '50.0000', '2026-07-05 22:00:00' );  // Monday 00:00 local.
		wp_set_current_user( $mine );

		$data = $this->data(
			array(
				'from'     => '2026-07-01',
				'to'       => '2026-07-16',
				'interval' => 'week',
			)
		);

		self::assertSame( 'week', $data['interval'] );
		self::assertSame(
			array(
				array( '2026-07-01', '2026-07-05', '100.0000', 1 ),
				array( '2026-07-06', '2026-07-12', '50.0000', 1 ),
				array( '2026-07-13', '2026-07-16', '0.0000', 0 ),
			),
			self::series( $data, 'DKK', array( 'start', 'end', 'net_sales', 'orders' ) )
		);
	}

	public function test_monthly_series_has_local_calendar_months(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 2, '200.0000', '2026-01-31 22:59:59' ); // January 31, 23:59:59 local.
		$this->row( $mine, 'sale', 102, 1, '100.0000', '2026-01-31 23:00:00' ); // February 1, 00:00 local.
		$this->row( $mine, 'refund', 101, -1, '-100.0000', '2026-03-31 21:59:59' ); // March 31, 23:59:59 local (UTC+2).
		wp_set_current_user( $mine );

		$data = $this->data(
			array(
				'from'     => '2026-01-15',
				'to'       => '2026-04-10',
				'interval' => 'month',
			)
		);

		self::assertSame(
			array(
				array( '2026-01-15', '2026-01-31', '200.0000', 2, 1 ),
				array( '2026-02-01', '2026-02-28', '100.0000', 1, 1 ),
				array( '2026-03-01', '2026-03-31', '-100.0000', -1, 0 ),
				array( '2026-04-01', '2026-04-10', '0.0000', 0, 0 ),
			),
			self::series( $data, 'DKK', array( 'start', 'end', 'net_sales', 'units', 'orders' ) )
		);
	}

	public function test_the_series_is_dense_before_between_and_after_the_events(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-03 10:00:00' );
		$this->row( $mine, 'sale', 102, 1, '25.5000', '2026-09-05 10:00:00' );
		wp_set_current_user( $mine );

		$data = $this->data(
			array(
				'from' => '2026-09-01',
				'to'   => '2026-09-07',
			)
		);

		self::assertSame(
			array(
				array( '2026-09-01', '0.0000', 0, 0 ),
				array( '2026-09-02', '0.0000', 0, 0 ),
				array( '2026-09-03', '100.0000', 1, 1 ),
				array( '2026-09-04', '0.0000', 0, 0 ),
				array( '2026-09-05', '25.5000', 1, 1 ),
				array( '2026-09-06', '0.0000', 0, 0 ),
				array( '2026-09-07', '0.0000', 0, 0 ),
			),
			self::series( $data, 'DKK', array( 'start', 'net_sales', 'units', 'orders' ) )
		);
	}

	public function test_currencies_are_separate_blocks_in_alphabetical_order_each_with_a_full_series(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-02 10:00:00', array( 'currency' => 'SEK' ) );
		$this->row( $mine, 'sale', 102, 2, '30.5000', '2026-09-03 10:00:00', array( 'currency' => 'EUR' ) );
		$this->row( $mine, 'sale', 103, 1, '75.0000', '2026-09-03 10:00:00', array( 'product_id' => 30 ) );
		$this->row( $mine, 'refund', 102, -1, '-15.2500', '2026-09-04 10:00:00', array( 'currency' => 'EUR' ) );
		$this->names->names = array( 20 => 'Tour tee' );
		wp_set_current_user( $mine );

		$data = $this->data(
			array(
				'from' => '2026-09-01',
				'to'   => '2026-09-05',
			)
		);

		self::assertSame( array( 'DKK', 'EUR', 'SEK' ), array_column( $data['currencies'], 'currency' ) );
		self::assertSame( array( '75.0000', '75.0000', '0.0000', 1, 1 ), array_values( $data['currencies'][0]['totals'] ) );
		self::assertSame( array( '15.2500', '30.5000', '-15.2500', 1, 1 ), array_values( $data['currencies'][1]['totals'] ) );
		self::assertSame( array( '100.0000', '100.0000', '0.0000', 1, 1 ), array_values( $data['currencies'][2]['totals'] ) );

		foreach ( $data['currencies'] as $block ) {
			self::assertCount( 5, $block['series'], $block['currency'] );
		}

		self::assertSame( array( '0.0000', '0.0000', '30.5000', '-15.2500', '0.0000' ), array_column( $data['currencies'][1]['series'], 'net_sales' ) );
		self::assertSame( array( 30 ), array_column( $data['currencies'][0]['products'], 'product_id' ) );
		self::assertSame( array( 20 ), array_column( $data['currencies'][1]['products'], 'product_id' ) );
		self::assertSame( array( array( 30, 20 ) ), $this->names->calls, 'One lookup per distinct product, across currencies.' );
		self::assertArrayNotHasKey( 'totals', $data, 'No total across currencies.' );
	}

	public function test_an_empty_period_has_no_currencies(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-07-10 10:00:00' );
		wp_set_current_user( $mine );

		$response = $this->request(
			array(
				'from' => '2026-08-01',
				'to'   => '2026-08-31',
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			'{"from":"2026-08-01","to":"2026-08-31","interval":"day","timezone":"Europe\/Copenhagen","currencies":[]}',
			wp_json_encode( $response->get_data() )
		);
		self::assertSame( array(), $this->names->calls );
	}

	public function test_at_most_100_products_per_currency_with_truncated(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );

		foreach ( range( 1, 101 ) as $i ) {
			$this->row( $mine, 'sale', 1000 + $i, 1, $i . '.0000', '2026-09-10 10:00:00', array( 'product_id' => 500 + $i ) );
		}

		foreach ( range( 1, 100 ) as $i ) {
			$this->row(
				$mine,
				'sale',
				2000 + $i,
				1,
				$i . '.0000',
				'2026-09-10 10:00:00',
				array(
					'product_id' => 500 + $i,
					'currency'   => 'EUR',
				)
			);
		}

		wp_set_current_user( $mine );

		$data = $this->data();
		$dkk  = $data['currencies'][0];
		$eur  = $data['currencies'][1];

		self::assertCount( 100, $dkk['products'] );
		self::assertTrue( $dkk['truncated'] );
		self::assertSame( 601, $dkk['products'][0]['product_id'], 'The largest net sales first.' );
		self::assertSame( 502, $dkk['products'][99]['product_id'], 'The smallest (501) is cut off.' );
		self::assertSame( '5151.0000', $dkk['totals']['net_sales'], 'Totals still include every product.' );
		self::assertCount( 100, $eur['products'] );
		self::assertFalse( $eur['truncated'] );
		self::assertCount( 1, $this->names->calls );
		self::assertCount( 101, $this->names->calls[0], 'Only shown products are looked up: 501-600 (EUR) and 502-601 (DKK).' );
	}

	public function test_products_are_sorted_by_net_sales_then_product_id(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '50.0000', '2026-09-10 10:00:00', array( 'product_id' => 40 ) );
		$this->row( $mine, 'sale', 102, 1, '100.0000', '2026-09-10 10:00:00', array( 'product_id' => 50 ) );
		$this->row( $mine, 'sale', 103, 1, '100.0000', '2026-09-10 10:00:00', array( 'product_id' => 30 ) );
		$this->row( $mine, 'sale', 104, 1, '250.0000', '2026-09-10 10:00:00', array( 'product_id' => 60 ) );
		$this->row( $mine, 'refund', 104, -1, '-250.0000', '2026-09-11 10:00:00', array( 'product_id' => 60 ) );
		wp_set_current_user( $mine );

		$products = $this->data()['currencies'][0]['products'];

		self::assertSame( array( 30, 50, 40, 60 ), array_column( $products, 'product_id' ) );
		self::assertSame( array( '100.0000', '100.0000', '50.0000', '0.0000' ), array_column( $products, 'net_sales' ) );
	}

	public function test_product_names_are_current_names_or_the_ledger_fallback(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		// Product 20 exists (renamed); product 30 was deleted; product 0 is the group of rows without a product.
		$this->row( $mine, 'sale', 101, 1, '300.0000', '2026-09-10 10:00:00', array( 'item_name' => 'Old tee name' ) );
		$this->row(
			$mine,
			'sale',
			102,
			1,
			'200.0000',
			'2026-09-10 10:00:00',
			array(
				'product_id' => 30,
				'item_name'  => 'Poster <b>A2</b>',
			)
		);
		$this->row(
			$mine,
			'sale',
			103,
			1,
			'100.0000',
			'2026-09-10 10:00:00',
			array(
				'product_id' => 0,
				'item_name'  => 'Removed item',
			)
		);
		$this->names->names = array(
			20 => 'Tour tee',
			0  => 'Must never be used',
		);
		wp_set_current_user( $mine );

		$products = $this->data()['currencies'][0]['products'];

		self::assertSame(
			array(
				array( 20, 'Tour tee' ),
				array( 30, 'Poster A2' ),
				array( 0, 'Removed item' ),
			),
			array_map( static fn( array $p ): array => array( $p['product_id'], $p['name'] ), $products )
		);
		self::assertSame( array( array( 20, 30 ) ), $this->names->calls, 'Product 0 is never looked up.' );
	}

	public function test_an_empty_fallback_name_is_unknown_product(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row(
			$mine,
			'sale',
			101,
			1,
			'100.0000',
			'2026-09-10 10:00:00',
			array(
				'product_id' => 0,
				'item_name'  => '',
			)
		);
		wp_set_current_user( $mine );

		$products = $this->data()['currencies'][0]['products'];

		self::assertSame( array( array( 0, 'Unknown product' ) ), array_map( static fn( array $p ): array => array( $p['product_id'], $p['name'] ), $products ) );
		self::assertSame( array(), $this->names->calls, 'Only product 0: no lookup at all.' );
	}

	public function test_variations_are_one_product_under_their_parent(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row(
			$mine,
			'sale',
			101,
			1,
			'100.0000',
			'2026-09-10 10:00:00',
			array(
				'variation_id' => 21,
				'item_name'    => 'Tee - S',
			)
		);
		$this->row(
			$mine,
			'sale',
			102,
			2,
			'200.0000',
			'2026-09-11 10:00:00',
			array(
				'variation_id' => 22,
				'item_name'    => 'Tee - M',
			)
		);
		$this->names->names = array( 20 => 'Tour tee' );
		wp_set_current_user( $mine );

		$products = $this->data()['currencies'][0]['products'];

		self::assertSame(
			array(
				array(
					'product_id' => 20,
					'name'       => 'Tour tee',
					'net_sales'  => '300.0000',
					'units'      => 3,
				),
			),
			$products
		);
		self::assertSame( array( array( 20 ) ), $this->names->calls, 'Only the parent is looked up.' );
	}

	public function test_amounts_are_strings_and_counts_integers_never_floats(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 3, '0.1000', '2026-09-10 10:00:00' );
		$this->row( $mine, 'sale', 102, 3, '0.2000', '2026-09-10 10:00:00' );
		wp_set_current_user( $mine );

		$data = $this->data();
		$dkk  = $data['currencies'][0];

		self::assertSame( '0.3000', $dkk['totals']['net_sales'], 'Exact, not 0.30000000000000004.' );

		$amounts = array( $dkk['totals']['net_sales'], $dkk['totals']['sales'], $dkk['totals']['adjustments'] );
		$amounts = array_merge( $amounts, array_column( $dkk['series'], 'net_sales' ), array_column( $dkk['products'], 'net_sales' ) );

		foreach ( $amounts as $amount ) {
			self::assertIsString( $amount );
			self::assertMatchesRegularExpression( '/^-?\d+\.\d{4}$/', $amount );
		}

		array_walk_recursive(
			$data,
			static function ( mixed $value ): void {
				self::assertNotTrue( is_float( $value ), 'No float anywhere in the response.' );
			}
		);
		self::assertIsInt( $dkk['totals']['units'] );
		self::assertIsInt( $dkk['totals']['orders'] );
	}

	public function test_orders_follow_sale_events_not_the_counted_cycle_state(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		// Order 101: sold in August, cancelled (reversal) in September.
		$this->row( $mine, 'sale', 101, 2, '200.0000', '2026-08-20 10:00:00', array( 'cycle' => 1 ) );
		$this->row( $mine, 'reversal', 101, -2, '-200.0000', '2026-09-05 10:00:00', array( 'cycle' => 1 ) );
		// Order 102: sold, cancelled and reopened in September (two sale rows).
		$this->row( $mine, 'sale', 102, 1, '100.0000', '2026-09-06 10:00:00', array( 'cycle' => 1 ) );
		$this->row( $mine, 'reversal', 102, -1, '-100.0000', '2026-09-07 10:00:00', array( 'cycle' => 1 ) );
		$this->row( $mine, 'sale', 102, 1, '100.0000', '2026-09-08 10:00:00', array( 'cycle' => 2 ) );
		// Order 103: sold in September and cancelled: still an order of September.
		$this->row( $mine, 'sale', 103, 1, '40.0000', '2026-09-09 10:00:00', array( 'cycle' => 1 ) );
		$this->row( $mine, 'reversal', 103, -1, '-40.0000', '2026-09-10 10:00:00', array( 'cycle' => 1 ) );
		wp_set_current_user( $mine );

		$data = $this->data();

		self::assertSame( array( '240.0000', '-340.0000', '-100.0000', -1, 2 ), self::total( $data, 'DKK', array( 'sales', 'adjustments', 'net_sales', 'units', 'orders' ) ) );

		$september = $data['currencies'][0]['series'];
		self::assertSame( 0, $september[4]['orders'], 'September 5: only the reversal.' );
		self::assertSame( 1, $september[7]['orders'], 'September 8: the reopened order.' );
	}

	public function test_refunds_reversals_and_corrections_are_adjustments_on_their_own_dates(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 3, '300.0000', '2026-09-01 10:00:00' );
		$this->row( $mine, 'refund', 101, -1, '-100.0000', '2026-09-02 10:00:00' );
		$this->row( $mine, 'reversal', 101, -2, '-200.0000', '2026-09-03 10:00:00' );
		$this->row( $mine, 'correction', 101, 1, '100.0000', '2026-09-04 10:00:00', array( 'correction_reason' => 'refund_deleted' ) );
		wp_set_current_user( $mine );

		$data = $this->data(
			array(
				'from' => '2026-09-01',
				'to'   => '2026-09-04',
			)
		);

		self::assertSame( array( '300.0000', '-200.0000', '100.0000', 1, 1 ), self::total( $data, 'DKK', array( 'sales', 'adjustments', 'net_sales', 'units', 'orders' ) ) );
		self::assertSame(
			array(
				array( '300.0000', 3, 1 ),
				array( '-100.0000', -1, 0 ),
				array( '-200.0000', -2, 0 ),
				array( '100.0000', 1, 0 ),
			),
			self::series( $data, 'DKK', array( 'net_sales', 'units', 'orders' ) )
		);

		$adjustments_only = $this->data(
			array(
				'from' => '2026-09-02',
				'to'   => '2026-09-04',
			)
		);
		self::assertSame( array( '0.0000', '-200.0000', '-200.0000', 0 ), self::total( $adjustments_only, 'DKK', array( 'sales', 'adjustments', 'net_sales', 'orders' ) ) );
	}

	public function test_only_the_creators_own_sales_and_request_parameters_cannot_select_another_creator(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		// An order with lines of both creators, and the other creator's own order.
		$this->row(
			$theirs,
			'sale',
			101,
			5,
			'999.0000',
			'2026-09-10 10:00:00',
			array(
				'order_item_id' => 1012,
				'product_id'    => 77,
				'item_name'     => 'Their hoodie',
			)
		);
		$this->row(
			$theirs,
			'sale',
			102,
			1,
			'888.0000',
			'2026-09-11 10:00:00',
			array(
				'product_id' => 78,
				'item_name'  => 'Their cap',
				'currency'   => 'EUR',
			)
		);
		wp_set_current_user( $mine );

		$response = $this->request(
			array(
				'creator_id' => (string) $this->creator_id( $theirs ),
				'creator'    => (string) $this->creator_id( $theirs ),
				'user_id'    => (string) $theirs,
				'product_id' => '77',
				'currency'   => 'EUR',
			)
		);
		$data     = (array) $response->get_data();
		$json     = (string) wp_json_encode( $data );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'DKK' ), array_column( $data['currencies'], 'currency' ) );
		self::assertSame( array( '100.0000', 1, 1 ), self::total( $data, 'DKK', array( 'net_sales', 'units', 'orders' ) ) );
		self::assertSame( array( 20 ), array_column( $data['currencies'][0]['products'], 'product_id' ) );

		foreach ( array( 'Their', '999', '888', '"77"', ':77', ':78' ) as $leak ) {
			self::assertStringNotContainsString( $leak, $json );
		}

		wp_set_current_user( $theirs );
		self::assertSame( array( 'DKK', 'EUR' ), array_column( $this->data()['currencies'], 'currency' ) );
	}

	/**
	 * Invalid formats and values.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalid(): array {
		return array(
			'from not a date'     => array( array( 'from' => 'yesterday' ) ),
			'from without zeros'  => array( array( 'from' => '2026-9-01' ) ),
			'from day 30 of Feb'  => array( array( 'from' => '2026-02-30' ) ),
			'from with a time'    => array( array( 'from' => '2026-09-01T00:00:00' ) ),
			'from empty'          => array( array( 'from' => '' ) ),
			'from an array'       => array( array( 'from' => array( '2026-09-01' ) ) ),
			'to not a date'       => array( array( 'to' => '30-09-2026' ) ),
			'to month 13'         => array( array( 'to' => '2026-13-01' ) ),
			'interval year'       => array( array( 'interval' => 'year' ) ),
			'interval upper case' => array( array( 'interval' => 'DAY' ) ),
			'interval empty'      => array( array( 'interval' => '' ) ),
		);
	}

	/**
	 * Formats and intervals are rejected by WordPress' argument validation.
	 *
	 * @param array<string, mixed> $query Query parameters.
	 */
	#[DataProvider( 'invalid' )]
	public function test_invalid_dates_and_intervals_are_rest_invalid_param( array $query ): void {
		wp_set_current_user( $this->creator_user( 'mine', 'Mine', CreatorStatus::Active ) );

		$response = $this->request( $query );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'rest_invalid_param', ( (array) $response->get_data() )['code'] ?? null );
		self::assertSame( array(), $this->names->calls );
	}

	/**
	 * Valid formats outside the limits.
	 *
	 * @return array<string, array{array<string, string>}>
	 */
	public static function invalid_periods(): array {
		return array(
			'from after to'         => array(
				array(
					'from' => '2026-09-02',
					'to'   => '2026-09-01',
				),
			),
			'only from after today' => array( array( 'from' => '2026-10-01' ) ),
			'more than five years'  => array(
				array(
					'from'     => '2020-01-01',
					'to'       => '2025-01-01',
					'interval' => 'month',
				),
			),
			'367 days'              => array(
				array(
					'from' => '2025-01-01',
					'to'   => '2026-01-02',
				),
			),
			'367 days, leap year'   => array(
				array(
					'from' => '2024-01-01',
					'to'   => '2025-01-01',
				),
			),
		);
	}

	/**
	 * Periods outside the limits are `merchboard_invalid_period` without the input.
	 *
	 * @param array<string, string> $query Query parameters.
	 */
	#[DataProvider( 'invalid_periods' )]
	public function test_periods_outside_the_limits_are_merchboard_invalid_period( array $query ): void {
		wp_set_current_user( $this->creator_user( 'mine', 'Mine', CreatorStatus::Active ) );

		$response = $this->request( $query );
		$data     = (array) $response->get_data();

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'merchboard_invalid_period', $data['code'] ?? null );
		self::assertSame( array( 'status' => 400 ), $data['data'] ?? null );

		foreach ( $query as $value ) {
			self::assertStringNotContainsString( $value, (string) wp_json_encode( $data ) );
		}
	}

	public function test_the_largest_allowed_periods(): void {
		wp_set_current_user( $this->creator_user( 'mine', 'Mine', CreatorStatus::Active ) );

		$days = $this->request(
			array(
				'from' => '2025-01-01',
				'to'   => '2026-01-01',
			)
		);
		$five = $this->request(
			array(
				'from'     => '2020-01-01',
				'to'       => '2024-12-31',
				'interval' => 'week',
			)
		);

		self::assertSame( 200, $days->get_status(), '366 days.' );
		self::assertSame( 200, $five->get_status(), 'Five years (262 weeks).' );
	}

	public function test_the_response_has_only_the_allowed_fields_and_no_personal_data(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row(
			$mine,
			'sale',
			4242,
			1,
			'100.0000',
			'2026-09-10 10:00:00',
			array(
				'order_item_id' => 4343,
				'variation_id'  => 21,
			)
		);
		wp_set_current_user( $mine );

		$data  = $this->data();
		$block = $data['currencies'][0];
		$json  = (string) wp_json_encode( $data );

		self::assertSame( array( 'from', 'to', 'interval', 'timezone', 'currencies' ), array_keys( $data ) );
		self::assertSame( array( 'currency', 'totals', 'series', 'products', 'truncated' ), array_keys( $block ) );
		self::assertSame( array( 'net_sales', 'sales', 'adjustments', 'units', 'orders' ), array_keys( $block['totals'] ) );
		self::assertSame( array( 'start', 'end', 'net_sales', 'units', 'orders' ), array_keys( $block['series'][0] ) );
		self::assertSame( array( 'product_id', 'name', 'net_sales', 'units' ), array_keys( $block['products'][0] ) );

		foreach ( array( 'customer', 'email', 'phone', 'address', 'billing', 'shipping', 'city', 'postcode', 'country', 'note', 'ip', 'payment', 'user', 'creator', 'order_id', 'order_item_id', 'variation_id', 'item_name', 'event_key', 'cycle', 'refund_id', '4242', '4343' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $json );
		}
	}

	/**
	 * Stores one ledger row of a user's creator: product 20, DKK.
	 *
	 * @param int                  $user_id     User of the creator.
	 * @param string               $type        Type.
	 * @param int                  $order_id    Order id.
	 * @param int                  $quantity    Signed quantity.
	 * @param string               $amount      Signed net amount.
	 * @param string               $occurred_at UTC time.
	 * @param array<string, mixed> $columns     Other columns.
	 */
	private function row( int $user_id, string $type, int $order_id, int $quantity, string $amount, string $occurred_at, array $columns = array() ): void {
		$inserted = $this->wpdb->insert(
			$this->table( Tables::SALES ),
			array_merge(
				array(
					'event_key'         => 'test:' . ( ++$this->key ),
					'creator_id'        => $this->creator_id( $user_id ),
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

	/**
	 * Dispatches GET /me/sales as the current user.
	 *
	 * @param array<string, mixed> $query Query parameters.
	 */
	private function request( array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( $query );

		return rest_do_request( $request );
	}

	/**
	 * The body of a successful GET /me/sales.
	 *
	 * @param array<string, mixed> $query Query parameters.
	 * @return array<string, mixed>
	 */
	private function data( array $query = array() ): array {
		$response = $this->request( $query );
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (array) $response->get_data();
	}

	/**
	 * Selected totals of a currency.
	 *
	 * @param array<string, mixed> $data     Response body.
	 * @param string               $currency Currency.
	 * @param string[]             $keys     Keys.
	 * @return array<mixed>
	 */
	private static function total( array $data, string $currency, array $keys ): array {
		foreach ( $data['currencies'] as $block ) {
			if ( $currency === $block['currency'] ) {
				return array_map( static fn( string $key ): mixed => $block['totals'][ $key ], $keys );
			}
		}

		self::fail( 'No ' . $currency . ' block.' );
	}

	/**
	 * Selected fields of every series entry of a currency.
	 *
	 * @param array<string, mixed> $data     Response body.
	 * @param string               $currency Currency.
	 * @param string[]             $keys     Keys.
	 * @return array<array<mixed>>
	 */
	private static function series( array $data, string $currency, array $keys ): array {
		foreach ( $data['currencies'] as $block ) {
			if ( $currency === $block['currency'] ) {
				return array_map( static fn( array $entry ): array => array_map( static fn( string $key ): mixed => $entry[ $key ], $keys ), $block['series'] );
			}
		}

		self::fail( 'No ' . $currency . ' block.' );
	}

	/**
	 * A user with a linked creator of the given status.
	 *
	 * @param string        $login  Login.
	 * @param string        $name   Creator name.
	 * @param CreatorStatus $status Status.
	 */
	private function creator_user( string $login, string $name, CreatorStatus $status ): int {
		$user = $this->create_user( $login );
		wp_set_current_user( 1 );
		$this->creators->create( $name, $status, $user );

		return $user;
	}

	/**
	 * The creator id linked to a user.
	 *
	 * @param int $user_id User id.
	 */
	private function creator_id( int $user_id ): int {
		$creator = $this->service( CreatorRepository::class )->find_by_user_id( $user_id );
		self::assertNotNull( $creator );

		return $creator->id;
	}

	/**
	 * A user id for a denied kind (0 = logged out).
	 *
	 * @param string $kind Kind.
	 */
	private function user_of_kind( string $kind ): int {
		switch ( $kind ) {
			case 'subscriber':
				return $this->create_user( 'plain_subscriber' );
			case 'admin':
				return 1;
			case 'inactive':
				return $this->creator_user( 'inactive_creator', 'Inactive', CreatorStatus::Inactive );
			case 'no_record':
				$user = $this->create_user( 'role_only' );
				( new WP_User( $user ) )->add_role( Capabilities::CREATOR_ROLE );
				return $user;
			case 'no_capability':
				$user = $this->creator_user( 'no_capability', 'No Cap', CreatorStatus::Active );
				( new WP_User( $user ) )->remove_role( Capabilities::CREATOR_ROLE );
				return $user;
			default:
				return 0;
		}
	}
}
