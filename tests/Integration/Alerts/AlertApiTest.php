<?php
/**
 * Integration tests for the alert API for the creators' own bots.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Admin\AlertApiActions;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Api\AlertApiAccess;
use MerchBoard\Modules\Alerts\Api\AlertApiEvents;
use MerchBoard\Modules\Alerts\Api\AlertApiException;
use MerchBoard\Modules\Alerts\Api\AlertApiFeed;
use MerchBoard\Modules\Alerts\Api\AlertApiFeedController;
use MerchBoard\Modules\Alerts\Api\AlertApiKeyRepository;
use MerchBoard\Modules\Alerts\Api\AlertApiKeys;
use MerchBoard\Modules\Alerts\Api\AlertApiQueue;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\History\AlertHistory;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;
use MerchBoard\Modules\Alerts\Rest\MeAlertsApiController;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDeliveryRepository;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Step 25.12.1 (ADR-073): creators make API keys (shown once, stored as an
 * HMAC), bots poll their own alerts with a cursor, tests and resends are
 * marked, and the API only answers while the administrator has it on and
 * alerts are sent.
 */
#[CoversClass( AlertApiKeys::class )]
#[CoversClass( AlertApiKeyRepository::class )]
#[CoversClass( AlertApiFeed::class )]
#[CoversClass( AlertApiFeedController::class )]
#[CoversClass( AlertApiEvents::class )]
#[CoversClass( AlertApiQueue::class )]
#[CoversClass( AlertApiSwitch::class )]
#[CoversClass( MeAlertsApiController::class )]
#[CoversClass( AlertApiActions::class )]
final class AlertApiTest extends IntegrationTestCase {

	private AlertApiSwitch $api;

	private AlertApiKeys $keys;

	private AlertApiFeed $feed;

	private AlertApiAccess $access;

	private AlertEventRepository $events;

	private int $user;

	private int $creator;

	/**
	 * Registers the routes on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		Settings::save( array_merge( Settings::all(), array( Settings::ALERTS_ENABLED => true ) ) );

		$audit        = $this->service( AuditTrail::class );
		$transaction  = $this->service( Transaction::class );
		$repository   = new AlertApiKeyRepository( $this->wpdb );
		$overlays     = new AlertOverlayRepository( $this->wpdb );
		$this->events = new AlertEventRepository( $this->wpdb );
		$this->api    = new AlertApiSwitch( $audit, $transaction );
		$this->access = new AlertApiAccess( new CreatorMetaStore( $this->wpdb ), new CreatorRepository( $this->wpdb ), $audit, $transaction );
		$this->keys   = new AlertApiKeys( $repository, $this->api, $audit, $transaction, $this->access );
		$template     = new AlertTemplate( static fn( int $term_id ): ?string => 77 === $term_id ? 'Nova merch' : null );
		$overlay_feed = new OverlayFeed( $overlays, $this->events, new CreatorRepository( $this->wpdb ), $template, static fn(): string => '', static function (): void {}, new OverlayTestQueue() );
		$this->feed   = new AlertApiFeed(
			$this->api,
			new AlertsAvailability( static fn(): bool => false ),
			new CreatorRepository( $this->wpdb ),
			$this->events,
			new AlertApiEvents( $template ),
			new AlertApiQueue( $repository ),
			new AlertOverlays( $overlays, $overlay_feed, new OverlayTestQueue(), new PostMetaProductStore(), $audit, $transaction, static fn(): int => 0 ),
			$this->access
		);

		wp_set_current_user( 1 );
		$this->api->set( true );
		$this->user    = $this->create_user( 'nova_api' );
		$this->creator = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->user )->id;
		$this->access->set( $this->creator, true, false );
		$this->routes();
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		delete_option( AlertApiSwitch::OPTION );
		// setUp switched alerts on; later tests expect the default.
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_a_key_is_shown_once_stored_as_an_hmac_and_limited_per_creator(): void {
		wp_set_current_user( $this->user );
		$made = $this->call( 'POST', '/merchboard/v1/me/alerts/api/keys', array( 'name' => 'Discord bot' ) );

		self::assertSame( 201, $made->get_status() );
		$data = $this->data( $made );
		self::assertMatchesRegularExpression( '/^mbk_[0-9a-f]{12}_[0-9a-f]{48}$/', $data['key'] );
		self::assertSame( 'Discord bot', $data['keys'][0]['name'] );
		self::assertSame( substr( $data['key'], 0, 16 ) . '_…', $data['keys'][0]['hint'] );
		self::assertNull( $data['keys'][0]['last_used_at'] );

		$row = $this->rows( Tables::ALERT_API_KEYS )[0];
		self::assertSame( substr( $data['key'], 4, 12 ), $row['lookup'] );
		self::assertSame( AlertApiKeys::hash( substr( $data['key'], 17 ) ), $row['secret_hash'] );
		self::assertStringNotContainsString( substr( $data['key'], 17 ), (string) wp_json_encode( $this->rows( Tables::ALERT_API_KEYS ) ), 'Never the secret.' );

		$listed = $this->data( $this->call( 'GET', '/merchboard/v1/me/alerts/api' ) );
		self::assertArrayNotHasKey( 'key', $listed, 'Shown once.' );
		self::assertTrue( $listed['enabled'] );
		self::assertStringEndsWith( '/merchboard/v1/alerts/api/events', $listed['endpoint'] );

		self::assertSame( 400, $this->call( 'POST', '/merchboard/v1/me/alerts/api/keys', array( 'name' => '' ) )->get_status() );

		for ( $i = 2; $i <= AlertApiKeys::MAX_KEYS; $i++ ) {
			self::assertSame( 201, $this->call( 'POST', '/merchboard/v1/me/alerts/api/keys', array( 'name' => 'Bot ' . $i ) )->get_status() );
		}

		self::assertSame( 409, $this->call( 'POST', '/merchboard/v1/me/alerts/api/keys', array( 'name' => 'One too many' ) )->get_status() );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( AlertApiKeys::MAX_KEYS, count( array_filter( $audit, static fn( array $row ): bool => 'alert_api_key_created' === $row['action'] ) ) );
		self::assertStringNotContainsString( substr( $data['key'], 17 ), (string) wp_json_encode( $audit ), 'The audit never has the key.' );
	}

	public function test_a_bot_polls_its_creators_alerts_with_a_cursor(): void {
		$orion = ( new CreatorRepository( $this->wpdb ) )->create( 'Orion', CreatorStatus::Active )->id;
		$old   = $this->alert( $this->creator, 100, 1 );
		$key   = $this->keys->create( $this->creator, 'Bot' );

		$start = $this->poll( $key );
		self::assertSame( 200, $start->get_status() );
		self::assertSame( 'no-store, max-age=0', $start->get_headers()['Cache-Control'] ?? null );
		self::assertSame(
			array(
				'cursor' => $old,
				'events' => array(),
			),
			$this->data( $start ),
			'Without a cursor it starts from now.'
		);

		$first  = $this->alert( $this->creator, 101, 2, 'Hoodie', 'Size: L', 2 );
		$second = $this->alert( $this->creator, 101, 3, 'Mug' );
		$this->alert( $orion, 101, 4, 'Orion tee' );

		$data = $this->data( $this->poll( $key, $old ) );
		self::assertSame( $second, $data['cursor'] );
		self::assertSame( array( $first, $second ), array_column( $data['events'], 'id' ), 'Only Nova\'s, oldest first.' );
		self::assertSame(
			array(
				'id'         => $first,
				'kind'       => 'purchase',
				'created_at' => '2026-10-23T18:00:00+00:00',
				'order_ref'  => AlertApiEvents::order_ref( $this->creator, 101 ),
				'username'   => 'nova_fan',
				'product'    => 'Hoodie',
				'quantity'   => 2,
				'category'   => 'Nova merch',
				'variation'  => 'Size: L',
				'price'      => '199.00',
				'line_total' => '398.00',
				'total'      => '548.00',
				'currency'   => 'DKK',
			),
			$data['events'][0]
		);
		self::assertSame( $data['events'][0]['order_ref'], $data['events'][1]['order_ref'], 'One order, one reference.' );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $data['events'][0]['order_ref'] );
		self::assertNotSame( AlertApiEvents::order_ref( $orion, 101 ), $data['events'][0]['order_ref'], 'Different for every creator.' );
		self::assertSame( array(), $this->data( $this->poll( $key, $second ) )['events'], 'Never twice.' );
		self::assertCount( 1, $this->data( $this->poll( $key, $old, 1 ) )['events'], 'The limit.' );

		$row = $this->rows( Tables::ALERT_API_KEYS )[0];
		self::assertNotNull( $row['last_used_at'] );
	}

	public function test_wrong_revoked_and_inactive_keys_get_401_and_the_switches_503(): void {
		$key = $this->keys->create( $this->creator, 'Bot' );

		self::assertSame( 401, $this->poll( '' )->get_status() );
		self::assertSame( 401, $this->poll( substr( $key, 0, -1 ) . ( str_ends_with( $key, '0' ) ? '1' : '0' ) )->get_status() );
		$request = new WP_REST_Request( 'GET', '/merchboard/v1/alerts/api/events' );
		$request->set_query_params( array( 'api_key' => $key ) );
		self::assertSame( 401, rest_do_request( $request )->get_status(), 'Never from the URL.' );

		$request = new WP_REST_Request( 'GET', '/merchboard/v1/alerts/api/events' );
		$request->set_header( AlertApiFeedController::KEY_HEADER, $key );
		self::assertSame( 200, rest_do_request( $request )->get_status(), 'The fallback header.' );
		$request->set_header( 'Authorization', 'Basic ' . base64_encode( 'site:password' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A Basic auth header.
		self::assertSame( 200, rest_do_request( $request )->get_status(), 'The fallback header also behind Basic auth.' );

		wp_set_current_user( 1 );
		$this->service( CreatorService::class )->set_status( $this->creator, CreatorStatus::Inactive );
		self::assertSame( 401, $this->poll( $key )->get_status(), 'An inactive creator.' );
		$this->service( CreatorService::class )->set_status( $this->creator, CreatorStatus::Active );
		self::assertSame( 200, $this->poll( $key )->get_status() );

		$this->api->set( false );
		self::assertSame( 503, $this->poll( $key )->get_status(), 'The administrator\'s switch.' );
		$this->api->set( true );

		Settings::save( array_merge( Settings::all(), array( Settings::ALERTS_ENABLED => false ) ) );
		self::assertSame( 503, $this->poll( $key )->get_status(), 'The kill switch.' );
		Settings::save( array_merge( Settings::all(), array( Settings::ALERTS_ENABLED => true ) ) );

		$keys = $this->keys->for_creator( $this->creator );
		wp_set_current_user( $this->user );
		self::assertSame( 404, $this->call( 'DELETE', '/merchboard/v1/me/alerts/api/keys/999999' )->get_status() );
		self::assertSame( 200, $this->call( 'DELETE', '/merchboard/v1/me/alerts/api/keys/' . $keys[0]->id )->get_status() );
		self::assertSame( 401, $this->poll( $key )->get_status(), 'Revoked.' );
		self::assertSame( array(), $this->keys->for_creator( $this->creator ) );
	}

	public function test_without_access_the_keys_stop_working_but_are_kept(): void {
		$key = $this->keys->create( $this->creator, 'Bot' );
		self::assertSame( 200, $this->poll( $key )->get_status() );

		$this->access->set( $this->creator, false, false );
		self::assertSame( 401, $this->poll( $key )->get_status(), 'The same answer as a wrong key.' );
		self::assertCount( 1, $this->keys->for_creator( $this->creator ), 'Kept.' );

		wp_set_current_user( $this->user );
		$page = $this->data( $this->call( 'GET', '/merchboard/v1/me/alerts/api' ) );
		self::assertSame(
			array(
				'keys'     => false,
				'webhooks' => false,
			),
			$page['access']
		);
		$made = $this->call( 'POST', '/merchboard/v1/me/alerts/api/keys', array( 'name' => 'New' ) );
		self::assertSame( array( 403, 'merchboard_alerts_api_no_access' ), array( $made->get_status(), $made->get_data()['code'] ?? null ) );
		self::assertSame( 403, $this->call( 'POST', '/merchboard/v1/me/alerts/api/test' )->get_status() );

		wp_set_current_user( 1 );
		$this->access->set( $this->creator, true, false );
		self::assertSame( 200, $this->poll( $key )->get_status(), 'Works again without a new key.' );

		$audit = array_column( $this->rows( Tables::AUDIT_LOG ), 'action' );
		self::assertSame( 3, count( array_keys( $audit, 'alert_api_access_changed', true ) ) );

		wp_set_current_user( $this->user );

		try {
			$this->access->set( $this->creator, true, true );
			self::fail( 'A creator cannot give themselves access.' );
		} catch ( AlertApiException $e ) {
			self::assertSame( AlertApiException::NOT_AUTHORIZED, $e->reason() );
		}
	}

	public function test_requests_are_limited_per_key(): void {
		$now     = 1_000_000;
		$limiter = new OverlayRateLimiter( static fn(): int => $now, 'merchboard_alerts_api_rl_', 2 );
		$key     = $this->keys->create( $this->creator, 'Bot' );
		$this->routes( $limiter );

		self::assertSame( array( 200, 200, 429 ), array( $this->poll( $key )->get_status(), $this->poll( $key )->get_status(), $this->poll( $key )->get_status() ) );
	}

	public function test_tests_and_resends_reach_every_key_marked_and_without_moving_the_cursor(): void {
		$first  = $this->keys->create( $this->creator, 'Discord' );
		$second = $this->keys->create( $this->creator, 'Twitch' );
		$alert  = $this->alert( $this->creator, 100, 1 );

		wp_set_current_user( $this->user );
		self::assertSame( array( 'sent' => 2 ), $this->data( $this->call( 'POST', '/merchboard/v1/me/alerts/api/test' ) ) );

		$history = new AlertHistory( $this->events, new AlertOverlayRepository( $this->wpdb ), new OverlayFeed( new AlertOverlayRepository( $this->wpdb ), $this->events, new CreatorRepository( $this->wpdb ), new AlertTemplate( static fn(): ?string => null ), static fn(): string => '', static function (): void {}, new OverlayTestQueue() ), new OverlayTestQueue(), null, $this->feed );
		self::assertSame( 1, $history->resend_alert( $this->creator, $alert ), 'No overlay: the bots get it, and it counts as resent (25.13 review).' );

		$data = $this->data( $this->poll( $first, $alert ) );
		self::assertSame( $alert, $data['cursor'], 'Tests and resends never move the cursor.' );
		self::assertSame( array( 'test', 'resend' ), array_column( $data['events'], 'kind' ) );
		self::assertSame( array( 0, 0 ), array_column( $data['events'], 'id' ) );
		self::assertSame( 'TestFan', $data['events'][0]['username'] );
		self::assertSame( 'Hoodie', $data['events'][1]['product'] );
		self::assertSame( array(), $this->data( $this->poll( $first, $alert ) )['events'], 'Taken once per key.' );
		self::assertSame( array( 'test', 'resend' ), array_column( $this->data( $this->poll( $second ) )['events'], 'kind' ), 'Every key gets them.' );

		wp_set_current_user( 1 );
		$this->api->set( false );
		wp_set_current_user( $this->user );
		self::assertSame( 403, $this->call( 'POST', '/merchboard/v1/me/alerts/api/test' )->get_status() );
		self::assertSame( 403, $this->call( 'POST', '/merchboard/v1/me/alerts/api/keys', array( 'name' => 'Off' ) )->get_status() );
	}

	public function test_the_administrator_switches_the_api_and_revokes_a_creators_keys(): void {
		$this->keys->create( $this->creator, 'One' );
		$this->keys->create( $this->creator, 'Two' );
		$actions = new AlertApiActions( $this->api, $this->keys, $this->service( Logger::class ) );
		$input   = array( '_wpnonce' => wp_create_nonce( AlertApiActions::ACTION ) );

		self::assertStringContainsString( 'api_confirm_required', $actions->process( array_merge( $input, array( 'do' => 'revoke_all', 'creator_id' => (string) $this->creator ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		self::assertCount( 2, $this->keys->for_creator( $this->creator ) );
		self::assertStringContainsString( 'api_keys_revoked', $actions->process( array_merge( $input, array( 'do' => 'revoke_all', 'creator_id' => (string) $this->creator, 'confirm' => '1' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.
		self::assertSame( array(), $this->keys->for_creator( $this->creator ) );

		self::assertStringContainsString( 'api_disabled', $actions->process( array_merge( $input, array( 'do' => 'switch' ) ) ) );
		self::assertFalse( $this->api->enabled() );
		self::assertStringContainsString( 'api_enabled', $actions->process( array_merge( $input, array( 'do' => 'switch', 'enabled' => '1' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short input.

		$actions_audited = array_column( $this->rows( Tables::AUDIT_LOG ), 'action' );
		self::assertSame( 2, count( array_keys( $actions_audited, 'alert_api_key_revoked', true ) ) );
		self::assertContains( 'alert_api_switched', $actions_audited );

		wp_set_current_user( $this->user );

		try {
			$this->keys->revoke_all( $this->creator );
			self::fail( 'A creator cannot revoke as the administrator.' );
		} catch ( AlertApiException $e ) {
			self::assertSame( AlertApiException::NOT_AUTHORIZED, $e->reason() );
		}
	}

	/**
	 * Registers the routes on a fresh REST server.
	 *
	 * @param OverlayRateLimiter|null $limiter Request limit; a generous one by default.
	 */
	private function routes( ?OverlayRateLimiter $limiter = null ): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		$feed           = new AlertApiFeedController( $this->feed, $this->keys, $limiter ?? new OverlayRateLimiter( null, 'merchboard_alerts_api_test_rl_', 1000 ), $this->service( Logger::class ) );
		$audit          = $this->service( AuditTrail::class );
		$transaction    = $this->service( Transaction::class );
		$webhooks       = AlertWebhooksTest::webhooks( $this->wpdb, $this->api, $audit, $transaction );
		$dispatcher     = AlertWebhooksTest::dispatcher( $this->wpdb, $this->api, $webhooks, new FakeJobQueue(), $audit, $transaction, $this->service( Logger::class ) );
		$me             = new MeAlertsApiController( $this->service( CreatorContext::class ), $this->keys, $this->api, $this->feed, $webhooks, $dispatcher, new WebhookDeliveryRepository( $this->wpdb ), $this->access, $this->service( Logger::class ) );
		$this->register = static function () use ( $feed, $me ): void {
			$feed->register_routes();
			$me->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	/**
	 * Stores an alert of an order line (Nova's category, 199.00 each, 548.00 for the creator's order).
	 *
	 * @param int    $creator_id Creator.
	 * @param int    $order_id   Order.
	 * @param int    $item_id    Order line.
	 * @param string $product    Product name.
	 * @param string $variation  Variation.
	 * @param int    $quantity   Quantity.
	 * @return int The alert's id.
	 */
	private function alert( int $creator_id, int $order_id, int $item_id, string $product = 'Hoodie', string $variation = '', int $quantity = 1 ): int {
		$this->events->insert( new AlertEvent( $creator_id, $order_id, (string) ( 1000 + $order_id ), $item_id, 70, 0, $product, $variation, $quantity, 0, array( 77 ), 'nova_fan', new DateTimeImmutable( '2026-10-23 18:00:00', new DateTimeZone( 'UTC' ) ), 0, number_format( 199 * $quantity, 4, '.', '' ), '548.0000', 'DKK' ) );

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', $this->table( Tables::ALERT_EVENTS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
	}

	/**
	 * A bot's request.
	 *
	 * @param string   $key   API key ('' for none).
	 * @param int|null $after Cursor.
	 * @param int|null $limit Limit.
	 */
	private function poll( string $key, ?int $after = null, ?int $limit = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/merchboard/v1/alerts/api/events' );

		if ( '' !== $key ) {
			$request->set_header( 'Authorization', 'Bearer ' . $key );
		}

		if ( null !== $after ) {
			$request->set_param( 'after', $after );
		}

		if ( null !== $limit ) {
			$request->set_param( 'limit', $limit );
		}

		return rest_do_request( $request );
	}

	/**
	 * Dispatches a request with a JSON body.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $body   JSON body.
	 */
	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * A response's data as an array.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string, mixed>
	 */
	private function data( WP_REST_Response $response ): array {
		$data = $response->get_data();
		self::assertIsArray( $data );

		/**
		 * The answer, checked above.
		 *
		 * @var array<string, mixed> $data
		 */
		return $data;
	}
}
