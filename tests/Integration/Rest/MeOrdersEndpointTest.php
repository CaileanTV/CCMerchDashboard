<?php
/**
 * GET /me/orders against the real WordPress REST server, users, roles and
 * database. WooCommerce is not loaded here: the ledger is filled by the real
 * synchronization with a test reader (ADR-038 Q4), and the controller is
 * registered by the test with FakeOrderLineEnricher (the WooCommerce
 * enrichment is unit-tested in WooCommerceOrderLineEnricherTest).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Rest;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Rest\MeOrdersController;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLines;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeOrderLineEnricher;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class MeOrdersEndpointTest extends IntegrationTestCase {

	private const ROUTE = '/merchboard/v1/me/orders';

	/**
	 * The only keys an order line may have (ADR-039 P3).
	 */
	private const KEYS = array( 'line_id', 'order_number', 'date', 'status', 'status_label', 'product_name', 'quantity', 'refunded', 'counted', 'profit', 'order_profit', 'order_lines' );

	private CreatorService $creators;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private FakeOrderLineEnricher $enricher;

	/**
	 * Registers the route on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->creators = $this->service( CreatorService::class );
		$this->reader   = new FakeOrderReader();
		$this->sync     = new SalesSynchronizer(
			new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) ),
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-30 12:00:00', new DateTimeZone( 'UTC' ) )
		);
		$this->enricher = new FakeOrderLineEnricher();
		$controller     = new MeOrdersController( $this->service( CreatorContext::class ), new SalesOrderLines( $this->wpdb ), $this->enricher, $this->service( Logger::class ), new LedgerCreatorProfits( $this->wpdb ) );
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

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		parent::tearDown();
	}

	public function test_the_route_is_registered_with_a_permission_callback_and_pagination_args(): void {
		$routes = rest_get_server()->get_routes( 'merchboard/v1' );

		self::assertArrayHasKey( self::ROUTE, $routes );
		self::assertCount( 1, $routes[ self::ROUTE ] );

		$endpoint = $routes[ self::ROUTE ][0];
		self::assertSame( array( 'GET' => true ), $endpoint['methods'] );
		self::assertIsCallable( $endpoint['permission_callback'] ?? null );
		self::assertNotSame( '__return_true', $endpoint['permission_callback'] );
		self::assertSame( array( 'page', 'per_page' ), array_keys( $endpoint['args'] ) );
		self::assertArrayNotHasKey( '/merchboard/v1/me/orders/(?P<id>[\d]+)', $routes, 'No order detail route.' );
	}

	public function test_without_woocommerce_the_plugin_registers_no_route_and_keeps_the_ledger(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->place( 101, 'completed', array( $this->line( 1011, 2, $mine ) ) );
		self::assertNotNull( $this->register );
		remove_action( 'rest_api_init', $this->register );
		$this->register            = null;
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		wp_set_current_user( $mine );

		$response = $this->request();

		self::assertArrayNotHasKey( self::ROUTE, rest_get_server()->get_routes( 'merchboard/v1' ) );
		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'rest_no_route', ( (array) $response->get_data() )['code'] ?? null );
		self::assertSame( 1, $this->count_rows( Tables::SALES ), 'The ledger is untouched.' );
		self::assertSame( 1, $this->count_rows( Tables::SALES_CYCLES ) );
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
	 * Every user without creator access is denied and sees no order line.
	 *
	 * @param string $kind   User kind.
	 * @param int    $status Expected status.
	 * @param string $code   Expected error code.
	 */
	#[DataProvider( 'denied' )]
	public function test_users_without_creator_access_get_no_orders( string $kind, int $status, string $code ): void {
		$bystander = $this->creator_user( 'bystander', 'Bystander', CreatorStatus::Active );
		$this->place( 101, 'completed', array( $this->line( 1011, 2, $bystander, 'Bystander tee' ) ) );
		wp_set_current_user( $this->user_of_kind( $kind ) );

		$response = $this->request();

		self::assertSame( $status, $response->get_status() );
		self::assertSame( $code, ( (array) $response->get_data() )['code'] ?? null );
		self::assertStringNotContainsString( 'Bystander tee', (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( array(), $this->enricher->calls, 'Nothing was enriched.' );
	}

	public function test_an_active_creator_gets_only_their_own_lines_with_the_allowed_fields(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		// A mixed order, and an order of the other creator alone.
		$this->place( 101, 'completed', array( $this->line( 1011, 2, $mine, 'Tour tee' ), $this->line( 1012, 5, $theirs, 'Their hoodie' ) ) );
		$this->place( 102, 'completed', array( $this->line( 1021, 1, $theirs, 'Their cap' ) ) );
		wp_set_current_user( $mine );

		$response = $this->request();
		$data     = (array) $response->get_data();

		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array(
				array(
					'line_id'      => 1011,
					'order_number' => '101',
					'date'         => '2026-09-01T10:05:00Z',
					'status'       => 'processing',
					'status_label' => 'Processing',
					'product_name' => 'Tour tee',
					'quantity'     => 2,
					'refunded'     => false,
					'counted'      => true,
					// No pricing snapshot and no tax: unknown, never 0 (ADR-050).
					'profit'       => array(
						'currency' => 'DKK',
						'status'   => 'unknown',
						'amount'   => null,
					),
					'order_profit' => array(
						'currency' => 'DKK',
						'status'   => 'unknown',
						'amount'   => null,
					),
					'order_lines'  => 1,
				),
			),
			$data
		);
		self::assertSame( array( '1', '1' ), self::totals( $response ) );
		self::assertSame( array( array( 1011 ) ), $this->enricher->calls, 'Only the creator\'s line reaches the enrichment.' );
		self::assertStringNotContainsString( 'Their', (string) wp_json_encode( $data ) );
	}

	public function test_each_line_has_its_profit_and_its_orders_total_with_signs(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		$me     = $this->creator_id( $mine );
		// Order 301: two lines of mine (100.00 incl. VAT each at 20 %) and one of theirs.
		$this->snapshot( 3011, 301, $me, 2000 );
		$this->snapshot( 3012, 301, $me, 2000 );
		$this->place(
			301,
			'completed',
			array(
				new OrderLineSnapshot( 3011, 20, 0, 'Tee', 1, SalesAmount::parse( '80' ), $me, SalesAmount::parse( '20' ) ),
				new OrderLineSnapshot( 3012, 20, 0, 'Cap', 1, SalesAmount::parse( '80' ), $me, SalesAmount::parse( '20' ) ),
				new OrderLineSnapshot( 3013, 20, 0, 'Their hoodie', 1, SalesAmount::parse( '800' ), $this->creator_id( $theirs ), SalesAmount::parse( '200' ) ),
			)
		);
		// Order 302: one line of mine without a snapshot (unknown), then cancelled.
		$this->place( 302, 'completed', array( $this->line( 3021, 1, $mine, 'Old tee' ) ) );
		$this->place( 302, 'cancelled', array( $this->line( 3021, 1, $mine, 'Old tee' ) ) );
		wp_set_current_user( $mine );

		$data = array_column( (array) $this->request()->get_data(), null, 'line_id' );

		$complete = static fn( string $amount ): array => array(
			'currency' => 'DKK',
			'status'   => 'complete',
			'amount'   => $amount,
		);
		self::assertSame( array( 3021, 3012, 3011 ), array_map( 'intval', array_keys( $data ) ), 'Newest first, then by line.' );
		self::assertSame( $complete( '20.0000' ), $data[3011]['profit'] );
		self::assertSame( $complete( '40.0000' ), $data[3011]['order_profit'], 'Both of my lines, never the other creator\'s.' );
		self::assertSame( 2, $data[3011]['order_lines'] );
		self::assertSame( $data[3011]['order_profit'], $data[3012]['order_profit'] );
		$unknown = array(
			'currency' => 'DKK',
			'status'   => 'unknown',
			'amount'   => null,
		);
		self::assertSame( $unknown, $data[3021]['profit'], 'Sale and reversal without a snapshot: unknown, not 0.' );
		self::assertSame( 1, $data[3021]['order_lines'] );
	}

	public function test_request_parameters_cannot_select_another_creator(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		$this->place( 101, 'completed', array( $this->line( 1011, 2, $mine ) ) );
		$this->place( 102, 'completed', array( $this->line( 1021, 1, $theirs, 'Their cap' ) ) );
		wp_set_current_user( $mine );

		$response = $this->request(
			array(
				'creator_id' => (string) $this->creator_id( $theirs ),
				'creator'    => (string) $this->creator_id( $theirs ),
				'user_id'    => (string) $theirs,
				'order_id'   => '102',
				'customer'   => '1',
				'status'     => 'completed',
				'search'     => 'cap',
				'orderby'    => 'id',
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 1011 ), array_column( (array) $response->get_data(), 'line_id' ) );
		self::assertSame( array( '1', '1' ), self::totals( $response ) );
		self::assertStringNotContainsString( 'Their cap', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_pages_follow_the_read_model_and_never_leak_another_creators_lines(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );

		foreach ( array( 1, 2, 3 ) as $day ) {
			$this->place( 200 + $day, 'completed', array( $this->line( 2000 + $day, 1, $mine ), $this->line( 2100 + $day, 1, $theirs ) ), '2026-06-0' . $day . ' 10:00:00' );
		}

		wp_set_current_user( $mine );
		$seen = array();

		foreach ( array( '1', '2', '3', '4' ) as $page ) {
			$response = $this->request(
				array(
					'page'     => $page,
					'per_page' => '1',
				)
			);
			self::assertSame( 200, $response->get_status() );
			self::assertSame( array( '3', '3' ), self::totals( $response ) );
			$seen = array_merge( $seen, array_column( (array) $response->get_data(), 'line_id' ) );
		}

		self::assertSame( array( 2003, 2002, 2001 ), $seen, 'Newest first.' );
		self::assertSame( array( array( 2003 ), array( 2002 ), array( 2001 ) ), $this->enricher->calls, 'One enrichment per page with data; none after the last.' );
	}

	public function test_a_page_after_the_last_and_an_empty_result(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->place( 101, 'completed', array( $this->line( 1011, 2, $mine ) ) );
		wp_set_current_user( $mine );

		$after = $this->request(
			array(
				'page'     => '2',
				'per_page' => '1',
			)
		);
		self::assertSame( 200, $after->get_status() );
		self::assertSame( '[]', wp_json_encode( $after->get_data() ) );
		self::assertSame( array( '1', '1' ), self::totals( $after ) );

		wp_set_current_user( $this->creator_user( 'empty', 'Empty', CreatorStatus::Active ) );
		$empty = $this->request();
		self::assertSame( 200, $empty->get_status() );
		self::assertSame( '[]', wp_json_encode( $empty->get_data() ) );
		self::assertSame( array( '0', '0' ), self::totals( $empty ) );
		self::assertSame( array(), $this->enricher->calls );
	}

	/**
	 * Invalid page and page sizes.
	 *
	 * @return array<string, array{array<string, string>}>
	 */
	public static function invalid(): array {
		return array(
			'page 0'       => array( array( 'page' => '0' ) ),
			'page text'    => array( array( 'page' => 'abc' ) ),
			'per_page 0'   => array( array( 'per_page' => '0' ) ),
			'per_page 101' => array( array( 'per_page' => '101' ) ),
		);
	}

	/**
	 * Invalid values are rejected by WordPress' argument validation.
	 *
	 * @param array<string, string> $query Query parameters.
	 */
	#[DataProvider( 'invalid' )]
	public function test_invalid_pagination_is_a_400( array $query ): void {
		wp_set_current_user( $this->creator_user( 'mine', 'Mine', CreatorStatus::Active ) );

		$response = $this->request( $query );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'rest_invalid_param', ( (array) $response->get_data() )['code'] ?? null );
	}

	public function test_lines_of_deleted_and_not_counted_orders_are_kept(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->place( 101, 'completed', array( $this->line( 1011, 2, $mine ) ), '2026-06-01 10:00:00' );
		$this->place( 102, 'completed', array( $this->line( 1021, 2, $mine ) ), '2026-06-02 10:00:00' );
		// Order 101 is deleted permanently: the ledger closes it (reversal + close).
		$this->sync->order_deleting( 101 );
		unset( $this->reader->orders[101] );
		$this->enricher->deleted = array( 101 );
		wp_set_current_user( $mine );

		$data = (array) $this->request()->get_data();

		self::assertSame( array( 1021, 1011 ), array_column( $data, 'line_id' ) );
		self::assertSame( array( 'processing', 'deleted' ), array_column( $data, 'status' ) );
		self::assertSame( array( 2, 0 ), array_column( $data, 'quantity' ) );
		self::assertSame( array( true, false ), array_column( $data, 'counted' ) );
	}

	public function test_the_response_has_only_the_allowed_fields(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->place( 101, 'completed', array( $this->line( 1011, 2, $mine ) ) );
		wp_set_current_user( $mine );

		$data = (array) $this->request()->get_data();

		self::assertSame( self::KEYS, array_keys( (array) $data[0] ) );

		// The only amounts are the creator's profit objects (ADR-050); no sales amount.
		$line = (array) $data[0];
		foreach ( array( 'profit', 'order_profit' ) as $field ) {
			self::assertSame( array( 'currency', 'status', 'amount' ), array_keys( (array) $line[ $field ] ) );
			unset( $line[ $field ] );
		}
		$json = (string) wp_json_encode( array( $line ) );
		self::assertStringNotContainsString( 'net_amount', (string) wp_json_encode( $data ) );
		self::assertStringNotContainsString( 'total', (string) wp_json_encode( $data ) );

		foreach ( array( 'creator', 'product_id', 'variation_id', 'order_id', 'customer', 'email', 'phone', 'address', 'city', 'postcode', 'country', 'note', 'ip', 'payment', 'net_amount', 'amount', 'total', 'currency', 'DKK', 'event_key', 'cycle' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $json );
		}
	}

	/**
	 * Synchronizes an order of the test reader.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 *
	 * @param int                 $order_id  Order id.
	 * @param string              $status    Status.
	 * @param OrderLineSnapshot[] $lines     Lines.
	 * @param string|null         $date_paid Payment date (UTC).
	 */
	private function place( int $order_id, string $status, array $lines, ?string $date_paid = null ): void {
		$order = array(
			'status'   => $status,
			'currency' => 'DKK',
			'lines'    => $lines,
			'refunds'  => array(),
		);

		if ( null !== $date_paid ) {
			$order['date_paid'] = $date_paid;
		}

		$this->reader->orders[ $order_id ] = $order;
		$this->sync->sync( $order_id );
	}

	/**
	 * A line of product 20 for the creator of a user.
	 *
	 * @param int    $item     Item id.
	 * @param int    $quantity Quantity.
	 * @param int    $user_id  User of the creator.
	 * @param string $name     Item name.
	 */
	private function line( int $item, int $quantity, int $user_id, string $name = '' ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, 20, 0, '' === $name ? 'Line ' . $item : $name, $quantity, SalesAmount::parse( (string) ( 50 * $quantity ) ), $this->creator_id( $user_id ) );
	}

	/**
	 * A share snapshot of an order line (product 20, DKK, 2 decimals, not VAT registered).
	 *
	 * @param int $item       Order item.
	 * @param int $order_id   Order.
	 * @param int $creator_id Creator.
	 * @param int $share_bps  Share.
	 */
	private function snapshot( int $item, int $order_id, int $creator_id, int $share_bps ): void {
		self::assertSame(
			1,
			$this->wpdb->insert(
				$this->table( Tables::SALES_LINE_PRICING ),
				array(
					'order_item_id'  => $item,
					'order_id'       => $order_id,
					'creator_id'     => $creator_id,
					'product_id'     => 20,
					'variation_id'   => 0,
					'status'         => 'share',
					'currency'       => 'DKK',
					'captured_at'    => '2026-09-01 10:00:00',
					'share_bps'      => $share_bps,
					'share_source'   => 'creator',
					'price_decimals' => 2,
					'vat_registered' => 0,
				)
			)
		);
	}

	/**
	 * Dispatches GET /me/orders as the current user.
	 *
	 * @param array<string, string> $query Query parameters.
	 */
	private function request( array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( $query );

		return rest_do_request( $request );
	}

	/**
	 * `X-WP-Total` and `X-WP-TotalPages` (null when missing).
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array{?string, ?string}
	 */
	private static function totals( WP_REST_Response $response ): array {
		$headers = $response->get_headers();

		return array( $headers['X-WP-Total'] ?? null, $headers['X-WP-TotalPages'] ?? null );
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
