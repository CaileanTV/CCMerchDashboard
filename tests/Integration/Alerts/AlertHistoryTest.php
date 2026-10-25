<?php
/**
 * Integration tests for the alert history, resend and "Stop all alerts".
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\History\AlertHistory;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlay;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;
use MerchBoard\Modules\Alerts\Rest\MeAlertsHistoryController;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Phase 25 step 7: the history lists only the creator's own alerts by
 * order, resends go through the overlay's queue, and "Stop all alerts"
 * clears the queue and makes the open overlay reload at once.
 */
#[CoversClass( AlertHistory::class )]
#[CoversClass( MeAlertsHistoryController::class )]
#[CoversClass( AlertEventRepository::class )]
#[CoversClass( OverlayTestQueue::class )]
#[CoversClass( AlertOverlayRepository::class )]
#[CoversClass( OverlayFeed::class )]
final class AlertHistoryTest extends IntegrationTestCase {

	private AlertOverlayRepository $overlays;

	private AlertEventRepository $events;

	private OverlayTestQueue $queue;

	/**
	 * Called once per long-poll second of the feed under test.
	 *
	 * @var (callable(): void)|null
	 */
	private $on_sleep = null;

	/**
	 * Registers the routes on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->overlays = new AlertOverlayRepository( $this->wpdb );
		$this->events   = new AlertEventRepository( $this->wpdb );
		$this->queue    = new OverlayTestQueue();
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		parent::tearDown();
	}

	public function test_the_history_lists_only_the_creators_orders_newest_first(): void {
		$nova  = $this->creator( 'Nova' );
		$orion = $this->creator( 'Orion' );

		$this->alert( $nova, 100, 1 );
		$this->alert( $orion, 100, 2, 'Orion tee' );
		$this->alert( $nova, 100, 3, 'Mug', 'Colour: Red' );

		for ( $order = 101; $order <= 111; $order++ ) {
			$this->alert( $nova, $order, $order * 10 );
		}

		$history = $this->history();
		self::assertSame( 12, $history->count( $nova ) );
		self::assertSame( 1, $history->count( $orion ) );

		$first = $history->page( $nova, new Pagination( 1, 10 ) );
		self::assertCount( 10, $first );
		self::assertSame( 111, $first[0]['order_id'], 'Newest first.' );

		$last = $history->page( $nova, new Pagination( 2, 10 ) );
		self::assertSame( array( 101, 100 ), array_column( $last, 'order_id' ) );
		self::assertSame( array( 'Hoodie', 'Mug' ), array_column( $last[1]['alerts'], 'product' ), 'Only Nova\'s lines of the shared order.' );
		self::assertSame( 'Colour: Red', $last[1]['alerts'][1]['variation'] );
		self::assertSame( 'nova_fan', $last[1]['username'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $last[1]['created_at'] );
		self::assertSame( array( 'order_id', 'order_number', 'username', 'created_at', 'alerts' ), array_keys( $last[1] ), 'Only what an alert shows.' );
	}

	public function test_imported_alerts_stay_in_the_history_but_never_reach_the_live_feeds(): void {
		$nova = $this->creator( 'Nova' );
		$live = $this->alert( $nova, 120, 1 );
		$this->events->insert( new AlertEvent( $nova, 121, '1121', 2, 70, 0, 'Old tee', '', 1, 0, array(), 'old_fan', new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ), true );

		self::assertSame( 2, $this->history()->count( $nova ), 'The history keeps alerts imported from MerchAlert (25.13 review).' );
		self::assertSame( array( $live ), array_map( static fn( AlertEvent $event ): int => $event->id, $this->events->after( $nova, 0, 50 ) ), 'An open overlay or an API bot never shows them as new purchases.' );
		self::assertSame( array( $live ), array_map( static fn( AlertEvent $event ): int => $event->id, $this->events->after_all( 0, 50 ) ), 'Nor the global overlay.' );
	}

	public function test_resends_go_to_the_creators_overlay_and_never_reach_other_alerts(): void {
		$nova  = $this->creator( 'Nova' );
		$orion = $this->creator( 'Orion' );
		$own   = $this->alert( $nova, 100, 1 );
		$this->alert( $nova, 100, 2, 'Mug' );
		$theirs  = $this->alert( $orion, 100, 3 );
		$history = $this->history();

		self::assertNull( $history->resend_alert( $nova, $own ), 'Set up the overlay first.' );

		$overlay = $this->overlay( $nova );
		self::assertSame( 1, $history->resend_alert( $nova, $own ) );
		self::assertSame( 0, $history->resend_alert( $nova, $theirs ), 'Orion\'s alert.' );
		self::assertSame( 0, $history->resend_alert( $nova, 999999 ) );
		self::assertSame( 2, $history->resend_order( $nova, 100 ), 'Only Nova\'s lines of the order.' );
		self::assertSame( 0, $history->resend_order( $nova, 555 ) );

		$queued = $this->queue->drain( $overlay->id );
		self::assertCount( 3, $queued );
		self::assertSame( array( 0, 0, 0 ), array_column( $queued, 'id' ), 'Queued like test alerts, without moving the cursor.' );
		self::assertSame( 'Hoodie for nova_fan', $queued[0]['text'] );
		self::assertSame( 'Mug for nova_fan', $queued[2]['text'] );
	}

	public function test_stop_clears_the_queue_and_the_open_overlay_reloads_at_once(): void {
		$nova    = $this->creator( 'Nova' );
		$overlay = $this->overlay( $nova );
		$this->alert( $nova, 100, 1 );
		$history = $this->history();

		self::assertFalse( $history->stop( $this->creator( 'Orion' ) ), 'Set up the overlay first.' );

		$history->resend_order( $nova, 100 );
		$before = $overlay->version();

		self::assertTrue( $history->stop( $nova ) );
		self::assertSame( array(), $this->queue->drain( $overlay->id ), 'Waiting alerts are dropped.' );

		$after = $this->overlays->find_for_creator( $nova );
		self::assertNotNull( $after );
		self::assertNotSame( $before, $after->version(), 'A new version, also within the same second.' );

		$history->stop( $nova );
		self::assertNotSame( $after->version(), $this->overlays->find_for_creator( $nova )?->version(), 'Every stop gives a new version.' );

		// An open overlay's long-poll answers at once with the new version.
		$feed           = $this->feed();
		$this->on_sleep = static function () use ( $history, $nova ): void {
			$history->stop( $nova );
		};
		$answer         = $feed->read( (string) $this->overlays->find_for_creator( $nova )?->overlay_id, 1, true );

		self::assertNotNull( $answer );
		self::assertSame( array(), $answer['alerts'] );
		self::assertSame( $this->overlays->find_for_creator( $nova )?->version(), $answer['version'] );
	}

	public function test_the_routes_serve_the_history_resend_stop_and_the_preference(): void {
		$user    = $this->create_user( 'nova' );
		$creator = $this->creator( 'Nova', $user );
		$theirs  = $this->alert( $this->creator( 'Orion' ), 100, 9 );
		$own     = $this->alert( $creator, 100, 1 );
		$this->routes();
		wp_set_current_user( $user );

		$list = $this->call( 'GET', '/merchboard/v1/me/alerts/history' );
		self::assertSame( 200, $list->get_status() );
		self::assertSame( '1', $list->get_headers()['X-WP-Total'] ?? null );
		self::assertSame( '1', $list->get_headers()['X-WP-TotalPages'] ?? null );
		self::assertCount( 1, (array) $list->get_data() );

		self::assertSame( 404, $this->call( 'POST', '/merchboard/v1/me/alerts/history/alerts/' . $own . '/resend' )->get_status(), 'Set up the overlay first.' );
		self::assertSame( 404, $this->call( 'POST', '/merchboard/v1/me/alerts/stop' )->get_status() );

		$this->overlay( $creator );
		self::assertSame( array( 'sent' => 1 ), $this->call( 'POST', '/merchboard/v1/me/alerts/history/alerts/' . $own . '/resend' )->get_data() );
		self::assertSame( array( 'sent' => 1 ), $this->call( 'POST', '/merchboard/v1/me/alerts/history/orders/100/resend' )->get_data() );
		self::assertSame( 404, $this->call( 'POST', '/merchboard/v1/me/alerts/history/alerts/' . $theirs . '/resend' )->get_status(), 'Another creator\'s alert.' );
		self::assertSame( array( 'stopped' => true ), $this->call( 'POST', '/merchboard/v1/me/alerts/stop' )->get_data() );

		self::assertSame( array( 'anonymize' => false ), $this->call( 'GET', '/merchboard/v1/me/alerts/preferences' )->get_data() );
		self::assertSame( array( 'anonymize' => true ), $this->call( 'PATCH', '/merchboard/v1/me/alerts/preferences', array( 'anonymize' => true ) )->get_data() );
		self::assertSame( '1', get_user_meta( $user, MeAlertsHistoryController::ANONYMIZE_META, true ), 'Saved for the logged-in user.' );
		self::assertSame( 400, $this->call( 'PATCH', '/merchboard/v1/me/alerts/preferences', array( 'anonymize' => 'yes' ) )->get_status() );
		self::assertSame( array( 'anonymize' => false ), $this->call( 'PATCH', '/merchboard/v1/me/alerts/preferences', array( 'anonymize' => false ) )->get_data() );
		self::assertSame( '', get_user_meta( $user, MeAlertsHistoryController::ANONYMIZE_META, true ), 'Off removes the meta.' );

		wp_set_current_user( 0 );
		self::assertSame( 401, $this->call( 'GET', '/merchboard/v1/me/alerts/history' )->get_status() );
		self::assertSame( 401, $this->call( 'POST', '/merchboard/v1/me/alerts/stop' )->get_status() );
	}

	/**
	 * The service as the module builds it.
	 */
	private function history(): AlertHistory {
		return new AlertHistory( $this->events, $this->overlays, $this->feed(), $this->queue );
	}

	/**
	 * A feed whose long-poll seconds call on_sleep.
	 */
	private function feed(): OverlayFeed {
		return new OverlayFeed(
			$this->overlays,
			$this->events,
			new CreatorRepository( $this->wpdb ),
			new AlertTemplate( static fn(): ?string => null ),
			static fn(): string => '',
			function (): void {
				if ( null !== $this->on_sleep ) {
					( $this->on_sleep )();
				}
			},
			$this->queue
		);
	}

	/**
	 * Registers the routes on a fresh REST server.
	 */
	private function routes(): void {
		$controller     = new MeAlertsHistoryController( $this->service( CreatorContext::class ), $this->history(), $this->service( Logger::class ) );
		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	/**
	 * An active creator, optionally linked to a user.
	 *
	 * @param string $name Name.
	 * @param int    $user User id; 0 for none.
	 */
	private function creator( string $name, int $user = 0 ): int {
		if ( 0 === $user ) {
			return ( new CreatorRepository( $this->wpdb ) )->create( $name, CreatorStatus::Active )->id;
		}

		$current = get_current_user_id();
		wp_set_current_user( 1 );
		$id = $this->service( CreatorService::class )->create( $name, CreatorStatus::Active, $user )->id;
		wp_set_current_user( $current );

		return $id;
	}

	/**
	 * A creator's overlay with "$Product for $UserName".
	 *
	 * @param int $creator_id Creator.
	 */
	private function overlay( int $creator_id ): AlertOverlay {
		$this->overlays->create( $creator_id, OverlayId::generate(), OverlaySettings::from_array( array( 'template' => '$Product for $UserName' ) ) );
		$overlay = $this->overlays->find_for_creator( $creator_id );
		self::assertNotNull( $overlay );

		return $overlay;
	}

	/**
	 * Stores an alert of an order line.
	 *
	 * @param int    $creator_id Creator.
	 * @param int    $order_id   Order.
	 * @param int    $item_id    Order line.
	 * @param string $product    Product name.
	 * @param string $variation  Variation.
	 * @return int The alert's id.
	 */
	private function alert( int $creator_id, int $order_id, int $item_id, string $product = 'Hoodie', string $variation = '' ): int {
		$this->events->insert( new AlertEvent( $creator_id, $order_id, (string) ( 1000 + $order_id ), $item_id, 70, 0, $product, $variation, 1, 0, array(), 'nova_fan', new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) );

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', $this->wpdb->prefix . 'merchboard_alert_events' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
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
}
