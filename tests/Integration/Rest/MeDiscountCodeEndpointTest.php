<?php
/**
 * GET /me/discount-code and POST /me/discount-code/reset (ADR-068, ADR-078).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Rest;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeService;
use MerchBoard\Modules\WooCommerce\Rest\MeDiscountCodeController;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeCouponIssuer;
use MerchBoard\Tests\Integration\Support\FakeCouponLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The creator resets their own code through the REST server: the new code
 * comes back, the old coupon is retired, and the limit and other creators
 * are refused. WooCommerce is not loaded: the coupons are test doubles.
 */
#[CoversClass( MeDiscountCodeController::class )]
final class MeDiscountCodeEndpointTest extends IntegrationTestCase {

	private int $nova;

	private int $nova_user;

	private FakeCouponIssuer $issuer;

	/**
	 * Codes the generator returns, in order.
	 *
	 * @var list<string>
	 */
	private array $next = array( 'nova-aaaa', 'nova-bbbb', 'nova-cccc', 'nova-dddd' );

	private ?\Closure $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators        = new CreatorRepository( $this->wpdb );
		$this->nova_user = $this->create_user( 'nova' );
		wp_set_current_user( 1 );
		$this->nova   = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->nova_user )->id;
		$coupons      = new FakeCouponLookup();
		$this->issuer = new FakeCouponIssuer( $coupons );
		$codes        = new CreatorCodeRepository( $this->wpdb );
		$service      = new CreatorCodeService(
			$creators,
			$codes,
			$coupons,
			$this->issuer,
			$this->service( Transaction::class ),
			$this->service( AuditTrail::class ),
			static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			fn(): string => (string) array_shift( $this->next )
		);
		$service->issue( $this->nova, CreatorCodeService::SOURCE_ADMIN );

		$controller     = new MeDiscountCodeController( $this->service( CreatorContext::class ), $codes, $this->service( Logger::class ), $service );
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

	public function test_the_creator_resets_the_code_and_the_old_one_stops_working(): void {
		wp_set_current_user( $this->nova_user );

		self::assertSame( array( 'code' => 'nova-aaaa' ), $this->call( 'GET', '' )->get_data() );

		$reset = $this->call( 'POST', '/reset' );
		self::assertSame( 200, $reset->get_status() );
		self::assertSame( array( 'code' => 'nova-bbbb' ), $reset->get_data() );
		self::assertSame( 'no-store', $reset->get_headers()['Cache-Control'] ?? null );
		self::assertSame( array( 501 ), $this->issuer->retired );
		self::assertSame( array( 'code' => 'nova-bbbb' ), $this->call( 'GET', '' )->get_data() );

		$this->call( 'POST', '/reset' );
		$refused = $this->call( 'POST', '/reset' );
		self::assertSame( 400, $refused->get_status() );
		self::assertSame( array( 'merchboard_code_refused', 'reset_limit' ), array( $refused->get_data()['code'] ?? null, $refused->get_data()['data']['reason'] ?? null ) );
	}

	public function test_without_creator_access_nothing_is_reset(): void {
		wp_set_current_user( $this->create_user( 'fan' ) );
		self::assertSame( 403, $this->call( 'POST', '/reset' )->get_status() );

		wp_set_current_user( 0 );
		self::assertSame( 401, $this->call( 'POST', '/reset' )->get_status() );
		self::assertSame( array(), $this->issuer->retired );
	}

	/**
	 * Dispatches a request below /me/discount-code.
	 *
	 * @param string $method HTTP method.
	 * @param string $suffix Path after the route.
	 */
	private function call( string $method, string $suffix ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( $method, '/merchboard/v1' . MeDiscountCodeController::ROUTE . $suffix ) );
	}
}
