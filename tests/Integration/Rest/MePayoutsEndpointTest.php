<?php
/**
 * /me/payouts against the real WordPress REST server, users, roles and
 * database (ADR-054–056). WooCommerce is not loaded: earnings come from real
 * ledger rows (sales of 100.00 incl. VAT at 20 %, VAT registered: payout
 * 20.00 each, dated 2026-09-01), and the controller is registered by the
 * test with the real services, a fixed clock (2026-11-30 12:00 UTC) and a
 * test key. Synthetic data only.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Rest;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardAvailability;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardSnapshot;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardStatus;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutGiftCards;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Rest\MePayoutsController;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetails;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetailsStore;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementPdf;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeGiftCardProvider;
use MerchBoard\Tests\Integration\Support\FakeOrderReader;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use MerchBoard\Tests\Integration\Support\RecordingFailureMarker;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class MePayoutsEndpointTest extends IntegrationTestCase {

	private const ROUTE = '/merchboard/v1/me/payouts';

	private const ACCOUNT = '0012345678';

	private CreatorService $creators;

	private ProductPosts $posts;

	private SalesLinePricingRecorder $recorder;

	private FakeOrderReader $reader;

	private SalesSynchronizer $sync;

	private PayoutRepository $repository;

	private PayoutGiftCards $gift_cards;

	private string $key;

	private int $next_order = 6001;

	/**
	 * Registers the routes on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	/**
	 * The registered controller.
	 *
	 * @var MePayoutsController|null
	 */
	private ?MePayoutsController $controller = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->creators = $this->service( CreatorService::class );
		$creators       = new CreatorRepository( $this->wpdb );
		$markups        = new CreatorMarkupRepository( $this->wpdb );
		$vat            = new CreatorVatRepository( $this->wpdb );
		$this->posts    = new ProductPosts( $this->wpdb );
		$links          = new ProductCreatorLink( new PostMetaProductStore(), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$this->recorder = new SalesLinePricingRecorder( new PostMetaPricingProductStore(), new MarkupResolver( $links, $markups, new ProductMarkupRepository( $this->wpdb ) ), new SalesLinePricingRepository( $this->wpdb ), new RecordingLogger(), $vat );
		$this->reader   = new FakeOrderReader();
		$this->sync     = new SalesSynchronizer(
			new SalesRepository( $this->wpdb, new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) ) ),
			new SalesPlanner(),
			$this->reader,
			new RecordingFailureMarker(),
			new RecordingLogger(),
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) )
		);
		$this->key      = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A test key.
		$this->routes( $this->key );
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		$this->posts->remove_all();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		parent::tearDown();
	}

	public function test_the_routes_are_registered_with_permission_callbacks(): void {
		$routes = rest_get_server()->get_routes( 'merchboard/v1' );

		foreach ( array( self::ROUTE => array( 'GET', 'POST' ), self::ROUTE . '/balance' => array( 'GET' ), self::ROUTE . '/(?P<id>\d+)/cancel' => array( 'POST' ), self::ROUTE . '/(?P<id>\d+)/gift-card' => array( 'GET' ), self::ROUTE . '/(?P<id>\d+)/settlement-document/pdf' => array( 'GET' ) ) as $route => $methods ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			self::assertArrayHasKey( $route, $routes );
			self::assertCount( count( $methods ), $routes[ $route ] );

			foreach ( $routes[ $route ] as $endpoint ) {
				self::assertIsCallable( $endpoint['permission_callback'] ?? null );
				self::assertNotSame( '__return_true', $endpoint['permission_callback'] );
			}
		}
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
	 * Every route refuses users without creator access and changes nothing.
	 *
	 * @param string $kind   User kind.
	 * @param int    $status Expected status.
	 * @param string $code   Expected error code.
	 */
	#[DataProvider( 'denied' )]
	public function test_users_without_creator_access_are_refused_everywhere( string $kind, int $status, string $code ): void {
		$bystander = $this->creator_user( 'bystander', 'Bystander' );
		$this->earn( $bystander, 2 );
		wp_set_current_user( $bystander );
		$payout = $this->create( '10', 'key-bystander-1' )->get_data();
		self::assertIsArray( $payout );
		wp_set_current_user( $this->user_of_kind( $kind ) );

		foreach ( array( $this->get(), $this->get( '/balance' ), $this->create( '5', 'key-denied-1' ), $this->cancel( (int) $payout['id'] ) ) as $response ) {
			self::assertSame( $status, $response->get_status() );
			self::assertSame( $code, ( (array) $response->get_data() )['code'] ?? null );
			self::assertStringNotContainsString( '10.0000', (string) wp_json_encode( $response->get_data() ) );
		}

		self::assertSame( 1, $this->count_rows( Tables::PAYOUTS ) );
		self::assertSame( 'pending', $this->repository->find( (int) $payout['id'] )?->status->value );
	}

	public function test_a_request_returns_the_payout_with_only_the_masked_destination(): void {
		$mine = $this->creator_user( 'mine', 'Mine' );
		$this->earn( $mine, 3 );
		wp_set_current_user( $mine );

		$response = $this->create(
			'25.50',
			'key-mine-0001',
			array(
				'creator_id' => 999,
				'status'     => 'completed',
			)
		);
		$data     = (array) $response->get_data();

		self::assertSame( 201, $response->get_status() );
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
		self::assertSame(
			array( 'status', 'currency', 'amount', 'method', 'destination_hint', 'decided_at', 'rejection_reason', 'gift_card', 'settlement_document' ),
			array_keys( array_diff_key( $data, array_flip( array( 'id', 'requested_at' ) ) ) )
		);
		self::assertSame( array( 'pending', 'DKK', '25.5000', 'bank', '•••• 5678', null, null ), array( $data['status'], $data['currency'], $data['amount'], $data['method'], $data['destination_hint'], $data['decided_at'], $data['rejection_reason'] ) );
		self::assertSame( '2026-11-30T12:00:00Z', $data['requested_at'] );
		self::assertSame( $this->creator_id( $mine ), $this->repository->find( (int) $data['id'] )?->creator_id, 'creator_id and status in the body are ignored.' );
		self::assertStringNotContainsString( self::ACCOUNT, (string) wp_json_encode( $data ) );
		self::assertStringNotContainsString( '1234', (string) wp_json_encode( $data ) );

		$balance = (array) $this->get( '/balance' )->get_data();
		self::assertSame(
			array(
				'currency'     => 'DKK',
				'status'       => 'complete',
				'earned'       => '60.0000',
				'completed'    => '0.0000',
				'reserved'     => '25.5000',
				'available'    => '34.5000',
				'outstanding'  => '0.0000',
				'unknown_rows' => 0,
			),
			$balance['currencies'][0]
		);
		self::assertSame( array( 14, null, array( 'bank', 'paypal', 'gift_card' ), true, false ), array( $balance['holding_days'], $balance['payout_start'], $balance['methods'], $balance['methods_usable'], $balance['gift_card_usable'] ) );
	}

	public function test_a_resent_key_returns_the_same_payout_once(): void {
		$mine = $this->creator_user( 'mine', 'Mine' );
		$this->earn( $mine, 2 );
		wp_set_current_user( $mine );

		$first  = (array) $this->create( '10', 'key-mine-0002' )->get_data();
		$second = $this->create( '15', 'key-mine-0002' );

		self::assertSame( 201, $second->get_status() );
		self::assertSame( $first, (array) $second->get_data(), 'Same response for the same key.' );
		self::assertSame( 1, $this->count_rows( Tables::PAYOUTS ) );
	}

	/**
	 * Invalid request bodies.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, string>}>
	 */
	public static function invalid_bodies(): array {
		$valid = array(
			'currency'        => 'DKK',
			'amount'          => '1',
			'method'          => 'bank',
			'details'         => array(
				'registration' => '1234',
				'account'      => '1',
			),
			'idempotency_key' => 'key-invalid-0001',
		);

		return array(
			'empty body'          => array( array(), array( 'currency' => 'invalid', 'amount' => 'invalid', 'method' => 'invalid', 'idempotency_key' => 'invalid', 'details' => 'invalid' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'amount as a number'  => array( array_merge( $valid, array( 'amount' => 1 ) ), array( 'amount' => 'invalid' ) ),
			'details as a string' => array( array_merge( $valid, array( 'details' => '1234 1' ) ), array( 'details' => 'invalid' ) ),
			'empty currency'      => array( array_merge( $valid, array( 'currency' => '' ) ), array( 'currency' => 'invalid' ) ),
		);
	}

	/**
	 * Missing or wrongly typed fields are 400 before any service is called.
	 *
	 * @param array<string, mixed>  $body   Body.
	 * @param array<string, string> $fields Expected field errors.
	 */
	#[DataProvider( 'invalid_bodies' )]
	public function test_missing_or_wrongly_typed_fields_are_rejected( array $body, array $fields ): void {
		$mine = $this->creator_user( 'mine', 'Mine' );
		$this->earn( $mine, 1 );
		wp_set_current_user( $mine );

		$response = $this->post( self::ROUTE, $body );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'merchboard_invalid_payout', ( (array) $response->get_data() )['code'] ?? null );
		self::assertSame( $fields, ( (array) $response->get_data() )['data']['fields'] ?? null );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUTS ) );
	}

	/**
	 * Refusals of the service with their status.
	 *
	 * @return array<string, array{string, array<string, mixed>, int, string}>
	 */
	public static function refusals(): array {
		return array(
			'amount with 3 decimals' => array( '1.234', array(), 400, 'invalid_amount' ),
			'amount 0'               => array( '0', array(), 400, 'invalid_amount' ),
			'more than available'    => array( '20.01', array(), 409, 'insufficient_balance' ),
			'invalid account'        => array( '1', array( 'account' => '12345678901' ), 400, 'invalid_details' ),
			'unknown method'         => array( '1', array( 'method' => 'gift_card' ), 400, 'method_unavailable' ),
			'invalid currency'       => array( '1', array( 'currency' => 'dkk' ), 400, 'invalid_currency' ),
			'short key'              => array( '1', array( 'idempotency_key' => 'short' ), 400, 'invalid_idempotency_key' ),
		);
	}

	/**
	 * The service's refusals become `merchboard_payout_refused` with the reason.
	 *
	 * @param string               $amount Amount.
	 * @param array<string, mixed> $change Body changes (`account` changes the details).
	 * @param int                  $status Expected status.
	 * @param string               $reason Expected reason.
	 */
	#[DataProvider( 'refusals' )]
	public function test_refusals_carry_their_reason( string $amount, array $change, int $status, string $reason ): void {
		$mine = $this->creator_user( 'mine', 'Mine' );
		$this->earn( $mine, 1 );
		wp_set_current_user( $mine );

		$response = $this->create( $amount, 'key-refused-0001', $change );

		self::assertSame( $status, $response->get_status() );
		self::assertSame( MePayoutsController::REFUSED, ( (array) $response->get_data() )['code'] ?? null );
		self::assertSame( $reason, ( (array) $response->get_data() )['data']['reason'] ?? null );
		self::assertSame( 0, $this->count_rows( Tables::PAYOUTS ) );
	}

	public function test_an_unknown_balance_blocks_and_is_never_shown_as_0(): void {
		$mine = $this->creator_user( 'mine', 'Mine' );
		$this->earn( $mine, 1 );
		// A line from before the snapshots: its payout is unknown.
		$this->order( $mine, 6999, false );
		wp_set_current_user( $mine );

		$balance = (array) $this->get( '/balance' )->get_data();
		self::assertSame( array( 'unknown', null, null, null, 1 ), array( $balance['currencies'][0]['status'], $balance['currencies'][0]['earned'], $balance['currencies'][0]['available'], $balance['currencies'][0]['outstanding'], $balance['currencies'][0]['unknown_rows'] ) );

		$response = $this->create( '1', 'key-unknown-0001' );
		self::assertSame( array( 409, 'balance_unknown' ), array( $response->get_status(), ( (array) $response->get_data() )['data']['reason'] ?? null ) );
	}

	public function test_limits_and_a_missing_key_are_refused(): void {
		$mine = $this->creator_user( 'mine', 'Mine' );
		$this->earn( $mine, 5 );
		wp_set_current_user( $mine );

		for ( $i = 1; $i <= 3; $i++ ) {
			self::assertSame( 201, $this->create( '1', 'key-limit-000' . $i )->get_status() );
		}

		$response = $this->create( '1', 'key-limit-0004' );
		self::assertSame( array( 429, 'too_many_pending' ), array( $response->get_status(), ( (array) $response->get_data() )['data']['reason'] ?? null ) );

		$this->routes( null );
		self::assertFalse( ( (array) $this->get( '/balance' )->get_data() )['methods_usable'] );
		$response = $this->create( '1', 'key-nokey-0001' );
		self::assertSame( array( 400, 'method_unavailable' ), array( $response->get_status(), ( (array) $response->get_data() )['data']['reason'] ?? null ) );
	}

	public function test_the_list_shows_only_the_creators_own_payouts_newest_first(): void {
		$mine  = $this->creator_user( 'mine', 'Mine' );
		$other = $this->creator_user( 'other', 'Other' );
		$this->earn( $mine, 3 );
		$this->earn( $other, 1 );
		wp_set_current_user( $other );
		$this->create( '7', 'key-other-0001' );
		wp_set_current_user( $mine );
		$first  = (array) $this->create( '1', 'key-mine-0003' )->get_data();
		$second = (array) $this->create( '2', 'key-mine-0004' )->get_data();

		$response = $this->get( '', array( 'per_page' => 1 ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( '2', '2' ), array( $response->get_headers()['X-WP-Total'], $response->get_headers()['X-WP-TotalPages'] ) );
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
		self::assertSame( array( $second['id'] ), array_column( (array) $response->get_data(), 'id' ), 'Newest first (same second: higher id first).' );
		self::assertSame( array( $first['id'] ), array_column( (array) $this->get( '', array( 'per_page' => 1, 'page' => 2 ) )->get_data(), 'id' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( '7.0000', (string) wp_json_encode( $this->get()->get_data() ) );
		self::assertStringNotContainsString( self::ACCOUNT, (string) wp_json_encode( $this->get()->get_data() ) );
	}

	public function test_cancel_only_the_creators_own_pending_payout(): void {
		$mine  = $this->creator_user( 'mine', 'Mine' );
		$other = $this->creator_user( 'other', 'Other' );
		$this->earn( $mine, 2 );
		$this->earn( $other, 1 );
		wp_set_current_user( $other );
		$theirs = (array) $this->create( '5', 'key-other-0002' )->get_data();
		wp_set_current_user( $mine );
		$payout = (array) $this->create( '10', 'key-mine-0005' )->get_data();

		$response = $this->cancel( (int) $theirs['id'] );
		self::assertSame( array( 404, 'merchboard_not_found' ), array( $response->get_status(), ( (array) $response->get_data() )['code'] ?? null ), 'Another creator\'s payout: as if missing.' );
		self::assertSame( 'pending', $this->repository->find( (int) $theirs['id'] )?->status->value );
		self::assertSame( 404, $this->cancel( 999999 )->get_status() );

		$response = $this->cancel( (int) $payout['id'] );
		self::assertSame( array( 200, 'cancelled' ), array( $response->get_status(), ( (array) $response->get_data() )['status'] ?? null ) );
		self::assertSame( '40.0000', ( (array) $this->get( '/balance' )->get_data() )['currencies'][0]['available'], 'The reservation is released.' );
		self::assertSame( 200, $this->cancel( (int) $payout['id'] )->get_status(), 'Again: already cancelled.' );

		$completed = (array) $this->create( '10', 'key-mine-0006' )->get_data();
		wp_set_current_user( 1 );
		$this->decisions()->complete( (int) $completed['id'] );
		wp_set_current_user( $mine );
		$response = $this->cancel( (int) $completed['id'] );
		self::assertSame( array( 409, 'not_pending' ), array( $response->get_status(), ( (array) $response->get_data() )['data']['reason'] ?? null ) );
		self::assertSame( array( '10.0000', '30.0000' ), array( ( (array) $this->get( '/balance' )->get_data() )['currencies'][0]['completed'], ( (array) $this->get( '/balance' )->get_data() )['currencies'][0]['available'] ) );
	}

	public function test_a_rejected_payout_shows_its_reason_to_the_creator(): void {
		$mine = $this->creator_user( 'mine', 'Mine' );
		$this->earn( $mine, 1 );
		wp_set_current_user( $mine );
		$payout = (array) $this->create( '5', 'key-mine-0007' )->get_data();

		wp_set_current_user( 1 );
		$this->decisions()->reject( (int) $payout['id'], 'The account is closed.' );
		wp_set_current_user( $mine );

		$item = ( (array) $this->get()->get_data() )[0];
		self::assertSame( array( 'rejected', 'The account is closed.', '2026-11-30T12:00:00Z' ), array( $item['status'], $item['rejection_reason'], $item['decided_at'] ) );
	}

	public function test_the_creator_is_notified_of_sales_and_every_payout_step_after_the_commit(): void {
		$mine    = $this->creator_user( 'mine', 'Mine' );
		$creator = $this->creator_id( $mine );
		$this->earn( $mine, 2 );
		$this->sync->sync( $this->next_order - 1 );
		wp_set_current_user( $mine );
		$cancelled = (int) ( (array) $this->create( '5', 'key-note-0001' )->get_data() )['id'];
		$this->cancel( $cancelled );
		$this->create( '5', 'key-note-0001' );
		$completed = (int) ( (array) $this->create( '15', 'key-note-0002' )->get_data() )['id'];
		$rejected  = (int) ( (array) $this->create( '5', 'key-note-0003' )->get_data() )['id'];

		wp_set_current_user( 1 );
		$this->decisions()->complete( $completed );
		$this->decisions()->complete( $completed );
		$this->decisions()->reject( $rejected, 'The account is closed.' );
		$documents = $this->documents();
		$shown     = $documents->for_payout( $completed );
		self::assertNotNull( $shown );
		$documents->correct( $completed, $shown['document']->id, 'The markup was wrong.', '30', '0' );

		$rows = array_values( array_filter( $this->rows( Tables::NOTIFICATIONS ), static fn( array $row ): bool => (string) $creator === (string) $row['creator_id'] ) );
		self::assertSame(
			array( 'account_welcome', 'sale_new', 'sale_new', 'payout_requested', 'payout_cancelled', 'payout_requested', 'payout_requested', 'payout_completed', 'payout_rejected', 'settlement_corrected' ),
			array_column( $rows, 'type' ),
			'One per order (a repeated sync adds none) and one per payout and step (a resent key or a second click adds none).'
		);

		$data = array_map( static fn( array $row ): array => (array) json_decode( (string) $row['data'], true ), $rows );
		self::assertSame( array( 'MB-PAY line', '1', '80.0000' ), array_values( $data[1]['items'][0] ?? array() ) );
		self::assertSame( array( '80.0000', 'DKK' ), array( $data[1]['total'] ?? null, $data[1]['currency'] ?? null ) );
		self::assertSame( array( '15.0000', 'DKK', 'AFR-000001' ), array( $data[7]['amount'] ?? null, $data[7]['currency'] ?? null, $data[7]['document_number'] ?? null ) );
		self::assertSame( 'The account is closed.', $data[8]['reason'] ?? null );
		self::assertSame( array( 'AFR-000001', 'AFR-000001-K1', '-10.0000', 'The markup was wrong.' ), array( $data[9]['document_number'] ?? null, $data[9]['correction_number'] ?? null, $data[9]['difference'] ?? null, $data[9]['reason'] ?? null ) );
		self::assertStringNotContainsString( self::ACCOUNT, (string) wp_json_encode( $data ), 'Never the payment details.' );
		self::assertStringNotContainsString( '5678', (string) wp_json_encode( $data ) );
	}

	public function test_the_settlement_document_is_shown_only_to_its_creator_and_never_cached(): void {
		$mine  = $this->creator_user( 'mine', 'Mine' );
		$other = $this->creator_user( 'other', 'Other' );
		$this->earn( $mine, 2 );
		$creator = ( new CreatorRepository( $this->wpdb ) )->find_by_user_id( $mine );
		self::assertNotNull( $creator );
		( new CreatorBillingDetailsStore( new CreatorMetaStore( $this->wpdb ) ) )->save( $creator->id, new CreatorBillingDetails( 'Mine ApS', 'Nørregade 2', '', '8000', 'Aarhus C' ) );
		wp_set_current_user( $mine );
		$id = (int) ( (array) $this->create( '15', 'key-doc-0001' )->get_data() )['id'];

		self::assertSame( 404, $this->get( '/' . $id . '/settlement-document' )->get_status(), 'Pending: no document yet.' );

		wp_set_current_user( 1 );
		$this->decisions()->complete( $id );
		wp_set_current_user( $mine );

		$response = $this->get( '/' . $id . '/settlement-document' );
		$data     = (array) $response->get_data();
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
		self::assertSame( array( 'AFR-000001', 'settlement', false, 'DKK', '40.0000', '0.0000', '15.0000', '25.0000', true, 'Mine' ), array( $data['number'], $data['type'], $data['preliminary'], $data['currency'], $data['commission'], $data['opening_balance'], $data['payout'], $data['closing_balance'], $data['vat_registered'], $data['creator_name'] ) );
		self::assertSame(
			array(
				'rate_bps' => 2500,
				'excl'     => '32.0000',
				'vat'      => '8.0000',
				'incl'     => '40.0000',
				'untaxed'  => '0.0000',
			),
			$data['commission_vat'],
			'ADR-062 K05: the commission of sales while VAT registered includes 25 % VAT.'
		);
		self::assertSame(
			array(
				'creator_legal_name' => 'Mine ApS',
				'creator_address'    => array( 'Nørregade 2', '8000 Aarhus C' ),
				'creator_cvr'        => '',
				'company_cvr'        => '',
			),
			$data['master_data'],
			'ADR-062 K09: as issued; not registered is empty, never filled in.'
		);
		self::assertCount( 2, $data['rows'] );
		self::assertSame( array( 'order_id', 'item_name', 'type', 'quantity', 'net', 'tax', 'payout' ), array_keys( $data['rows'][0] ) );
		self::assertSame( '•••• 5678', $data['destination'] );
		self::assertStringNotContainsString( self::ACCOUNT, (string) wp_json_encode( $data ) );
		self::assertSame( 'AFR-000001', ( (array) $this->get()->get_data() )[0]['settlement_document'] );

		wp_set_current_user( $other );
		self::assertSame( 404, $this->get( '/' . $id . '/settlement-document' )->get_status(), 'Another creator: as if missing.' );
		wp_set_current_user( 0 );
		self::assertSame( 401, $this->get( '/' . $id . '/settlement-document' )->get_status() );
	}

	public function test_a_correction_document_is_shown_to_its_creator_with_its_amounts_and_pdf(): void {
		$mine  = $this->creator_user( 'mine', 'Mine' );
		$other = $this->creator_user( 'other', 'Other' );
		$this->earn( $mine, 2 );
		wp_set_current_user( $mine );
		$id    = (int) ( (array) $this->create( '15', 'key-doc-0101' )->get_data() )['id'];
		$route = '/' . $id . '/settlement-document/pdf';

		wp_set_current_user( 1 );
		$this->decisions()->complete( $id );
		$documents = $this->documents();
		$shown     = $documents->for_payout( $id );
		self::assertNotNull( $shown );
		$fixed = $documents->correct( $id, $shown['document']->id, 'The markup was wrong.', '30', '0' );
		wp_set_current_user( $mine );

		$data = (array) $this->get( '/' . $id . '/settlement-document' )->get_data();
		self::assertSame( '40.0000', $data['commission'], 'The original as issued.' );
		self::assertSame( array( 'AFR-000001-K1', 'The markup was wrong.' ), array( $data['corrections'][0]['number'] ?? null, $data['corrections'][0]['reason'] ?? null ) );
		self::assertSame(
			array(
				'suffix'     => 'K1',
				'original'   => 'AFR-000001',
				'corrects'   => 'AFR-000001',
				'rate_bps'   => 2500,
				'before'     => array(
					'commission' => '40.0000',
					'excl'       => '32.0000',
					'vat'        => '8.0000',
					'incl'       => '40.0000',
					'untaxed'    => '0.0000',
				),
				'after'      => array(
					'commission' => '30.0000',
					'excl'       => '24.0000',
					'vat'        => '6.0000',
					'incl'       => '30.0000',
					'untaxed'    => '0.0000',
				),
				'difference' => array(
					'commission' => '-10.0000',
					'excl'       => '-8.0000',
					'vat'        => '-2.0000',
					'incl'       => '-10.0000',
					'untaxed'    => '0.0000',
				),
				'payout'     => '15.0000',
			),
			$data['corrections'][0]['amounts'] ?? null
		);

		$response = $this->get( $route, array( 'correction' => 'K1' ) );
		$fresh    = $documents->for_payout( $id );
		self::assertNotNull( $fresh );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( ( new SettlementPdf() )->render_correction( $fresh, $fixed ), $response->get_data() );
		self::assertSame( 'attachment; filename="AFR-000001-K1.pdf"', $response->get_headers()['Content-Disposition'] ?? null );
		self::assertSame( 404, $this->get( $route, array( 'correction' => 'K2' ) )->get_status(), 'No such correction.' );
		self::assertSame( 400, $this->get( $route, array( 'correction' => 'AFR-000001' ) )->get_status(), 'Only a suffix.' );
		self::assertFalse( $data['corrections'][0]['master_data']['changed'] ?? null, 'K1 corrected only the amounts.' );

		// K2 corrects the master data (no CVR/SE number registered): shown from its snapshot, also after a later change.
		$creator = ( new CreatorRepository( $this->wpdb ) )->find_by_user_id( $mine );
		self::assertNotNull( $creator );
		$billing = new CreatorBillingDetailsStore( new CreatorMetaStore( $this->wpdb ) );
		$billing->save( $creator->id, new CreatorBillingDetails( 'Mine ApS', 'Nørregade 2', '', '8000', 'Aarhus C' ) );
		wp_set_current_user( 1 );
		$documents->correct( $id, $fixed->id, 'The legal name was missing.', '30', '0' );
		wp_set_current_user( $mine );
		$billing->save( $creator->id, new CreatorBillingDetails( 'Later ApS', '', '', '', '', '', '87654321' ) );

		$master = ( (array) $this->get( '/' . $id . '/settlement-document' )->get_data() )['corrections'][1]['master_data'] ?? null;
		self::assertIsArray( $master );
		self::assertSame( array( 'Mine ApS', array( 'Nørregade 2', '8000 Aarhus C' ), '', true ), array( $master['creator_legal_name'] ?? null, $master['creator_address'] ?? null, $master['creator_cvr'] ?? null, $master['changed'] ?? null ) );

		wp_set_current_user( $other );
		self::assertSame( 404, $this->get( '/' . $id . '/settlement-document' )->get_status(), 'Another creator gets neither the correction nor its master data.' );
		self::assertSame( 404, $this->get( $route, array( 'correction' => 'K1' ) )->get_status(), 'Another creator: as if missing.' );
	}

	public function test_the_settlement_pdf_is_sent_only_to_its_creator_as_a_private_attachment(): void {
		$mine  = $this->creator_user( 'mine', 'Mine' );
		$other = $this->creator_user( 'other', 'Other' );
		$this->earn( $mine, 2 );
		wp_set_current_user( $mine );
		$id    = (int) ( (array) $this->create( '15', 'key-pdf-0001' )->get_data() )['id'];
		$route = '/' . $id . '/settlement-document/pdf';

		self::assertSame( 404, $this->get( $route )->get_status(), 'Pending: no document yet.' );

		wp_set_current_user( 1 );
		$this->decisions()->complete( $id );
		wp_set_current_user( $mine );

		$response = $this->get( $route );
		$body     = $response->get_data();
		self::assertSame( 200, $response->get_status() );
		self::assertIsString( $body );
		self::assertStringStartsWith( '%PDF-1.4', $body );
		self::assertStringNotContainsString( self::ACCOUNT, $body, 'Never the full account.' );
		self::assertSame(
			array(
				'Content-Type'           => 'application/pdf',
				'Content-Disposition'    => 'attachment; filename="AFR-000001.pdf"',
				'Cache-Control'          => 'no-store, private',
				'X-Content-Type-Options' => 'nosniff',
			),
			$response->get_headers()
		);
		self::assertSame( $body, $this->get( $route )->get_data(), 'The same document gives the same bytes.' );

		// Sent as the PDF itself, never as JSON; nothing else is taken over.
		self::assertNotNull( $this->controller );
		ob_start();
		$served = $this->controller->serve_pdf( false, $response, new WP_REST_Request( 'GET', self::ROUTE . $route ) );
		self::assertSame( $body, ob_get_clean() );
		self::assertTrue( $served );
		ob_start();
		self::assertFalse( $this->controller->serve_pdf( false, $this->get( '/' . $id . '/settlement-document' ), new WP_REST_Request( 'GET', self::ROUTE . '/' . $id . '/settlement-document' ) ) );
		self::assertFalse( $this->controller->serve_pdf( false, $response, new WP_REST_Request( 'HEAD', self::ROUTE . $route ) ) );
		self::assertTrue( $this->controller->serve_pdf( true, $response, new WP_REST_Request( 'GET', self::ROUTE . $route ) ), 'Already served by someone else.' );
		self::assertSame( '', ob_get_clean() );

		wp_set_current_user( $other );
		self::assertSame( 404, $this->get( $route )->get_status(), 'Another creator: as if missing.' );
		wp_set_current_user( 0 );
		self::assertSame( 401, $this->get( $route )->get_status() );
	}

	public function test_the_gift_card_is_shown_only_to_its_creator_and_never_cached(): void {
		$before = get_option( Settings::OPTION );
		$fake   = new FakeGiftCardProvider();
		Settings::save( array( Settings::PAYOUT_GIFT_CARD_PROVIDER => 'fake' ) + Settings::all() );

		try {
			$this->routes( $this->key, $fake );
			$mine  = $this->creator_user( 'mine', 'Mine' );
			$other = $this->creator_user( 'other', 'Other' );
			$this->earn( $mine, 2 );
			wp_set_current_user( $mine );
			self::assertTrue( ( (array) $this->get( '/balance' )->get_data() )['gift_card_usable'] );
			$created = $this->post(
				self::ROUTE,
				array(
					'currency'        => 'DKK',
					'amount'          => '20',
					'method'          => 'gift_card',
					'details'         => array(),
					'idempotency_key' => 'key-gift-0001',
				)
			);
			self::assertSame( 201, $created->get_status() );
			$id = (int) ( (array) $created->get_data() )['id'];

			self::assertSame( 404, $this->get( '/' . $id . '/gift-card' )->get_status(), 'Pending: no card yet.' );

			$fake->cards['MB-REST-CODE-55'] = new GiftCardSnapshot( '55', SalesAmount::parse( '20' ), SalesAmount::parse( '20' ), 'DKK', GiftCardStatus::Active, null, true );
			wp_set_current_user( 1 );
			$this->gift_cards->register( $id, 'MB-REST-CODE-55' );
			wp_set_current_user( $mine );

			$notices = array_column( $this->rows( Tables::NOTIFICATIONS ), 'data', 'type' );
			self::assertSame(
				array(
					'amount'   => '20.0000',
					'currency' => 'DKK',
				),
				json_decode( (string) ( $notices['payout_gift_card'] ?? '' ), true ),
				'Never the code (ADR-057).'
			);
			self::assertArrayNotHasKey( 'payout_completed', $notices, 'A gift card is told once, as a gift card.' );

			$response = $this->get( '/' . $id . '/gift-card' );
			self::assertSame( 200, $response->get_status() );
			self::assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] ?? null );
			self::assertSame(
				array(
					'code'       => 'MB-REST-CODE-55',
					'amount'     => '20.0000',
					'balance'    => '20.0000',
					'currency'   => 'DKK',
					'status'     => 'active',
					'expires_at' => null,
				),
				$response->get_data()
			);
			$list = $this->get();
			self::assertTrue( ( (array) $list->get_data() )[0]['gift_card'] );
			self::assertStringNotContainsString( 'MB-REST-CODE-55', (string) wp_json_encode( $list->get_data() ), 'Never in the list.' );

			wp_set_current_user( $other );
			self::assertSame( array( 404, 'merchboard_not_found' ), array( $this->get( '/' . $id . '/gift-card' )->get_status(), ( (array) $this->get( '/' . $id . '/gift-card' )->get_data() )['code'] ?? null ), 'Another creator: as if missing.' );
			wp_set_current_user( 0 );
			self::assertSame( 401, $this->get( '/' . $id . '/gift-card' )->get_status() );

			wp_set_current_user( $mine );
			$fake->availability = GiftCardAvailability::unavailable( GiftCardAvailability::INACTIVE );
			$response           = $this->get( '/' . $id . '/gift-card' );
			self::assertSame( array( 503, 'gift_cards_unavailable' ), array( $response->get_status(), ( (array) $response->get_data() )['data']['reason'] ?? null ) );
			self::assertStringNotContainsString( 'MB-REST-CODE-55', (string) wp_json_encode( $response->get_data() ) );
		} finally {
			update_option( Settings::OPTION, $before );
		}
	}

	/**
	 * Registers the controller with real services and a cipher key (null = none).
	 *
	 * @param string|null               $key  Base64 key.
	 * @param FakeGiftCardProvider|null $fake The chosen gift card adapter (null = none).
	 */
	private function routes( ?string $key, ?FakeGiftCardProvider $fake = null ): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		$clock            = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$this->repository = new PayoutRepository( $this->wpdb );
		$balances         = new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock );
		$providers        = new GiftCardProviders( null === $fake ? array() : array( $fake ) );
		$transaction      = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );
		$requests         = new PayoutRequests( $this->repository, $balances, new PayoutCipher( $key ), new PostMetaPricingProductStore(), $this->service( AuditTrail::class ), $transaction, $clock, $providers );
		$audit            = $this->service( AuditTrail::class );
		$documents        = new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), new CreatorRepository( $this->wpdb ), new CreatorVatRepository( $this->wpdb ), $audit, $transaction, $clock, null, new CreatorBillingDetailsStore( new CreatorMetaStore( $this->wpdb ) ) );
		$this->gift_cards = new PayoutGiftCards( $this->repository, $balances, $providers, $audit, $transaction, new RecordingLogger(), $clock, $documents );
		$controller       = new MePayoutsController( $this->service( CreatorContext::class ), $requests, $balances, $this->repository, $this->service( Logger::class ), $this->gift_cards, $documents, new SettlementPdf() );
		$this->controller = $controller;
		$this->register   = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	/**
	 * The admin decisions on the same repository and key.
	 */
	private function decisions(): PayoutDecisions {
		$clock = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );

		$audit       = $this->service( AuditTrail::class );
		$transaction = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );

		return new PayoutDecisions( $this->repository, new PayoutBalances( new LedgerCreatorPayouts( $this->wpdb ), $this->repository, $clock ), new PayoutCipher( $this->key ), $audit, $transaction, $clock, $this->documents() );
	}

	/**
	 * The admin's settlement documents on the same repository.
	 */
	private function documents(): SettlementDocuments {
		$clock       = static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) );
		$transaction = new Transaction( $this->wpdb, new TransactionSupport( $this->wpdb ) );

		return new SettlementDocuments( new SettlementRepository( $this->wpdb ), $this->repository, new LedgerCreatorPayouts( $this->wpdb ), new CreatorRepository( $this->wpdb ), new CreatorVatRepository( $this->wpdb ), $this->service( AuditTrail::class ), $transaction, $clock, null, new CreatorBillingDetailsStore( new CreatorMetaStore( $this->wpdb ) ) );
	}

	/**
	 * `POST /me/payouts` for a bank payout.
	 *
	 * @param string               $amount Amount.
	 * @param string               $key    Request key.
	 * @param array<string, mixed> $change Body changes; `account` changes the details.
	 */
	private function create( string $amount, string $key, array $change = array() ): WP_REST_Response {
		$account = $change['account'] ?? self::ACCOUNT;
		unset( $change['account'] );

		return $this->post(
			self::ROUTE,
			array_merge(
				array(
					'currency'        => 'DKK',
					'amount'          => $amount,
					'method'          => 'bank',
					'details'         => array(
						'registration' => '1234',
						'account'      => $account,
					),
					'idempotency_key' => $key,
				),
				$change
			)
		);
	}

	/**
	 * `POST /me/payouts/{id}/cancel`.
	 *
	 * @param int $id Payout id.
	 */
	private function cancel( int $id ): WP_REST_Response {
		return $this->post( self::ROUTE . '/' . $id . '/cancel', array() );
	}

	/**
	 * A JSON POST.
	 *
	 * @param string               $route Route.
	 * @param array<string, mixed> $body  Body.
	 */
	private function post( string $route, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * A GET below the route.
	 *
	 * @param string               $suffix Suffix ('' or '/balance').
	 * @param array<string, mixed> $query  Query parameters.
	 */
	private function get( string $suffix = '', array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE . $suffix );
		$request->set_query_params( $query );

		return rest_do_request( $request );
	}

	/**
	 * Sales of 100.00 incl. VAT for the creator of a user (payout 20.00 each).
	 *
	 * @param int $user_id User.
	 * @param int $count   Number of sales.
	 */
	private function earn( int $user_id, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->order( $user_id, $this->next_order++, true );
		}
	}

	/**
	 * One order with one line of the creator's product.
	 *
	 * @param int  $user_id  User of the creator.
	 * @param int  $order_id Order id.
	 * @param bool $snapshot Whether the line has its share snapshot.
	 */
	private function order( int $user_id, int $order_id, bool $snapshot ): void {
		$creator = $this->creator_id( $user_id );
		$product = $this->posts->add( 'MB-PAY product', 'publish', array( (string) $creator ) );
		$this->posts->meta( $product, '_regular_price', '100' );
		$this->posts->meta( $product, '_price', '100' );
		$item = 1000 + $order_id;

		if ( $snapshot ) {
			$this->recorder->record( $item, $order_id, $product, 0, 'DKK' );
		}

		$this->reader->orders[ $order_id ] = array(
			'status'    => 'processing',
			'currency'  => 'DKK',
			'date_paid' => '2026-09-01 10:05:00',
			'lines'     => array( new OrderLineSnapshot( $item, $product, 0, 'MB-PAY line', 1, SalesAmount::parse( '80' ), $creator, SalesAmount::parse( '20' ) ) ),
			'refunds'   => array(),
		);
		$this->sync->sync( $order_id );
	}

	/**
	 * An active creator with a linked user, 20 % markup and VAT registered.
	 *
	 * @param string        $login Login.
	 * @param string        $name  Display name.
	 * @param CreatorStatus $status Status.
	 */
	private function creator_user( string $login, string $name, CreatorStatus $status = CreatorStatus::Active ): int {
		$user = $this->create_user( $login );
		wp_set_current_user( 1 );
		$creator = $this->creators->create( $name, $status, $user );
		( new CreatorMarkupRepository( $this->wpdb ) )->save( $creator->id, new MarkupBps( 2000 ) );
		( new CreatorVatRepository( $this->wpdb ) )->save( $creator->id, true );

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
	 * A user of a kind without creator access (0 = logged out).
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
				$user = $this->creator_user( 'no_capability', 'No Cap' );
				( new WP_User( $user ) )->remove_role( Capabilities::CREATOR_ROLE );
				return $user;
			default:
				return 0;
		}
	}
}
