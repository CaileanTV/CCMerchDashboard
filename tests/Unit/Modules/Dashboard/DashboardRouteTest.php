<?php
/**
 * Tests for the dashboard route.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use Brain\Monkey\Functions;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( DashboardRoute::class )]
final class DashboardRouteTest extends TestCase {

	private InMemoryOptions $options;

	/**
	 * Query vars of the simulated main query.
	 *
	 * @var array<string, mixed>
	 */
	private array $query_vars = array();

	protected function setUp(): void {
		parent::setUp();
		$this->options = InMemoryOptions::install( array( 'permalink_structure' => '/%postname%/' ) );

		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.
		// Like WordPress: relative URLs and the site's own host are allowed, other hosts are not.
		Functions\when( 'wp_validate_redirect' )->alias(
			static function ( string $location, string $fallback ): string {
				$host = parse_url( str_starts_with( $location, '//' ) ? 'http:' . $location : $location, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.

				return null === $host || 'shop.test' === strtolower( (string) $host ) ? $location : $fallback;
			}
		);
		Functions\when( 'get_query_var' )->alias( fn( string $name ): mixed => $this->query_vars[ $name ] ?? '' );
	}

	public function test_urls_with_pretty_permalinks(): void {
		$route = new DashboardRoute();

		self::assertSame( 'https://shop.test/creator-dashboard/', $route->url() );
		self::assertSame( 'https://shop.test/creator-dashboard/sales/2026/', $route->url( '/Sales/2026' ) );
		self::assertSame( 'https://shop.test/creator-dashboard/', $route->url( '../wp-admin' ), 'Not a plain path: start page.' );

		$this->options->options[ Settings::OPTION ] = array( Settings::DASHBOARD_SLUG => 'partner' );
		self::assertSame( 'https://shop.test/partner/', $route->url(), 'The slug is read on every call.' );
		self::assertSame( '^partner(?:/(.*?))?/?$', $route->regex() );
	}

	public function test_urls_with_plain_permalinks(): void {
		$this->options->options['permalink_structure'] = '';
		$route = new DashboardRoute();

		self::assertSame( 'https://shop.test/?merchboard_dashboard=1', $route->url() );
		self::assertSame( 'https://shop.test/?merchboard_dashboard=1&merchboard_dashboard_path=sales', $route->url( 'sales' ) );
	}

	/**
	 * Deep-link paths and their normalized form.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function paths(): array {
		return array(
			'empty'           => array( '', '' ),
			'simple'          => array( 'sales', 'sales' ),
			'nested, slashes' => array( '/sales/2026/', 'sales/2026' ),
			'uppercase'       => array( 'Sales', 'sales' ),
			'traversal'       => array( '../wp-admin', '' ),
			'double slash'    => array( 'sales//x', '' ),
			'script'          => array( '<script>', '' ),
			'encoded'         => array( 'sales%2Fx', '' ),
			'query'           => array( 'sales?x=1', '' ),
			'too long'        => array( str_repeat( 'a', 201 ), '' ),
		);
	}

	#[DataProvider( 'paths' )]
	public function test_paths_are_normalized( string $raw, string $expected ): void {
		self::assertSame( $expected, DashboardRoute::normalize_path( $raw ) );
	}

	public function test_request_detection_and_path_come_from_query_vars(): void {
		$route = new DashboardRoute();
		self::assertFalse( $route->is_request() );

		$this->query_vars = array(
			DashboardRoute::QUERY_VAR => '1',
			DashboardRoute::PATH_VAR  => 'sales/<b>',
		);
		self::assertTrue( $route->is_request() );
		self::assertSame( '', $route->path() );

		$this->query_vars[ DashboardRoute::PATH_VAR ] = 'sales/2026';
		self::assertSame( 'sales/2026', $route->path() );

		$this->query_vars[ DashboardRoute::QUERY_VAR ] = 'yes';
		self::assertFalse( $route->is_request(), 'Only exactly 1.' );
	}

	/**
	 * Redirect targets and the dashboard URL they may lead to (null = refused).
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public static function redirect_targets(): array {
		return array(
			'dashboard absolute'        => array( 'https://shop.test/creator-dashboard/', 'https://shop.test/creator-dashboard/' ),
			'dashboard without slash'   => array( 'https://shop.test/creator-dashboard', 'https://shop.test/creator-dashboard/' ),
			'dashboard relative'        => array( '/creator-dashboard/', 'https://shop.test/creator-dashboard/' ),
			'deep link'                 => array( 'https://shop.test/creator-dashboard/sales/2026/', 'https://shop.test/creator-dashboard/sales/2026/' ),
			'deep link with query'      => array( 'https://shop.test/creator-dashboard/sales/?x=<y>', 'https://shop.test/creator-dashboard/sales/' ),
			'host uppercase'            => array( 'https://SHOP.test/creator-dashboard/', 'https://shop.test/creator-dashboard/' ),
			'invalid deep link'         => array( 'https://shop.test/creator-dashboard/<script>/', 'https://shop.test/creator-dashboard/' ),
			'empty'                     => array( '', null ),
			'wp-admin'                  => array( 'https://shop.test/wp-admin/', null ),
			'other page'                => array( 'https://shop.test/shop/', null ),
			'prefix trick'              => array( 'https://shop.test/creator-dashboard-evil/', null ),
			'other host'                => array( 'https://evil.test/creator-dashboard/', null ),
			'protocol relative'         => array( '//evil.test/creator-dashboard/', null ),
			'credentials'               => array( 'https://evil.test@shop.test/creator-dashboard/', null ),
			'traversal'                 => array( 'https://shop.test/creator-dashboard/../wp-admin/', null ),
			'encoded traversal'         => array( 'https://shop.test/creator-dashboard/%2e%2e/wp-admin/', null ),
			'backslash'                 => array( '/creator-dashboard/\\evil.test', null ),
			'javascript'                => array( 'javascript:alert(1)//creator-dashboard/', null ),
			'plain permalink form only' => array( 'https://shop.test/?merchboard_dashboard=1', null ),
		);
	}

	#[DataProvider( 'redirect_targets' )]
	public function test_only_same_site_dashboard_urls_are_accepted_as_redirect_targets( string $target, ?string $expected ): void {
		self::assertSame( $expected, ( new DashboardRoute() )->dashboard_url_for( $target ) );
	}

	public function test_redirect_targets_with_plain_permalinks(): void {
		$this->options->options['permalink_structure'] = '';
		$route = new DashboardRoute();

		self::assertSame( 'https://shop.test/?merchboard_dashboard=1', $route->dashboard_url_for( 'https://shop.test/?merchboard_dashboard=1' ) );
		self::assertSame( 'https://shop.test/?merchboard_dashboard=1&merchboard_dashboard_path=sales', $route->dashboard_url_for( '/?merchboard_dashboard=1&merchboard_dashboard_path=sales' ) );
		self::assertNull( $route->dashboard_url_for( 'https://shop.test/?merchboard_dashboard=2' ) );
		self::assertNull( $route->dashboard_url_for( 'https://shop.test/wp-admin/?merchboard_dashboard=1' ) );
		self::assertNull( $route->dashboard_url_for( 'https://shop.test/creator-dashboard/' ) );
	}

	public function test_missing_rule_invalidates_the_stored_rewrite_rules(): void {
		$route = new DashboardRoute();

		$this->options->options['rewrite_rules'] = array( '^creator-dashboard(?:/(.*?))?/?$' => 'index.php?merchboard_dashboard=1' );
		$route->ensure_rewrite_rules();
		self::assertArrayHasKey( 'rewrite_rules', $this->options->options, 'Current rule present: untouched.' );

		$this->options->options[ Settings::OPTION ] = array( Settings::DASHBOARD_SLUG => 'partner' );
		$route->ensure_rewrite_rules();
		self::assertArrayNotHasKey( 'rewrite_rules', $this->options->options, 'Changed slug: rebuilt on the next request.' );

		$this->options->options['rewrite_rules']       = array();
		$this->options->options['permalink_structure'] = '';
		$route->ensure_rewrite_rules();
		self::assertArrayHasKey( 'rewrite_rules', $this->options->options, 'Plain permalinks use no rules.' );
	}

	public function test_rule_and_query_vars_are_registered(): void {
		Functions\expect( 'add_rewrite_rule' )
			->once()
			->with( '^creator-dashboard(?:/(.*?))?/?$', 'index.php?merchboard_dashboard=1&merchboard_dashboard_path=$matches[1]', 'top' );

		$route = new DashboardRoute();
		$route->add_rewrite_rule();

		self::assertSame( array( 'p', DashboardRoute::QUERY_VAR, DashboardRoute::PATH_VAR ), $route->add_query_vars( array( 'p' ) ) );
		self::assertSame( 'garbage', $route->add_query_vars( 'garbage' ) );
	}
}
