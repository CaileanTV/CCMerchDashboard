<?php
/**
 * Tests for MitCCMerch's own login screen, logos and icons (ADR-103).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Dashboard;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Dashboard\Branding\BrandIcons;
use MerchBoard\Modules\Dashboard\Branding\BrandingDesign;
use MerchBoard\Modules\Dashboard\Branding\BrandLogo;
use MerchBoard\Modules\Dashboard\Branding\LoginBranding;
use MerchBoard\Modules\Dashboard\Branding\LogoAttachment;
use MerchBoard\Modules\Dashboard\Branding\ManifestPage;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Tests\Unit\Support\FakeMediaLibrary;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_User;

/**
 * The four screens are branded only in MitCCMerch context, and the context
 * only changes the look: WordPress keeps the login, the reset key is never
 * read, and everything printed is escaped.
 */
#[CoversClass( LoginBranding::class )]
#[CoversClass( BrandingDesign::class )]
#[CoversClass( BrandIcons::class )]
#[CoversClass( ManifestPage::class )]
#[CoversClass( LogoAttachment::class )]
final class LoginScreenTest extends TestCase {

	private const ROOT = __DIR__ . '/../../../../';

	private const DASHBOARD = 'https://shop.test/creator-dashboard/';

	private FakeMediaLibrary $media;

	private InMemoryOptions $options;

	protected function setUp(): void {
		parent::setUp();
		$this->media   = FakeMediaLibrary::install();
		$this->options = InMemoryOptions::install( array( 'permalink_structure' => '/%postname%/' ) );

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.
		Functions\when( 'plugins_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-content/plugins/merchboard/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( mixed $args, mixed $value = '', mixed $url = '' ): string => is_array( $args ) ? $value . '?' . http_build_query( $args ) : $url . '?' . $args . '=' . $value );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Nova Merch' );
		Functions\when( 'network_site_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/' . $path );
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( mixed $value ): string => trim( strip_tags( (string) $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'is_email' )->alias( static fn( string $value ): bool => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_validate_redirect' )->alias(
			static fn( string $location, string $fallback = '' ): string => str_starts_with( $location, 'https://shop.test/' ) || str_starts_with( $location, '/' ) ? $location : $fallback
		);
		Functions\when( 'get_user_by' )->alias(
			static function ( string $field, string $value ): WP_User|false {
				if ( 'nova' !== $value && 'admin' !== $value ) {
					return false;
				}

				$user     = new WP_User();
				$user->ID = 'nova' === $value ? 7 : 1;

				return $user;
			}
		);
	}

	/**
	 * Requests of each action and whether, and as which screen, they are branded.
	 *
	 * @return array<string, array{string, array<string, mixed>, array<string, mixed>, string}>
	 */
	public static function screens(): array {
		$back = array( 'redirect_to' => self::DASHBOARD );

		return array(
			'login'                       => array( 'login', $back, array(), 'login' ),
			'logged out'                  => array( 'login', $back + array( 'loggedout' => 'true' ), array(), 'loggedout' ),
			'receipt after lost password' => array( 'receipt', $back + array( 'checkemail' => 'confirm' ), array(), 'lostpassword' ),
			'receipt without dashboard'   => array( 'receipt', array( 'checkemail' => 'confirm' ), array(), '' ),
			'receipt of a registration'   => array( 'receipt', $back + array( 'checkemail' => 'registered' ), array(), '' ),
			'admin login'                 => array( 'login', array( 'redirect_to' => 'https://shop.test/wp-admin/' ), array(), '' ),
			'customer login'              => array( 'login', array( 'redirect_to' => 'https://shop.test/my-account/' ), array(), '' ),
			'forged redirect'             => array( 'login', array( 'redirect_to' => 'https://evil.test/creator-dashboard/' ), array(), '' ),
			'interim login'               => array( 'login', $back + array( 'interim-login' => '1' ), array(), '' ),
			'lost password with marker'   => array( 'lost', array( 'merchboard' => '1' ), array(), 'lostpassword' ),
			'lost password without'       => array( 'lost', array(), array(), '' ),
			'reset link of a creator'     => array( 'reset', array( 'login' => 'nova' ), array(), 'resetpass' ),
			'reset cookie of a creator'   => array( 'reset', array(), array( 'wp-resetpass-hash' => 'nova:secret-key' ), 'resetpass' ),
			'reset of the administrator'  => array( 'reset', array( 'login' => 'admin' ), array(), '' ),
			'reset of an unknown user'    => array( 'reset', array( 'login' => 'ghost' ), array(), '' ),
		);
	}

	/**
	 * Each screen is branded only in its context.
	 *
	 * @param string               $action  login, receipt, lost or reset.
	 * @param array<string, mixed> $request Request parameters.
	 * @param array<string, mixed> $cookie  Cookies.
	 * @param string               $screen  Expected screen ('' outside).
	 */
	#[DataProvider( 'screens' )]
	public function test_each_screen_is_branded_only_in_its_context( string $action, array $request, array $cookie, string $screen ): void {
		if ( ! defined( 'COOKIEHASH' ) ) {
			define( 'COOKIEHASH', 'hash' );
		}

		$branding = $this->branding();

		match ( $action ) {
			'lost'    => $branding->observe_lost( $request ),
			'reset'   => $branding->observe_reset( $request, $cookie ),
			'receipt' => $branding->observe_receipt( $request ),
			default   => $branding->observe( $request ),
		};

		self::assertSame( $screen, $branding->screen() );
		self::assertSame( '' !== $screen, $branding->is_active() );

		if ( '' === $screen ) {
			self::assertSame( array( 'login' ), $branding->body_class( array( 'login' ) ) );
			self::assertSame( 'kept', $branding->message( 'kept' ) );
		} else {
			self::assertContains( 'merchboard-login--' . $screen, (array) $branding->body_class( array( 'login' ) ) );
		}
	}

	public function test_the_lost_password_link_and_its_receipt_stay_in_context(): void {
		$branding = $this->branding();

		self::assertSame( 'https://shop.test/my-account/lost-password/', $branding->lostpassword_url( 'https://shop.test/my-account/lost-password/' ), 'Outside the context: WooCommerce\'s own page.' );

		$branding->observe( array( 'redirect_to' => self::DASHBOARD ) );
		self::assertSame( 'https://shop.test/wp-login.php?action=lostpassword&merchboard=1', $branding->lostpassword_url( 'https://shop.test/my-account/lost-password/' ), 'WordPress\' own page with the marker.' );

		$branding->observe_lost( array( 'merchboard' => '1' ) );
		ob_start();
		$branding->lostpassword_marker();
		self::assertSame( '<input type="hidden" name="merchboard" value="1">', ob_get_clean() );
		self::assertStringStartsWith( 'https://shop.test/wp-login.php?checkemail=confirm&redirect_to=', (string) $branding->lostpassword_redirect( 'wp-login.php?checkemail=confirm' ) );
		self::assertSame( 'https://shop.test/wp-login.php', $branding->login_url( 'https://shop.test/wp-login.php', '' ), 'Only after a reset.' );
	}

	public function test_after_a_reset_log_in_leads_to_the_branded_login(): void {
		$branding = $this->branding();
		$branding->observe_reset( array( 'login' => 'nova' ), array() );

		self::assertStringStartsWith( 'https://shop.test/wp-login.php?redirect_to=', (string) $branding->login_url( 'https://shop.test/wp-login.php', '' ) );
		self::assertSame( 'https://shop.test/wp-login.php', $branding->login_url( 'https://shop.test/wp-login.php', 'https://shop.test/x/' ), 'A requested redirect stays.' );
		self::assertSame( 'wp-login.php?checkemail=confirm', $branding->lostpassword_redirect( 'wp-login.php?checkemail=confirm' ) );
	}

	public function test_the_heading_and_text_are_the_administrator_s_or_the_defaults_and_escaped(): void {
		$branding = $this->branding();
		$branding->observe( array( 'redirect_to' => self::DASHBOARD ) );

		self::assertSame( array( 'Welcome back', 'Log in to your creator dashboard.' ), $branding->texts( BrandingDesign::defaults() ) );

		$branding->observe_receipt(
			array(
				'checkemail'  => 'confirm',
				'redirect_to' => self::DASHBOARD,
			)
		);
		self::assertSame( array( 'Forgot your password?', 'Check your e-mail: we have sent you a link to choose a new password.' ), $branding->texts( BrandingDesign::defaults() ), 'The receipt keeps the heading, with its own text.' );
		$branding->observe_lost( array( 'merchboard' => '1' ) );
		self::assertSame( 'Enter your username or e-mail address, and we will send you a link to choose a new password.', $branding->texts( BrandingDesign::defaults() )[1] );
		$branding->observe( array( 'redirect_to' => self::DASHBOARD ) );

		$design                                        = BrandingDesign::defaults();
		$design['layout']                              = 'centered';
		$design['texts']['login']['title']             = 'Hej <b>Tom & Jerry</b>';
		$this->options->options['merchboard_branding'] = $design;

		$message = (string) $branding->message( '<p class="message">WP</p>' );

		self::assertStringContainsString( '>Hej Tom &amp; Jerry</h2>', $message, 'Plain text (tags are stripped when read) and escaped.' );
		self::assertStringEndsWith( '<p class="message">WP</p>', $message, 'WordPress\' own message stays.' );

		$design['layout']                              = 'split';
		$this->options->options['merchboard_branding'] = $design;
		self::assertSame( 'WP', $branding->message( 'WP' ), 'Split: the heading stands on the panel.' );
		ob_start();
		$branding->open_layout();
		self::assertStringContainsString( 'merchboard-login-intro__title', (string) ob_get_clean() );
	}

	public function test_the_inline_css_holds_only_validated_values(): void {
		$this->media->add(
			9,
			array(
				'url'    => 'https://shop.test/a").png',
				'width'  => 1600,
				'height' => 900,
			)
		);

		$design                  = BrandingDesign::defaults();
		$design['background_id'] = 9;
		$css                     = $this->branding()->inline_css( $design );

		self::assertStringStartsWith( 'body.merchboard-login{--merchboard-login-bg:#007279;--merchboard-login-panel-text:#ffffff;--merchboard-login-accent:#007279;--merchboard-login-accent-dark:#338e94', $css );
		self::assertStringNotContainsString( 'a")', $css, 'A hostile image URL never reaches the CSS.' );
		self::assertStringNotContainsString( '--merchboard-login-image', $css );
	}

	public function test_the_form_is_validated_and_refused_values_keep_the_stored_ones(): void {
		$this->media->add(
			3,
			array(
				'width'  => 512,
				'height' => 512,
			)
		);
		$this->media->add(
			4,
			array(
				'width'  => 600,
				'height' => 100,
			)
		);

		[ $design, $problems ] = BrandingDesign::validate(
			array(
				'layout'           => 'centered',
				'background_color' => 'red',
				'panel_text'       => '#FFFFFF',
				'accent'           => '#4F46E5',
				'favicon_id'       => '3',
				'app_icon_id'      => '4',
				'support_url'      => 'javascript:alert(1)',
				'texts'            => array( 'login' => array( 'title' => str_repeat( 'x', 120 ) ) ),
				'logo_size'        => 'huge',
				'shop_link'        => '1',
			),
			BrandingDesign::defaults(),
			new LogoAttachment()
		);

		self::assertSame( 'centered', $design['layout'] );
		self::assertSame( '#007279', $design['background_color'], 'A bad colour keeps the stored one.' );
		self::assertSame( '#ffffff', $design['panel_text'] );
		self::assertSame( 3, $design['favicon_id'], 'A square favicon.' );
		self::assertSame( 0, $design['app_icon_id'], 'Not square: refused.' );
		self::assertSame( '', $design['support_url'] );
		self::assertSame( 80, mb_strlen( $design['texts']['login']['title'] ) );
		self::assertSame( 'small', $design['logo_size'] );
		self::assertTrue( $design['shop_link'] );
		self::assertFalse( $design['signup_link'], 'Unticked: not sent.' );
		self::assertEqualsCanonicalizing( array( BrandingDesign::BAD_COLOR, BrandingDesign::BAD_IMAGE, BrandingDesign::BAD_LINK ), $problems );
	}

	public function test_unreadable_colours_are_refused(): void {
		[ $design, $problems ] = BrandingDesign::validate(
			array(
				'background_color' => '#ffffff',
				'panel_text'       => '#eeeeee',
				'accent'           => '#ffff00',
			),
			BrandingDesign::defaults(),
			new LogoAttachment()
		);

		self::assertSame( array( BrandingDesign::LOW_CONTRAST ), $problems );
		self::assertSame( array( '#007279', '#ffffff', '#007279' ), array( $design['background_color'], $design['panel_text'], $design['accent'] ) );
		self::assertSame( 'mailto:help@shop.test', BrandingDesign::link( 'mailto:help@shop.test' ) );
		self::assertSame( 'https://shop.test/help', BrandingDesign::link( 'https://shop.test/help' ) );
		self::assertNull( BrandingDesign::link( 'http://shop.test/help' ), 'https only.' );
		self::assertGreaterThan( 20, BrandingDesign::contrast( '#000000', '#ffffff' ) );
	}

	public function test_the_button_colour_is_lightened_just_enough_for_the_dark_login(): void {
		self::assertSame( '#338e94', BrandingDesign::on_dark( '#007279' ), 'The logo teal, 20 % white.' );
		self::assertSame( '#9aa3f2', BrandingDesign::on_dark( '#9aa3f2' ), 'Already readable: unchanged.' );

		foreach ( array( '#007279', '#000000', '#1e1b4b', '#b91c1c' ) as $color ) {
			self::assertGreaterThanOrEqual( BrandingDesign::MIN_CONTRAST, BrandingDesign::contrast( BrandingDesign::on_dark( $color ), BrandingDesign::DARK_SURFACE ), $color );
		}
	}

	public function test_the_manifest_holds_the_app_icon_and_no_personal_data(): void {
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'wp_check_filetype' )->justReturn( array( 'type' => 'image/png' ) );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $value, int $flags = 0 ): string|false => json_encode( $value, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stand-in.

		$route = new DashboardRoute();
		$icons = new BrandIcons( $this->logo(), $route );
		$page  = new ManifestPage( $route, $icons );

		self::assertSame( 404, $page->respond()[0], 'Without an icon there is no manifest.' );
		self::assertSame( '', $icons->links() );

		$this->media->add(
			8,
			array(
				'width'  => 512,
				'height' => 512,
			)
		);
		$this->options->options['merchboard_branding'] = array( 'app_icon_id' => 8 ) + BrandingDesign::defaults();

		[ $status, $headers, $body ] = $page->respond();
		$manifest                    = json_decode( $body, true );

		self::assertSame( 200, $status );
		self::assertSame( 'application/manifest+json; charset=utf-8', $headers['Content-Type'] );
		self::assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		self::assertSame( array( 'Nova Merch', 'standalone', '512x512' ), array( $manifest['name'], $manifest['display'], $manifest['icons'][0]['sizes'] ) );
		self::assertSame( self::DASHBOARD, $manifest['start_url'] );
		self::assertStringContainsString( '<link rel="apple-touch-icon" href="https://shop.test/wp-content/uploads/logo-8.png">', $icons->links() );
		self::assertStringContainsString( '<link rel="manifest" href="' . self::DASHBOARD . 'manifest/">', $icons->links() );
	}

	private function logo(): BrandLogo {
		return new BrandLogo( new LogoAttachment(), self::ROOT );
	}

	public function test_become_a_creator_is_a_button_only_while_signing_up_is_open(): void {
		$open = new LoginBranding( new DashboardRoute(), $this->logo(), new BrandIcons( $this->logo(), new DashboardRoute() ), self::ROOT, null, static fn(): string => 'https://shop.test/opret/' );
		$open->observe( array( 'redirect_to' => self::DASHBOARD ) );

		ob_start();
		$open->close_layout();
		self::assertStringContainsString( '<a class="merchboard-login-button" href="https://shop.test/opret/">Become a creator</a>', (string) ob_get_clean() );

		$closed = new LoginBranding( new DashboardRoute(), $this->logo(), new BrandIcons( $this->logo(), new DashboardRoute() ), self::ROOT, null, static fn(): string => '' );
		$closed->observe( array( 'redirect_to' => self::DASHBOARD ) );

		ob_start();
		$closed->close_layout();
		self::assertStringNotContainsString( 'Become a creator', (string) ob_get_clean(), 'Signing up is closed.' );
	}

	private function branding(): LoginBranding {
		$route = new DashboardRoute();

		return new LoginBranding( $route, $this->logo(), new BrandIcons( $this->logo(), $route ), self::ROOT, static fn( int $user_id ): bool => 7 === $user_id );
	}
}
