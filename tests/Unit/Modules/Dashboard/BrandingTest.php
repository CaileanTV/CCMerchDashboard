<?php
/**
 * Tests for the logo and the Merch Dashboard login branding (ADR-042).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use Brain\Monkey\Functions;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Dashboard\Branding\BrandLogo;
use MerchBoard\Modules\Dashboard\Branding\LoginBranding;
use MerchBoard\Modules\Dashboard\Branding\LogoAttachment;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Tests\Unit\Support\FakeMediaLibrary;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( LogoAttachment::class )]
#[CoversClass( BrandLogo::class )]
#[CoversClass( LoginBranding::class )]
final class BrandingTest extends TestCase {

	private const ROOT = __DIR__ . '/../../../../';

	private const DEFAULT_URL = 'https://shop.test/wp-content/plugins/merchboard/assets/brand/logo.svg';

	private FakeMediaLibrary $media;

	private InMemoryOptions $options;

	/**
	 * Enqueued styles and inline styles.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $styles = array();

	protected function setUp(): void {
		parent::setUp();
		$this->media   = FakeMediaLibrary::install();
		$this->options = InMemoryOptions::install( array( 'permalink_structure' => '/%postname%/' ) );

		Functions\stubEscapeFunctions();
		Functions\when( 'plugins_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-content/plugins/merchboard/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Nova Merch' );
		Functions\when( 'wp_validate_redirect' )->alias(
			static fn( string $location, string $fallback = '' ): string => str_starts_with( $location, 'https://shop.test/' ) || str_starts_with( $location, '/' ) ? $location : $fallback
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( string $handle, string $src, array $deps, string|false $ver ): void {
				$this->styles['enqueued'][] = array( $handle, $src, $deps, $ver );
			}
		);
		Functions\when( 'wp_add_inline_style' )->alias(
			function ( string $handle, string $css ): bool {
				$this->styles['inline'][] = array( $handle, $css );
				return true;
			}
		);
	}

	/**
	 * Accepted raster types.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function accepted(): array {
		return array(
			'PNG'  => array( 'image/png', 'logo.png' ),
			'JPEG' => array( 'image/jpeg', 'logo.jpg' ),
			'WebP' => array( 'image/webp', 'logo.webp' ),
		);
	}

	/**
	 * PNG, JPEG and WebP are accepted.
	 *
	 * @param string $mime MIME type.
	 * @param string $file File name.
	 */
	#[DataProvider( 'accepted' )]
	public function test_png_jpeg_and_webp_are_accepted( string $mime, string $file ): void {
		$this->media->add(
			5,
			array(
				'mime' => $mime,
				'file' => $file,
				'url'  => 'https://shop.test/wp-content/uploads/' . $file,
			)
		);

		self::assertNull( ( new LogoAttachment() )->problem( 5 ) );
		self::assertSame(
			array(
				'url'    => 'https://shop.test/wp-content/uploads/' . $file,
				'width'  => 400,
				'height' => 100,
			),
			( new LogoAttachment() )->usable( 5 )
		);
	}

	/**
	 * Attachments that must be refused, with the reason.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refused(): array {
		return array(
			'SVG'                    => array(
				array(
					'mime' => 'image/svg+xml',
					'file' => 'logo.svg',
				),
				LogoAttachment::WRONG_TYPE,
			),
			'SVG named .png'         => array( array( 'file' => 'logo.svg' ), LogoAttachment::WRONG_TYPE ),
			'PDF'                    => array(
				array(
					'mime' => 'application/pdf',
					'file' => 'logo.pdf',
				),
				LogoAttachment::WRONG_TYPE,
			),
			'GIF'                    => array(
				array(
					'mime' => 'image/gif',
					'file' => 'logo.gif',
				),
				LogoAttachment::WRONG_TYPE,
			),
			'not an attachment'      => array( array( 'type' => 'post' ), LogoAttachment::NOT_FOUND ),
			'trashed'                => array( array( 'status' => 'trash' ), LogoAttachment::NOT_FOUND ),
			'private'                => array( array( 'status' => 'private' ), LogoAttachment::NOT_FOUND ),
			'no dimensions'          => array(
				array(
					'width'  => null,
					'height' => null,
				),
				LogoAttachment::WRONG_SIZE,
			),
			'too low'                => array(
				array(
					'width'  => 200,
					'height' => 63,
				),
				LogoAttachment::WRONG_SIZE,
			),
			'too large'              => array(
				array(
					'width'  => 2001,
					'height' => 400,
				),
				LogoAttachment::WRONG_SIZE,
			),
			'portrait'               => array(
				array(
					'width'  => 100,
					'height' => 101,
				),
				LogoAttachment::WRONG_SIZE,
			),
			'wider than 6:1'         => array(
				array(
					'width'  => 601,
					'height' => 100,
				),
				LogoAttachment::WRONG_SIZE,
			),
			'other host'             => array( array( 'url' => 'https://cdn.example/logo.png' ), LogoAttachment::FOREIGN_URL ),
			'other scheme'           => array( array( 'url' => 'http://shop.test/logo.png' ), LogoAttachment::FOREIGN_URL ),
			'other port'             => array( array( 'url' => 'https://shop.test:8443/logo.png' ), LogoAttachment::FOREIGN_URL ),
			'credentials in the URL' => array( array( 'url' => 'https://user:pass@shop.test/logo.png' ), LogoAttachment::FOREIGN_URL ),
			'data URL'               => array( array( 'url' => 'data:image/png;base64,AAAA' ), LogoAttachment::FOREIGN_URL ),
			'javascript URL'         => array( array( 'url' => 'javascript:alert(1)' ), LogoAttachment::FOREIGN_URL ),
		);
	}

	/**
	 * Refused attachments, with the reason.
	 *
	 * @param array<string, mixed> $overrides Attachment fields.
	 * @param string               $reason    Expected problem.
	 */
	#[DataProvider( 'refused' )]
	public function test_unusable_attachments_are_refused( array $overrides, string $reason ): void {
		$this->media->add( 5, $overrides );

		self::assertSame( $reason, ( new LogoAttachment() )->problem( 5 ) );
		self::assertNull( ( new LogoAttachment() )->usable( 5 ) );
	}

	public function test_missing_and_invalid_ids_are_refused(): void {
		foreach ( array( 0, -1, 99 ) as $id ) {
			self::assertSame( LogoAttachment::NOT_FOUND, ( new LogoAttachment() )->problem( $id ) );
		}
	}

	public function test_size_bounds_are_inclusive(): void {
		foreach ( array( array( 64, 64 ), array( 384, 64 ), array( 2000, 400 ), array( 2000, 2000 ) ) as [ $width, $height ] ) {
			$this->media->add(
				5,
				array(
					'width'  => $width,
					'height' => $height,
				)
			);
			self::assertNull( ( new LogoAttachment() )->problem( 5 ), "{$width}×{$height}" );
		}
	}

	public function test_without_a_setting_the_default_logo_is_used(): void {
		self::assertTrue( is_file( self::ROOT . BrandLogo::DEFAULT_PATH ) );

		$logo = $this->logo()->resolve();

		self::assertNotNull( $logo );
		self::assertFalse( $logo['custom'] );
		self::assertStringStartsWith( self::DEFAULT_URL, $logo['url'] );
		self::assertSame( 64, $logo['width'] );
	}

	public function test_a_valid_setting_gives_the_chosen_logo(): void {
		$this->media->add( 5 );
		$this->options->options[ Settings::OPTION ] = array( Settings::LOGO_ATTACHMENT_ID => 5 );

		self::assertSame(
			array(
				'url'    => 'https://shop.test/wp-content/uploads/logo-5.png',
				'width'  => 400,
				'height' => 100,
				'custom' => true,
			),
			$this->logo()->resolve()
		);
	}

	/**
	 * After the attachment was deleted or changed, the default logo is shown.
	 *
	 * @return array<string, array{array<string, mixed>|null}>
	 */
	public static function broken_settings(): array {
		return array(
			'deleted'          => array( null ),
			'now an SVG'       => array(
				array(
					'mime' => 'image/svg+xml',
					'file' => 'logo.svg',
				),
			),
			'trashed'          => array( array( 'status' => 'trash' ) ),
			'moved to the CDN' => array( array( 'url' => 'https://cdn.example/logo.png' ) ),
		);
	}

	/**
	 * Fallback to the default logo.
	 *
	 * @param array<string, mixed>|null $attachment Attachment fields, null = deleted.
	 */
	#[DataProvider( 'broken_settings' )]
	public function test_an_unusable_setting_falls_back_to_the_default_logo( ?array $attachment ): void {
		if ( null !== $attachment ) {
			$this->media->add( 5, $attachment );
		}

		$this->options->options[ Settings::OPTION ] = array( Settings::LOGO_ATTACHMENT_ID => 5 );

		$logo = $this->logo()->resolve();

		self::assertNotNull( $logo );
		self::assertFalse( $logo['custom'] );
		self::assertStringStartsWith( self::DEFAULT_URL, $logo['url'] );
	}

	public function test_without_a_usable_default_there_is_no_logo(): void {
		self::assertNull( ( new BrandLogo( new LogoAttachment(), sys_get_temp_dir() . '/merchboard-no-plugin/' ) )->resolve(), 'Missing file.' );

		Functions\when( 'plugins_url' )->alias( static fn( string $path = '' ): string => 'https://cdn.example/merchboard/' . $path );
		self::assertNull( $this->logo()->resolve(), 'Plugin assets on another origin.' );
	}

	public function test_the_default_logo_is_a_safe_first_party_svg(): void {
		$svg = (string) file_get_contents( self::ROOT . BrandLogo::DEFAULT_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local asset.

		self::assertStringStartsWith( '<svg xmlns="http://www.w3.org/2000/svg"', $svg );
		self::assertStringContainsString( 'viewBox="0 0 64 64"', $svg );
		self::assertDoesNotMatchRegularExpression( '/<script|<foreignObject|\son[a-z]+=|href=|<image|<text|<style|@import|url\(/i', $svg, 'No script, events, references, text, fonts or styles.' );
		self::assertStringNotContainsString( 'var(--', $svg, 'Not tied to the theme accent.' );
	}

	/**
	 * Login requests and whether the branding applies.
	 *
	 * @return array<string, array{array<string, mixed>, bool}>
	 */
	public static function login_requests(): array {
		$dashboard = 'https://shop.test/creator-dashboard/';

		return array(
			'dashboard start page'          => array( array( 'redirect_to' => $dashboard ), true ),
			'dashboard deep link'           => array( array( 'redirect_to' => $dashboard . 'sales/' ), true ),
			'after a failed attempt'        => array(
				array(
					'redirect_to' => $dashboard,
					'log'         => 'nova',
				),
				true,
			),
			'no redirect'                   => array( array(), false ),
			'wp-admin'                      => array( array( 'redirect_to' => 'https://shop.test/wp-admin/' ), false ),
			'other page on the site'        => array( array( 'redirect_to' => 'https://shop.test/my-account/' ), false ),
			'other host'                    => array( array( 'redirect_to' => 'https://evil.test/creator-dashboard/' ), false ),
			'path traversal'                => array( array( 'redirect_to' => $dashboard . '../wp-admin/' ), false ),
			'not a string'                  => array( array( 'redirect_to' => array( $dashboard ) ), false ),
			'interim login (session modal)' => array(
				array(
					'redirect_to'   => $dashboard,
					'interim-login' => '1',
				),
				false,
			),
		);
	}

	/**
	 * The context decides the branding; nothing else on the screen changes.
	 *
	 * @param array<string, mixed> $request Request parameters.
	 * @param bool                 $active  Expected context.
	 */
	#[DataProvider( 'login_requests' )]
	public function test_branding_only_in_merch_dashboard_login_context( array $request, bool $active ): void {
		$branding = $this->branding();
		$branding->observe( $request );

		self::assertSame( $active, $branding->is_active() );

		$branding->enqueue();

		if ( $active ) {
			self::assertSame( array( array( LoginBranding::HANDLE, 'https://shop.test/wp-content/plugins/merchboard/assets/css/login.css', array( 'login' ), false ) ), $this->styles['enqueued'] );
			self::assertSame( LoginBranding::HANDLE, $this->styles['inline'][0][0] );
			self::assertStringStartsWith( 'body.merchboard-login #login h1 a{background-image:url("' . self::DEFAULT_URL, $this->styles['inline'][0][1] );
			self::assertSame( array( 'login', 'merchboard-login' ), $branding->body_class( array( 'login' ) ) );
			self::assertSame( 'https://shop.test/', $branding->header_url( 'https://wordpress.org/' ) );
			self::assertSame( 'Nova Merch', $branding->header_text( 'Powered by WordPress' ) );
		} else {
			self::assertSame( array(), $this->styles, 'No stylesheet, no inline style.' );
			self::assertSame( array( 'login' ), $branding->body_class( array( 'login' ) ) );
			self::assertSame( 'https://wordpress.org/', $branding->header_url( 'https://wordpress.org/' ) );
			self::assertSame( 'Powered by WordPress', $branding->header_text( 'Powered by WordPress' ) );
		}
	}

	public function test_the_branding_is_off_until_the_login_action_is_observed(): void {
		// lostpassword, rp/resetpass, register, logout, checkemail …: WordPress
		// fires login_form_{action} for them, never login_form_login, and only
		// login_form_login is hooked (next test). Without the observation the
		// branding stays off, even on a dashboard redirect_to. The integration
		// test runs WordPress' real hooks.
		$branding = $this->branding();
		$branding->register();
		$branding->enqueue();

		self::assertFalse( $branding->is_active() );
		self::assertSame( array(), $this->styles );
		self::assertSame( array( 'login' ), $branding->body_class( array( 'login' ) ) );
	}

	public function test_only_presentation_hooks_are_registered(): void {
		$branding = $this->branding();
		$branding->register();

		foreach ( array( 'login_form_login', 'login_enqueue_scripts' ) as $hook ) {
			self::assertNotFalse( has_action( $hook ), $hook );
		}

		foreach ( array( 'login_body_class', 'login_headerurl', 'login_headertext' ) as $hook ) {
			self::assertNotFalse( has_filter( $hook ), $hook );
		}

		// Nothing that touches authentication, credentials, redirects or URLs.
		foreach ( array( 'authenticate', 'wp_authenticate', 'wp_login', 'wp_login_failed', 'login_redirect', 'login_url', 'logout_url', 'lostpassword_url', 'login_init', 'login_form', 'login_form_lostpassword', 'login_form_rp', 'login_form_resetpass', 'login_form_register', 'login_form_logout', 'lostpassword_redirect', 'secure_signon_cookie', 'login_message', 'login_errors' ) as $hook ) {
			self::assertFalse( has_action( $hook ), $hook );
			self::assertFalse( has_filter( $hook ), $hook );
		}
	}

	public function test_the_chosen_logo_is_used_on_the_login(): void {
		$this->media->add( 5 );
		$this->options->options[ Settings::OPTION ] = array( Settings::LOGO_ATTACHMENT_ID => 5 );

		self::assertSame( 'body.merchboard-login #login h1 a{background-image:url("https://shop.test/wp-content/uploads/logo-5.png");}', $this->branding()->logo_css() );
	}

	/**
	 * URLs that could break out of url("…") never reach the inline style.
	 *
	 * @return array<string, array{string}>
	 */
	public static function hostile_urls(): array {
		return array(
			'quote'       => array( 'https://shop.test/a".png' ),
			'parenthesis' => array( 'https://shop.test/a).png' ),
			'semicolon'   => array( 'https://shop.test/a;}body{x:y.png' ),
			'backslash'   => array( 'https://shop.test/a\\".png' ),
			'newline'     => array( "https://shop.test/a\n.png" ),
			'markup'      => array( 'https://shop.test/</style><script>.png' ),
		);
	}

	/**
	 * Hostile URLs give no logo rule (WordPress' own logo stays).
	 *
	 * @param string $url Image URL.
	 */
	#[DataProvider( 'hostile_urls' )]
	public function test_unsafe_urls_never_reach_the_css( string $url ): void {
		$this->media->add( 5, array( 'url' => $url ) );
		$this->options->options[ Settings::OPTION ] = array( Settings::LOGO_ATTACHMENT_ID => 5 );
		Functions\when( 'plugins_url' )->justReturn( $url );

		self::assertSame( '', $this->branding()->logo_css() );
	}

	public function test_the_login_stylesheet_is_scoped_static_and_self_contained(): void {
		$css = (string) file_get_contents( self::ROOT . LoginBranding::STYLESHEET ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local asset.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		self::assertDoesNotMatchRegularExpression( '/@import|url\(|@font-face|https?:|expression\(|javascript:/i', $css, 'No external resources, fonts or script.' );

		preg_match_all( '/([^{}]+)\{/', $css, $matches );

		foreach ( $matches[1] as $selector_list ) {
			$selector_list = trim( $selector_list );

			if ( str_starts_with( $selector_list, '@media' ) ) {
				continue;
			}

			foreach ( explode( ',', $selector_list ) as $selector ) {
				self::assertStringStartsWith( 'body.merchboard-login', trim( $selector ), 'Every rule is scoped to the Merch Dashboard login.' );
			}
		}

		self::assertStringContainsString( '@media (prefers-color-scheme: dark)', $css );
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
	}

	private function logo(): BrandLogo {
		return new BrandLogo( new LogoAttachment(), self::ROOT );
	}

	private function branding(): LoginBranding {
		return new LoginBranding( new DashboardRoute(), $this->logo(), self::ROOT );
	}
}
