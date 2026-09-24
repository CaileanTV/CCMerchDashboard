<?php
/**
 * Tests for the dashboard entry point.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use Brain\Monkey\Functions;
use MerchBoard\Core\Container;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\DashboardModule;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Dashboard\ShellController;
use MerchBoard\Modules\Dashboard\ShellResponse;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass( ShellController::class )]
#[CoversClass( ShellResponse::class )]
#[CoversClass( DashboardModule::class )]
final class ShellControllerTest extends TestCase {

	private FakeDatabase $db;

	private CreatorRepository $creators;

	private RecordingLogger $logger;

	private int $current_user_id = 0;

	/**
	 * Capabilities per user id.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $user_caps = array();

	/**
	 * Query vars of the simulated main query.
	 *
	 * @var array<string, mixed>
	 */
	private array $query_vars = array( DashboardRoute::QUERY_VAR => '1' );

	protected function setUp(): void {
		parent::setUp();
		$this->db       = new FakeDatabase();
		$this->creators = new CreatorRepository( $this->db );
		$this->logger   = new RecordingLogger();
		InMemoryOptions::install( array( 'permalink_structure' => '/%postname%/' ) );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'current_time' )->justReturn( '2026-09-24 12:00:00' );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'wp_login_url' )->alias( static fn( string $redirect = '' ): string => 'https://shop.test/wp-login.php?redirect_to=' . rawurlencode( $redirect ) );
		Functions\when( 'wp_logout_url' )->alias( static fn( string $redirect = '' ): string => 'https://shop.test/wp-login.php?action=logout&_wpnonce=abc&redirect_to=' . rawurlencode( $redirect ) );
		Functions\when( 'get_bloginfo' )->alias( static fn( string $show ): string => 'charset' === $show ? 'UTF-8' : 'Shop <b>&</b> Co' );
		Functions\when( 'language_attributes' )->alias(
			static function (): void {
				echo 'lang="en-US"';
			}
		);
		Functions\when( 'get_query_var' )->alias( fn( string $name ): mixed => $this->query_vars[ $name ] ?? '' );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user_id );
		Functions\when( 'is_user_logged_in' )->alias( fn(): bool => $this->current_user_id > 0 );
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool => in_array( $capability, $this->user_caps[ $user_id ] ?? array(), true )
		);
	}

	public function test_logged_out_visitors_are_sent_to_login_and_back_to_the_deep_link(): void {
		$this->query_vars[ DashboardRoute::PATH_VAR ] = 'sales/2026';

		$response = $this->controller()->respond();

		self::assertSame( 302, $response->status );
		self::assertSame( 'https://shop.test/wp-login.php?redirect_to=' . rawurlencode( 'https://shop.test/creator-dashboard/sales/2026/' ), $response->redirect );
		self::assertNull( $response->template );
	}

	public function test_malicious_deep_link_redirects_to_the_start_page_after_login(): void {
		$this->query_vars[ DashboardRoute::PATH_VAR ] = '../../wp-admin';

		self::assertSame( 'https://shop.test/wp-login.php?redirect_to=' . rawurlencode( 'https://shop.test/creator-dashboard/' ), $this->controller()->respond()->redirect );
	}

	/**
	 * Logged-in users without creator access.
	 *
	 * @return array<string, array{array<int, string>, CreatorStatus|null}>
	 */
	public static function no_access(): array {
		$admin = array( Capabilities::MANAGE, Capabilities::MANAGE_CREATORS, 'manage_options' );

		return array(
			'subscriber'                     => array( array( 'read' ), null ),
			'administrator without creator'  => array( $admin, null ),
			'creator role, no record'        => array( array( 'read', Capabilities::ACCESS_DASHBOARD ), null ),
			'inactive creator'               => array( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Inactive ),
			'active record, capability lost' => array( array( 'read' ), CreatorStatus::Active ),
		);
	}

	/**
	 * Every user without creator access gets the 403 page.
	 *
	 * @param array<int, string> $caps   Capabilities.
	 * @param CreatorStatus|null $status Linked creator status; null = no record.
	 */
	#[DataProvider( 'no_access' )]
	public function test_users_without_creator_access_get_403( array $caps, ?CreatorStatus $status ): void {
		$this->login( $caps, $status );

		$response = $this->controller()->respond();

		self::assertSame( 403, $response->status );
		self::assertSame( ShellResponse::TEMPLATE_NO_ACCESS, $response->template );
		self::assertArrayNotHasKey( 'creator_name', $response->view );

		$html = $this->controller()->render( $response );
		self::assertStringContainsString( 'Your access is not active', $html );
		self::assertStringContainsString( 'action=logout', $html );
		self::assertStringNotContainsString( 'wp-admin', $html, 'Never a way into wp-admin.' );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( 'Shop &lt;b&gt;&amp;&lt;/b&gt; Co', $html, 'Site name escaped.' );
	}

	public function test_active_creator_gets_the_shell_with_escaped_values_and_no_scripts(): void {
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active, 'Nova <img src=x onerror=alert(1)>' );

		$response = $this->controller()->respond();

		self::assertSame( 200, $response->status );
		self::assertSame( ShellResponse::TEMPLATE_SHELL, $response->template );

		$html = $this->controller()->render( $response );
		self::assertStringStartsWith( '<!DOCTYPE html>', $html );
		self::assertStringContainsString( 'Welcome, Nova &lt;img src=x onerror=alert(1)&gt;', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( 'noindex, nofollow', $html );
		self::assertStringContainsString( 'action=logout', $html );
		self::assertStringNotContainsString( 'This page is not available yet', $html );
	}

	public function test_deep_links_render_the_shell_for_creators(): void {
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active );
		$this->query_vars[ DashboardRoute::PATH_VAR ] = 'sales';

		$response = $this->controller()->respond();

		self::assertSame( 200, $response->status );
		self::assertStringContainsString( 'This page is not available yet', $this->controller()->render( $response ) );
	}

	public function test_creator_identity_never_comes_from_the_request(): void {
		$this->user_caps[6] = array( Capabilities::ACCESS_DASHBOARD );
		$this->creators->create( 'Other', CreatorStatus::Active, 6 );
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active, 'Mine' );
		$_GET['creator_id'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Proves request data is ignored.
		$_GET['user_id']    = '6'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Proves request data is ignored.

		try {
			self::assertSame( 'Mine', $this->controller()->respond()->view['creator_name'] );
		} finally {
			unset( $_GET['creator_id'], $_GET['user_id'] );
		}
	}

	public function test_database_failure_denies_access_and_is_logged(): void {
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active );
		$this->db->data['wp_merchboard_creators'][1]['status'] = 'corrupted';

		self::assertSame( 403, $this->controller()->respond()->status );
		self::assertSame( array( 'error' ), array_column( $this->logger->entries, 'level' ) );
	}

	public function test_non_dashboard_requests_are_ignored(): void {
		$this->query_vars = array();

		ob_start();
		$this->controller()->handle_request();
		self::assertSame( '', ob_get_clean() );
	}

	public function test_security_headers(): void {
		$headers = ShellController::security_headers();

		self::assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
		self::assertSame( 'strict-origin-when-cross-origin', $headers['Referrer-Policy'] );
		self::assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		self::assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		self::assertStringContainsString( "default-src 'none'", $headers['Content-Security-Policy'] );
		self::assertStringContainsString( "frame-ancestors 'self'", $headers['Content-Security-Policy'] );
		self::assertStringNotContainsString( 'script-src', $headers['Content-Security-Policy'], 'No scripts at all.' );
	}

	public function test_unknown_templates_are_refused(): void {
		$this->expectException( RuntimeException::class );

		$this->controller()->render( ShellResponse::redirect( 'https://shop.test/' ) );
	}

	public function test_module_registers_route_shell_and_hooks(): void {
		$container = new Container();
		$container->set( CreatorContext::class, fn(): CreatorContext => new CreatorContext( $this->creators, $this->logger ) );

		( new DashboardModule() )->register( $container );

		self::assertSame( 'dashboard', ( new DashboardModule() )->id() );
		self::assertInstanceOf( ShellController::class, $container->get( ShellController::class ) );
		self::assertNotFalse( has_action( 'init' ) );
		self::assertNotFalse( has_filter( 'query_vars' ) );
		self::assertNotFalse( has_action( 'template_redirect' ) );
		self::assertSame( 10, has_action( 'merchboard_dashboard_slug_changed', array( DashboardRoute::class, 'invalidate_rewrite_rules' ) ) );
	}

	/**
	 * Logs in user 5 with capabilities and an optional linked creator.
	 *
	 * @param array<int, string> $caps   Capabilities.
	 * @param CreatorStatus|null $status Linked creator status; null = no record.
	 * @param string             $name   Creator display name.
	 */
	private function login( array $caps, ?CreatorStatus $status, string $name = 'Nova' ): void {
		$this->user_caps[5]    = $caps;
		$this->current_user_id = 5;

		if ( null !== $status ) {
			$this->creators->create( $name, $status, 5 );
		}
	}

	private function controller(): ShellController {
		return new ShellController( new DashboardRoute(), new CreatorContext( $this->creators, $this->logger ) );
	}
}
