<?php
/**
 * GET /me/products against the real WordPress REST server, users, roles and
 * database. WooCommerce is not loaded here: the controller is registered by
 * the test with FakeProductDetails (the WooCommerce data is unit-tested in
 * WooCommerceProductDetailsTest).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Rest;

use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\Rest\MeProductsController;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeProductDetails;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class MeProductsEndpointTest extends IntegrationTestCase {

	private const ROUTE = '/merchboard/v1/me/products';

	private CreatorService $creators;

	private ProductPosts $posts;

	private FakeProductDetails $details;

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
		$this->posts    = new ProductPosts( $this->wpdb );
		$this->details  = new FakeProductDetails();
		$this->posts->remove_all();
		$this->register_route();
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		$this->posts->remove_all();
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
	}

	public function test_without_woocommerce_the_plugin_registers_no_route(): void {
		self::assertNotNull( $this->register );
		remove_action( 'rest_api_init', $this->register );
		$this->register            = null;
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		wp_set_current_user( $this->creator_user( 'no_wc', 'No WC', CreatorStatus::Active ) );

		$response = $this->request();

		self::assertArrayNotHasKey( self::ROUTE, rest_get_server()->get_routes( 'merchboard/v1' ) );
		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'rest_no_route', ( (array) $response->get_data() )['code'] ?? null );
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
	 * Every user without creator access is denied and sees no product.
	 *
	 * @param string $kind   User kind.
	 * @param int    $status Expected status.
	 * @param string $code   Expected error code.
	 */
	#[DataProvider( 'denied' )]
	public function test_users_without_creator_access_get_no_products( string $kind, int $status, string $code ): void {
		$bystander = $this->creator_user( 'bystander', 'Bystander', CreatorStatus::Active );
		$this->posts->add( 'Bystander tee', 'publish', array( (string) $this->creator_id( $bystander ) ) );
		wp_set_current_user( $this->user_of_kind( $kind ) );

		$response = $this->request();

		self::assertSame( $status, $response->get_status() );
		self::assertSame( $code, ( (array) $response->get_data() )['code'] ?? null );
		self::assertStringNotContainsString( 'Bystander tee', (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( array(), $this->details->requested, 'No product was read.' );
	}

	public function test_an_active_creator_gets_only_their_own_products(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		$tee    = $this->posts->add( 'Tee', 'publish', array( (string) $this->creator_id( $mine ) ) );
		$cap    = $this->posts->add( 'Cap', 'private', array( (string) $this->creator_id( $mine ) ) );
		$this->posts->add( 'Draft', 'draft', array( (string) $this->creator_id( $mine ) ) );
		$this->posts->add( 'Their hoodie', 'publish', array( (string) $this->creator_id( $theirs ) ) );
		wp_set_current_user( $mine );

		$response = $this->request();

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( $cap, $tee ), array_column( (array) $response->get_data(), 'id' ) );
		self::assertSame(
			array(
				'id'           => $cap,
				'name'         => 'Cap',
				'status'       => 'private',
				'price'        => null,
				'stock_status' => 'instock',
				'image'        => null,
				// No ledger rows of the creator for the product: no profit, never 0 (ADR-050).
				'profit'       => null,
			),
			( (array) $response->get_data() )[0]
		);
		self::assertSame( array( '2', '1' ), self::totals( $response ) );
	}

	public function test_each_product_has_its_own_historical_profit_or_none(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		$me     = $this->creator_id( $mine );
		$tee    = $this->posts->add( 'A tee', 'publish', array( (string) $me ) );
		$cap    = $this->posts->add( 'B cap', 'publish', array( (string) $me ) );
		$this->posts->add( 'C unsold', 'publish', array( (string) $me ) );

		// Tee: a sale of the simple product and of a variation (both 100.00 incl. VAT at 20 %), and a refund.
		$this->ledger_row( $me, $tee, 0, 601, 's:601:1', '80.0000', '20.0000', 2000 );
		$this->ledger_row( $me, $tee, 31, 602, 's:602:1', '80.0000', '20.0000', 2000 );
		$this->ledger_row( $me, $tee, 31, 602, 'r:602:9', '-40.0000', '-10.0000', 2000 );
		// The other creator's row on the same product id never counts for me.
		$this->ledger_row( $this->creator_id( $theirs ), $tee, 0, 603, 's:603:1', '800.0000', '200.0000', 5000 );
		// Cap: one known row and one row without a snapshot (before phase 22).
		$this->ledger_row( $me, $cap, 0, 604, 's:604:1', '80.0000', '20.0000', 2000 );
		$this->ledger_row( $me, $cap, 0, 605, 's:605:1', '80.0000', '20.0000', null );
		wp_set_current_user( $mine );

		$data = array_column( (array) $this->request()->get_data(), 'profit', 'name' );

		self::assertSame(
			array(
				'A tee'    => array(
					array(
						'currency' => 'DKK',
						'status'   => 'complete',
						'amount'   => '30.0000',
					),
				),
				'B cap'    => array(
					array(
						'currency' => 'DKK',
						'status'   => 'incomplete',
						'amount'   => null,
					),
				),
				'C unsold' => null,
			),
			$data,
			'20 + 20 - 10 for the tee (variation under its parent); the cap is incomplete, not a partial sum.'
		);
	}

	public function test_request_parameters_cannot_select_another_creator(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		$tee    = $this->posts->add( 'Tee', 'publish', array( (string) $this->creator_id( $mine ) ) );
		$this->posts->add( 'Their hoodie', 'publish', array( (string) $this->creator_id( $theirs ) ) );
		wp_set_current_user( $mine );

		$response = $this->request(
			array(
				'creator_id' => (string) $this->creator_id( $theirs ),
				'creator'    => (string) $this->creator_id( $theirs ),
				'user_id'    => (string) $theirs,
				'author'     => (string) $theirs,
				'status'     => 'draft',
				'search'     => 'hoodie',
				'orderby'    => 'id',
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( $tee ), array_column( (array) $response->get_data(), 'id' ) );
		self::assertSame( array( '1', '1' ), self::totals( $response ) );
		self::assertStringNotContainsString( 'Their hoodie', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_pages_never_leak_another_creators_products(): void {
		$mine   = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$theirs = $this->creator_user( 'theirs', 'Theirs', CreatorStatus::Active );
		$own    = array();

		foreach ( array( 'B', 'D', 'F' ) as $name ) {
			$own[] = $this->posts->add( $name, 'publish', array( (string) $this->creator_id( $mine ) ) );
		}

		foreach ( array( 'A', 'C', 'E', 'G' ) as $name ) {
			$this->posts->add( $name, 'publish', array( (string) $this->creator_id( $theirs ) ) );
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
			$seen = array_merge( $seen, array_column( (array) $response->get_data(), 'id' ) );
		}

		self::assertSame( $own, $seen );
	}

	public function test_pagination_headers_and_a_page_after_the_last(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );

		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $name ) {
			$this->posts->add( $name, 'publish', array( (string) $this->creator_id( $mine ) ) );
		}

		wp_set_current_user( $mine );

		$last = $this->request(
			array(
				'page'     => '3',
				'per_page' => '2',
			)
		);
		self::assertSame( array( 'E' ), array_column( (array) $last->get_data(), 'name' ) );
		self::assertSame( array( '5', '3' ), self::totals( $last ) );

		$this->details->requested = array();
		$after                    = $this->request(
			array(
				'page'     => '4',
				'per_page' => '2',
			)
		);
		self::assertSame( 200, $after->get_status() );
		self::assertSame( '[]', wp_json_encode( $after->get_data() ) );
		self::assertSame( array( '5', '3' ), self::totals( $after ) );
		self::assertSame( array(), $this->details->requested, 'No product is read after the last page.' );
	}

	public function test_a_creator_without_products_gets_an_empty_array_and_zero_pages(): void {
		wp_set_current_user( $this->creator_user( 'empty', 'Empty', CreatorStatus::Active ) );

		$response = $this->request();

		self::assertSame( 200, $response->get_status() );
		self::assertSame( '[]', wp_json_encode( $response->get_data() ) );
		self::assertSame( array( '0', '0' ), self::totals( $response ) );
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

	public function test_100_per_page_is_accepted(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$this->posts->add( 'Tee', 'publish', array( (string) $this->creator_id( $mine ) ) );
		wp_set_current_user( $mine );

		$response = $this->request( array( 'per_page' => '100' ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( '1', '1' ), self::totals( $response ) );
	}

	public function test_a_product_gone_after_the_selection_is_left_out(): void {
		$mine = $this->creator_user( 'mine', 'Mine', CreatorStatus::Active );
		$tee  = $this->posts->add( 'Tee', 'publish', array( (string) $this->creator_id( $mine ) ) );
		$cap  = $this->posts->add( 'Cap', 'publish', array( (string) $this->creator_id( $mine ) ) );
		wp_set_current_user( $mine );
		$this->details->gone = array( $cap );

		$response = $this->request();

		self::assertSame( array( $tee ), array_column( (array) $response->get_data(), 'id' ) );
		self::assertSame( array( '2', '1' ), self::totals( $response ) );
	}

	/**
	 * A ledger row (DKK, order = item id) with its stored tax and, with a
	 * share, the line's share snapshot (not VAT registered, 2 decimals).
	 *
	 * @param int      $creator_id Ledger creator.
	 * @param int      $product_id Parent product.
	 * @param int      $variation  Variation.
	 * @param int      $item       Order item (also the order id).
	 * @param string   $event_key  Event key.
	 * @param string   $net        Net amount.
	 * @param string   $tax        Tax.
	 * @param int|null $share_bps  Share, null for no snapshot.
	 */
	private function ledger_row( int $creator_id, int $product_id, int $variation, int $item, string $event_key, string $net, string $tax, ?int $share_bps ): void {
		$refund = str_starts_with( $event_key, 'r:' );

		self::assertSame(
			1,
			$this->wpdb->insert(
				$this->table( Tables::SALES ),
				array(
					'event_key'     => $event_key,
					'creator_id'    => $creator_id,
					'order_id'      => $item,
					'order_item_id' => $item,
					'cycle'         => 1,
					'refund_id'     => $refund ? 9 : 0,
					'product_id'    => $product_id,
					'variation_id'  => $variation,
					'item_name'     => 'Line',
					'type'          => $refund ? 'refund' : 'sale',
					'quantity'      => $refund ? 0 : 1,
					'net_amount'    => $net,
					'currency'      => 'DKK',
					'occurred_at'   => '2026-09-10 10:00:00',
					'created_at'    => '2026-09-10 10:00:00',
				)
			)
		);
		$this->wpdb->insert(
			$this->table( Tables::SALES_TAX ),
			array(
				'event_key'     => $event_key,
				'order_id'      => $item,
				'order_item_id' => $item,
				'tax_amount'    => $tax,
				'currency'      => 'DKK',
				'created_at'    => '2026-09-10 10:00:00',
			)
		);

		if ( null !== $share_bps && ! $refund ) {
			$this->wpdb->insert(
				$this->table( Tables::SALES_LINE_PRICING ),
				array(
					'order_item_id'  => $item,
					'order_id'       => $item,
					'creator_id'     => $creator_id,
					'product_id'     => $product_id,
					'variation_id'   => $variation,
					'status'         => 'share',
					'currency'       => 'DKK',
					'captured_at'    => '2026-09-10 10:00:00',
					'share_bps'      => $share_bps,
					'share_source'   => 'creator',
					'price_decimals' => 2,
					'vat_registered' => 0,
				)
			);
		}
	}

	/**
	 * Registers the controller (with FakeProductDetails) on a fresh REST server.
	 */
	private function register_route(): void {
		$controller = new MeProductsController(
			$this->service( CreatorContext::class ),
			new CreatorProducts( $this->wpdb ),
			$this->details,
			$this->service( Logger::class ),
			new LedgerCreatorProfits( $this->wpdb )
		);

		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	/**
	 * Dispatches GET /me/products as the current user.
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
