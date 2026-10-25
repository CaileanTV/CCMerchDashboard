<?php
/**
 * Integration tests for `/me/alerts/overlay`.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Access\Impersonation;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Images\AlertImageLibrary;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;
use MerchBoard\Modules\Alerts\Rest\MeAlertsController;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Integration\Support\ProductPosts;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ADR-065 pkt. 3 and ADR-007: the creator manages only the own overlay;
 * the secret URL only reaches its owner; a new id kills the old URL; test
 * alerts reach the overlay through the feed.
 */
#[CoversClass( MeAlertsController::class )]
#[CoversClass( AlertOverlays::class )]
#[CoversClass( OverlayTestQueue::class )]
#[CoversClass( AlertSounds::class )]
#[CoversClass( AlertImages::class )]
final class MeAlertsEndpointTest extends IntegrationTestCase {

	private const ROUTE = '/merchboard/v1/me/alerts/overlay';

	private ProductPosts $posts;

	private AlertOverlayRepository $overlays;

	private OverlayFeed $feed;

	/**
	 * Registers the routes on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->posts    = new ProductPosts( $this->wpdb );
		$this->overlays = new AlertOverlayRepository( $this->wpdb );
		$sounds         = AlertSoundsTest::sounds( $this->overlays, AlertSoundsTest::library( $this->service( AuditTrail::class ), $this->service( Transaction::class ) ) );
		$this->feed     = new OverlayFeed( $this->overlays, new AlertEventRepository( $this->wpdb ), new CreatorRepository( $this->wpdb ), new AlertTemplate( static fn(): ?string => null ), static fn(): string => '', static function (): void {}, new OverlayTestQueue(), $sounds );
		$fonts          = new AlertFonts( $this->service( AuditTrail::class ), $this->service( Transaction::class ), static fn(): mixed => null );
		$images         = new AlertImages( $this->overlays, AlertImagesTest::image_files(), new AlertImageLibrary( AlertImagesTest::image_files(), $this->service( AuditTrail::class ), $this->service( Transaction::class ) ) );
		$service        = new AlertOverlays( $this->overlays, $this->feed, new OverlayTestQueue(), new PostMetaProductStore(), $this->service( AuditTrail::class ), $this->service( Transaction::class ), static fn(): int => 0, $fonts, $images );
		$controller     = new MeAlertsController( $this->service( CreatorContext::class ), $service, new AlertsAvailability( static fn(): bool => false ), $this->service( Logger::class ), $fonts, $sounds, $images );
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

		delete_option( AlertFonts::APPROVED_OPTION );

		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $attachment ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
			wp_delete_attachment( (int) $attachment, true );
		}

		$this->posts->remove_all();
		parent::tearDown();
	}

	public function test_set_up_returns_the_url_once_and_is_audited(): void {
		$creator = $this->creator( 'nova' );

		$before = $this->call( 'GET', self::ROUTE );
		self::assertSame( 200, $before->get_status() );
		self::assertNull( $this->data( $before )['overlay'] );
		self::assertFalse( $this->data( $before )['merchalert_active'] );

		self::assertSame( 404, $this->call( 'PATCH', self::ROUTE, array( 'font_size' => 40 ) )->get_status(), 'Nothing to change before the set up.' );
		self::assertSame( 404, $this->call( 'POST', self::ROUTE . '/test' )->get_status() );

		$created = $this->data( $this->call( 'POST', self::ROUTE ) );
		self::assertIsArray( $created['overlay'] );
		self::assertMatchesRegularExpression( '#/merchboard-alerts/overlay/[0-9a-f]{32}/$|merchboard_alert_overlay=[0-9a-f]{32}$#', (string) $created['overlay']['url'] );
		self::assertSame( 32, $created['overlay']['settings']['font_size'] );

		$again = $this->data( $this->call( 'POST', self::ROUTE ) );
		self::assertSame( $created['overlay']['url'], $again['overlay']['url'], 'Setting up again keeps the overlay.' );
		self::assertSame( array( 'alert_overlay_created' ), $this->audit_actions( $creator ) );
		self::assertStringNotContainsString( (string) $this->overlays->find_for_creator( $creator )?->overlay_id, (string) wp_json_encode( $this->rows( Tables::AUDIT_LOG ) ), 'The secret id is never audited.' );
	}

	public function test_an_administrator_viewing_as_the_creator_never_gets_the_secret_url(): void {
		$creator = $this->creator( 'nova' );
		$secret  = (string) $this->data( $this->call( 'POST', self::ROUTE ) )['overlay']['url'];

		wp_set_current_user( 1 );
		$this->service( Impersonation::class )->start( $creator );

		$url = (string) $this->data( $this->call( 'GET', self::ROUTE ) )['overlay']['url'];
		self::assertStringContainsString( MeAlertsController::HIDDEN_OVERLAY_ID, $url, 'The URL\'s shape without the secret id (ADR-075).' );
		self::assertNotSame( $secret, $url );
		delete_user_meta( 1, ImpersonationSession::META );
	}

	public function test_settings_change_only_the_form_fields_and_are_normalized(): void {
		$creator = $this->creator( 'nova' );
		$this->call( 'POST', self::ROUTE );

		$saved = $this->data(
			$this->call(
				'PATCH',
				self::ROUTE,
				array(
					'template'       => 'Thanks $UserName!',
					'font_size'      => 999,
					'text_color'     => '#ABC',
					'show_secondary' => true,
					'text_fit'       => 'shrink',
					'image_url'      => 'https://evil.example/x.png',
				)
			)
		);

		self::assertSame( 'Thanks $UserName!', $saved['overlay']['settings']['template'] );
		self::assertSame( 200, $saved['overlay']['settings']['font_size'], 'Clamped to the maximum.' );
		self::assertSame( '#aabbcc', $saved['overlay']['settings']['text_color'] );
		self::assertTrue( $saved['overlay']['settings']['show_secondary'] );
		self::assertSame( 'shrink', $saved['overlay']['settings']['text_fit'] );

		$animated = $this->data(
			$this->call(
				'PATCH',
				self::ROUTE,
				array(
					'animations' => array(
						'main_text' => array(
							'in'     => 'typewriter',
							'effect' => '<script>',
						),
					),
				)
			)
		)['overlay']['settings']['animations'];
		self::assertSame( 'typewriter', $animated['main_text']['in'] );
		self::assertSame( 'none', $animated['main_text']['effect'], 'Unknown effects become none.' );
		self::assertSame( 'fade', $animated['secondary_text']['in'] );
		self::assertSame( 400, $this->call( 'PATCH', self::ROUTE, array( 'animations' => 'pop' ) )->get_status() );
		self::assertSame( 'fixed', $this->data( $this->call( 'PATCH', self::ROUTE, array( 'text_fit' => 'stretch' ) ) )['overlay']['settings']['text_fit'], 'Unknown modes fall back to fixed.' );

		$stored = $this->overlays->find_for_creator( $creator )?->settings->to_array();
		self::assertIsArray( $stored );
		self::assertSame( 'inherit', $stored['font_family'], 'The font is untouched.' );
		self::assertSame( 40, $stored['layout']['elements']['product_image']['x'], 'The layout is untouched.' );
		self::assertTrue( $stored['layout']['elements']['secondary_text']['visible'] );
		self::assertArrayNotHasKey( 'image_url', $stored );

		self::assertSame( 400, $this->call( 'PATCH', self::ROUTE, array( 'font_size' => array( 1 ) ) )->get_status() );
		self::assertSame( 400, $this->call( 'PATCH', self::ROUTE, array( 'show_secondary' => 'yes' ) )->get_status() );
	}

	public function test_only_approved_fonts_are_saved_and_a_removed_font_is_flagged(): void {
		$creator = $this->creator( 'nova' );
		update_option(
			AlertFonts::APPROVED_OPTION,
			array(
				array(
					'family'   => 'Roboto',
					'category' => 'sans-serif',
					'weights'  => array( 400, 700 ),
				),
			),
			false
		);
		$created = $this->data( $this->call( 'POST', self::ROUTE ) );
		self::assertSame(
			array(
				array(
					'family' => 'Roboto',
					'url'    => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap',
				),
			),
			$created['fonts']
		);
		self::assertSame( 'inherit', $created['overlay']['settings']['font_family'] );
		self::assertTrue( $created['overlay']['font_approved'] );

		$refused = $this->call( 'PATCH', self::ROUTE, array( 'font_family' => 'Lobster' ) );
		self::assertSame( 400, $refused->get_status() );
		self::assertSame( array( 'font_family' => 'not_approved' ), $refused->get_data()['data']['fields'] ?? null );
		self::assertSame( 400, $this->call( 'PATCH', self::ROUTE, array( 'font_family' => 7 ) )->get_status() );

		$saved = $this->data( $this->call( 'PATCH', self::ROUTE, array( 'font_family' => 'Roboto' ) ) );
		self::assertSame( 'Roboto', $saved['overlay']['settings']['font_family'] );
		self::assertTrue( $saved['overlay']['font_approved'] );

		delete_option( AlertFonts::APPROVED_OPTION );
		$after = $this->data( $this->call( 'GET', self::ROUTE ) );
		self::assertSame( array(), $after['fonts'] );
		self::assertSame( 'Roboto', $after['overlay']['settings']['font_family'], 'The choice is kept for when the font comes back.' );
		self::assertFalse( $after['overlay']['font_approved'] );

		self::assertSame( 'inherit', $this->data( $this->call( 'PATCH', self::ROUTE, array( 'font_family' => 'inherit' ) ) )['overlay']['settings']['font_family'] );
		self::assertNotNull( $this->overlays->find_for_creator( $creator ) );
	}

	public function test_the_designer_layout_is_saved_normalized(): void {
		$creator = $this->creator( 'nova' );
		$this->call( 'POST', self::ROUTE );
		$layout = $this->data( $this->call( 'GET', self::ROUTE ) )['overlay']['layout'];
		self::assertSame( array( 'product_image', 'main_text', 'secondary_text' ), array_keys( $layout['elements'] ) );

		$layout['elements']['main_text']['x']      = 300;
		$layout['elements']['main_text']['w']      = 99999;
		$layout['elements']['product_image']['x']  = 'evil';
		$layout['elements']['secondary_text']['h'] = 60;
		$layout['canvas']                          = array(
			'snap_grid' => true,
			'grid_size' => 20,
		);
		$layout['elements']['extra']               = array( 'x' => 1 );

		$saved = $this->data( $this->call( 'PATCH', self::ROUTE, array( 'layout' => $layout ) ) )['overlay']['layout'];
		self::assertSame( 300, $saved['elements']['main_text']['x'] );
		self::assertSame( 3840, $saved['elements']['main_text']['w'], 'Clamped.' );
		self::assertSame( 40, $saved['elements']['product_image']['x'], 'Invalid values fall back to the default.' );
		self::assertSame( 60, $saved['elements']['secondary_text']['h'] );
		self::assertSame(
			array(
				'snap_grid' => true,
				'grid_size' => 20,
			),
			$saved['canvas']
		);
		self::assertArrayNotHasKey( 'extra', $saved['elements'] );
		self::assertSame( $saved, $this->overlays->find_for_creator( $creator )?->settings->to_array()['layout'] );
		self::assertSame( 400, $this->call( 'PATCH', self::ROUTE, array( 'layout' => 'big' ) )->get_status() );
	}

	public function test_sounds_are_uploaded_switched_on_and_deleted_through_the_routes(): void {
		$this->creator( 'nova' );
		$user = get_current_user_id();
		self::assertSame( 404, $this->upload( 'default', AlertSoundsTest::wav_upload( 1 ) )->get_status(), 'Set up the overlay first.' );
		$this->call( 'POST', self::ROUTE );

		$saved = $this->data( $this->upload( 'default', AlertSoundsTest::wav_upload( 2 ) ) )['overlay']['audio'];
		self::assertSame( 2, $saved['default']['seconds'] );
		self::assertTrue( $saved['enabled'], 'The first sound switches sounds on.' );
		self::assertSame( 'Hoodies', $saved['categories'][0]['name'] );

		$refused = $this->upload( 'default', AlertSoundsTest::upload( '<?php echo 1;', 'x.php' ) );
		self::assertSame( 400, $refused->get_status() );
		self::assertSame( array( 'file' => 'invalid_type' ), $refused->get_data()['data']['fields'] ?? null );

		$foreign = $this->upload( '77', AlertSoundsTest::wav_upload( 1 ) );
		self::assertSame( array( 'slot' => 'unknown_category' ), $foreign->get_data()['data']['fields'] ?? null );
		self::assertSame( 400, $this->upload( 'default', null )->get_status(), 'No file.' );
		self::assertSame( 404, $this->upload( 'abc', AlertSoundsTest::wav_upload( 1 ) )->get_status(), 'Unknown slot route.' );

		self::assertFalse( $this->data( $this->call( 'PATCH', self::ROUTE, array( 'audio_enabled' => false ) ) )['overlay']['audio']['enabled'] );
		self::assertTrue( $this->data( $this->call( 'PATCH', self::ROUTE, array( 'audio_enabled' => true ) ) )['overlay']['audio']['enabled'] );
		self::assertSame( 400, $this->call( 'PATCH', self::ROUTE, array( 'audio_enabled' => 'yes' ) )->get_status() );
		self::assertTrue( $this->data( $this->call( 'PATCH', self::ROUTE, array( 'display_from_sound' => true ) ) )['overlay']['settings']['display_from_sound'] );
		self::assertSame( 400, $this->call( 'PATCH', self::ROUTE, array( 'display_from_sound' => 1 ) )->get_status() );

		self::assertNull( $this->data( $this->call( 'DELETE', self::ROUTE . '/sounds/default' ) )['overlay']['audio']['default'] );

		// A library sound is chosen with JSON instead of a file.
		wp_set_current_user( 1 );
		$chime = AlertSoundsTest::library( $this->service( AuditTrail::class ), $this->service( Transaction::class ) )->add( AlertSoundsTest::wav_upload( 1, 'chime.wav' ) );
		wp_set_current_user( $user );
		$chosen = $this->data( $this->call( 'POST', self::ROUTE . '/sounds/default', array( 'library' => $chime->attachment_id ) ) )['overlay']['audio'];
		self::assertSame( $chime->attachment_id, $chosen['default']['library_id'] );
		self::assertSame( 'chime', $chosen['library'][0]['name'] );
		self::assertSame( array( 'library' => 'unknown_sound' ), $this->call( 'POST', self::ROUTE . '/sounds/default', array( 'library' => 1 ) )->get_data()['data']['fields'] ?? null );
	}

	public function test_text_fill_images_go_through_the_routes_and_stay_the_creators(): void {
		$this->creator( 'orion' );
		$this->call( 'POST', self::ROUTE );
		$theirs = (int) ( $this->data( $this->upload_image( AlertImagesTest::upload( AlertImagesTest::png( 4, 2 ), 'theirs.png' ) ) )['overlay']['images']['own'][0]['id'] ?? 0 );
		self::assertGreaterThan( 0, $theirs );

		$this->creator( 'nova' );
		self::assertSame( 404, $this->upload_image( AlertImagesTest::upload( AlertImagesTest::png( 4, 2 ) ) )->get_status(), 'Set up the overlay first.' );
		$this->call( 'POST', self::ROUTE );

		$images = $this->data( $this->upload_image( AlertImagesTest::upload( AlertImagesTest::png( 4, 2 ), 'stars.png' ) ) )['overlay']['images'];
		self::assertSame( array( 'stars.png' ), array_column( $images['own'], 'name' ), 'Only the own images are listed.' );
		$own = (int) $images['own'][0]['id'];

		$refused = $this->upload_image( AlertImagesTest::upload( 'not an image', 'x.png' ) );
		self::assertSame( 400, $refused->get_status() );
		self::assertSame( array( 'file' => 'invalid_type' ), $refused->get_data()['data']['fields'] ?? null );

		$fill  = static fn( int $image ): array => array(
			'main_text' => array(
				'type'   => 'image',
				'image'  => $image,
				'motion' => 'pan',
			),
		);
		$saved = $this->data( $this->call( 'PATCH', self::ROUTE, array( 'text_fills' => $fill( $own ) ) ) )['overlay']['settings']['text_fills'];
		self::assertSame(
			array(
				'type'   => 'image',
				'preset' => 'rainbow',
				'image'  => $own,
				'motion' => 'pan',
			),
			$saved['main_text']
		);

		$foreign = $this->data( $this->call( 'PATCH', self::ROUTE, array( 'text_fills' => $fill( $theirs ) ) ) )['overlay']['settings']['text_fills'];
		self::assertSame( 'none', $foreign['main_text']['type'], 'Another creator\'s image is cleared.' );
		self::assertSame( 0, $foreign['main_text']['image'] );
		self::assertSame( 400, $this->call( 'PATCH', self::ROUTE, array( 'text_fills' => 'gold' ) )->get_status() );

		$delete = $this->call( 'DELETE', self::ROUTE . '/images/' . $theirs );
		self::assertSame( 400, $delete->get_status() );
		self::assertSame( array( 'image' => 'unknown_image' ), $delete->get_data()['data']['fields'] ?? null );
		self::assertNotNull( get_post( $theirs ), 'Orion\'s file is kept.' );

		$this->call( 'PATCH', self::ROUTE, array( 'text_fills' => $fill( $own ) ) );
		$after = $this->data( $this->call( 'DELETE', self::ROUTE . '/images/' . $own ) )['overlay'];
		self::assertSame( array(), $after['images']['own'] );
		self::assertSame( 'none', $after['settings']['text_fills']['main_text']['type'], 'The fill that used it is cleared.' );
	}

	public function test_the_main_text_around_the_image_is_saved_normalized(): void {
		$this->creator( 'nova' );
		$this->call( 'POST', self::ROUTE );

		$saved = $this->data(
			$this->call(
				'PATCH',
				self::ROUTE,
				array(
					'text_frame' => array(
						'enabled'   => true,
						'direction' => 'counterclockwise',
						'speed'     => 'warp',
					),
				)
			)
		)['overlay']['settings']['text_frame'];

		self::assertSame(
			array(
				'enabled'   => true,
				'direction' => 'counterclockwise',
				'speed'     => 'normal',
				'repeat'    => true,
			),
			$saved
		);
		self::assertSame( 400, $this->call( 'PATCH', self::ROUTE, array( 'text_frame' => true ) )->get_status() );
	}

	public function test_a_new_url_kills_the_old_one_at_once(): void {
		$creator = $this->creator( 'nova' );
		$this->call( 'POST', self::ROUTE );
		$old = (string) $this->overlays->find_for_creator( $creator )?->overlay_id;

		$answer = $this->data( $this->call( 'POST', self::ROUTE . '/regenerate' ) );
		$new    = (string) $this->overlays->find_for_creator( $creator )?->overlay_id;

		self::assertNotSame( $old, $new );
		self::assertStringContainsString( $new, (string) $answer['overlay']['url'] );
		self::assertNull( $this->feed->read( $old, null, false ), 'The old OBS URL is gone.' );
		self::assertNotNull( $this->feed->read( $new, null, false ) );
		self::assertSame( array( 'alert_overlay_created', 'alert_overlay_id_regenerated' ), $this->audit_actions( $creator ) );
	}

	public function test_a_test_alert_reaches_the_overlay_once_without_moving_the_cursor(): void {
		$creator = $this->creator( 'nova' );
		$product = $this->posts->add( 'Hoodie', 'publish', array( (string) $creator ) );
		$this->posts->add( 'Draft hoodie', 'draft', array( (string) $creator ) );
		$this->call( 'POST', self::ROUTE );
		$this->call( 'PATCH', self::ROUTE, array( 'template' => '$UserName tested $Product' ) );
		$id    = (string) $this->overlays->find_for_creator( $creator )?->overlay_id;
		$start = $this->feed->read( $id, null, false );
		self::assertNotNull( $start );

		$sent = $this->call( 'POST', self::ROUTE . '/test' );
		self::assertSame( 200, $sent->get_status() );
		self::assertSame( array( 'sent' => true ), $sent->get_data() );

		$answer = $this->feed->read( $id, $start['cursor'], false );
		self::assertNotNull( $answer );
		self::assertSame( $start['cursor'], $answer['cursor'] );
		self::assertSame(
			array(
				array(
					'id'        => 0,
					'image'     => '',
					'text'      => 'TestFan tested Hoodie',
					'secondary' => '',
					'sound'     => '',
				),
			),
			$answer['alerts']
		);
		self::assertSame( array(), $this->feed->read( $id, $start['cursor'], false )['alerts'] ?? null, 'Delivered once.' );
		self::assertSame( 0, $this->count_rows( Tables::ALERT_EVENTS ), 'A test alert is never stored as an alert.' );
		self::assertGreaterThan( 0, $product );
	}

	public function test_each_creator_sees_only_the_own_overlay_and_guests_nothing(): void {
		$nova     = $this->creator( 'nova' );
		$nova_url = $this->data( $this->call( 'POST', self::ROUTE ) )['overlay']['url'];

		$this->creator( 'orion' );
		self::assertNull( $this->data( $this->call( 'GET', self::ROUTE ) )['overlay'], 'Orion has no overlay yet.' );
		$orion_url = $this->data( $this->call( 'POST', self::ROUTE ) )['overlay']['url'];
		self::assertNotSame( $nova_url, $orion_url );
		self::assertNotNull( $this->overlays->find_for_creator( $nova ) );

		wp_set_current_user( 0 );
		self::assertSame( 401, $this->call( 'GET', self::ROUTE )->get_status() );

		wp_set_current_user( $this->create_user( 'subscriber_only' ) );
		self::assertSame( 403, $this->call( 'POST', self::ROUTE )->get_status() );
	}

	/**
	 * A logged-in active creator; returns the creator id.
	 *
	 * @param string $login Login.
	 */
	private function creator( string $login ): int {
		$user = $this->create_user( $login );
		wp_set_current_user( 1 );
		$creator = $this->service( CreatorService::class )->create( ucfirst( $login ), CreatorStatus::Active, $user );
		wp_set_current_user( $user );

		return $creator->id;
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
	 * Uploads a sound file through the route.
	 *
	 * @phpstan-param array{name: non-empty-string, type: non-empty-string, tmp_name: non-empty-string, error: int<0, 8>, size: int<0, max>}|null $file
	 *
	 * @param string     $slot Slot segment.
	 * @param array|null $file Upload, or null for none.
	 */
	private function upload( string $slot, ?array $file ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE . '/sounds/' . $slot );

		if ( null !== $file ) {
			$request->set_file_params( array( 'file' => $file ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Uploads a text fill image through the route.
	 *
	 * @phpstan-param array{name: non-empty-string, type: non-empty-string, tmp_name: non-empty-string, error: int<0, 8>, size: int<0, max>} $file
	 *
	 * @param array $file Upload.
	 */
	private function upload_image( array $file ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE . '/images' );
		$request->set_file_params( array( 'file' => $file ) );

		return rest_do_request( $request );
	}

	/**
	 * The answer data of an overlay route.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string, mixed>
	 */
	private function data( WP_REST_Response $response ): array {
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );

		return $data;
	}

	/**
	 * The audit actions of a creator, oldest first.
	 *
	 * @param int $creator Creator id.
	 * @return list<string>
	 */
	private function audit_actions( int $creator ): array {
		return array_values(
			array_map(
				static fn( array $row ): string => (string) $row['action'],
				array_filter( $this->rows( Tables::AUDIT_LOG ), static fn( array $row ): bool => (string) $creator === (string) $row['creator_id'] && str_starts_with( (string) $row['action'], 'alert_overlay' ) )
			)
		);
	}
}
