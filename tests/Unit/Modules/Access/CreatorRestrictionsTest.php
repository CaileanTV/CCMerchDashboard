<?php
/**
 * Tests for the pure-creator restrictions: login redirect, wp-admin guard,
 * admin bar and Application Passwords.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Access;

use Brain\Monkey\Functions;
use MerchBoard\Core\Container;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Access\AccessModule;
use MerchBoard\Modules\Access\AdminAreaGuard;
use MerchBoard\Modules\Access\CreatorRestrictions;
use MerchBoard\Modules\Access\LoginRedirect;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use WP_User;

#[CoversClass( CreatorRestrictions::class )]
#[CoversClass( LoginRedirect::class )]
#[CoversClass( AdminAreaGuard::class )]
#[CoversClass( AccessModule::class )]
final class CreatorRestrictionsTest extends TestCase {

	private const DASHBOARD = 'https://shop.test/creator-dashboard/';

	/**
	 * Capabilities per user id (users not listed do not exist).
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $user_caps = array();

	private int $current_user_id = 0;

	protected function setUp(): void {
		parent::setUp();
		InMemoryOptions::install( array( 'permalink_structure' => '/%postname%/' ) );

		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user_id );
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool => in_array( $capability, $this->user_caps[ $user_id ] ?? array(), true )
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.
		Functions\when( 'wp_validate_redirect' )->alias(
			static function ( string $location, string $fallback ): string {
				$host = parse_url( str_starts_with( $location, '//' ) ? 'http:' . $location : $location, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.

				return null === $host || 'shop.test' === $host ? $location : $fallback;
			}
		);
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
	}

	/**
	 * Who counts as a pure creator (ADR-026).
	 *
	 * @return array<string, array{array<int, string>, bool}>
	 */
	public static function users(): array {
		$creator = array( 'read', Capabilities::ACCESS_DASHBOARD );

		return array(
			'creator role'                   => array( $creator, true ),
			'creator with view_logs only'    => array( array_merge( $creator, array( Capabilities::VIEW_LOGS ) ), true ),
			'subscriber'                     => array( array( 'read' ), false ),
			'no capabilities'                => array( array(), false ),
			'administrator'                  => array( array( 'read', 'manage_options', Capabilities::MANAGE, Capabilities::EDIT_POSTS ), false ),
			'administrator who is a creator' => array( array_merge( $creator, array( 'manage_options', Capabilities::MANAGE, Capabilities::EDIT_POSTS ) ), false ),
			'editor who is a creator'        => array( array_merge( $creator, array( Capabilities::EDIT_POSTS ) ), false ),
			'shop manager who is a creator'  => array( array_merge( $creator, array( Capabilities::MANAGE_WOOCOMMERCE ) ), false ),
			'plugin manager who is creator'  => array( array_merge( $creator, array( Capabilities::MANAGE ) ), false ),
			'customer'                       => array( array( 'read' ), false ),
		);
	}

	/**
	 * The whole matrix for every restriction.
	 *
	 * @param array<int, string> $caps       Capabilities of user 5.
	 * @param bool               $restricted Whether user 5 is a pure creator.
	 */
	#[DataProvider( 'users' )]
	public function test_restrictions_apply_only_to_pure_creators( array $caps, bool $restricted ): void {
		$this->user_caps[5]    = $caps;
		$this->current_user_id = 5;
		$restrictions          = new CreatorRestrictions();

		self::assertSame( $restricted, $restrictions->applies_to( 5 ) );
		self::assertSame( ! $restricted, $restrictions->filter_admin_bar( true ), 'Admin bar' );
		self::assertFalse( $restrictions->filter_admin_bar( false ), 'Never shows a hidden admin bar.' );
		self::assertSame( ! $restricted, $restrictions->filter_application_passwords( true, $this->user( 5 ) ), 'Application Passwords' );
		self::assertFalse( $restrictions->filter_application_passwords( false, $this->user( 5 ) ), 'Never enables them.' );
		self::assertSame( $restricted, $this->guard()->should_redirect( 5, 'index.php' ), 'wp-admin' );
		self::assertSame( $restricted, $this->guard()->should_redirect( 5, 'profile.php' ), 'profile' );
		self::assertSame( $restricted ? self::DASHBOARD : null, $this->redirect()->target( $this->user( 5 ), 'https://shop.test/wp-admin/' ), 'Login redirect' );
	}

	public function test_unknown_users_and_visitors_are_never_restricted(): void {
		$restrictions = new CreatorRestrictions();

		self::assertFalse( $restrictions->applies_to( 0 ) );
		self::assertFalse( $restrictions->applies_to( 99 ) );
		self::assertFalse( $restrictions->applies_to( -1 ) );
		self::assertTrue( $restrictions->filter_admin_bar( true ), 'Visitors: WordPress decides.' );
	}

	public function test_application_passwords_fail_closed_without_a_user_object(): void {
		self::assertFalse( ( new CreatorRestrictions() )->filter_application_passwords( true, null ) );
		self::assertFalse( ( new CreatorRestrictions() )->filter_application_passwords( true, 5 ) );
	}

	public function test_admin_ajax_post_upload_cron_and_rest_are_never_redirected(): void {
		$this->user_caps[5] = array( 'read', Capabilities::ACCESS_DASHBOARD );
		$guard              = $this->guard();

		foreach ( array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ) as $script ) {
			self::assertFalse( $guard->should_redirect( 5, $script ), $script );
		}

		self::assertTrue( $guard->should_redirect( 5, 'edit.php' ) );
		self::assertTrue( $guard->should_redirect( 5, 'options-general.php' ) );

		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		self::assertFalse( $guard->should_redirect( 5, 'index.php' ), 'AJAX' );

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		self::assertFalse( $guard->should_redirect( 5, 'index.php' ), 'Cron' );
	}

	public function test_guard_redirects_pure_creators_to_the_dashboard_and_exits(): void {
		$this->user_caps[5]    = array( 'read', Capabilities::ACCESS_DASHBOARD );
		$this->current_user_id = 5;
		$GLOBALS['pagenow']    = 'profile.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the admin request.

		Functions\expect( 'wp_safe_redirect' )->once()->with( self::DASHBOARD )->andThrow( new \RuntimeException( 'redirected' ) );

		try {
			$this->expectExceptionMessage( 'redirected' );
			$this->guard()->maybe_redirect();
		} finally {
			unset( $GLOBALS['pagenow'] );
		}
	}

	public function test_guard_leaves_other_users_alone(): void {
		$this->user_caps[1]    = array( 'read', 'manage_options', Capabilities::MANAGE, Capabilities::EDIT_POSTS );
		$this->current_user_id = 1;
		$GLOBALS['pagenow']    = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the admin request.
		Functions\expect( 'wp_safe_redirect' )->never();

		try {
			$this->guard()->maybe_redirect();
		} finally {
			unset( $GLOBALS['pagenow'] );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Requested redirect targets and where a pure creator ends up.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public static function requested_targets(): array {
		return array(
			'none'                => array( '', self::DASHBOARD ),
			'wp-admin'            => array( 'https://shop.test/wp-admin/', self::DASHBOARD ),
			'profile'             => array( 'https://shop.test/wp-admin/profile.php', self::DASHBOARD ),
			'my account'          => array( 'https://shop.test/my-account/', self::DASHBOARD ),
			'external'            => array( 'https://evil.test/creator-dashboard/', self::DASHBOARD ),
			'protocol relative'   => array( '//evil.test/', self::DASHBOARD ),
			'javascript'          => array( 'javascript:alert(1)', self::DASHBOARD ),
			'traversal'           => array( 'https://shop.test/creator-dashboard/../wp-admin/', self::DASHBOARD ),
			'not a string'        => array( array( 'x' ), self::DASHBOARD ),
			'dashboard deep link' => array( 'https://shop.test/creator-dashboard/sales/', 'https://shop.test/creator-dashboard/sales/' ),
			'relative deep link'  => array( '/creator-dashboard/payouts', 'https://shop.test/creator-dashboard/payouts/' ),
		);
	}

	/**
	 * Login redirects (wp-login.php and WooCommerce) for a pure creator.
	 *
	 * @param mixed  $requested Requested target.
	 * @param string $expected  Final target.
	 */
	#[DataProvider( 'requested_targets' )]
	public function test_pure_creators_land_on_the_dashboard_or_a_valid_deep_link( mixed $requested, string $expected ): void {
		$this->user_caps[5] = array( 'read', Capabilities::ACCESS_DASHBOARD );
		$redirect           = $this->redirect();

		self::assertSame( $expected, $redirect->filter_login_redirect( 'https://shop.test/wp-admin/', $requested, $this->user( 5 ) ) );
		self::assertSame( $expected, $redirect->filter_woocommerce_login_redirect( $requested, $this->user( 5 ) ) );
	}

	public function test_login_redirect_leaves_other_users_and_failed_logins_alone(): void {
		$this->user_caps[1] = array( 'read', 'manage_options', Capabilities::MANAGE, Capabilities::EDIT_POSTS );
		$this->user_caps[2] = array( 'read' );
		$redirect           = $this->redirect();

		self::assertSame( 'https://shop.test/wp-admin/', $redirect->filter_login_redirect( 'https://shop.test/wp-admin/', '', $this->user( 1 ) ) );
		self::assertSame( 'https://shop.test/my-account/', $redirect->filter_woocommerce_login_redirect( 'https://shop.test/my-account/', $this->user( 2 ) ) );
		self::assertSame( 'https://shop.test/wp-admin/', $redirect->filter_login_redirect( 'https://shop.test/wp-admin/', '', new stdClass() ) );
	}

	public function test_module_registers_hooks_with_the_documented_priorities(): void {
		$container = new Container();
		$container->set( CreatorRepository::class, static fn(): CreatorRepository => new CreatorRepository( new FakeDatabase() ) );
		$container->set( Logger::class, static fn(): Logger => new RecordingLogger() );
		$container->set( DashboardRoute::class, static fn(): DashboardRoute => new DashboardRoute() );

		( new AccessModule() )->register( $container );

		self::assertInstanceOf( LoginRedirect::class, $container->get( LoginRedirect::class ) );
		self::assertInstanceOf( AdminAreaGuard::class, $container->get( AdminAreaGuard::class ) );
		self::assertNotFalse( has_filter( 'login_redirect' ) );
		self::assertNotFalse( has_filter( 'woocommerce_login_redirect' ) );
		self::assertNotFalse( has_action( 'admin_init' ) );
		self::assertNotFalse( has_action( 'admin_page_access_denied' ) );
		self::assertNotFalse( has_filter( 'show_admin_bar' ) );
		self::assertNotFalse( has_filter( 'wp_is_application_passwords_available_for_user' ) );
	}

	private function user( int $id ): WP_User {
		$user     = new WP_User();
		$user->ID = $id;

		return $user;
	}

	private function guard(): AdminAreaGuard {
		return new AdminAreaGuard( new CreatorRestrictions(), new DashboardRoute() );
	}

	private function redirect(): LoginRedirect {
		return new LoginRedirect( new CreatorRestrictions(), new DashboardRoute() );
	}
}
