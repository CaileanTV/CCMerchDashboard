<?php
/**
 * Integration tests for the standard overlays.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Images\AlertImageLibrary;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;
use MerchBoard\Modules\Alerts\Presets\AdminOverlayPresetsController;
use MerchBoard\Modules\Alerts\Presets\OverlayPresetException;
use MerchBoard\Modules\Alerts\Presets\OverlayPresets;
use MerchBoard\Modules\Alerts\Presets\OverlayPresetStore;
use MerchBoard\Modules\Alerts\Rest\MeAlertsController;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Step 25.9.5: the administrator makes, names, designs and deletes standard
 * overlays (audited); a creator copies one's look and texts into their own
 * overlay as a starting point, keeping their sounds and URL, and later
 * changes of the standard do not change the copy.
 */
#[CoversClass( OverlayPresets::class )]
#[CoversClass( OverlayPresetStore::class )]
#[CoversClass( AdminOverlayPresetsController::class )]
#[CoversClass( MeAlertsController::class )]
final class OverlayPresetsTest extends IntegrationTestCase {

	private const ADMIN = '/merchboard/v1/admin/alerts/presets';

	private OverlayPresets $presets;
	private AlertOverlayRepository $overlays;
	private AlertImageLibrary $library;

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
		$this->overlays = new AlertOverlayRepository( $this->wpdb );
		$this->library  = new AlertImageLibrary( AlertImagesTest::image_files(), $audit, $transaction );
		$images         = new AlertImages( $this->overlays, AlertImagesTest::image_files(), $this->library );
		$fonts          = new AlertFonts( $audit, $transaction, static fn(): mixed => null );
		$this->presets  = new OverlayPresets( new OverlayPresetStore(), $audit, $this->overlays, $fonts, $this->library, $images );

		$feed           = new OverlayFeed( $this->overlays, new AlertEventRepository( $this->wpdb ), new CreatorRepository( $this->wpdb ), new AlertTemplate( static fn(): ?string => null ), static fn(): string => '', static function (): void {}, new OverlayTestQueue() );
		$service        = new AlertOverlays( $this->overlays, $feed, new OverlayTestQueue(), new PostMetaProductStore(), $audit, $transaction, static fn(): int => 0, $fonts, $images );
		$me             = new MeAlertsController( $this->service( CreatorContext::class ), $service, new AlertsAvailability( static fn(): bool => false ), $this->service( Logger::class ), $fonts, null, $images, $this->presets );
		$admin          = new AdminOverlayPresetsController( $this->presets, new AlertsAvailability( static fn(): bool => false ), $this->service( Logger::class ), $fonts );
		$this->register = static function () use ( $me, $admin ): void {
			$me->register_routes();
			$admin->register_routes();
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

		delete_option( OverlayPresetStore::OPTION );
		parent::tearDown();
	}

	public function test_standard_overlays_are_made_named_designed_and_deleted_with_audit(): void {
		$neon = $this->presets->create( "  Neon \n glow " );
		self::assertSame( 'Neon glow', $neon->name );
		self::assertSame( OverlaySettings::defaults()->to_array(), $neon->settings->to_array(), 'From the defaults.' );

		foreach ( array( '', str_repeat( 'x', OverlayPresetStore::NAME_MAX + 1 ) ) as $name ) {
			try {
				$this->presets->create( $name );
				self::fail( 'Expected OverlayPresetException.' );
			} catch ( OverlayPresetException $e ) {
				self::assertSame( OverlayPresetException::INVALID_NAME, $e->reason() );
			}
		}

		self::assertSame( 'Glow', $this->presets->rename( $neon->id, 'Glow' )->name );
		self::assertSame( 'Glow', $this->presets->rename( $neon->id, 'Glow' )->name, 'Unchanged: not audited again.' );

		// The design: the same fields and rules as a creator's; fills keep only library images.
		$own     = AlertImagesTest::image_files()->store( AlertImagesTest::upload( AlertImagesTest::png( 8, 4 ) ), AlertImages::OWNER_META, 7 );
		$fill    = array(
			'type'   => 'image',
			'preset' => 'gold',
			'image'  => $own,
			'motion' => 'none',
		);
		$changed = $this->presets->update_settings(
			$neon->id,
			array(
				'template'   => 'Neon $Product!',
				'bg_color'   => '#ff00ff',
				'text_fills' => array(
					'main_text'      => $fill,
					'secondary_text' => $fill,
				),
			)
		);
		self::assertSame( array( 'Neon $Product!', '#ff00ff', 0 ), array( $changed->settings->template(), $changed->settings->to_array()['bg_color'], $changed->settings->text_fills()['main_text']['image'] ), 'Not an administrator\'s or creator\'s own image.' );

		$this->presets->delete( $neon->id );
		self::assertSame( array(), $this->presets->all() );
		self::assertSame( array( 'alert_overlay_preset_created', 'alert_overlay_preset_renamed', 'alert_overlay_preset_deleted' ), array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );

		try {
			$this->presets->delete( $neon->id );
			self::fail( 'Expected OverlayPresetException.' );
		} catch ( OverlayPresetException $e ) {
			self::assertSame( OverlayPresetException::UNKNOWN_PRESET, $e->reason() );
		}

		for ( $count = 0; $count < OverlayPresetStore::MAX; $count++ ) {
			$this->presets->create( 'P' . $count );
		}

		try {
			$this->presets->create( 'One too many' );
			self::fail( 'Expected OverlayPresetException.' );
		} catch ( OverlayPresetException $e ) {
			self::assertSame( OverlayPresetException::FULL, $e->reason() );
		}
	}

	public function test_a_copy_takes_the_look_and_texts_and_keeps_the_creators_sounds_and_url(): void {
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
		$values  = OverlaySettings::defaults()->to_array();
		$values  = array_merge(
			$values,
			array(
				'template'            => 'Thanks $UserName!',
				'audio_enabled'       => true,
				'audio_default_sound' => 4242,
				'display_from_sound'  => true,
			)
		);
		$overlay = $this->overlays->create( $creator, OverlayId::generate(), OverlaySettings::from_array( $values ) );

		$neon = $this->presets->create( 'Neon' );
		$neon = $this->presets->update_settings(
			$neon->id,
			array(
				'template'         => 'Neon $Product!',
				'bg_color'         => '#ff00ff',
				'display_duration' => 9000,
				'layout'           => array_replace_recursive( OverlaySettings::defaults()->to_array()['layout'], array( 'elements' => array( 'main_text' => array( 'x' => 300 ) ) ) ),
			)
		);

		$copied = $this->presets->apply( $creator, $neon->id );
		self::assertNotNull( $copied );
		$after = $copied->settings->to_array();
		self::assertSame( array( 'Neon $Product!', '#ff00ff', 9000, 300 ), array( $after['template'], $after['bg_color'], $after['display_duration'], $after['layout']['elements']['main_text']['x'] ), 'The look and the texts.' );
		self::assertSame( array( true, 4242, true ), array( $after['audio_enabled'], $after['audio_default_sound'], $after['display_from_sound'] ), 'The creator\'s sounds stay.' );
		self::assertSame( $overlay->overlay_id, $copied->overlay_id, 'The URL stays.' );

		$this->presets->update_settings( $neon->id, array( 'template' => 'Changed later' ) );
		self::assertSame( 'Neon $Product!', $this->overlays->find_for_creator( $creator )?->settings->template(), 'A copy, not a link.' );
		self::assertNull( $this->presets->apply( 999999, $neon->id ), 'No overlay yet.' );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) - 1, 'Copying and designing are not audited (only the making).' );
	}

	public function test_the_admin_routes_need_the_alert_capability(): void {
		wp_set_current_user( $this->create_user( 'presets-subscriber' ) );
		self::assertSame( 403, $this->call( 'GET', self::ADMIN )->get_status() );
		wp_set_current_user( 1 );

		self::assertSame(
			array(
				'presets' => array(),
				'max'     => OverlayPresetStore::MAX,
			),
			$this->data( $this->call( 'GET', self::ADMIN ) )
		);
		self::assertSame( 400, $this->call( 'POST', self::ADMIN, array( 'name' => '' ) )->get_status() );
		$listed = $this->data( $this->call( 'POST', self::ADMIN, array( 'name' => 'Neon' ) ) )['presets'];
		self::assertIsArray( $listed );
		$id = (string) ( $listed[0]['id'] ?? '' );

		$one = $this->data( $this->call( 'GET', self::ADMIN . '/' . $id ) )['overlay'];
		self::assertIsArray( $one );
		self::assertNull( $one['audio'], 'No sounds in a standard overlay.' );
		self::assertSame( array( array(), 0 ), array( $one['images']['own'] ?? null, $one['images']['max_own'] ?? null ), 'Only the image library.' );
		self::assertSame( 'Neon', $one['name'] );

		$patched = $this->data( $this->call( 'PATCH', self::ADMIN . '/' . $id, array( 'template' => 'Glow $Product' ) ) )['overlay'];
		self::assertSame( 'Glow $Product', $patched['settings']['template'] ?? null );
		self::assertSame( 'Glow', $this->data( $this->call( 'POST', self::ADMIN . '/' . $id . '/name', array( 'name' => 'Glow' ) ) )['presets'][0]['name'] ?? null );
		self::assertSame( 404, $this->call( 'GET', self::ADMIN . '/ffffffffffffffff' )->get_status() );
		self::assertSame( array(), $this->data( $this->call( 'DELETE', self::ADMIN . '/' . $id ) )['presets'] );
	}

	public function test_a_creator_lists_the_standard_overlays_and_copies_one_into_their_own(): void {
		$neon = $this->presets->create( 'Neon' );
		$this->presets->update_settings( $neon->id, array( 'template' => 'Neon $Product!' ) );
		$user = $this->create_user( 'nova' );
		$this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $user );
		wp_set_current_user( $user );

		$list = $this->data( $this->call( 'GET', '/merchboard/v1/me/alerts/presets' ) )['presets'];
		self::assertIsArray( $list );
		self::assertSame( array( 'id', 'name', 'settings', 'font_approved', 'layout', 'images' ), array_keys( $list[0] ?? array() ) );
		self::assertSame( 'Neon $Product!', $list[0]['settings']['template'] ?? null );

		self::assertSame( 404, $this->call( 'POST', '/merchboard/v1/me/alerts/overlay/preset', array( 'preset' => $neon->id ) )->get_status(), 'No overlay yet.' );
		$this->call( 'POST', '/merchboard/v1/me/alerts/overlay' );
		$copied = $this->data( $this->call( 'POST', '/merchboard/v1/me/alerts/overlay/preset', array( 'preset' => $neon->id ) ) )['overlay'];
		self::assertSame( 'Neon $Product!', $copied['settings']['template'] ?? null );
		self::assertSame( 400, $this->call( 'POST', '/merchboard/v1/me/alerts/overlay/preset', array( 'preset' => 'ffffffffffffffff' ) )->get_status() );

		wp_set_current_user( $this->create_user( 'not-a-creator' ) );
		self::assertSame( 403, $this->call( 'GET', '/merchboard/v1/me/alerts/presets' )->get_status() );
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
