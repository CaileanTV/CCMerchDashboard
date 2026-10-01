<?php
/**
 * The pagination convention of list routes (docs/API.md#paginering) against
 * the real WordPress REST server and its argument validation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Rest;

use MerchBoard\Core\Http\Pagination;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestPaginationTest extends IntegrationTestCase {

	/**
	 * Route of the test-only list controller.
	 */
	private const ROUTE = '/merchboard/v1/pagination-fixture';

	/**
	 * Registers the fixture route on `rest_api_init`.
	 *
	 * @var callable(): void
	 */
	private $register;

	protected function setUp(): void {
		parent::setUp();
		$controller     = self::fixture_controller( $this->service( Logger::class ) );
		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	protected function tearDown(): void {
		remove_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		parent::tearDown();
	}

	public function test_defaults_are_page_1_with_20_per_page(): void {
		$response = $this->request( array( 'total' => 45 ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( range( 1, 20 ), $response->get_data() );
		self::assertSame( array( '45', '3' ), self::totals( $response ) );
	}

	public function test_a_custom_page_and_page_size_are_used(): void {
		$response = $this->request(
			array(
				'total'    => 45,
				'page'     => '2',
				'per_page' => '10',
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( range( 11, 20 ), $response->get_data() );
		self::assertSame( array( '45', '5' ), self::totals( $response ) );
	}

	/**
	 * Invalid values for page and per_page.
	 *
	 * @return array<string, array{array<string, string>}>
	 */
	public static function invalid_params(): array {
		return array(
			'page 0'            => array( array( 'page' => '0' ) ),
			'negative page'     => array( array( 'page' => '-1' ) ),
			'page not a number' => array( array( 'page' => 'abc' ) ),
			'per_page 0'        => array( array( 'per_page' => '0' ) ),
			'per_page 101'      => array( array( 'per_page' => '101' ) ),
			'per_page fraction' => array( array( 'per_page' => '1.5' ) ),
		);
	}

	/**
	 * Out-of-range values are rejected by WordPress' argument validation.
	 *
	 * @param array<string, string> $query Query parameters.
	 */
	#[DataProvider( 'invalid_params' )]
	public function test_invalid_page_or_page_size_is_a_400( array $query ): void {
		$response = $this->request( array_merge( array( 'total' => 5 ), $query ) );

		$error = $response->as_error();

		self::assertSame( 400, $response->get_status() );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'rest_invalid_param', $error->get_error_code() );
		self::assertSame( array( null, null ), self::totals( $response ) );
	}

	public function test_100_per_page_is_accepted(): void {
		$response = $this->request(
			array(
				'total'    => 250,
				'per_page' => '100',
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( range( 1, 100 ), $response->get_data() );
		self::assertSame( array( '250', '3' ), self::totals( $response ) );
	}

	public function test_no_items_gives_an_empty_array_and_zero_pages(): void {
		$response = $this->request( array( 'total' => 0 ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array(), $response->get_data() );
		self::assertSame( '[]', wp_json_encode( $response->get_data() ) );
		self::assertSame( array( '0', '0' ), self::totals( $response ) );
	}

	public function test_exactly_one_full_page(): void {
		$response = $this->request( array( 'total' => 20 ) );

		self::assertSame( range( 1, 20 ), $response->get_data() );
		self::assertSame( array( '20', '1' ), self::totals( $response ) );
	}

	public function test_several_full_pages(): void {
		$response = $this->request(
			array(
				'total' => 60,
				'page'  => '3',
			)
		);

		self::assertSame( range( 41, 60 ), $response->get_data() );
		self::assertSame( array( '60', '3' ), self::totals( $response ) );
	}

	public function test_the_last_partial_page(): void {
		$response = $this->request(
			array(
				'total' => 45,
				'page'  => '3',
			)
		);

		self::assertSame( range( 41, 45 ), $response->get_data() );
		self::assertSame( array( '45', '3' ), self::totals( $response ) );
	}

	public function test_a_page_after_the_last_is_200_with_an_empty_array(): void {
		$response = $this->request(
			array(
				'total' => 45,
				'page'  => '4',
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array(), $response->get_data() );
		self::assertSame( '[]', wp_json_encode( $response->get_data() ) );
		self::assertSame( array( '45', '3' ), self::totals( $response ) );
	}

	public function test_the_body_is_always_a_plain_json_array(): void {
		$queries = array(
			array( 'total' => 0 ),
			array( 'total' => 7 ),
			array(
				'total' => 7,
				'page'  => '9',
			),
		);

		foreach ( $queries as $query ) {
			$data = $this->request( $query )->get_data();
			$json = (string) wp_json_encode( $data );

			self::assertIsArray( $data );
			self::assertTrue( array_is_list( $data ), $json );
			self::assertStringStartsWith( '[', $json );
			self::assertArrayNotHasKey( 'items', $data );
		}
	}

	/**
	 * Dispatches a GET request to the fixture route.
	 *
	 * @param array<string, int|string> $query Query parameters.
	 */
	private function request( array $query ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( $query );

		return rest_do_request( $request );
	}

	/**
	 * The `X-WP-Total` and `X-WP-TotalPages` headers (null when missing).
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array{?string, ?string}
	 */
	private static function totals( WP_REST_Response $response ): array {
		$headers = $response->get_headers();

		return array( $headers['X-WP-Total'] ?? null, $headers['X-WP-TotalPages'] ?? null );
	}

	/**
	 * A list route over the items 1..total, built only from the shared
	 * pagination helpers.
	 *
	 * @param Logger $logger Technical log.
	 */
	private static function fixture_controller( Logger $logger ): RestController {
		return new class( $logger ) extends RestController {

			public function register_routes(): void {
				register_rest_route(
					self::NAMESPACE,
					'/pagination-fixture',
					array(
						array(
							'methods'             => 'GET',
							'callback'            => array( $this, 'list_items' ),
							'permission_callback' => '__return_true',
							'args'                => array_merge(
								self::pagination_args(),
								array(
									'total' => array(
										'type'    => 'integer',
										'minimum' => 0,
										'default' => 0,
									),
								)
							),
						),
					)
				);
			}

			/**
			 * The requested page of 1..total.
			 *
			 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
			 *
			 * @param WP_REST_Request $request Request.
			 */
			public function list_items( WP_REST_Request $request ): WP_REST_Response {
				$total      = $request->get_param( 'total' );
				$total      = is_int( $total ) ? $total : 0;
				$pagination = Pagination::from_request( $request );
				$items      = $total > 0 ? array_slice( range( 1, $total ), $pagination->offset(), $pagination->per_page ) : array();

				return self::paginated( $items, $total, $pagination );
			}
		};
	}
}
