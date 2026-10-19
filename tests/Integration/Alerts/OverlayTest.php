<?php
/**
 * Integration tests for the alert overlay: storage, feed, REST route and page.
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
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertTemplate;
use MerchBoard\Modules\Alerts\Overlay\OverlayAssets;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeedController;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlayPage;
use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use MerchBoard\Modules\Alerts\Overlay\OverlayRoute;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * ADR-065 pkt. 4: the overlay id is the only credential, the feed starts at
 * the newest alert and never skips or repeats one, and the page carries
 * only validated values.
 */
#[CoversClass( AlertOverlayRepository::class )]
#[CoversClass( OverlayFeed::class )]
#[CoversClass( OverlayFeedController::class )]
#[CoversClass( OverlayPage::class )]
final class OverlayTest extends IntegrationTestCase {

	private CreatorRepository $creators;

	private AlertOverlayRepository $overlays;

	private AlertEventRepository $events;

	/**
	 * Seconds slept by the feed.
	 *
	 * @var list<int>
	 */
	private array $slept = array();

	/**
	 * Registers the route on `rest_api_init` (null when not registered).
	 *
	 * @var (callable(): void)|null
	 */
	private $register = null;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->creators = new CreatorRepository( $this->wpdb );
		$this->overlays = new AlertOverlayRepository( $this->wpdb );
		$this->events   = new AlertEventRepository( $this->wpdb );
	}

	protected function tearDown(): void {
		if ( null !== $this->register ) {
			remove_action( 'rest_api_init', $this->register );
		}

		parent::tearDown();
	}

	public function test_one_overlay_per_creator_with_a_unique_secret_id(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$id      = OverlayId::generate();
		$overlay = $this->overlays->create( $creator->id, $id, OverlaySettings::from_array( array( 'font_size' => 40 ) ) );

		self::assertSame( $id, $overlay->overlay_id );
		self::assertSame( 40, $overlay->settings->to_array()['font_size'] );
		self::assertSame( $overlay->id, $this->overlays->create( $creator->id, OverlayId::generate(), OverlaySettings::defaults() )->id, 'A second create keeps the first overlay.' );
		self::assertSame( $id, $this->overlays->find_for_creator( $creator->id )?->overlay_id );
		self::assertSame( $overlay->id, $this->overlays->find_by_overlay_id( $id )?->id );
		self::assertNull( $this->overlays->find_by_overlay_id( strtoupper( $id ) ) );
		self::assertNull( $this->overlays->find_by_overlay_id( OverlayId::generate() ) );
		self::assertNull( $this->overlays->find_by_overlay_id( "' OR 1=1 -- " ) );
	}

	public function test_the_feed_starts_at_the_newest_alert_and_then_delivers_each_once(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$id      = $this->overlay( $creator->id, array( 'template' => '$UserName bought $Product' ) );
		$old     = $this->event( $creator->id, 1001, 'old_fan' );
		$feed    = $this->feed();

		$first = $feed->read( $id, null, true );
		self::assertNotNull( $first );
		self::assertSame( $old, $first['cursor'], 'Old alerts are skipped.' );
		self::assertSame( array(), $first['alerts'] );
		self::assertSame( array(), $this->slept, 'The first request never waits.' );

		$new  = $this->event( $creator->id, 1002, 'nova_fan' );
		$next = $feed->read( $id, $first['cursor'], true );
		self::assertNotNull( $next );
		self::assertSame( $new, $next['cursor'] );
		self::assertSame(
			array(
				array(
					'id'        => $new,
					'image'     => '',
					'text'      => 'nova_fan bought Hoodie',
					'secondary' => '',
					'sound'     => '',
				),
			),
			$next['alerts']
		);
		self::assertSame( array(), $this->slept, 'Alerts are answered at once.' );

		$empty = $feed->read( $id, $next['cursor'], false );
		self::assertNotNull( $empty );
		self::assertSame( $new, $empty['cursor'] );
		self::assertSame( array(), $empty['alerts'], 'Nothing is delivered twice.' );
		self::assertSame( $first['version'], $empty['version'] );
	}

	public function test_a_long_poll_waits_up_to_eight_seconds_and_other_creators_never_appear(): void {
		$nova  = $this->creators->create( 'Nova', CreatorStatus::Active );
		$orion = $this->creators->create( 'Orion', CreatorStatus::Active );
		$id    = $this->overlay( $nova->id );
		$this->event( $orion->id, 1001, 'orion_fan' );

		$answer = $this->feed()->read( $id, 0, true );

		self::assertNotNull( $answer );
		self::assertSame( array(), $answer['alerts'] );
		self::assertSame( 0, $answer['cursor'] );
		self::assertSame( array_fill( 0, OverlayFeed::WAIT_SECONDS, 1 ), $this->slept );
	}

	public function test_unknown_ids_and_inactive_creators_are_not_found(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$id      = $this->overlay( $creator->id );
		$feed    = $this->feed();

		self::assertNull( $feed->read( OverlayId::generate(), null, false ) );
		self::assertNull( $feed->read( 'not-an-id', null, false ) );

		$this->creators->set_status( $creator->id, CreatorStatus::Inactive );
		self::assertNull( $feed->read( $id, null, false ) );
	}

	public function test_the_rest_route_is_public_limited_and_never_cached(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$id      = $this->overlay( $creator->id );
		$this->routes();
		wp_set_current_user( 0 );

		$response = rest_do_request( $this->request( $id ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] ?? null );
		self::assertSame( array( 'cursor', 'version', 'alerts' ), array_keys( (array) $response->get_data() ) );

		self::assertSame( 404, rest_do_request( $this->request( OverlayId::generate() ) )->get_status() );
		self::assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/merchboard/v1/alerts/overlay/xyz/feed' ) )->get_status() );

		for ( $i = 1; $i < OverlayRateLimiter::LIMIT; $i++ ) {
			rest_do_request( $this->request( $id ) );
		}

		self::assertSame( 429, rest_do_request( $this->request( $id ) )->get_status() );
		self::assertSame( 404, rest_do_request( $this->request( OverlayId::generate() ) )->get_status(), 'Unknown ids are never counted.' );
	}

	public function test_the_page_carries_only_validated_values_and_no_unknown_overlay(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$id      = $this->overlay(
			$creator->id,
			array(
				'font_family' => 'Roboto;}</style><script>alert(1)</script>',
				'text_color'  => '#abc',
				'text_shadow' => '1px 1px red;}body{display:none',
			)
		);
		$page    = $this->page();
		$html    = (string) $page->render( $id, 'nonce123' );

		self::assertStringContainsString( '--merchboard-font-family:inherit;', $html );
		self::assertStringContainsString( '--merchboard-text-color:#aabbcc;', $html );
		self::assertStringContainsString( '--merchboard-text-shadow:none;', $html );
		self::assertStringNotContainsString( '<script>alert', $html );
		self::assertStringContainsString( '<meta name="robots" content="noindex, nofollow">', $html );
		self::assertStringNotContainsString( '<button', $html, 'Sounds play without a click (OBS allows autoplay).' );
		self::assertMatchesRegularExpression( '#<script type="application/json" id="merchboard-overlay-config">\{.*"feed":"[^"]+/merchboard/v1/alerts/overlay/' . $id . '/feed".*\}</script>#', $html );
		self::assertStringContainsString( '"textFit":"fixed"', $html, 'The overlay script knows how text meets its box.' );
		self::assertStringContainsString( '"imageFollows":false', $html );
		self::assertStringContainsString( '"displayFromSound":false', $html );
		self::assertNull( $page->render( OverlayId::generate(), 'nonce123' ) );

		$headers = OverlayPage::security_headers( 'nonce123' );
		self::assertStringContainsString( "script-src 'self' 'nonce-nonce123'", $headers['Content-Security-Policy'] );
		self::assertStringContainsString( "connect-src 'self'", $headers['Content-Security-Policy'] );
		self::assertSame( 'no-referrer', $headers['Referrer-Policy'] );
	}

	public function test_only_an_approved_font_is_loaded_from_google(): void {
		$creator = $this->creators->create( 'Nova', CreatorStatus::Active );
		$id      = $this->overlay( $creator->id, array( 'font_family' => 'Open Sans' ) );
		$fonts   = new AlertFonts( $this->service( AuditTrail::class ), $this->service( Transaction::class ), static fn(): mixed => null );
		update_option(
			AlertFonts::APPROVED_OPTION,
			array(
				array(
					'family'   => 'Open Sans',
					'category' => 'sans-serif',
					'weights'  => array( 400, 700 ),
				),
			),
			false
		);

		try {
			$html = (string) $this->page( $fonts )->render( $id, 'nonce123' );
			self::assertStringContainsString( "href='https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&#038;display=swap'", $html );
			self::assertStringContainsString( '--merchboard-font-family:"Open Sans", sans-serif;', $html );

			delete_option( AlertFonts::APPROVED_OPTION );
			$html = (string) $this->page( $fonts )->render( $id, 'nonce123' );
			self::assertStringNotContainsString( 'fonts.googleapis.com', $html, 'A removed font is never loaded.' );
			self::assertStringContainsString( '--merchboard-font-family:inherit;', $html );
		} finally {
			delete_option( AlertFonts::APPROVED_OPTION );
		}

		$csp = OverlayPage::security_headers( 'nonce123' )['Content-Security-Policy'];
		self::assertStringContainsString( "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;", $csp );
		self::assertStringContainsString( "font-src 'self' https://fonts.gstatic.com;", $csp );
		self::assertStringContainsString( "connect-src 'self';", $csp, 'Nothing else reaches Google.' );
	}

	public function test_overlay_urls_and_the_legacy_rule(): void {
		$id = OverlayId::generate();

		self::assertStringContainsString( $id, OverlayRoute::url( $id ) );
		self::assertMatchesRegularExpression( '#' . OverlayRoute::LEGACY_REGEX . '#', 'ccmerch-alert/creator-overlay/' . $id . '/' );
		self::assertDoesNotMatchRegularExpression( '#' . OverlayRoute::REGEX . '#', 'merchboard-alerts/overlay/' . strtoupper( $id ) . '/' );
	}

	/**
	 * A creator's overlay with settings; returns its secret id.
	 *
	 * @param int                  $creator_id Creator.
	 * @param array<string, mixed> $settings   Raw settings.
	 */
	private function overlay( int $creator_id, array $settings = array() ): string {
		$id = OverlayId::generate();
		$this->overlays->create( $creator_id, $id, OverlaySettings::from_array( $settings ) );

		return $id;
	}

	/**
	 * Stores an alert; returns its id.
	 *
	 * @param int    $creator_id    Creator.
	 * @param int    $order_item_id Order line.
	 * @param string $username      Username.
	 */
	private function event( int $creator_id, int $order_item_id, string $username ): int {
		$this->events->insert( new AlertEvent( $creator_id, 7, '7', $order_item_id, 10, 0, 'Hoodie', '', 1, 0, array(), $username, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) );

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', Tables::name( $this->wpdb, Tables::ALERT_EVENTS ) ) );
	}

	/**
	 * The feed with a recorded sleep and no image lookup.
	 */
	private function feed(): OverlayFeed {
		return new OverlayFeed(
			$this->overlays,
			$this->events,
			$this->creators,
			new AlertTemplate( static fn(): ?string => null ),
			static fn(): string => '',
			function ( int $seconds ): void {
				$this->slept[] = $seconds;
			}
		);
	}

	/**
	 * The page with the real bundle location.
	 *
	 * @param AlertFonts|null $fonts Approved fonts.
	 */
	private function page( ?AlertFonts $fonts = null ): OverlayPage {
		$root = dirname( __DIR__, 3 ) . '/';

		return new OverlayPage( new OverlayRoute( true ), $this->feed(), new OverlayAssets( $root, plugin_dir_url( $root . 'merchboard.php' ), new RecordingLogger() ), new RecordingLogger(), $fonts );
	}

	/**
	 * Registers the route on a fresh REST server.
	 */
	private function routes(): void {
		$controller     = new OverlayFeedController( $this->feed(), new OverlayRateLimiter(), new RecordingLogger() );
		$this->register = static function () use ( $controller ): void {
			$controller->register_routes();
		};
		add_action( 'rest_api_init', $this->register );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server per test.
		rest_get_server();
	}

	/**
	 * A feed request without waiting.
	 *
	 * @param string $overlay_id Overlay id.
	 */
	private function request( string $overlay_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/merchboard/v1/alerts/overlay/' . $overlay_id . '/feed' );
		$request->set_param( 'wait', false );

		return $request;
	}
}
