<?php
/**
 * Tests for the logo setting (ADR-042): service, form handler and page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Settings;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Settings\Admin\SettingsActions;
use MerchBoard\Modules\Settings\Admin\SettingsPage;
use MerchBoard\Modules\Settings\SettingsException;
use MerchBoard\Modules\Settings\SettingsService;
use MerchBoard\Modules\Settings\SlugAvailability;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\FakeMediaLibrary;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( SettingsService::class )]
#[CoversClass( SettingsActions::class )]
#[CoversClass( SettingsPage::class )]
final class LogoSettingTest extends TestCase {

	private FakeDatabase $db;

	private InMemoryOptions $options;

	private FakeMediaLibrary $media;

	private SettingsService $service;

	/**
	 * The current user's capabilities.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array( Capabilities::MANAGE_SETTINGS );

	protected function setUp(): void {
		parent::setUp();
		$this->db      = new FakeDatabase();
		$this->options = InMemoryOptions::install();
		$this->media   = FakeMediaLibrary::install();
		$this->service = new SettingsService(
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) ),
			new SlugAvailability()
		);

		$this->media->add( 5 );
		$this->media->add(
			6,
			array(
				'mime' => 'image/webp',
				'file' => 'logo-6.webp',
				'url'  => 'https://shop.test/wp-content/uploads/logo-6.webp',
			)
		);
		$this->media->add(
			7,
			array(
				'mime' => 'image/svg+xml',
				'file' => 'logo-7.svg',
			)
		);

		Functions\when( 'current_user_can' )->alias( fn( string $cap ): bool => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-10-08 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce, string $action ): int|false => 'valid:' . $action === $nonce ? 1 : false );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
		Functions\when( 'absint' )->alias( static fn( mixed $value ): int => abs( (int) $value ) );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'plugins_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-content/plugins/merchboard/' . $path );
	}

	public function test_a_valid_logo_is_saved_as_its_id_and_audited(): void {
		self::assertTrue( $this->service->set_logo_attachment_id( 5 ) );

		self::assertSame( 5, Settings::logo_attachment_id() );
		self::assertSame( 5, $this->options->options[ Settings::OPTION ][ Settings::LOGO_ATTACHMENT_ID ], 'Only the id is stored, no URL.' );

		$audit = $this->db->rows( 'merchboard_audit_log' );
		self::assertCount( 1, $audit );
		self::assertSame( 'settings_updated', $audit[0]['action'] );
		self::assertSame( '{"setting":"logo_attachment_id","previous_value":0,"value":5}', $audit[0]['context'] );

		self::assertTrue( $this->service->set_logo_attachment_id( 6 ), 'WebP.' );
		self::assertFalse( $this->service->set_logo_attachment_id( 6 ), 'Unchanged: no write, no audit.' );
		self::assertCount( 2, $this->db->rows( 'merchboard_audit_log' ) );

		self::assertTrue( $this->service->set_logo_attachment_id( 0 ), 'Back to the default logo.' );
		self::assertSame( 0, Settings::logo_attachment_id() );
		self::assertSame( '{"setting":"logo_attachment_id","previous_value":6,"value":0}', $this->db->rows( 'merchboard_audit_log' )[2]['context'] );
	}

	/**
	 * Refused ids and the reason shown to the admin.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function refused(): array {
		return array(
			'SVG'       => array( 7, SettingsException::LOGO_WRONG_TYPE ),
			'missing'   => array( 99, SettingsException::LOGO_NOT_FOUND ),
			'too small' => array( 8, SettingsException::LOGO_WRONG_SIZE ),
			'foreign'   => array( 9, SettingsException::LOGO_FOREIGN_URL ),
		);
	}

	/**
	 * Unusable attachments are refused; nothing is stored or audited.
	 *
	 * @param int    $id     Attachment id.
	 * @param string $reason Expected reason.
	 */
	#[DataProvider( 'refused' )]
	public function test_unusable_attachments_are_refused( int $id, string $reason ): void {
		$this->media->add(
			8,
			array(
				'width'  => 100,
				'height' => 40,
			)
		);
		$this->media->add( 9, array( 'url' => 'https://cdn.example/logo.png' ) );

		try {
			$this->service->set_logo_attachment_id( $id );
			self::fail( 'Expected SettingsException.' );
		} catch ( SettingsException $e ) {
			self::assertSame( $reason, $e->reason() );
		}

		self::assertSame( 0, Settings::logo_attachment_id() );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
		self::assertNotNull( SettingsPage::notice_message( $reason ) );
	}

	public function test_only_the_settings_capability_may_change_the_logo(): void {
		foreach ( array( array( 'read', Capabilities::ACCESS_DASHBOARD ), array( Capabilities::MANAGE, Capabilities::MANAGE_CREATORS ), array( 'manage_options', 'upload_files' ) ) as $caps ) {
			$this->caps = $caps;

			try {
				$this->service->set_logo_attachment_id( 5 );
				self::fail( 'Expected SettingsException.' );
			} catch ( SettingsException $e ) {
				self::assertSame( SettingsException::NOT_AUTHORIZED, $e->reason() );
			}

			try {
				$this->actions()->save_logo( $this->form() );
				self::fail( 'Expected WpDie.' );
			} catch ( WpDie $e ) {
				self::assertSame( 403, $e->response );
			}
		}

		self::assertSame( 0, Settings::logo_attachment_id() );
	}

	public function test_the_handler_checks_its_own_nonce(): void {
		foreach ( array( null, 'forged', 'valid:' . SettingsActions::SAVE_SLUG_ACTION, 'valid:' . SettingsActions::SAVE_ACTION ) as $nonce ) {
			$form = $this->form();
			unset( $form['_wpnonce'] );

			if ( null !== $nonce ) {
				$form['_wpnonce'] = $nonce;
			}

			try {
				$this->actions()->save_logo( $form );
				self::fail( 'Expected WpDie.' );
			} catch ( WpDie $e ) {
				self::assertSame( 403, $e->response );
			}
		}

		self::assertSame( 0, Settings::logo_attachment_id() );
	}

	public function test_the_handler_saves_resets_and_reports_rejections(): void {
		self::assertStringContainsString( 'merchboard_notice=saved', $this->actions()->save_logo( $this->form() ) );
		self::assertSame( 5, Settings::logo_attachment_id() );

		self::assertStringContainsString( 'merchboard_notice=logo_wrong_type', $this->actions()->save_logo( $this->form( array( SettingsPage::FIELD_LOGO => '7' ) ) ) );

		foreach ( array( 'https://shop.test/logo.png', '5 OR 1=1', '-5', '5.0', '', array( '5' ) ) as $value ) {
			self::assertStringContainsString( 'merchboard_notice=logo_not_found', $this->actions()->save_logo( $this->form( array( SettingsPage::FIELD_LOGO => $value ) ) ), 'Only a plain id is accepted.' );
		}

		self::assertStringContainsString( 'merchboard_notice=no_changes', $this->actions()->save_logo( $this->form() ) );
		self::assertSame( 5, Settings::logo_attachment_id() );

		// "Use the default logo" sends its button (with its translated label as value).
		self::assertStringContainsString( 'merchboard_notice=saved', $this->actions()->save_logo( $this->form( array( SettingsPage::FIELD_REMOVE_LOGO => 'Use the default logo' ) ) ) );
		self::assertSame( 0, Settings::logo_attachment_id() );
	}

	public function test_the_page_shows_the_logo_in_use_and_escapes_it(): void {
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action ): void {
				echo '<input type="hidden" name="_wpnonce" value="nonce:' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias(
			static function ( string $text, string $type = 'primary', string $name = 'submit' ): void {
				echo '<input type="submit" name="' . $name . '" value="' . $text . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $fallback = false ): mixed => $this->options->options[ $name ] ?? $fallback );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'trailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/' ) . '/' );
		Functions\when( 'do_action' )->justReturn( null );

		$html = $this->render();
		self::assertStringContainsString( 'name="action" value="' . SettingsActions::SAVE_LOGO_ACTION . '"', $html );
		self::assertStringContainsString( 'value="nonce:' . SettingsActions::SAVE_LOGO_ACTION . '"', $html );
		self::assertStringContainsString( 'src="https://shop.test/wp-content/plugins/merchboard/assets/brand/logo.svg', $html, 'Default logo.' );
		self::assertStringContainsString( 'The default Merch Dashboard logo is in use.', $html );
		self::assertStringNotContainsString( 'name="' . SettingsPage::FIELD_REMOVE_LOGO . '"', $html, 'Nothing to reset.' );
		self::assertStringContainsString( 'SVG is not allowed', $html );

		$this->options->options[ Settings::OPTION ] = array( Settings::LOGO_ATTACHMENT_ID => 5 );
		$html                                       = $this->render();
		self::assertStringContainsString( 'src="https://shop.test/wp-content/uploads/logo-5.png"', $html );
		self::assertStringContainsString( 'A custom logo is in use.', $html );
		self::assertStringContainsString( 'name="' . SettingsPage::FIELD_REMOVE_LOGO . '"', $html );
		self::assertStringContainsString( 'id="merchboard-logo-id" name="logo_attachment_id" value="5"', $html );

		// The attachment became unusable: warning and the default logo.
		$this->media->items[5]['url'] = 'https://cdn.example/"><script>alert(1)</script>.png';
		$html                         = $this->render();
		self::assertStringContainsString( 'can no longer be used', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'assets/brand/logo.svg', $html );
	}

	private function render(): string {
		ob_start();
		( new SettingsPage() )->render();

		return (string) ob_get_clean();
	}

	private function actions(): SettingsActions {
		return new SettingsActions( $this->service, new RecordingLogger() );
	}

	/**
	 * A valid logo submission.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function form( array $overrides = array() ): array {
		return array_merge(
			array(
				'_wpnonce'               => 'valid:' . SettingsActions::SAVE_LOGO_ACTION,
				SettingsPage::FIELD_LOGO => '5',
			),
			$overrides
		);
	}
}
