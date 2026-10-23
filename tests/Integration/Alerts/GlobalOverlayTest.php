<?php
/**
 * Integration tests for the global alert overlay.
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
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\GlobalOverlay\AdminGlobalOverlayController;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlay;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayFeed;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayImages;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayPage;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayRoute;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayService;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlaySounds;
use MerchBoard\Modules\Alerts\GlobalOverlay\GlobalOverlayStore;
use MerchBoard\Modules\Alerts\Images\AlertImageException;
use MerchBoard\Modules\Alerts\Images\AlertImageLibrary;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertRenderer;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Overlay\OverlayAssets;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlayPage;
use MerchBoard\Modules\Alerts\Overlay\OverlayRoute;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundException;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Step 25.9.4 (ADR-065 pkt. 7): the global overlay shows every creator's
 * alerts and is totally isolated from the creators' overlays: its own
 * option, id, route, feed, test queue, sounds and images, and its own admin
 * routes (`merchboard_manage_alerts`).
 */
#[CoversClass( GlobalOverlayStore::class )]
#[CoversClass( GlobalOverlayFeed::class )]
#[CoversClass( GlobalOverlayService::class )]
#[CoversClass( GlobalOverlaySounds::class )]
#[CoversClass( GlobalOverlayImages::class )]
#[CoversClass( AdminGlobalOverlayController::class )]
#[CoversClass( GlobalOverlayPage::class )]
#[CoversClass( GlobalOverlayRoute::class )]
#[CoversClass( OverlayTestQueue::class )]
final class GlobalOverlayTest extends IntegrationTestCase {

	private const ROUTE = '/merchboard/v1/admin/alerts/global-overlay';

	/**
	 * The product category the sounds test uses.
	 */
	private const HOODIES = 77;

	private AlertEventRepository $events;
	private GlobalOverlayStore $store;
	private GlobalOverlayFeed $feed;
	private GlobalOverlayService $service;
	private GlobalOverlaySounds $sounds;
	private GlobalOverlayImages $images;
	private AlertOverlayRepository $overlays;

	/**
	 * The REST routes' registration.
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$audit          = $this->service( AuditTrail::class );
		$transaction    = $this->service( Transaction::class );
		$this->events   = new AlertEventRepository( $this->wpdb );
		$this->overlays = new AlertOverlayRepository( $this->wpdb );
		$this->store    = new GlobalOverlayStore();
		$this->sounds   = new GlobalOverlaySounds( $this->store, AlertSoundsTest::sound_files(), AlertSoundsTest::library( $audit, $transaction ), static fn(): array => array( self::HOODIES => 'Hoodies' ), static fn(): array => array() );
		$this->images   = new GlobalOverlayImages( $this->store, AlertImagesTest::image_files(), new AlertImageLibrary( AlertImagesTest::image_files(), $audit, $transaction ) );
		$this->feed     = new GlobalOverlayFeed( $this->store, $this->events, new AlertRenderer( new AlertTemplate( static fn(): ?string => null ), static fn(): string => '' ), new AlertsAvailability( static fn(): bool => false ), new OverlayTestQueue( 'global' ), $this->sounds, static function (): void {} );
		$this->service  = new GlobalOverlayService( $this->store, $this->feed, new OverlayTestQueue( 'global' ), $this->events, $audit, null, $this->images );

		$controller     = new AdminGlobalOverlayController( $this->service, new AlertsAvailability( static fn(): bool => false ), $this->service( Logger::class ), null, $this->sounds, $this->images );
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

		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $attachment ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
			wp_delete_attachment( (int) $attachment, true );
		}

		( new OverlayTestQueue( 'global' ) )->clear( GlobalOverlay::QUEUE );
		( new OverlayTestQueue() )->clear( GlobalOverlay::QUEUE );
		delete_option( GlobalOverlayStore::OPTION );
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_it_shows_every_creators_alerts_only_while_on_and_never_answers_a_creators_id(): void {
		$overlay = $this->service->create();
		self::assertFalse( $overlay->enabled, 'Off by default.' );
		self::assertTrue( OverlayId::valid( $overlay->overlay_id ) );
		self::assertSame( $overlay->overlay_id, $this->service->create()->overlay_id, 'Set up once.' );
		self::assertSame( array( 'alert_global_overlay_created' ), $this->audit_actions() );

		self::assertNull( $this->feed->active( $overlay->overlay_id ), 'Off.' );
		self::assertTrue( $this->service->set_enabled( true ) );
		self::assertFalse( $this->service->set_enabled( true ), 'Unchanged.' );
		self::assertNull( $this->feed->active( $overlay->overlay_id ), 'Alerts are switched off.' );
		$this->alerts_on();
		self::assertNotNull( $this->feed->active( $overlay->overlay_id ) );

		// A creator's overlay never opens the global feed, and the global id never a creator's.
		$creators = new CreatorRepository( $this->wpdb );
		$nova     = $creators->create( 'Nova', CreatorStatus::Active )->id;
		$orion    = $creators->create( 'Orion', CreatorStatus::Active )->id;
		$creator  = $this->overlays->create( $nova, OverlayId::generate(), OverlaySettings::defaults() );
		self::assertNull( $this->feed->active( $creator->overlay_id ) );
		self::assertNull( $this->creator_feed()->active_overlay( $overlay->overlay_id ) );

		$this->alert( $nova, 10, 101, 'Cap' );
		$first = $this->feed->read( $overlay->overlay_id, null, false );
		self::assertNotNull( $first );
		self::assertSame( array(), $first['alerts'], 'Only alerts from now on.' );
		$this->alert( $nova, 11, 111, 'Hoodie' );
		$this->alert( $orion, 12, 121, 'Poster' );
		$next = $this->feed->read( $overlay->overlay_id, $first['cursor'], false );
		self::assertSame( array( 'Hoodie was purchased by nova_fan!', 'Poster was purchased by nova_fan!' ), array_column( $next['alerts'] ?? array(), 'text' ), 'Every creator\'s alerts.' );

		$regenerated = $this->service->regenerate();
		self::assertNotNull( $regenerated );
		self::assertNotSame( $overlay->overlay_id, $regenerated->overlay_id );
		self::assertNull( $this->feed->active( $overlay->overlay_id ), 'The old URL stops at once.' );
		self::assertSame( $creator->overlay_id, $this->overlays->find_for_creator( $nova )?->overlay_id, 'A creator\'s overlay is untouched.' );

		$this->service->set_enabled( false );
		self::assertNull( $this->feed->active( $regenerated->overlay_id ) );
		self::assertSame( array( 'alert_global_overlay_created', 'alert_global_overlay_toggled', 'alert_global_overlay_id_regenerated', 'alert_global_overlay_toggled' ), $this->audit_actions() );
		self::assertSame( 1, $this->count_rows( Tables::ALERT_OVERLAYS ), 'No row in the creators\' table.' );
	}

	public function test_test_alerts_and_replays_go_only_to_its_own_queue(): void {
		$this->service->create();
		$creator_queue = new OverlayTestQueue();
		$creator_queue->push(
			GlobalOverlay::QUEUE,
			array(
				'id'        => 0,
				'image'     => '',
				'text'      => 'A creator\'s test',
				'secondary' => '',
				'sound'     => '',
			)
		);

		self::assertTrue( $this->service->send_test() );
		self::assertSame( 0, $this->service->replay_latest(), 'No order yet.' );
		$global = ( new OverlayTestQueue( 'global' ) )->drain( GlobalOverlay::QUEUE );
		self::assertSame( array( 'Test product was purchased by TestFan!' ), array_column( $global, 'text' ) );

		$this->alert( 3, 20, 201, 'Cap' );
		$this->alert( 4, 20, 202, 'Mug' );
		self::assertSame( 2, $this->service->replay_latest() );
		self::assertSame( array( 'Cap was purchased by nova_fan!', 'Mug was purchased by nova_fan!' ), array_column( ( new OverlayTestQueue( 'global' ) )->drain( GlobalOverlay::QUEUE ), 'text' ) );
		self::assertSame( array( 'A creator\'s test' ), array_column( $creator_queue->drain( GlobalOverlay::QUEUE ), 'text' ), 'The same row number of a creator\'s overlay keeps its own queue.' );
	}

	public function test_sounds_and_images_are_its_own_or_the_librarys_never_a_creators(): void {
		$this->service->create();

		$overlay = $this->sounds->upload( 0, AlertSoundsTest::wav_upload( 1 ) );
		self::assertNotNull( $overlay );
		$own = $overlay->settings->to_array()['audio_default_sound'];
		self::assertSame( '1', get_post_meta( $own, GlobalOverlaySounds::OWNER_META, true ) );
		self::assertSame( '', get_post_meta( $own, AlertSounds::OWNER_META, true ) );
		self::assertTrue( $overlay->settings->to_array()['audio_enabled'], 'The first sound switches sounds on.' );
		self::assertNotNull( $this->sounds->upload( self::HOODIES, AlertSoundsTest::wav_upload( 1, 'hoodie.wav' ) ) );
		self::assertSame( array( self::HOODIES ), array_column( $this->sounds->describe( $this->found() )['categories'], 'term_id' ), 'Every product category.' );

		// A creator's sound: neither chosen nor played, and never deleted.
		$creators = AlertSoundsTest::sound_files()->store( AlertSoundsTest::wav_upload( 1, 'creator.wav' ), AlertSounds::OWNER_META, 7 );

		foreach ( array( fn() => $this->sounds->choose( 0, $creators ), fn() => $this->sounds->upload( 99, AlertSoundsTest::wav_upload( 1 ) ) ) as $refused ) {
			try {
				$refused();
				self::fail( 'Expected AlertSoundException.' );
			} catch ( AlertSoundException $e ) {
				self::assertContains( $e->reason(), array( AlertSoundException::UNKNOWN_SOUND, AlertSoundException::UNKNOWN_CATEGORY ) );
			}
		}

		$values                        = $this->found()->settings->to_array();
		$values['audio_default_sound'] = $creators;
		$tampered                      = $this->store->update_settings( $this->found(), OverlaySettings::from_array( $values ) );
		self::assertNull( $this->sounds->describe( $tampered )['default'], 'A creator\'s sound is not the global overlay\'s.' );
		$this->sounds->remove( 0 );
		self::assertNotNull( get_post( $creators ), 'A creator\'s file is never deleted.' );

		// Images: its own, never a creator's.
		$image = $this->images->upload( AlertImagesTest::upload( AlertImagesTest::png( 8, 4 ) ) );
		self::assertTrue( GlobalOverlayImages::owns( $image->attachment_id ) );
		self::assertSame( array( $image->attachment_id ), array_column( $this->images->describe()['own'], 'id' ) );
		$creator_image = AlertImagesTest::image_files()->store( AlertImagesTest::upload( AlertImagesTest::png( 8, 4 ), 'creator.png' ), AlertImages::OWNER_META, 7 );

		try {
			$this->images->remove( $creator_image );
			self::fail( 'Expected AlertImageException.' );
		} catch ( AlertImageException $e ) {
			self::assertSame( AlertImageException::UNKNOWN_IMAGE, $e->reason() );
		}

		$fill    = array(
			'type'   => 'image',
			'preset' => 'gold',
			'image'  => $creator_image,
			'motion' => 'none',
		);
		$cleaned = $this->images->clean(
			array(
				'main_text'      => $fill,
				'secondary_text' => array_merge( $fill, array( 'image' => $image->attachment_id ) ),
			)
		);
		self::assertSame( array( 0, $image->attachment_id ), array( $cleaned['main_text']['image'], $cleaned['secondary_text']['image'] ) );
		self::assertNotNull( get_post( $creator_image ) );
	}

	public function test_the_admin_routes_need_the_alert_capability_and_answer_like_a_creators(): void {
		wp_set_current_user( $this->create_user( 'global-subscriber' ) );
		self::assertSame( 403, $this->call( 'GET', self::ROUTE )->get_status() );
		wp_set_current_user( 1 );

		self::assertNull( $this->data( $this->call( 'GET', self::ROUTE ) )['overlay'] );
		self::assertSame( 404, $this->call( 'POST', self::ROUTE . '/test' )->get_status(), 'Nothing before the set up.' );

		$created = $this->data( $this->call( 'POST', self::ROUTE ) )['overlay'];
		self::assertIsArray( $created );
		self::assertFalse( $created['enabled'] );
		self::assertStringContainsString( 'global', (string) $created['url'] );
		self::assertSame( array( 'url', 'settings', 'font_approved', 'layout', 'audio', 'images', 'enabled' ), array_keys( $created ) );

		$patched = $this->data( $this->call( 'PATCH', self::ROUTE, array( 'template' => 'Thanks $UserName!' ) ) )['overlay'];
		self::assertIsArray( $patched );
		self::assertSame( 'Thanks $UserName!', $patched['settings']['template'] ?? null );
		self::assertTrue( $this->data( $this->call( 'POST', self::ROUTE . '/enabled', array( 'enabled' => true ) ) )['overlay']['enabled'] ?? null );
		self::assertSame( 400, $this->call( 'POST', self::ROUTE . '/enabled', array( 'enabled' => 'yes' ) )->get_status() );
		self::assertSame( 404, $this->call( 'POST', self::ROUTE . '/replay' )->get_status(), 'No order yet.' );
		self::assertSame( array( 'sent' => true ), $this->data( $this->call( 'POST', self::ROUTE . '/test' ) ) );
		self::assertSame( 0, $this->count_rows( Tables::ALERT_OVERLAYS ) );
	}

	public function test_the_page_answers_only_the_active_global_id_with_its_own_feed(): void {
		$overlay = $this->service->create();
		$root    = dirname( __DIR__, 3 ) . '/';
		$shared  = new OverlayPage( new OverlayRoute( true ), $this->creator_feed(), new OverlayAssets( $root, plugin_dir_url( $root . 'merchboard.php' ), new RecordingLogger() ), new RecordingLogger() );
		$page    = new GlobalOverlayPage( new GlobalOverlayRoute( true ), $this->feed, $shared, new RecordingLogger(), $this->images );

		self::assertNull( $page->render( $overlay->overlay_id, 'nonce123' ), 'Off.' );
		$this->service->set_enabled( true );
		$this->alerts_on();
		$html = (string) $page->render( $overlay->overlay_id, 'nonce123' );
		self::assertStringContainsString( 'alerts/global-overlay/' . $overlay->overlay_id . '/feed', $html );
		self::assertNull( $page->render( OverlayId::generate(), 'nonce123' ) );

		self::assertSame( 1, preg_match( '#' . GlobalOverlayRoute::LEGACY_REGEX . '#', 'ccmerch-alert/overlay/' . $overlay->overlay_id . '/' ), 'MerchAlert\'s URL (ADR-065 pkt. 5).' );
		self::assertSame( 0, preg_match( '#' . GlobalOverlayRoute::REGEX . '#', 'merchboard-alerts/overlay/' . $overlay->overlay_id . '/' ), 'Not a creator\'s URL.' );
	}

	/**
	 * Switches purchase alerts on.
	 */
	private function alerts_on(): void {
		Settings::save( array_merge( Settings::all(), array( Settings::ALERTS_ENABLED => true ) ) );
	}

	/**
	 * The global overlay (set up).
	 */
	private function found(): GlobalOverlay {
		$overlay = $this->store->find();
		self::assertNotNull( $overlay );

		return $overlay;
	}

	/**
	 * A creator's overlay feed.
	 */
	private function creator_feed(): OverlayFeed {
		return new OverlayFeed( $this->overlays, $this->events, new CreatorRepository( $this->wpdb ), new AlertTemplate( static fn(): ?string => null ), static fn(): string => '', static function (): void {}, new OverlayTestQueue() );
	}

	/**
	 * Stores an alert.
	 *
	 * @param int    $creator_id Creator.
	 * @param int    $order_id   Order.
	 * @param int    $item_id    Line.
	 * @param string $product    Product name.
	 */
	private function alert( int $creator_id, int $order_id, int $item_id, string $product ): void {
		$this->events->insert( new AlertEvent( $creator_id, $order_id, (string) $order_id, $item_id, 5, 0, $product, '', 1, 0, array(), 'nova_fan', new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) );
	}

	/**
	 * The audit actions, oldest first.
	 *
	 * @return list<string>
	 */
	private function audit_actions(): array {
		return array_map( 'strval', array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );
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
	 * The data of a 200 answer.
	 *
	 * @param WP_REST_Response $response Answer.
	 * @return array<mixed>
	 */
	private function data( WP_REST_Response $response ): array {
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );

		return $data;
	}
}
