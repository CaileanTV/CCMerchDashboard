<?php
/**
 * Integration tests for the setup of the creator's overlay settings page.
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
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;
use MerchBoard\Modules\Alerts\PageLayout\AdminCreatorPageController;
use MerchBoard\Modules\Alerts\PageLayout\CreatorPageLayout;
use MerchBoard\Modules\Alerts\PageLayout\CreatorPageLayoutStore;
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
 * Step 25.9.6: the administrator sets up the creators' overlay settings page
 * (audited, only with `merchboard_manage_alerts`); every creator gets the
 * setup with their overlay.
 */
#[CoversClass( CreatorPageLayoutStore::class )]
#[CoversClass( AdminCreatorPageController::class )]
#[CoversClass( MeAlertsController::class )]
final class CreatorPageLayoutTest extends IntegrationTestCase {

	private const ADMIN = '/merchboard/v1/admin/alerts/creator-page';

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
		$store          = new CreatorPageLayoutStore( $audit );
		$overlays       = new AlertOverlayRepository( $this->wpdb );
		$fonts          = new AlertFonts( $audit, $transaction, static fn(): mixed => null );
		$feed           = new OverlayFeed( $overlays, new AlertEventRepository( $this->wpdb ), new CreatorRepository( $this->wpdb ), new AlertTemplate( static fn(): ?string => null ), static fn(): string => '', static function (): void {}, new OverlayTestQueue() );
		$service        = new AlertOverlays( $overlays, $feed, new OverlayTestQueue(), new PostMetaProductStore(), $audit, $transaction, static fn(): int => 0, $fonts );
		$me             = new MeAlertsController( $this->service( CreatorContext::class ), $service, new AlertsAvailability( static fn(): bool => false ), $this->service( Logger::class ), $fonts, null, null, null, null, $store );
		$admin          = new AdminCreatorPageController( $store, $this->service( Logger::class ) );
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

		delete_option( CreatorPageLayoutStore::OPTION );
		parent::tearDown();
	}

	public function test_the_administrator_sets_up_the_page_and_every_creator_gets_it(): void {
		wp_set_current_user( 1 );
		self::assertSame( CreatorPageLayout::defaults()->to_array(), $this->data( $this->call( 'GET', self::ADMIN ) ), 'The default before any setup.' );

		$wanted = array(
			'cards'           => array(
				'full'  => array(),
				'left'  => array( 'alert_text', 'designs' ),
				'right' => array( 'timing', 'browser_source', 'sounds', 'text_style', 'box_image', 'animations' ),
			),
			'fields'          => array( 'timing' => array( 'anim_duration', 'display_duration', 'display_from_sound' ) ),
			'designs_compact' => true,
		);
		$saved  = $this->data( $this->call( 'POST', self::ADMIN, $wanted ) );
		self::assertSame( $wanted['cards'], $saved['cards'] );
		self::assertSame( array( 'anim_duration', 'display_duration', 'display_from_sound' ), $saved['fields']['timing'] );
		self::assertSame( CreatorPageLayout::FIELDS['alert_text'], $saved['fields']['alert_text'], 'Complete.' );
		self::assertTrue( $saved['designs_compact'] );
		self::assertFalse( wp_load_alloptions()[ CreatorPageLayoutStore::OPTION ] ?? false, 'Not autoloaded.' );

		$this->call( 'POST', self::ADMIN, $wanted );
		self::assertSame( array( 'alert_creator_page_changed' ), array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ), 'An unchanged setup is not audited again.' );

		// A creator gets the setup with their overlay, also before it is set up.
		$creator = $this->create_user( 'nova' );
		$this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $creator );
		wp_set_current_user( $creator );
		self::assertSame( $saved, $this->data( $this->call( 'GET', '/merchboard/v1/me/alerts/overlay' ) )['page'] );
		self::assertSame( $saved, $this->data( $this->call( 'POST', '/merchboard/v1/me/alerts/overlay' ) )['page'] );

		// Only users who manage alerts change it.
		foreach ( array( 'GET', 'POST', 'DELETE' ) as $method ) {
			self::assertSame( 403, $this->call( $method, self::ADMIN, array( 'designs_compact' => false ) )->get_status(), $method );
		}

		wp_set_current_user( 1 );
		self::assertSame( CreatorPageLayout::defaults()->to_array(), $this->data( $this->call( 'DELETE', self::ADMIN ) ) );
		self::assertNull( get_option( CreatorPageLayoutStore::OPTION, null ) );
		$this->call( 'DELETE', self::ADMIN );
		self::assertSame( array( 'alert_creator_page_changed', 'creator_created', 'alert_overlay_created', 'alert_creator_page_changed' ), array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ), 'Reset audited once.' );
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
