<?php
/**
 * Dashboard entry point, login redirect, wp-admin guard, admin bar,
 * Application Passwords and the dashboard slug with real WordPress users,
 * roles, rewrite rules and database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Access;

use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\Deactivator;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\AdminAreaGuard;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Access\CreatorRestrictions;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\DashboardAssets;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Dashboard\ShellController;
use MerchBoard\Modules\Dashboard\ShellResponse;
use MerchBoard\Modules\Settings\SettingsException;
use MerchBoard\Modules\Settings\SettingsService;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use WP;
use WP_Application_Passwords;
use WP_Error;
use WP_User;

final class DashboardAccessTest extends IntegrationTestCase {

	private const HOME = 'http://merchboard.test';

	private CreatorService $creators;

	private mixed $request_uri = null;

	protected function setUp(): void {
		parent::setUp();
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved only to restore it after the test.
		$this->reset_script_registry();
		$this->install();
		$this->creators = $this->service( CreatorService::class );
		delete_option( Settings::OPTION );
		$this->use_permalinks( '/%postname%/' );
	}

	protected function tearDown(): void {
		$this->reset_script_registry();
		$_SERVER['REQUEST_URI']          = $this->request_uri;
		$GLOBALS['wp_query']->query_vars = array();
		remove_all_filters( 'application_password_is_api_request' );
		remove_all_filters( 'wp_is_application_passwords_available' );
		delete_option( Settings::OPTION );
		$this->use_permalinks( '' );

		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $page ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			wp_delete_post( (int) $page, true );
		}

		parent::tearDown();
	}

	public function test_dashboard_response_for_every_kind_of_user(): void {
		$subscriber = $this->create_user( 'plain_subscriber' );
		$cap_only   = $this->create_user( 'cap_only' );
		$this->user( $cap_only )->add_role( Capabilities::CREATOR_ROLE );
		$inactive = $this->create_user( 'inactive_creator' );
		$this->creators->create( 'Inactive', CreatorStatus::Inactive, $inactive );
		$active = $this->create_user( 'active_creator' );
		$this->creators->create( 'Nova <b>Studio</b>', CreatorStatus::Active, $active );
		$admin_creator = $this->create_user( 'admin_creator' );
		$this->user( $admin_creator )->set_role( 'administrator' );
		$this->creators->create( 'Admin Creator', CreatorStatus::Active, $admin_creator );

		$matrix = array(
			'administrator without creator' => array( 1, 403 ),
			'subscriber'                    => array( $subscriber, 403 ),
			'creator role without record'   => array( $cap_only, 403 ),
			'inactive creator'              => array( $inactive, 403 ),
			'active creator'                => array( $active, 200 ),
			'administrator with creator'    => array( $admin_creator, 200 ),
		);

		$this->request( '/creator-dashboard/' );

		foreach ( $matrix as $case => [ $user_id, $status ] ) {
			wp_set_current_user( $user_id );
			self::assertSame( $status, $this->controller()->respond()->status, $case );
		}

		wp_set_current_user( $inactive );
		$this->reset_script_registry();
		$response = $this->controller()->respond();
		$html     = $this->controller()->render( $response );
		self::assertNull( $response->script_nonce );
		self::assertStringContainsString( 'Your access is not active', $html );
		self::assertStringContainsString( 'action=logout', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( 'Inactive', $html, 'Reveals nothing about the record.' );
		self::assertFalse( wp_script_is( 'merchboard-dashboard', 'registered' ), 'No bundle without access.' );
	}

	public function test_react_shell_prints_only_the_bundle_with_nonces_and_safe_bootstrap(): void {
		$active = $this->create_user( 'active_creator' );
		$this->creators->create( 'Nova <b>Studio</b>', CreatorStatus::Active, $active );
		wp_set_current_user( $active );
		$this->request( '/creator-dashboard/sales/' );
		wp_enqueue_script( 'mb-test-foreign', 'http://merchboard.test/foreign.js', array(), '1', true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter -- A theme/plugin script that must not reach the shell.

		$filters  = $this->callback_counts();
		$response = $this->controller()->respond();
		$html     = $this->controller()->render( $response );
		$nonce    = (string) $response->script_nonce;

		self::assertSame( 200, $response->status );
		self::assertNotSame( '', $nonce );
		self::assertStringContainsString( '<div id="merchboard-dashboard-root" class="merchboard-app">', $html );
		self::assertStringContainsString( 'build/dashboard.js?ver=test-build', $html );
		self::assertStringContainsString( 'build/dashboard.css?ver=test-build', $html );
		self::assertStringContainsString( 'wp-includes/js/dist/element', $html, 'WordPress provides React and wp-element.' );
		self::assertStringNotContainsString( 'foreign.js', $html, 'Other enqueued scripts are not printed.' );
		self::assertSame( substr_count( $html, '<script' ), substr_count( $html, 'nonce="' . $nonce . '"' ), 'Every script tag carries the nonce.' );
		self::assertStringContainsString( 'window.merchboardDashboard = {', $html );
		self::assertStringNotContainsString( 'Nova', $html, 'No creator data in the page (REST, phase 14).' );
		self::assertStringNotContainsString( '&amp;_wpnonce', $html, 'Logout URL in the bootstrap is not HTML-escaped.' );
		self::assertSame( $filters, $this->callback_counts(), 'The nonce filters are removed after rendering.' );

		wp_dequeue_script( 'mb-test-foreign' );
	}

	/**
	 * Number of callbacks on the script attribute filters.
	 *
	 * @return array<string, int>
	 */
	private function callback_counts(): array {
		$counts = array();

		foreach ( array( 'wp_script_attributes', 'wp_inline_script_attributes' ) as $hook ) {
			$counts[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? count( $GLOBALS['wp_filter'][ $hook ]->callbacks, COUNT_RECURSIVE ) : 0;
		}

		return $counts;
	}

	public function test_missing_build_gives_503(): void {
		$active = $this->create_user( 'active_creator' );
		$this->creators->create( 'Nova', CreatorStatus::Active, $active );
		wp_set_current_user( $active );
		$this->request( '/creator-dashboard/' );

		$controller = new ShellController( new DashboardRoute(), $this->service( CreatorContext::class ), new DashboardAssets( sys_get_temp_dir() . '/merchboard-no-build/', 'http://merchboard.test/', $this->service( Logger::class ) ) );
		$response   = $controller->respond();

		self::assertSame( 503, $response->status );
		self::assertStringContainsString( 'temporarily unavailable', $controller->render( $response ) );
	}

	public function test_logged_out_visitors_are_redirected_to_login_with_the_deep_link(): void {
		wp_set_current_user( 0 );
		$this->request( '/creator-dashboard/sales/2026/' );

		$response = $this->controller()->respond();

		self::assertSame( 302, $response->status );
		self::assertSame( wp_login_url( self::HOME . '/creator-dashboard/sales/2026/' ), $response->redirect );
		self::assertStringStartsWith( self::HOME . '/wp-login.php?redirect_to=', (string) $response->redirect );
	}

	public function test_access_changes_apply_on_the_next_request(): void {
		$user_id = $this->create_user( 'lifecycle' );
		$creator = $this->creators->create( 'Lifecycle', CreatorStatus::Active, $user_id );
		wp_set_current_user( $user_id );
		$this->request( '/creator-dashboard/' );
		self::assertSame( 200, $this->controller()->respond()->status );

		$this->as_admin( fn() => $this->creators->set_status( $creator->id, CreatorStatus::Inactive ) );
		self::assertSame( 403, $this->controller()->respond()->status, 'Deactivated.' );

		$this->as_admin( fn() => $this->creators->set_status( $creator->id, CreatorStatus::Active ) );
		$this->user( $user_id )->remove_role( Capabilities::CREATOR_ROLE );
		self::assertSame( 403, $this->controller()->respond()->status, 'Capability removed, record still active.' );

		$this->user( $user_id )->add_role( Capabilities::CREATOR_ROLE );
		self::assertSame( 200, $this->controller()->respond()->status );

		$this->as_admin( fn() => $this->creators->update( $creator->id, 'Lifecycle', CreatorStatus::Active, null ) );
		self::assertSame( 403, $this->controller()->respond()->status, 'Unlinked.' );
	}

	public function test_a_deleted_user_session_gets_no_dashboard(): void {
		$user_id = $this->create_user( 'deleted_creator' );
		$this->creators->create( 'Deleted', CreatorStatus::Active, $user_id );
		wp_delete_user( $user_id );

		wp_set_current_user( $user_id );
		$this->request( '/creator-dashboard/' );

		self::assertSame( 302, $this->controller()->respond()->status, 'WordPress no longer knows the user: login.' );
	}

	public function test_database_failure_fails_closed(): void {
		$user_id = $this->create_user( 'db_failure' );
		$this->creators->create( 'Nova', CreatorStatus::Active, $user_id );
		wp_set_current_user( $user_id );
		$this->request( '/creator-dashboard/' );

		$this->execute( 'RENAME TABLE %i TO %i', $this->table( Tables::CREATORS ), $this->table( Tables::CREATORS ) . '_away' );
		$this->wpdb->suppress_errors( true );
		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.

		try {
			self::assertSame( 403, $this->controller()->respond()->status );
			self::assertFalse( ( new CreatorRestrictions() )->applies_to( 1 ), 'Admins are not affected by the failure.' );
		} finally {
			$this->execute( 'RENAME TABLE %i TO %i', $this->table( Tables::CREATORS ) . '_away', $this->table( Tables::CREATORS ) );
			$this->forget_table_metadata();
		}

		self::assertStringContainsString( 'Creator access check failed; access denied.', (string) file_get_contents( MERCHBOARD_TEST_LOG ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.
	}

	public function test_rewrite_rules_route_the_slug_and_deep_links_only(): void {
		$route = new DashboardRoute();

		self::assertSame(
			array(
				DashboardRoute::QUERY_VAR => '1',
				DashboardRoute::PATH_VAR  => '',
			),
			$this->parse( '/creator-dashboard/' )
		);
		self::assertSame(
			array(
				DashboardRoute::QUERY_VAR => '1',
				DashboardRoute::PATH_VAR  => '',
			),
			$this->parse( '/creator-dashboard' )
		);
		self::assertSame(
			array(
				DashboardRoute::QUERY_VAR => '1',
				DashboardRoute::PATH_VAR  => 'sales/2026',
			),
			$this->parse( '/creator-dashboard/sales/2026/' )
		);
		self::assertArrayNotHasKey( DashboardRoute::QUERY_VAR, $this->parse( '/creator-dashboard-evil/' ) );
		self::assertArrayNotHasKey( DashboardRoute::QUERY_VAR, $this->parse( '/about/creator-dashboard/' ) );

		// Deep links are routed, but only plain paths reach the controller.
		$this->request( '/creator-dashboard/%3Cscript%3E/' );
		self::assertSame( '', $route->path() );
		self::assertSame( self::HOME . '/creator-dashboard/', $route->url() );
	}

	public function test_changing_the_slug_moves_the_route_and_is_audited(): void {
		$this->creators->create( 'Audit neighbour', CreatorStatus::Active, null );
		$audit_before = $this->count_rows( Tables::AUDIT_LOG );
		wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'About',
				'post_name'   => 'about',
			)
		);
		$service = $this->service( SettingsService::class );

		foreach ( array(
			'about'      => SettingsException::SLUG_IN_USE,
			'wp-admin'   => SettingsException::INVALID_SLUG,
			'my-account' => SettingsException::INVALID_SLUG,
		) as $slug => $reason ) {
			try {
				$service->set_dashboard_slug( $slug );
				self::fail( 'Expected SettingsException for ' . $slug );
			} catch ( SettingsException $e ) {
				self::assertSame( $reason, $e->reason(), $slug );
			}
		}

		self::assertTrue( $service->set_dashboard_slug( 'partner' ) );
		self::assertFalse( get_option( 'rewrite_rules' ), 'Stored rules invalidated.' );

		// The next request registers the new rule; the old URL is gone.
		$this->rebuild_rewrite_rules();
		self::assertSame( '1', $this->parse( '/partner/sales/' )[ DashboardRoute::QUERY_VAR ] ?? null );
		self::assertArrayNotHasKey( DashboardRoute::QUERY_VAR, $this->parse( '/creator-dashboard/' ) );
		self::assertSame( self::HOME . '/partner/', ( new DashboardRoute() )->url() );

		$audit = array_slice( $this->rows( Tables::AUDIT_LOG ), $audit_before );
		self::assertSame( array( 'settings_updated' ), array_column( $audit, 'action' ) );
		self::assertSame( '1', $audit[0]['actor_user_id'] );
		self::assertSame( '{"setting":"dashboard_slug","previous_value":"creator-dashboard","value":"partner"}', $audit[0]['context'] );
	}

	public function test_self_healing_and_deactivation_invalidate_stale_rules(): void {
		$route = new DashboardRoute();
		self::assertArrayHasKey( $route->regex(), (array) get_option( 'rewrite_rules' ) );

		$route->ensure_rewrite_rules();
		self::assertIsArray( get_option( 'rewrite_rules' ), 'Current rules are kept.' );

		update_option( 'rewrite_rules', array( '^other/?$' => 'index.php' ) );
		$route->ensure_rewrite_rules();
		self::assertFalse( get_option( 'rewrite_rules' ), 'Rules without the dashboard are rebuilt.' );

		$this->rebuild_rewrite_rules();
		Settings::save( array( Settings::DASHBOARD_SLUG => 'partner' ) );
		( new Deactivator() )->deactivate();
		self::assertFalse( get_option( 'rewrite_rules' ) );
		self::assertSame( 'partner', Settings::dashboard_slug(), 'Deactivation deletes no settings.' );
	}

	public function test_restrictions_with_real_roles(): void {
		$active = $this->create_user( 'active_creator' );
		$this->creators->create( 'Active', CreatorStatus::Active, $active );
		$inactive = $this->create_user( 'inactive_creator' );
		$this->creators->create( 'Inactive', CreatorStatus::Inactive, $inactive );
		$editor = $this->create_user( 'editor_creator' );
		$this->user( $editor )->set_role( 'editor' );
		$this->creators->create( 'Editor', CreatorStatus::Active, $editor );
		add_role(
			'mb_test_shop_manager',
			'Shop Manager',
			array(
				'read'                           => true,
				Capabilities::MANAGE_WOOCOMMERCE => true,
			)
		); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
		$shop = $this->create_user( 'shop_creator' );
		$this->user( $shop )->set_role( 'mb_test_shop_manager' );
		$this->creators->create( 'Shop', CreatorStatus::Active, $shop );
		$subscriber = $this->create_user( 'plain_subscriber' );

		$guard = new AdminAreaGuard( new CreatorRestrictions(), new DashboardRoute() );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		try {
			$matrix = array(
				'administrator'      => array( 1, false ),
				'active creator'     => array( $active, true ),
				'inactive creator'   => array( $inactive, true ),
				'editor + creator'   => array( $editor, false ),
				'shop mgr + creator' => array( $shop, false ),
				'subscriber'         => array( $subscriber, false ),
			);

			foreach ( $matrix as $case => [ $user_id, $restricted ] ) {
				wp_set_current_user( $user_id );
				self::assertSame( $restricted, $guard->should_redirect( $user_id, 'index.php' ), $case . ': wp-admin' );
				self::assertSame( $restricted, $guard->should_redirect( $user_id, 'profile.php' ), $case . ': profile' );
				self::assertFalse( $guard->should_redirect( $user_id, 'admin-ajax.php' ), $case . ': AJAX' );
				self::assertFalse( $guard->should_redirect( $user_id, 'admin-post.php' ), $case . ': admin-post' );
				self::assertFalse( $guard->should_redirect( $user_id, 'async-upload.php' ), $case . ': upload' );
				self::assertSame( ! $restricted, (bool) apply_filters( 'show_admin_bar', true ), $case . ': admin bar' );
				self::assertSame( ! $restricted, wp_is_application_passwords_available_for_user( $user_id ), $case . ': Application Passwords' );

				$target = apply_filters( 'login_redirect', admin_url(), 'https://evil.example/', $this->user( $user_id ) );
				self::assertSame( $restricted ? self::HOME . '/creator-dashboard/' : admin_url(), $target, $case . ': login redirect' );

				$wc_target = apply_filters( 'woocommerce_login_redirect', self::HOME . '/my-account/', $this->user( $user_id ) );
				self::assertSame( $restricted ? self::HOME . '/creator-dashboard/' : self::HOME . '/my-account/', $wc_target, $case . ': WooCommerce login redirect' );
			}
		} finally {
			remove_role( 'mb_test_shop_manager' );
		}

		$deep = apply_filters( 'login_redirect', admin_url(), self::HOME . '/creator-dashboard/payouts/', $this->user( $active ) );
		self::assertSame( self::HOME . '/creator-dashboard/payouts/', $deep, 'Validated deep link is kept.' );
		self::assertSame( self::HOME . '/creator-dashboard/', apply_filters( 'login_redirect', admin_url(), '//evil.example/creator-dashboard/', $this->user( $active ) ) );
		self::assertSame( admin_url(), apply_filters( 'login_redirect', admin_url(), '', new WP_Error( 'incorrect_password' ) ) );
	}

	public function test_application_passwords_of_pure_creators_cannot_be_used(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );

		$creator = $this->create_user( 'app_creator' );
		$this->creators->create( 'App', CreatorStatus::Active, $creator );
		$editor = $this->create_user( 'app_editor' );
		$this->user( $editor )->set_role( 'editor' );
		$this->creators->create( 'App Editor', CreatorStatus::Active, $editor );

		// Created directly (as if before the user became a pure creator).
		$creator_password = WP_Application_Passwords::create_new_application_password( $creator, array( 'name' => 'Old' ) );
		$editor_password  = WP_Application_Passwords::create_new_application_password( $editor, array( 'name' => 'Tooling' ) );
		self::assertIsArray( $creator_password );
		self::assertIsArray( $editor_password );

		$denied = wp_authenticate_application_password( null, 'app_creator', $creator_password[0] );
		self::assertInstanceOf( WP_Error::class, $denied );
		self::assertSame( 'application_passwords_disabled_for_user', $denied->get_error_code() );

		$allowed = wp_authenticate_application_password( null, 'app_editor', $editor_password[0] );
		self::assertInstanceOf( WP_User::class, $allowed );
		self::assertSame( $editor, $allowed->ID );
	}

	/**
	 * Runs a creator change as the administrator, then restores the current user.
	 *
	 * @param callable $change Change.
	 */
	private function as_admin( callable $change ): void {
		$current = get_current_user_id();
		wp_set_current_user( 1 );

		try {
			$change();
		} finally {
			wp_set_current_user( $current );
		}
	}

	/**
	 * The controller with a fixture build, so the test does not depend on
	 * `npm run build` having run.
	 */
	private function controller(): ShellController {
		$dir = sys_get_temp_dir() . '/merchboard-it-build/';

		if ( ! is_dir( $dir . 'build' ) ) {
			mkdir( $dir . 'build', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		}

		file_put_contents( $dir . 'build/dashboard.asset.php', "<?php return array( 'dependencies' => array( 'react-jsx-runtime', 'wp-element', 'wp-i18n' ), 'version' => 'test-build' );" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $dir . 'build/dashboard.js', '/* bundle */' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return new ShellController(
			new DashboardRoute(),
			$this->service( CreatorContext::class ),
			new DashboardAssets( $dir, 'http://merchboard.test/wp-content/plugins/merchboard/', $this->service( Logger::class ) )
		);
	}

	/**
	 * A fresh script/style registry, as every request starts with one.
	 */
	private function reset_script_registry(): void {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates a new request.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates a new request.
	}

	/**
	 * Parses a request path like WordPress does and sets the main query vars.
	 *
	 * @param string $path Request path.
	 */
	private function request( string $path ): void {
		foreach ( $this->parse( $path ) as $name => $value ) {
			set_query_var( $name, $value );
		}
	}

	/**
	 * The query vars WordPress derives from a request path.
	 *
	 * @param string $path Request path.
	 * @return array<string, mixed>
	 */
	private function parse( string $path ): array {
		$_SERVER['REQUEST_URI']          = $path;
		$GLOBALS['wp_query']->query_vars = array();

		$wp = new WP();
		$wp->parse_request();

		return $wp->query_vars;
	}

	/**
	 * Switches the permalink structure and rebuilds the rules with the plugin's route.
	 *
	 * @param string $structure Permalink structure ('' = plain).
	 */
	private function use_permalinks( string $structure ): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( $structure );
		$this->rebuild_rewrite_rules();
	}

	/**
	 * What the next request does: registers the current rule and rebuilds.
	 */
	private function rebuild_rewrite_rules(): void {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();
		( new DashboardRoute() )->add_rewrite_rule();
		$wp_rewrite->flush_rules( false );
	}

	private function user( int $user_id ): WP_User {
		return new WP_User( $user_id );
	}
}
