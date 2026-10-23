<?php
/**
 * Integration tests for a creator's saved overlay designs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Designs\CreatorDesignException;
use MerchBoard\Modules\Alerts\Designs\CreatorDesigns;
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
use MerchBoard\Modules\Alerts\Rest\MeAlertsController;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A creator saves their overlay's design (look, texts and sounds) under a
 * name, overwrites, renames, deletes and takes it into use again; only they
 * reach their designs, and a sound file stays while a design uses it.
 */
#[CoversClass( CreatorDesigns::class )]
#[CoversClass( AlertSounds::class )]
#[CoversClass( MeAlertsController::class )]
final class CreatorDesignsTest extends IntegrationTestCase {

	private CreatorDesigns $designs;
	private AlertSounds $sounds;
	private AlertOverlayRepository $overlays;
	private int $creator;

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
		$images         = new AlertImages( $this->overlays, AlertImagesTest::image_files(), new AlertImageLibrary( AlertImagesTest::image_files(), $audit, $transaction ) );
		$fonts          = new AlertFonts( $audit, $transaction, static fn(): mixed => null );
		$this->designs  = new CreatorDesigns( new CreatorMetaStore( $this->wpdb ), $this->overlays, $fonts, $images );
		$designs        = $this->designs;
		$this->sounds   = new AlertSounds(
			$this->overlays,
			static fn(): array => array( 77 => 'Hoodies' ),
			AlertSoundsTest::sound_files(),
			null,
			static fn(): array => array(),
			null,
			static fn( int $creator_id ): array => $designs->sounds_in_use( $creator_id )
		);
		$this->creator  = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;

		$feed           = new OverlayFeed( $this->overlays, new AlertEventRepository( $this->wpdb ), new CreatorRepository( $this->wpdb ), new AlertTemplate( static fn(): ?string => null ), static fn(): string => '', static function (): void {}, new OverlayTestQueue() );
		$service        = new AlertOverlays( $this->overlays, $feed, new OverlayTestQueue(), new PostMetaProductStore(), $audit, $transaction, static fn(): int => 0, $fonts, $images );
		$controller     = new MeAlertsController( $this->service( CreatorContext::class ), $service, new AlertsAvailability( static fn(): bool => false ), $this->service( Logger::class ), $fonts, $this->sounds, $images, null, $this->designs );
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

		parent::tearDown();
	}

	public function test_a_design_keeps_the_look_texts_and_sounds_and_its_sound_file_stays(): void {
		$this->overlays->create( $this->creator, OverlayId::generate(), OverlaySettings::defaults() );
		$overlay = $this->sounds->upload( $this->creator, 0, AlertSoundsTest::wav_upload( 1, 'gaming.wav' ) );
		self::assertNotNull( $overlay );
		$gaming = $overlay->settings->to_array()['audio_default_sound'];
		$this->set(
			array(
				'template' => 'Gaming $Product',
				'bg_color' => '#ff0000',
			)
		);

		$saved = $this->designs->save_current( $this->creator, '  Gaming  ' );
		self::assertSame( array( 'Gaming' ), array_map( static fn( $design ): string => $design->name, $saved ) );

		// A new sound: the old file stays, because the design uses it.
		$this->sounds->upload( $this->creator, 0, AlertSoundsTest::wav_upload( 1, 'chatting.wav' ) );
		$this->set( array( 'template' => 'Chatting $Product' ) );
		self::assertNotNull( get_post( $gaming ), 'A saved design keeps its sound file.' );
		$chatting = $this->overlay()->settings->to_array()['audio_default_sound'];

		$applied = $this->designs->apply( $this->creator, $saved[0]->id )->settings->to_array();
		self::assertSame( array( 'Gaming $Product', '#ff0000', $gaming ), array( $applied['template'], $applied['bg_color'], $applied['audio_default_sound'] ), 'Look, texts and sounds.' );
		self::assertNull( get_post( $chatting ), 'The replaced sound nothing uses any more is deleted.' );

		$this->set( array( 'template' => 'Later' ) );
		$updated = $this->designs->update_from_current( $this->creator, $saved[0]->id );
		self::assertSame( 'Later', $updated[0]->settings->template() );
		self::assertSame( 'Chill', $this->designs->rename( $this->creator, $saved[0]->id, 'Chill' )[0]->name );

		// Deleting the design: its sound stays while the overlay uses it.
		self::assertSame( array(), $this->designs->delete( $this->creator, $saved[0]->id ) );
		self::assertNotNull( get_post( $gaming ), 'The overlay still uses it.' );

		foreach ( array(
			array( fn() => $this->designs->save_current( $this->creator, '' ), CreatorDesignException::INVALID_NAME ),
			array( fn() => $this->designs->apply( $this->creator, 'ffffffffffffffff' ), CreatorDesignException::UNKNOWN_DESIGN ),
			array( fn() => $this->designs->save_current( 999999, 'No overlay' ), CreatorDesignException::NO_OVERLAY ),
		) as [ $refused, $reason ] ) {
			try {
				$refused();
				self::fail( 'Expected CreatorDesignException.' );
			} catch ( CreatorDesignException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		for ( $count = 0; $count < CreatorDesigns::MAX; $count++ ) {
			$this->designs->save_current( $this->creator, 'D' . $count );
		}

		try {
			$this->designs->save_current( $this->creator, 'One too many' );
			self::fail( 'Expected CreatorDesignException.' );
		} catch ( CreatorDesignException $e ) {
			self::assertSame( CreatorDesignException::FULL, $e->reason() );
		}
	}

	public function test_only_the_creator_reaches_their_designs_through_me(): void {
		$user = $this->login( 'nova' );

		self::assertSame( 404, $this->call( 'POST', '/merchboard/v1/me/alerts/designs', array( 'name' => 'Gaming' ) )->get_status(), 'No overlay yet.' );
		$this->call( 'POST', '/merchboard/v1/me/alerts/overlay' );
		$list = $this->data( $this->call( 'POST', '/merchboard/v1/me/alerts/designs', array( 'name' => 'Gaming' ) ) );
		self::assertSame( CreatorDesigns::MAX, $list['max'] );
		$design = $list['designs'][0] ?? array();
		self::assertSame( array( 'id', 'name', 'saved_at', 'settings', 'font_approved', 'layout', 'images' ), array_keys( $design ) );
		$id = (string) $design['id'];

		self::assertSame( 400, $this->call( 'POST', '/merchboard/v1/me/alerts/designs', array( 'name' => '' ) )->get_status() );
		self::assertSame( 'Chill', $this->data( $this->call( 'POST', '/merchboard/v1/me/alerts/designs/' . $id . '/name', array( 'name' => 'Chill' ) ) )['designs'][0]['name'] ?? null );
		self::assertNotNull( $this->data( $this->call( 'POST', '/merchboard/v1/me/alerts/designs/' . $id . '/update' ) )['designs'][0] ?? null );
		self::assertIsArray( $this->data( $this->call( 'POST', '/merchboard/v1/me/alerts/overlay/design', array( 'design' => $id ) ) )['overlay'] );

		// Another creator never sees or uses it.
		$this->login( 'orion' );
		$this->call( 'POST', '/merchboard/v1/me/alerts/overlay' );
		self::assertSame( array(), $this->data( $this->call( 'GET', '/merchboard/v1/me/alerts/designs' ) )['designs'] );
		self::assertSame( 400, $this->call( 'POST', '/merchboard/v1/me/alerts/overlay/design', array( 'design' => $id ) )->get_status() );
		self::assertSame( 400, $this->call( 'DELETE', '/merchboard/v1/me/alerts/designs/' . $id )->get_status() );

		wp_set_current_user( $user );
		self::assertSame( array(), $this->data( $this->call( 'DELETE', '/merchboard/v1/me/alerts/designs/' . $id ) )['designs'] );
	}

	/**
	 * A logged-in active creator; returns the user id.
	 *
	 * @param string $login Login.
	 */
	private function login( string $login ): int {
		$user = $this->create_user( $login );
		wp_set_current_user( 1 );
		$this->service( CreatorService::class )->create( ucfirst( $login ) . ' 2', CreatorStatus::Active, $user );
		wp_set_current_user( $user );

		return $user;
	}

	/**
	 * Changes the creator's overlay settings.
	 *
	 * @param array<string, mixed> $changes Changes.
	 */
	private function set( array $changes ): void {
		$overlay = $this->overlay();
		$this->overlays->update_settings( $overlay->id, OverlaySettings::from_array( array_merge( $overlay->settings->to_array(), $changes ) ) );
	}

	/**
	 * The creator's overlay.
	 */
	private function overlay(): \MerchBoard\Modules\Alerts\Overlay\AlertOverlay {
		$overlay = $this->overlays->find_for_creator( $this->creator );
		self::assertNotNull( $overlay );

		return $overlay;
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
