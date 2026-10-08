<?php
/**
 * The logo setting, its resolution and the Merch Dashboard login branding
 * against the real WordPress media, options, hooks and database (ADR-042).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Dashboard;

use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Dashboard\Branding\BrandLogo;
use MerchBoard\Modules\Dashboard\Branding\LoginBranding;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Settings\SettingsException;
use MerchBoard\Modules\Settings\SettingsService;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BrandingTest extends IntegrationTestCase {

	/**
	 * Attachments created by the test.
	 *
	 * @var array<int, int>
	 */
	private array $attachments = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( Settings::OPTION );
		$this->reset_login();
	}

	protected function tearDown(): void {
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}

		delete_option( Settings::OPTION );
		$this->reset_login();
		wp_dequeue_style( LoginBranding::HANDLE );
		wp_deregister_style( LoginBranding::HANDLE );
		parent::tearDown();
	}

	/**
	 * An attachment record with metadata (no file is needed for the checks).
	 *
	 * @param string $mime   MIME type.
	 * @param string $file   File name in the uploads directory.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 */
	private function attachment( string $mime, string $file, int $width = 400, int $height = 100 ): int {
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'MB-T21 logo',
				'post_status'    => 'inherit',
			),
			$file
		);
		self::assertGreaterThan( 0, $id );
		update_attached_file( $id, $file );
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => $width,
				'height' => $height,
				'file'   => $file,
			)
		);
		$this->attachments[] = $id;

		return $id;
	}

	/**
	 * Accepted logo types.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function accepted(): array {
		return array(
			'PNG'  => array( 'image/png', 'mbt21-logo.png' ),
			'JPEG' => array( 'image/jpeg', 'mbt21-logo.jpg' ),
			'WebP' => array( 'image/webp', 'mbt21-logo.webp' ),
		);
	}

	/**
	 * PNG, JPEG and WebP are saved as the attachment id, audited, and used
	 * in the dashboard and on the login.
	 *
	 * @param string $mime MIME type.
	 * @param string $file File name.
	 */
	#[DataProvider( 'accepted' )]
	public function test_a_raster_logo_is_saved_and_used_everywhere( string $mime, string $file ): void {
		$id    = $this->attachment( $mime, $file );
		$audit = $this->count_rows( Tables::AUDIT_LOG );

		self::assertTrue( $this->service( SettingsService::class )->set_logo_attachment_id( $id ) );

		$stored = get_option( Settings::OPTION );
		self::assertIsArray( $stored );
		self::assertSame( $id, $stored[ Settings::LOGO_ATTACHMENT_ID ], 'Only the attachment id is stored.' );
		self::assertSame( $audit + 1, $this->count_rows( Tables::AUDIT_LOG ) );

		$logo = $this->service( BrandLogo::class )->resolve();
		self::assertNotNull( $logo );
		self::assertTrue( $logo['custom'] );
		self::assertSame( wp_get_attachment_url( $id ), $logo['url'] );
		self::assertTrue( BrandLogo::is_same_origin( $logo['url'] ) );
		self::assertStringContainsString( 'url("' . $logo['url'] . '")', $this->service( LoginBranding::class )->logo_css() );
	}

	/**
	 * Attachments the admin cannot choose.
	 *
	 * @return array<string, array{string, string, int, int, string}>
	 */
	public static function refused(): array {
		return array(
			'SVG'       => array( 'image/svg+xml', 'mbt21-logo.svg', 400, 100, SettingsException::LOGO_WRONG_TYPE ),
			'PDF'       => array( 'application/pdf', 'mbt21-logo.pdf', 400, 100, SettingsException::LOGO_WRONG_TYPE ),
			'too small' => array( 'image/png', 'mbt21-small.png', 100, 40, SettingsException::LOGO_WRONG_SIZE ),
			'portrait'  => array( 'image/png', 'mbt21-tall.png', 100, 300, SettingsException::LOGO_WRONG_SIZE ),
		);
	}

	/**
	 * Refused attachments change nothing.
	 *
	 * @param string $mime   MIME type.
	 * @param string $file   File name.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @param string $reason Expected reason.
	 */
	#[DataProvider( 'refused' )]
	public function test_unusable_attachments_are_refused( string $mime, string $file, int $width, int $height, string $reason ): void {
		$id    = $this->attachment( $mime, $file, $width, $height );
		$audit = $this->count_rows( Tables::AUDIT_LOG );

		try {
			$this->service( SettingsService::class )->set_logo_attachment_id( $id );
			self::fail( 'Expected SettingsException.' );
		} catch ( SettingsException $e ) {
			self::assertSame( $reason, $e->reason() );
		}

		self::assertSame( 0, Settings::logo_attachment_id() );
		self::assertSame( $audit, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_a_post_or_a_missing_id_is_not_a_logo(): void {
		$post = (int) wp_insert_post(
			array(
				'post_title'  => 'MB-T21 post',
				'post_status' => 'publish',
			)
		);

		try {
			foreach ( array( $post, 999999 ) as $id ) {
				try {
					$this->service( SettingsService::class )->set_logo_attachment_id( $id );
					self::fail( 'Expected SettingsException.' );
				} catch ( SettingsException $e ) {
					self::assertSame( SettingsException::LOGO_NOT_FOUND, $e->reason() );
				}
			}
		} finally {
			wp_delete_post( $post, true );
		}
	}

	public function test_only_settings_managers_may_change_the_logo(): void {
		$id = $this->attachment( 'image/png', 'mbt21-logo.png' );
		wp_set_current_user( $this->create_user( 'mbt21_subscriber' ) );

		try {
			$this->service( SettingsService::class )->set_logo_attachment_id( $id );
			self::fail( 'Expected SettingsException.' );
		} catch ( SettingsException $e ) {
			self::assertSame( SettingsException::NOT_AUTHORIZED, $e->reason() );
		}

		self::assertSame( 0, Settings::logo_attachment_id() );
	}

	public function test_a_deleted_logo_falls_back_to_the_default(): void {
		$id = $this->attachment( 'image/png', 'mbt21-logo.png' );
		$this->service( SettingsService::class )->set_logo_attachment_id( $id );

		wp_delete_attachment( $id, true );

		$logo = $this->service( BrandLogo::class )->resolve();
		self::assertNotNull( $logo );
		self::assertFalse( $logo['custom'] );
		self::assertStringContainsString( '/assets/brand/logo.svg', $logo['url'] );
		self::assertSame( $id, Settings::logo_attachment_id(), 'The stored id stays until an admin changes it; it is just not used.' );
	}

	public function test_the_login_is_branded_only_in_merch_dashboard_context(): void {
		$dashboard = ( new DashboardRoute() )->url();
		$branding  = $this->service( LoginBranding::class );

		// Other actions never activate it, even with a dashboard redirect_to.
		$_REQUEST = array( 'redirect_to' => $dashboard ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simulated login request.
		do_action( 'login_form_lostpassword' );
		do_action( 'login_form_rp' );
		do_action( 'login_form_register' );
		self::assertFalse( $branding->is_active() );
		self::assertSame( array( 'login' ), apply_filters( 'login_body_class', array( 'login' ), 'lostpassword' ) );

		// The interim login (wp-admin's session modal) is never branded.
		$_REQUEST = array(
			'redirect_to'   => $dashboard,
			'interim-login' => '1',
		);
		do_action( 'login_form_login' );
		self::assertFalse( $branding->is_active() );

		// A login for something else is never branded.
		$_REQUEST = array( 'redirect_to' => admin_url() );
		do_action( 'login_form_login' );
		self::assertFalse( $branding->is_active() );
		do_action( 'login_enqueue_scripts' );
		self::assertFalse( wp_style_is( LoginBranding::HANDLE, 'enqueued' ) );
		self::assertSame( 'https://wordpress.org/', apply_filters( 'login_headerurl', 'https://wordpress.org/' ) );

		// The login screen on its way to the dashboard is branded.
		$_REQUEST = array( 'redirect_to' => ( new DashboardRoute() )->url( 'sales' ) );
		do_action( 'login_form_login' );
		self::assertTrue( $branding->is_active() );
		self::assertContains( LoginBranding::BODY_CLASS, apply_filters( 'login_body_class', array( 'login' ), 'login' ) );
		self::assertSame( home_url( '/' ), apply_filters( 'login_headerurl', 'https://wordpress.org/' ) );
		self::assertSame( get_bloginfo( 'name' ), apply_filters( 'login_headertext', 'Powered by WordPress' ) );

		do_action( 'login_enqueue_scripts' );
		self::assertTrue( wp_style_is( LoginBranding::HANDLE, 'enqueued' ) );
		self::assertSame( array( 'login' ), wp_styles()->registered[ LoginBranding::HANDLE ]->deps, 'After WordPress\' own login style.' );
		$inline = (array) wp_styles()->get_data( LoginBranding::HANDLE, 'after' );
		self::assertStringContainsString( '/assets/brand/logo.svg', implode( '', $inline ) );
	}

	public function test_the_login_flow_itself_is_untouched(): void {
		// Merch Dashboard adds no authentication, credential, redirect or URL
		// filters of its own on top of the existing LoginRedirect (ADR-026).
		$login_url = wp_login_url( home_url( '/' ) );

		self::assertStringContainsString( '/wp-login.php', $login_url );
		self::assertStringContainsString( '/wp-login.php', wp_lostpassword_url() );

		$hooks = array( 'login_url', 'lostpassword_url', 'logout_url', 'authenticate', 'wp_authenticate', 'wp_login', 'login_redirect', 'login_init', 'login_form', 'login_form_lostpassword', 'login_form_retrievepassword', 'login_form_rp', 'login_form_resetpass', 'login_form_register', 'login_form_logout', 'lostpassword_redirect', 'secure_signon_cookie' );

		foreach ( $hooks as $hook ) {
			self::assertSame( array(), $this->branding_callbacks( $hook ), $hook . ' has no branding callback.' );
		}

		self::assertNotSame( array(), $this->branding_callbacks( 'login_form_login' ), 'The only action observed is the login screen.' );
	}

	/**
	 * Callbacks on a hook that are defined in the branding code.
	 *
	 * @param string $hook Hook.
	 * @return array<int, string>
	 */
	private function branding_callbacks( string $hook ): array {
		global $wp_filter;

		$found = array();
		$dir   = realpath( dirname( __DIR__, 3 ) . '/src/Modules/Dashboard/Branding' );

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $found;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];

				if ( is_array( $function ) && is_object( $function[0] ) && is_string( $function[1] ) ) {
					$reflection = new \ReflectionMethod( $function[0], $function[1] );
				} elseif ( $function instanceof \Closure ) {
					$reflection = new \ReflectionFunction( $function );
				} else {
					continue;
				}

				$file = (string) $reflection->getFileName();

				if ( false !== $dir && str_starts_with( (string) realpath( $file ), $dir ) ) {
					$found[] = $file;
				}
			}
		}

		return $found;
	}

	/**
	 * Clears the simulated login request and the observed context.
	 */
	private function reset_login(): void {
		$_REQUEST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simulated login request.
		$this->service( LoginBranding::class )->observe( array() );
	}
}
