<?php
/**
 * Tests for the dashboard entry point and its assets.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use Brain\Monkey\Functions;
use MerchBoard\Core\Container;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Dashboard\Branding\BrandLogo;
use MerchBoard\Modules\Dashboard\Branding\LogoAttachment;
use MerchBoard\Modules\Dashboard\DashboardAssets;
use MerchBoard\Modules\Dashboard\DashboardModule;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Dashboard\DashboardSections;
use MerchBoard\Modules\Dashboard\FallbackStyles;
use MerchBoard\Modules\Dashboard\ShellController;
use MerchBoard\Modules\Dashboard\ShellResponse;
use MerchBoard\Modules\Dashboard\ThemePreference;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\FakeMediaLibrary;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass( ShellController::class )]
#[CoversClass( ShellResponse::class )]
#[CoversClass( DashboardAssets::class )]
#[CoversClass( DashboardModule::class )]
#[CoversClass( FallbackStyles::class )]
#[CoversClass( ThemePreference::class )]
final class ShellControllerTest extends TestCase {

	private FakeDatabase $db;

	private CreatorRepository $creators;

	private RecordingLogger $logger;

	private InMemoryOptions $options;

	private int $current_user_id = 0;

	/**
	 * User meta per user id and key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $user_meta = array();

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

	/**
	 * Registered scripts and styles, inline scripts and printed handles.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $wp_assets = array();

	/**
	 * Temporary plugin directory with a fake build.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->db         = new FakeDatabase();
		$this->creators   = new CreatorRepository( $this->db );
		$this->logger     = new RecordingLogger();
		$this->options    = InMemoryOptions::install( array( 'permalink_structure' => '/%postname%/' ) );
		$this->plugin_dir = sys_get_temp_dir() . '/merchboard-shell-' . bin2hex( random_bytes( 4 ) ) . '/';
		$this->write_build( "<?php return array( 'dependencies' => array( 'react-jsx-runtime', 'wp-element', 'wp-i18n' ), 'version' => 'abc123' );" );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'current_time' )->justReturn( '2026-09-24 12:00:00' );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_login_url' )->alias( static fn( string $redirect = '' ): string => 'https://shop.test/wp-login.php?redirect_to=' . rawurlencode( $redirect ) );
		// Like WordPress: HTML-escaped.
		Functions\when( 'wp_logout_url' )->alias( static fn( string $redirect = '' ): string => 'https://shop.test/wp-login.php?action=logout&amp;_wpnonce=abc&amp;redirect_to=' . rawurlencode( $redirect ) );
		Functions\when( 'get_bloginfo' )->alias( static fn( string $show ): string => 'charset' === $show ? 'UTF-8' : 'Shop <b>&</b> Co' );
		Functions\when( 'determine_locale' )->justReturn( 'da_DK' );
		Functions\when( 'wp_lostpassword_url' )->justReturn( 'https://shop.test/wp-login.php?action=lostpassword' );
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => in_array( $capability, $this->user_caps[ $this->current_user_id ] ?? array(), true ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text ): string => strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'wp_set_script_translations' )->alias(
			function ( string $handle, string $domain, string $path ): bool {
				$this->wp_assets['translations'] = array( $handle, $domain, $path );
				return true;
			}
		);
		Functions\when( 'language_attributes' )->alias(
			static function (): void {
				echo 'lang="en-US"';
			}
		);
		Functions\when( 'get_query_var' )->alias( fn( string $name ): mixed => $this->query_vars[ $name ] ?? '' );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user_id );
		Functions\when( 'get_user_meta' )->alias( fn( int $user_id, string $key ): mixed => $this->user_meta[ $user_id ][ $key ] ?? '' );
		Functions\when( 'is_user_logged_in' )->alias( fn(): bool => $this->current_user_id > 0 );
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool => in_array( $capability, $this->user_caps[ $user_id ] ?? array(), true )
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data, int $flags = 0 ): string|false => json_encode( $data, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stand-in.
		Functions\when( 'wp_register_script' )->alias(
			function ( string $handle, string $src, array $deps, string $ver, bool $footer ): bool {
				$this->wp_assets['script'] = array( $handle, $src, $deps, $ver, $footer );
				return true;
			}
		);
		Functions\when( 'wp_register_style' )->alias(
			function ( string $handle, string $src, array $deps, string $ver ): bool {
				$this->wp_assets['style'] = array( $handle, $src, $deps, $ver );
				return true;
			}
		);
		Functions\when( 'wp_style_add_data' )->alias(
			function ( string $handle, string $key, string $value ): bool {
				$this->wp_assets['style_data'] = array( $handle, $key, $value );
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( string $handle, string $data, string $position ): bool {
				$this->wp_assets['inline'] = array( $handle, $data, $position );
				return true;
			}
		);
		Functions\when( 'wp_print_styles' )->alias(
			function ( array $handles ): array {
				echo '<link rel="stylesheet" href="https://shop.test/wp-content/plugins/merchboard/build/dashboard.css">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Test stub of wp_print_styles().
				$this->wp_assets['printed_styles'] = $handles;
				return $handles;
			}
		);
		// Records whether the nonce filters are active while WordPress prints the
		// tags (real tags and nonces are checked by the integration test).
		Functions\when( 'wp_print_scripts' )->alias(
			function ( array $handles ): array {
				$this->wp_assets['printed_scripts'] = $handles;
				$this->wp_assets['nonce_filters']   = array( false !== has_filter( 'wp_script_attributes' ), false !== has_filter( 'wp_inline_script_attributes' ) );
				echo '<script src="https://shop.test/wp-content/plugins/merchboard/build/dashboard.js"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Test stub.
				return $handles;
			}
		);
	}

	protected function tearDown(): void {
		foreach ( array( 'build/dashboard.asset.php', 'build/dashboard.js' ) as $file ) {
			if ( is_file( $this->plugin_dir . $file ) ) {
				unlink( $this->plugin_dir . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
			}
		}

		if ( is_dir( $this->plugin_dir . 'build' ) ) {
			rmdir( $this->plugin_dir . 'build' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
			rmdir( $this->plugin_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
		}

		parent::tearDown();
	}

	public function test_logged_out_visitors_are_sent_to_login_and_back_to_the_deep_link(): void {
		$this->query_vars[ DashboardRoute::PATH_VAR ] = 'sales/2026';

		$response = $this->controller()->respond();

		self::assertSame( 302, $response->status );
		self::assertSame( 'https://shop.test/wp-login.php?redirect_to=' . rawurlencode( 'https://shop.test/creator-dashboard/sales/2026/' ), $response->redirect );
		self::assertNull( $response->template );
		self::assertNull( $response->script_nonce );
		self::assertSame( array(), $this->wp_assets, 'No assets for visitors.' );
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
	 * Every user without creator access gets the 403 page and no app.
	 *
	 * @param array<int, string> $caps   Capabilities.
	 * @param CreatorStatus|null $status Linked creator status; null = no record.
	 */
	#[DataProvider( 'no_access' )]
	public function test_users_without_creator_access_get_403_without_the_app( array $caps, ?CreatorStatus $status ): void {
		$this->login( $caps, $status );

		$response = $this->controller()->respond();

		self::assertSame( 403, $response->status );
		self::assertSame( ShellResponse::TEMPLATE_NO_ACCESS, $response->template );
		self::assertNull( $response->script_nonce, 'The 403 page runs no script.' );
		self::assertSame( array(), $this->wp_assets, 'The bundle is not even registered.' );

		$html = $this->controller()->render( $response );
		self::assertStringContainsString( 'Your access is not active', $html );
		self::assertStringContainsString( 'action=logout&_wpnonce=abc', $html, 'Decoded from wp_logout_url(), escaped again only by esc_url().' );
		self::assertStringNotContainsString( '&amp;amp;', $html );
		self::assertStringNotContainsString( 'wp-admin', $html, 'Never a way into wp-admin.' );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( 'Shop &lt;b&gt;&amp;&lt;/b&gt; Co', $html, 'Site name escaped.' );
		self::assertStringContainsString( '<style>' . FallbackStyles::css() . '</style>', $html, 'Semantic tokens without the bundle.' );
		self::assertStringContainsString( '<div class="merchboard-fallback__card">', $html );
		self::assertStringNotContainsString( 'style="', $html, 'No inline style attributes.' );
	}

	public function test_active_creator_gets_the_react_shell_with_bootstrap_data_and_nonce(): void {
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active, 'Nova <img src=x onerror=alert(1)>' );

		$response = $this->controller()->respond();

		self::assertSame( 200, $response->status );
		self::assertSame( ShellResponse::TEMPLATE_SHELL, $response->template );
		self::assertMatchesRegularExpression( '#^[A-Za-z0-9+/]{22}==$#', (string) $response->script_nonce );

		self::assertSame( array( 'merchboard-dashboard', $this->plugin_url() . 'build/dashboard.js', array( 'react-jsx-runtime', 'wp-element', 'wp-i18n' ), 'abc123', true ), $this->wp_assets['script'] );
		self::assertSame( array( 'merchboard-dashboard', $this->plugin_url() . 'build/dashboard.css', array(), 'abc123' ), $this->wp_assets['style'] );
		self::assertSame( array( 'merchboard-dashboard', 'rtl', 'replace' ), $this->wp_assets['style_data'] );
		self::assertSame( array( 'merchboard-dashboard', 'merchboard', $this->plugin_dir . 'languages' ), $this->wp_assets['translations'], 'Script translations from languages/.' );
		self::assertSame( 'merchboard-dashboard', $this->wp_assets['inline'][0] );
		self::assertSame( 'before', $this->wp_assets['inline'][2] );

		$html = $this->controller()->render( $response );
		self::assertStringStartsWith( '<!DOCTYPE html>', $html );
		self::assertStringContainsString( '<div id="merchboard-dashboard-root" class="merchboard-app">', $html );
		self::assertStringContainsString( '<noscript>', $html );
		self::assertSame( array( true, true ), $this->wp_assets['nonce_filters'], 'Script tags get the nonce while printing.' );
		self::assertSame( array( 'merchboard-dashboard' ), $this->wp_assets['printed_scripts'], 'Only the dashboard handle (and its dependencies).' );
		self::assertSame( array( 'merchboard-dashboard' ), $this->wp_assets['printed_styles'] );
		self::assertStringNotContainsString( 'merchboard-fallback', $html, 'The fallback styles are only for pages without the bundle.' );
		self::assertStringNotContainsString( 'Nova', $html, 'The creator is not part of the page; it comes from the API (phase 14).' );
		self::assertFalse( has_filter( 'wp_script_attributes' ), 'Nonce filters are removed after rendering.' );
		self::assertFalse( has_filter( 'wp_inline_script_attributes' ) );
	}

	/**
	 * Every valid theme becomes `data-theme` on <html> (ADR-044).
	 *
	 * @return array<string, array{string}>
	 */
	public static function themes(): array {
		return array_combine( ThemePreference::THEMES, array_map( static fn( string $theme ): array => array( $theme ), ThemePreference::THEMES ) );
	}

	/**
	 * The user's explicit theme is on <html> in the server response, so the
	 * first paint uses it.
	 *
	 * @param string $theme Theme.
	 */
	#[DataProvider( 'themes' )]
	public function test_the_explicit_theme_is_set_on_html_by_the_server( string $theme ): void {
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active );
		$this->user_meta[ $this->current_user_id ][ ThemePreference::META_KEY ] = $theme;

		$html = $this->controller()->render( $this->controller()->respond() );

		self::assertStringContainsString( '<html lang="en-US" data-theme="' . $theme . '">', $html );
		self::assertSame( 1, substr_count( $html, 'data-theme=' ) );
	}

	/**
	 * Values that must not reach the page: no attribute, so CSS follows the
	 * system preference.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function no_explicit_theme(): array {
		return array(
			'no preference'   => array( '' ),
			'unknown theme'   => array( 'dark-purple' ),
			'injection'       => array( 'dark-red"><script>alert(1)</script>' ),
			'different case'  => array( 'Dark-Red' ),
			'not a string'    => array( array( 'dark-red' ) ),
			'system guessing' => array( 'dark' ),
		);
	}

	/**
	 * Without a valid explicit theme there is no `data-theme`, so the CSS
	 * `prefers-color-scheme` rule decides between Light and Dark Black.
	 *
	 * @param mixed $stored Stored meta value.
	 */
	#[DataProvider( 'no_explicit_theme' )]
	public function test_without_a_valid_theme_html_has_no_data_theme( mixed $stored ): void {
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active );
		$this->user_meta[ $this->current_user_id ][ ThemePreference::META_KEY ] = $stored;

		$response = $this->controller()->respond();
		$html     = $this->controller()->render( $response );

		self::assertNull( $response->view['theme'] );
		self::assertStringContainsString( '<html lang="en-US">', $html );
		self::assertStringNotContainsString( 'data-theme', $html );
		self::assertStringNotContainsString( 'alert(1)', $html );
	}

	public function test_the_theme_is_not_bootstrap_data_and_not_on_pages_without_the_app(): void {
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active );
		$this->user_meta[ $this->current_user_id ][ ThemePreference::META_KEY ] = 'dark-blue';

		self::assertArrayNotHasKey( 'theme', $this->controller()->bootstrap(), 'ADR-044: no new bootstrap field.' );
		$this->controller()->respond();
		self::assertStringNotContainsString( 'dark-blue', (string) $this->wp_assets['inline'][1], 'The bootstrap script does not carry the theme.' );

		unlink( $this->plugin_dir . 'build/dashboard.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
		$html = $this->controller()->render( $this->controller()->respond() );
		self::assertStringNotContainsString( 'data-theme', $html, 'The 503 page is the Light fallback.' );

		$this->login( array( 'read' ), null );
		$this->user_meta[ $this->current_user_id ][ ThemePreference::META_KEY ] = 'dark-blue';
		$html = $this->controller()->render( $this->controller()->respond() );
		self::assertStringContainsString( 'Your access is not active', $html );
		self::assertStringNotContainsString( 'data-theme', $html, 'The 403 page is the Light fallback.' );
	}

	public function test_bootstrap_data_is_non_sensitive_and_encoded_safely(): void {
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active, 'Nova' );
		$this->controller()->respond();

		$inline = (string) $this->wp_assets['inline'][1];
		self::assertStringStartsWith( 'window.merchboardDashboard = {', $inline );
		self::assertStringNotContainsString( '<', $inline, 'No raw < (cannot close the script tag).' );
		self::assertStringNotContainsString( '&amp;', $inline, 'URLs are plain, not HTML-escaped.' );

		$data = json_decode( substr( $inline, strlen( 'window.merchboardDashboard = ' ), -1 ), true );
		self::assertIsArray( $data );
		self::assertSame( array( 'site_name', 'dashboard_url', 'routing', 'logout_url', 'login_url', 'password_url', 'locale', 'sections', 'logo' ), array_keys( $data ) );
		self::assertNull( $data['logo'], 'This fake plugin has no default logo file: the site name is shown as text.' );
		self::assertSame( 'https://shop.test/wp-login.php?action=lostpassword', $data['password_url'] );
		self::assertSame( 'Shop <b>&</b> Co', $data['site_name'] );
		self::assertSame( 'https://shop.test/creator-dashboard/', $data['dashboard_url'] );
		self::assertSame( 'path', $data['routing'] );
		self::assertSame( 'https://shop.test/wp-login.php?action=logout&_wpnonce=abc&redirect_to=' . rawurlencode( 'https://shop.test/creator-dashboard/' ), $data['logout_url'] );
		self::assertSame( 'https://shop.test/wp-login.php?redirect_to=' . rawurlencode( 'https://shop.test/creator-dashboard/' ), $data['login_url'] );
		self::assertSame( 'da_DK', $data['locale'] );
		self::assertSame(
			array(
				array(
					'id'    => 'overview',
					'label' => 'Overview',
					'path'  => '',
				),
				array(
					'id'    => 'profile',
					'label' => 'Profile',
					'path'  => 'profile',
				),
			),
			$data['sections']
		);
		self::assertStringNotContainsString( 'Nova', $inline, 'No creator data in the page.' );
		self::assertStringNotContainsString( '"id":1', $inline, 'No creator id.' );
	}

	public function test_the_header_gets_the_default_logo_or_the_chosen_one(): void {
		$media = FakeMediaLibrary::install();
		Functions\when( 'plugins_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-content/plugins/merchboard/' . $path );
		mkdir( $this->plugin_dir . 'assets/brand', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		copy( dirname( __DIR__, 4 ) . '/assets/brand/logo.svg', $this->plugin_dir . 'assets/brand/logo.svg' );
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active );

		try {
			$logo = $this->controller()->bootstrap()['logo'];
			self::assertIsArray( $logo );
			self::assertStringStartsWith( 'https://shop.test/wp-content/plugins/merchboard/assets/brand/logo.svg', $logo['url'] );
			self::assertSame( array( 64, 64, '', false ), array( $logo['width'], $logo['height'], $logo['alt'], $logo['custom'] ), 'The default mark sits next to the site name: empty alt.' );

			$media->add( 5 );
			$this->options->options[ Settings::OPTION ] = array( Settings::LOGO_ATTACHMENT_ID => 5 );
			self::assertSame(
				array(
					'url'    => 'https://shop.test/wp-content/uploads/logo-5.png',
					'width'  => 400,
					'height' => 100,
					'alt'    => 'Shop <b>&</b> Co',
					'custom' => true,
				),
				$this->controller()->bootstrap()['logo'],
				'The chosen logo replaces the site name, which becomes its alt text (JSON-encoded safely, escaped by React).'
			);

			$media->items[5]['mime'] = 'image/svg+xml';
			$fallback                = ( new BrandLogo( new LogoAttachment(), $this->plugin_dir ) )->resolve();
			self::assertNotNull( $fallback );
			self::assertFalse( $fallback['custom'], 'An unusable choice falls back to the default logo.' );
		} finally {
			unlink( $this->plugin_dir . 'assets/brand/logo.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
			rmdir( $this->plugin_dir . 'assets/brand' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
			rmdir( $this->plugin_dir . 'assets' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
		}
	}

	public function test_plain_permalinks_use_query_routing(): void {
		$this->options->options['permalink_structure'] = '';
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active );

		$data = $this->controller()->bootstrap();

		self::assertSame( 'query', $data['routing'] );
		self::assertSame( 'https://shop.test/?merchboard_dashboard=1', $data['dashboard_url'] );
	}

	public function test_missing_build_gives_503_and_is_logged(): void {
		unlink( $this->plugin_dir . 'build/dashboard.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Active );

		$response = $this->controller()->respond();

		self::assertSame( 503, $response->status );
		self::assertNull( $response->script_nonce );
		self::assertArrayNotHasKey( 'script', $this->wp_assets );
		self::assertSame( array( 'error' ), array_column( $this->logger->entries, 'level' ) );

		$html = $this->controller()->render( $response );
		self::assertStringContainsString( 'temporarily unavailable', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( '<style>' . FallbackStyles::css() . '</style>', $html, 'Semantic tokens without the bundle.' );
		self::assertStringContainsString( '<div role="alert" class="merchboard-fallback__card">', $html );
		self::assertStringNotContainsString( 'style="', $html, 'No inline style attributes.' );
	}

	/**
	 * Manifests that must be refused.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_manifests(): array {
		return array(
			'not an array'       => array( "<?php return 'x';" ),
			'missing version'    => array( "<?php return array( 'dependencies' => array() );" ),
			'non-string handle'  => array( "<?php return array( 'dependencies' => array( 1 ), 'version' => 'a' );" ),
			'dependencies not a' => array( "<?php return array( 'dependencies' => 'wp-element', 'version' => 'a' );" ),
		);
	}

	#[DataProvider( 'invalid_manifests' )]
	public function test_invalid_manifests_are_refused( string $manifest ): void {
		$this->write_build( $manifest );

		self::assertNull( $this->assets()->asset() );
		self::assertFalse( $this->assets()->register( array() ) );
	}

	public function test_creator_identity_never_comes_from_the_request(): void {
		$this->user_caps[6] = array( Capabilities::ACCESS_DASHBOARD );
		$this->creators->create( 'Other', CreatorStatus::Active, 6 );
		$this->login( array( 'read', Capabilities::ACCESS_DASHBOARD ), CreatorStatus::Inactive, 'Mine' );
		$_GET['creator_id'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Proves request data is ignored.
		$_GET['user_id']    = '6'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Proves request data is ignored.

		try {
			self::assertSame( 403, $this->controller()->respond()->status, 'Own inactive record decides, not the requested one.' );
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
		$locked = ShellController::security_headers();
		self::assertSame( 'noindex, nofollow', $locked['X-Robots-Tag'] );
		self::assertSame( 'strict-origin-when-cross-origin', $locked['Referrer-Policy'] );
		self::assertSame( 'nosniff', $locked['X-Content-Type-Options'] );
		self::assertSame( 'SAMEORIGIN', $locked['X-Frame-Options'] );
		self::assertStringContainsString( "default-src 'none'", $locked['Content-Security-Policy'] );
		self::assertStringNotContainsString( 'script-src', $locked['Content-Security-Policy'], 'Pages without the app run no scripts.' );

		$app = ShellController::security_headers( 'abc=' )['Content-Security-Policy'];
		self::assertStringContainsString( "script-src 'self' 'nonce-abc='", $app );
		self::assertStringContainsString( "connect-src 'self'", $app );
		self::assertStringContainsString( "object-src 'none'", $app );
		self::assertStringContainsString( "base-uri 'none'", $app );
		self::assertStringContainsString( "frame-ancestors 'self'", $app );
		self::assertStringNotContainsString( 'unsafe-eval', $app );
		self::assertStringNotContainsString( "script-src 'self' 'unsafe-inline'", $app );
	}

	public function test_unknown_templates_are_refused(): void {
		$this->expectException( RuntimeException::class );

		$this->controller()->render( ShellResponse::redirect( 'https://shop.test/' ) );
	}

	public function test_module_registers_route_shell_and_hooks(): void {
		Functions\when( 'plugin_dir_url' )->justReturn( 'https://shop.test/wp-content/plugins/merchboard/' );
		$container = new Container();
		$container->set( CreatorContext::class, fn(): CreatorContext => new CreatorContext( $this->creators, $this->logger ) );
		$container->set( Logger::class, fn(): Logger => $this->logger );

		( new DashboardModule() )->register( $container );

		self::assertSame( 'dashboard', ( new DashboardModule() )->id() );
		self::assertInstanceOf( ShellController::class, $container->get( ShellController::class ) );
		self::assertInstanceOf( DashboardAssets::class, $container->get( DashboardAssets::class ) );
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

	/**
	 * Writes a fake build (manifest and bundle).
	 *
	 * @param string $manifest PHP source of dashboard.asset.php.
	 */
	private function write_build( string $manifest ): void {
		if ( ! is_dir( $this->plugin_dir . 'build' ) ) {
			mkdir( $this->plugin_dir . 'build', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
		}

		file_put_contents( $this->plugin_dir . 'build/dashboard.asset.php', $manifest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->plugin_dir . 'build/dashboard.js', '/* bundle */' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
	}

	private function plugin_url(): string {
		return 'https://shop.test/wp-content/plugins/merchboard/';
	}

	private function assets(): DashboardAssets {
		return new DashboardAssets( $this->plugin_dir, $this->plugin_url(), $this->logger );
	}

	private function controller(): ShellController {
		return new ShellController( new DashboardRoute(), new CreatorContext( $this->creators, $this->logger ), $this->assets(), new DashboardSections( $this->logger ), new ThemePreference(), new BrandLogo( new LogoAttachment(), $this->plugin_dir ) );
	}
}
