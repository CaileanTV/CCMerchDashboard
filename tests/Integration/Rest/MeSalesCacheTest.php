<?php
/**
 * GET /me/sales through the object cache (ADR-040 pkt. 10) against the real
 * WordPress REST server, object cache and database. Without a persistent
 * object cache WordPress keeps entries for the request (here: the test), which
 * is enough to observe hits, misses, keys and invalidation. Ledger queries are
 * counted with the `query` filter.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Rest;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Dashboard\CachedSalesReports;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReport;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReports;
use MerchBoard\Modules\WooCommerce\Rest\MeSalesController;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\FakeSalesProductNames;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use WP_REST_Request;

final class MeSalesCacheTest extends IntegrationTestCase {

	private const ROUTE = '/merchboard/v1/me/sales';

	private const NOW = '2026-09-30 10:00:00';

	private CreatorService $creators;

	private FakeSalesProductNames $names;

	private SalesCache $cache;

	private SalesReport $uncached;

	/**
	 * Called inside the uncached build, after the ledger was read (null: nothing).
	 *
	 * @var (callable(int): void)|null
	 */
	private $during_build = null;

	private int $key = 0;

	private string $timezone_string = '';

	private string $gmt_offset = '';

	/**
	 * Ledger queries seen by the `query` filter.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * Registers the route on `rest_api_init`.
	 *
	 * @var callable(): void
	 */
	private $register;

	/**
	 * Records ledger queries.
	 *
	 * @var callable(string): string
	 */
	private $record;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->timezone_string = (string) get_option( 'timezone_string' );
		$this->gmt_offset      = (string) get_option( 'gmt_offset' );
		update_option( 'timezone_string', 'Europe/Copenhagen' );
		update_option( 'gmt_offset', '' );

		$this->creators = $this->service( CreatorService::class );
		$this->names    = new FakeSalesProductNames();
		$this->cache    = new SalesCache();
		$this->uncached = new SalesReport( new LedgerSalesStatistics( $this->wpdb ), $this->names );
		$after          = function ( int $creator_id ): void {
			if ( is_callable( $this->during_build ) ) {
				( $this->during_build )( $creator_id );
			}
		};
		$inner          = new class( $this->uncached, $after ) implements SalesReports {

			/**
			 * Constructor.
			 *
			 * @phpstan-param Closure(int): void $after
			 *
			 * @param SalesReports $reports Real reports.
			 * @param Closure      $after   Called after the ledger was read.
			 */
			public function __construct( private SalesReports $reports, private Closure $after ) {
			}

			/**
			 * The real report, then the hook.
			 *
			 * @param int        $creator_id Creator.
			 * @param SalesQuery $query      Query.
			 * @return array<string, mixed>
			 */
			public function build( int $creator_id, SalesQuery $query ): array {
				$report = $this->reports->build( $creator_id, $query );
				( $this->after )( $creator_id );

				return $report;
			}
		};
		$controller     = new MeSalesController(
			$this->service( CreatorContext::class ),
			new CachedSalesReports( $inner, $this->cache ),
			$this->service( Logger::class ),
			static fn(): DateTimeImmutable => new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) )
		);
		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		$sales          = $this->table( Tables::SALES );
		$this->record   = function ( string $query ) use ( $sales ): string {
			if ( str_contains( $query, $sales ) ) {
				$this->queries[] = $query;
			}

			return $query;
		};
		add_action( 'rest_api_init', $this->register );
		add_filter( 'query', $this->record );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	protected function tearDown(): void {
		remove_action( 'rest_api_init', $this->register );
		remove_filter( 'query', $this->record );
		update_option( 'timezone_string', $this->timezone_string );
		update_option( 'gmt_offset', $this->gmt_offset );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		parent::tearDown();
	}

	public function test_a_miss_reads_the_ledger_and_stores_the_report_and_an_identical_request_hits_without_a_ledger_query(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 2, '200.0000', '2026-09-10 10:00:00' );
		wp_set_current_user( $mine );

		$miss = $this->sales( array(), 3 );
		$key  = $this->key_of( $mine, array() );

		self::assertSame( SalesCache::INITIAL_GENERATION, $this->cache->generation( $this->creator_id( $mine ) ) );
		self::assertSame( $miss, wp_cache_get( $key, SalesCache::GROUP ), 'The report is stored under the creator\'s key.' );
		self::assertMatchesRegularExpression( '/^report:\d+:1:[0-9a-f]{32}$/', $key );

		$hit = $this->sales( array(), 0 );

		self::assertSame( $miss, $hit );
		self::assertSame( '200.0000', $hit['currencies'][0]['totals']['net_sales'] );
	}

	/**
	 * The Overview's key figures (phase 20, step 5) are `GET /me/sales`
	 * without parameters: the same entry as the explicit default period (the
	 * Sales section's first view), so whichever comes first fills the cache
	 * for the other. No separate KPI query and no `/me/overview`.
	 */
	public function test_the_overview_request_shares_the_entry_of_the_explicit_default_period(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 2, '200.0000', '2026-09-10 10:00:00' );
		$this->row( $mine, 'refund', 101, -1, '-50.0000', '2026-09-12 10:00:00' );
		$this->row( $mine, 'sale', 102, 1, '10.0000', '2026-09-11 10:00:00', array( 'currency' => 'EUR' ) );
		wp_set_current_user( $mine );
		$explicit = array(
			'from'     => '2026-09-01',
			'to'       => '2026-09-30',
			'interval' => 'day',
		);

		$sales    = $this->sales( $explicit, 3 );
		$overview = $this->sales( array(), 0 );

		self::assertSame( $sales, $overview );
		self::assertSame( $this->key_of( $mine, array() ), $this->key_of( $mine, $explicit ) );
		self::assertSame( array( '2026-09-01', '2026-09-30', 'day' ), array( $overview['from'], $overview['to'], $overview['interval'] ) );
		self::assertSame(
			array(
				array( 'DKK', '150.0000', '200.0000', '-50.0000', 1, 1 ),
				array( 'EUR', '10.0000', '10.0000', '0.0000', 1, 1 ),
			),
			array_map(
				static fn( array $block ): array => array( $block['currency'], ...array_values( $block['totals'] ) ),
				$overview['currencies']
			),
			'One set of key figures per currency, never summed.'
		);

		$this->cache->invalidate( array( $this->creator_id( $mine ) ) );
		$overview = $this->sales( array(), 3 );
		self::assertSame( $overview, $this->sales( $explicit, 0 ), 'And the other way round.' );

		self::assertArrayNotHasKey( '/merchboard/v1/me/overview', rest_get_server()->get_routes( 'merchboard/v1' ) );
	}

	public function test_a_cached_response_is_identical_to_the_uncached_one(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 2, '200.0000', '2026-09-10 10:00:00' );
		$this->row( $mine, 'refund', 101, -1, '-100.0000', '2026-09-12 10:00:00' );
		$this->row(
			$mine,
			'sale',
			102,
			1,
			'10.5000',
			'2026-09-11 10:00:00',
			array(
				'currency'   => 'EUR',
				'product_id' => 0,
				'item_name'  => 'Gone',
			)
		);
		$this->names->names = array( 20 => 'Tour tee' );
		wp_set_current_user( $mine );
		$query = array(
			'from'     => '2026-09-01',
			'to'       => '2026-09-30',
			'interval' => 'week',
		);

		$uncached = $this->uncached->build( $this->creator_id( $mine ), $this->resolve( $query ) );
		$miss     = $this->sales( $query, 3 );
		$hit      = $this->sales( $query, 0 );

		self::assertSame( $uncached, $miss );
		self::assertSame( $uncached, $hit );
		self::assertSame( wp_json_encode( $uncached ), wp_json_encode( $hit ) );
	}

	public function test_creators_never_share_an_entry(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		$this->row( $theirs, 'sale', 102, 5, '999.0000', '2026-09-10 10:00:00' );

		wp_set_current_user( $mine );
		self::assertSame( '100.0000', $this->sales( array(), 3 )['currencies'][0]['totals']['net_sales'] );

		wp_set_current_user( $theirs );
		$theirs_data = $this->sales( array( 'creator_id' => (string) $this->creator_id( $mine ) ), 3 );
		self::assertSame( '999.0000', $theirs_data['currencies'][0]['totals']['net_sales'], 'Same period and generation `1`, but a miss: the key has the creator.' );
		self::assertNotSame( $this->key_of( $mine, array() ), $this->key_of( $theirs, array() ) );

		wp_set_current_user( $mine );
		self::assertSame( '100.0000', $this->sales( array(), 0 )['currencies'][0]['totals']['net_sales'] );

		wp_set_current_user( $this->creator_user( 'empty', 'Empty', CreatorStatus::Active ) );
		self::assertSame( array(), $this->sales( array(), 1 )['currencies'], 'No data: one totals query, then cached too.' );
	}

	public function test_periods_and_intervals_have_their_own_entries(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		wp_set_current_user( $mine );
		$queries = array(
			array(),
			array(
				'from' => '2026-09-01',
				'to'   => '2026-09-29',
			),
			array(
				'from' => '2026-09-02',
				'to'   => '2026-09-30',
			),
			array( 'interval' => 'week' ),
			array( 'interval' => 'month' ),
		);

		foreach ( $queries as $query ) {
			$this->sales( $query, 3 );
		}

		foreach ( $queries as $query ) {
			$this->sales( $query, 0 );
		}

		self::assertSame( 'day', $this->sales( array( 'interval' => 'day' ), 0 )['interval'], 'The explicit default is the same query as no parameter.' );
		self::assertCount( 5, array_unique( array_map( fn( array $query ): string => $this->key_of( $mine, $query ), $queries ) ) );
	}

	public function test_the_time_zone_is_part_of_the_entry(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		// 21:30 UTC: September 30 in UTC and New York, 23:30 on September 30 in Copenhagen.
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-30 21:30:00' );
		wp_set_current_user( $mine );
		$query = array(
			'from' => '2026-10-01',
			'to'   => '2026-10-01',
		);

		$copenhagen = $this->sales( $query, 1 );
		update_option( 'timezone_string', 'UTC' );
		$utc = $this->sales( $query, 1 );

		self::assertSame( array( 'Europe/Copenhagen', array() ), array( $copenhagen['timezone'], $copenhagen['currencies'] ) );
		self::assertSame( 'UTC', $utc['timezone'] );
		self::assertSame( array(), $utc['currencies'], 'October 1 in UTC has no sale either; a separate entry.' );

		update_option( 'timezone_string', 'America/New_York' );
		$new_york = $this->sales(
			array(
				'from' => '2026-09-30',
				'to'   => '2026-09-30',
			),
			3
		);
		self::assertSame( 'America/New_York', $new_york['timezone'] );
		self::assertSame( '100.0000', $new_york['currencies'][0]['totals']['net_sales'] );

		update_option( 'timezone_string', 'Europe/Copenhagen' );
		self::assertSame( $copenhagen, $this->sales( $query, 0 ), 'Back in Copenhagen: its own entry is still there.' );
	}

	public function test_all_currencies_are_one_entry(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		$this->row( $mine, 'sale', 102, 1, '20.0000', '2026-09-10 10:00:00', array( 'currency' => 'EUR' ) );
		$this->row( $mine, 'sale', 103, 1, '300.0000', '2026-09-10 10:00:00', array( 'currency' => 'SEK' ) );
		wp_set_current_user( $mine );

		$this->sales( array(), 3 );
		$hit = $this->sales( array(), 0 );

		self::assertSame( array( 'DKK', 'EUR', 'SEK' ), array_column( $hit['currencies'], 'currency' ) );
		self::assertSame( $hit, wp_cache_get( $this->key_of( $mine, array() ), SalesCache::GROUP ) );
	}

	public function test_a_ledger_write_invalidates_only_its_creator(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		$this->row( $theirs, 'sale', 102, 1, '50.0000', '2026-09-10 10:00:00' );
		wp_set_current_user( $mine );
		$this->sales( array(), 3 );
		wp_set_current_user( $theirs );
		$this->sales( array(), 3 );

		// A new order of mine through the real synchronization (repository with the default SalesCache).
		$reader              = new FakeOrderReader();
		$reader->orders[201] = array(
			'status'    => 'completed',
			'currency'  => 'DKK',
			'lines'     => array( new OrderLineSnapshot( 2011, 20, 0, 'Line', 1, SalesAmount::parse( '25' ), $this->creator_id( $mine ) ) ),
			'refunds'   => array(),
			'date_paid' => '2026-09-20 10:00:00',
		);
		( new SalesSynchronizer(
			new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) ),
			new SalesPlanner(),
			$reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			static fn(): DateTimeImmutable => new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) )
		) )->sync( 201 );

		self::assertNotSame( SalesCache::INITIAL_GENERATION, $this->cache->generation( $this->creator_id( $mine ) ) );
		self::assertSame( SalesCache::INITIAL_GENERATION, $this->cache->generation( $this->creator_id( $theirs ) ) );

		wp_set_current_user( $mine );
		$this->queries = array();
		self::assertSame( array( '125.0000', 2 ), self::totals( $this->sales( array(), 3 ), array( 'net_sales', 'orders' ) ), 'A miss with the new sale.' );
		self::assertSame( array( '125.0000', 2 ), self::totals( $this->sales( array(), 0 ), array( 'net_sales', 'orders' ) ), 'Cached again under the new generation.' );

		wp_set_current_user( $theirs );
		self::assertSame( array( '50.0000', 1 ), self::totals( $this->sales( array(), 0 ), array( 'net_sales', 'orders' ) ), 'The other creator is still cached.' );
	}

	public function test_product_changes_without_a_ledger_write_do_not_invalidate(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		$this->names->names = array( 20 => 'Tour tee' );
		wp_set_current_user( $mine );
		$generation = $this->cache->generation( $this->creator_id( $mine ) );

		self::assertSame( 'Tour tee', $this->sales( array(), 3 )['currencies'][0]['products'][0]['name'] );

		// The product is renamed in WooCommerce: no ledger write, so the entry stays (at most 15 minutes).
		$this->names->names = array( 20 => 'Renamed tee' );

		self::assertSame( 'Tour tee', $this->sales( array(), 0 )['currencies'][0]['products'][0]['name'] );
		self::assertCount( 1, $this->names->calls, 'No new lookup on a hit.' );
		self::assertSame( $generation, $this->cache->generation( $this->creator_id( $mine ) ) );
	}

	public function test_a_write_during_a_miss_is_not_stored_under_the_old_generation(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		wp_set_current_user( $mine );
		$old_key = $this->key_of( $mine, array() );

		// A concurrent write commits after this request read the ledger.
		$this->during_build = function ( int $creator_id ): void {
			$this->during_build = null;
			$this->row_of_creator( $creator_id, 'sale', 102, 1, '50.0000', '2026-09-11 10:00:00' );
			$this->cache->invalidate( array( $creator_id ) );
		};

		self::assertSame( '100.0000', $this->sales( array(), 3 )['currencies'][0]['totals']['net_sales'], 'This request answers with what it read.' );
		self::assertFalse( wp_cache_get( $old_key, SalesCache::GROUP ), 'Nothing stored under the old generation.' );
		self::assertFalse( wp_cache_get( $this->key_of( $mine, array() ), SalesCache::GROUP ), 'Nor under the new one.' );

		self::assertSame( '150.0000', $this->sales( array(), 3 )['currencies'][0]['totals']['net_sales'], 'The next request reads the ledger again.' );
		self::assertSame( '150.0000', $this->sales( array(), 0 )['currencies'][0]['totals']['net_sales'] );
	}

	public function test_an_entry_that_does_not_match_its_query_is_a_miss(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->row( $mine, 'sale', 101, 1, '100.0000', '2026-09-10 10:00:00' );
		wp_set_current_user( $mine );
		$key = $this->key_of( $mine, array() );

		foreach ( array( 'garbage', array( 'from' => '2020-01-01' ), array() ) as $bad ) {
			wp_cache_set( $key, $bad, SalesCache::GROUP );
			self::assertSame( '100.0000', $this->sales( array(), 3 )['currencies'][0]['totals']['net_sales'] );
		}

		$this->sales( array(), 0 );
	}

	/**
	 * Dispatches GET /me/sales and checks the number of ledger queries.
	 *
	 * @param array<string, string> $query   Query parameters.
	 * @param int                   $queries Expected ledger queries (0 = a cache hit).
	 * @return array<string, mixed>
	 */
	private function sales( array $query, int $queries ): array {
		$this->queries = array();
		$request       = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( $query );
		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		self::assertCount( $queries, $this->queries, implode( "\n", $this->queries ) );

		return (array) $response->get_data();
	}

	/**
	 * The resolved query of request parameters, at the fixed time and the current time zone.
	 *
	 * @param array<string, string> $query Query parameters.
	 */
	private function resolve( array $query ): SalesQuery {
		return SalesQuery::resolve(
			$query['from'] ?? null,
			$query['to'] ?? null,
			SalesInterval::from( $query['interval'] ?? 'day' ),
			wp_timezone(),
			new DateTimeImmutable( self::NOW, new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * The current report key of a user's creator for request parameters.
	 *
	 * @param int                   $user_id User.
	 * @param array<string, string> $query   Query parameters.
	 */
	private function key_of( int $user_id, array $query ): string {
		$creator_id = $this->creator_id( $user_id );

		return $this->cache->report_key( $creator_id, $this->cache->generation( $creator_id ), $this->resolve( $query ) );
	}

	/**
	 * Selected totals of the first currency.
	 *
	 * @param array<string, mixed> $data Response body.
	 * @param string[]             $keys Keys.
	 * @return array<mixed>
	 */
	private static function totals( array $data, array $keys ): array {
		return array_map( static fn( string $key ): mixed => $data['currencies'][0]['totals'][ $key ], $keys );
	}

	/**
	 * Stores one ledger row of a user's creator directly (no write path, so no
	 * invalidation): product 20, DKK.
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
		$this->row_of_creator( $this->creator_id( $user_id ), $type, $order_id, $quantity, $amount, $occurred_at, $columns );
	}

	/**
	 * Stores one ledger row of a creator directly.
	 *
	 * @param int                  $creator_id  Creator.
	 * @param string               $type        Type.
	 * @param int                  $order_id    Order id.
	 * @param int                  $quantity    Signed quantity.
	 * @param string               $amount      Signed net amount.
	 * @param string               $occurred_at UTC time.
	 * @param array<string, mixed> $columns     Other columns.
	 */
	private function row_of_creator( int $creator_id, string $type, int $order_id, int $quantity, string $amount, string $occurred_at, array $columns = array() ): void {
		remove_filter( 'query', $this->record );
		$inserted = $this->wpdb->insert(
			$this->table( Tables::SALES ),
			array_merge(
				array(
					'event_key'         => 'test:' . ( ++$this->key ),
					'creator_id'        => $creator_id,
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
		add_filter( 'query', $this->record );

		self::assertSame( 1, $inserted, $this->wpdb->last_error );
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
}
