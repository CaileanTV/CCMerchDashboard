<?php
/**
 * Tests for the dashboard slug setting.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Settings;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
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
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

#[CoversClass( Settings::class )]
#[CoversClass( SettingsService::class )]
#[CoversClass( SettingsActions::class )]
#[CoversClass( SettingsPage::class )]
#[CoversClass( SlugAvailability::class )]
final class DashboardSlugTest extends TestCase {

	private FakeDatabase $db;

	private InMemoryOptions $options;

	private RecordingLogger $logger;

	private SettingsService $service;

	/**
	 * Capabilities of the current user.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array( Capabilities::MANAGE_SETTINGS );

	/**
	 * Slugs of existing posts of any type.
	 *
	 * @var array<int, string>
	 */
	private array $post_slugs = array( 'about' );

	protected function setUp(): void {
		parent::setUp();
		$this->db      = new FakeDatabase();
		$this->options = InMemoryOptions::install( array( 'permalink_structure' => '/%postname%/' ) );
		$this->logger  = new RecordingLogger();
		$this->service = new SettingsService(
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) ),
			new SlugAvailability()
		);

		Functions\when( 'current_user_can' )->alias( fn( string $cap ): bool => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-09-24 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce, string $action ): int|false => 'valid:' . $action === $nonce ? 1 : false );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'trailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/' ) . '/' );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'plugins_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-content/plugins/merchboard/' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.

		// The site's content and rewrite bases, as WooCommerce registers them.
		Functions\when( 'get_posts' )->alias( fn( array $args ): array => in_array( $args['name'], $this->post_slugs, true ) ? array( 7 ) : array() );
		Functions\when( 'get_post_types' )->alias(
			static function ( array $args = array(), string $output = 'names' ): array {
				$product              = new stdClass();
				$product->rewrite     = array( 'slug' => 'produkt' );
				$product->has_archive = 'butik';
				$page                 = new stdClass();
				$page->rewrite        = false;
				$page->has_archive    = false;

				return 'objects' === $output ? array( $product, $page ) : array( 'post', 'page', 'product' );
			}
		);
		Functions\when( 'get_taxonomies' )->alias(
			static function (): array {
				$taxonomy          = new stdClass();
				$taxonomy->rewrite = array( 'slug' => 'kategori/produkter' );

				return array( $taxonomy );
			}
		);
	}

	/**
	 * Slugs and whether they are well-formed and not reserved.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function slugs(): array {
		return array(
			'default'        => array( 'creator-dashboard', true ),
			'short'          => array( 'abc', true ),
			'digits'         => array( 'partner-2026', true ),
			'too short'      => array( 'ab', false ),
			'too long'       => array( str_repeat( 'a', 51 ), false ),
			'uppercase'      => array( 'Creator', false ),
			'space'          => array( 'creator dashboard', false ),
			'slash'          => array( 'creator/dashboard', false ),
			'leading hyphen' => array( '-creator', false ),
			'double hyphen'  => array( 'creator--x', false ),
			'unicode'        => array( 'kreatør', false ),
			'dot'            => array( 'creator.php', false ),
			'script'         => array( '<script>', false ),
			'wp-admin'       => array( 'wp-admin', false ),
			'wp-json'        => array( 'wp-json', false ),
			'login'          => array( 'login', false ),
			'feed'           => array( 'feed', false ),
			'shop'           => array( 'shop', false ),
			'cart'           => array( 'cart', false ),
			'checkout'       => array( 'checkout', false ),
			'my-account'     => array( 'my-account', false ),
			'product'        => array( 'product', false ),
		);
	}

	#[DataProvider( 'slugs' )]
	public function test_slug_format_and_reserved_paths( string $slug, bool $valid ): void {
		self::assertSame( $valid, Settings::valid_slug( $slug ) );

		$this->options->options[ Settings::OPTION ] = array( Settings::DASHBOARD_SLUG => $slug );
		self::assertSame( $valid ? $slug : Settings::DEFAULT_DASHBOARD_SLUG, Settings::dashboard_slug(), 'A forged option falls back to the default.' );
	}

	public function test_default_slug_and_non_string_values(): void {
		self::assertSame( Settings::DEFAULT_DASHBOARD_SLUG, Settings::dashboard_slug() );
		self::assertSame( 'creator-dashboard', Settings::defaults()[ Settings::DASHBOARD_SLUG ] );

		$this->options->options[ Settings::OPTION ] = array( Settings::DASHBOARD_SLUG => array( 'partner' ) );
		self::assertSame( 'creator-dashboard', Settings::dashboard_slug() );
	}

	public function test_changing_the_slug_is_audited_and_invalidates_the_rewrite_rules(): void {
		$this->options->options['rewrite_rules'] = array( 'x' => 'y' );
		Actions\expectDone( 'merchboard_dashboard_slug_changed' )->once()->with( 'partner', 'creator-dashboard' );

		self::assertTrue( $this->service->set_dashboard_slug( ' /Partner/ ' ) );

		self::assertSame( 'partner', Settings::dashboard_slug() );
		$audit = $this->db->rows( 'merchboard_audit_log' );
		self::assertCount( 1, $audit );
		self::assertSame( 'settings_updated', $audit[0]['action'] );
		self::assertSame( '1', $audit[0]['actor_user_id'] );
		self::assertSame( '{"setting":"dashboard_slug","previous_value":"creator-dashboard","value":"partner"}', $audit[0]['context'] );
	}

	public function test_changing_the_slug_keeps_the_deletion_opt_in(): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::DELETE_DATA_ON_UNINSTALL => true );

		$this->service->set_dashboard_slug( 'partner' );

		self::assertTrue( Settings::delete_data_on_uninstall() );
	}

	public function test_unchanged_slug_writes_nothing(): void {
		self::assertFalse( $this->service->set_dashboard_slug( 'creator-dashboard' ) );
		self::assertSame( array(), $this->options->updates );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_invalid_and_reserved_slugs_are_rejected(): void {
		foreach ( array( '', 'wp-admin', 'my-account', 'a b', '../x', 'x' ) as $slug ) {
			try {
				$this->service->set_dashboard_slug( $slug );
				self::fail( 'Expected SettingsException for "' . $slug . '".' );
			} catch ( SettingsException $e ) {
				self::assertSame( SettingsException::INVALID_SLUG, $e->reason() );
			}
		}

		self::assertSame( 'creator-dashboard', Settings::dashboard_slug() );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_slugs_used_by_content_or_rewrite_bases_are_rejected(): void {
		foreach ( array( 'about', 'produkt', 'butik', 'kategori' ) as $slug ) {
			try {
				$this->service->set_dashboard_slug( $slug );
				self::fail( 'Expected SettingsException for "' . $slug . '".' );
			} catch ( SettingsException $e ) {
				self::assertSame( SettingsException::SLUG_IN_USE, $e->reason(), $slug );
			}
		}

		self::assertSame( 'creator-dashboard', Settings::dashboard_slug() );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_failed_option_write_keeps_the_old_slug_and_no_audit_record(): void {
		Functions\when( 'update_option' )->justReturn( false );
		Actions\expectDone( 'merchboard_dashboard_slug_changed' )->never();

		$this->expectException( DatabaseException::class );

		try {
			$this->service->set_dashboard_slug( 'partner' );
		} finally {
			self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
		}
	}

	public function test_only_the_settings_capability_may_change_the_slug(): void {
		foreach ( array( array( 'read', Capabilities::ACCESS_DASHBOARD ), array( Capabilities::MANAGE, Capabilities::MANAGE_CREATORS ), array( 'manage_options' ) ) as $caps ) {
			$this->caps = $caps;

			try {
				$this->service->set_dashboard_slug( 'partner' );
				self::fail( 'Expected SettingsException.' );
			} catch ( SettingsException $e ) {
				self::assertSame( SettingsException::NOT_AUTHORIZED, $e->reason() );
			}

			try {
				$this->actions()->save_slug( $this->form() );
				self::fail( 'Expected WpDie.' );
			} catch ( WpDie $e ) {
				self::assertSame( 403, $e->response );
			}
		}

		self::assertSame( 'creator-dashboard', Settings::dashboard_slug() );
	}

	public function test_handler_checks_its_own_nonce(): void {
		foreach ( array( null, 'forged', 'valid:' . SettingsActions::SAVE_ACTION ) as $nonce ) {
			$form = $this->form();
			unset( $form['_wpnonce'] );

			if ( null !== $nonce ) {
				$form['_wpnonce'] = $nonce;
			}

			try {
				$this->actions()->save_slug( $form );
				self::fail( 'Expected WpDie.' );
			} catch ( WpDie $e ) {
				self::assertSame( 403, $e->response );
			}
		}

		self::assertSame( 'creator-dashboard', Settings::dashboard_slug() );
	}

	public function test_handler_saves_and_reports_rejections(): void {
		self::assertStringContainsString( 'merchboard_notice=saved', $this->actions()->save_slug( $this->form() ) );
		self::assertSame( 'partner', Settings::dashboard_slug() );

		self::assertStringContainsString( 'merchboard_notice=invalid_slug', $this->actions()->save_slug( $this->form( array( SettingsPage::FIELD_SLUG => 'wp-admin' ) ) ) );
		self::assertStringContainsString( 'merchboard_notice=slug_in_use', $this->actions()->save_slug( $this->form( array( SettingsPage::FIELD_SLUG => 'about' ) ) ) );
		self::assertStringContainsString( 'merchboard_notice=invalid_slug', $this->actions()->save_slug( $this->form( array( SettingsPage::FIELD_SLUG => array( 'x' ) ) ) ) );
		self::assertStringContainsString( 'merchboard_notice=no_changes', $this->actions()->save_slug( $this->form() ) );
		self::assertSame( 'partner', Settings::dashboard_slug() );
		self::assertNotNull( SettingsPage::notice_message( SettingsException::INVALID_SLUG ) );
		self::assertNotNull( SettingsPage::notice_message( SettingsException::SLUG_IN_USE ) );
	}

	public function test_page_shows_the_slug_form_and_dashboard_url(): void {
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action ): void {
				echo '<input type="hidden" name="_wpnonce" value="nonce:' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias(
			static function ( string $text ): void {
				echo '<button>' . $text . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);

		ob_start();
		( new SettingsPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'nonce:merchboard_save_dashboard_slug', $html );
		self::assertStringContainsString( 'nonce:merchboard_save_settings', $html );
		self::assertStringContainsString( 'name="dashboard_slug" value="creator-dashboard"', $html );
		self::assertStringContainsString( 'https://shop.test/creator-dashboard/', $html );
		self::assertStringNotContainsString( 'Plain permalinks', $html );
	}

	private function actions(): SettingsActions {
		return new SettingsActions( $this->service, $this->logger );
	}

	/**
	 * A valid slug submission.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function form( array $overrides = array() ): array {
		return array_merge(
			array(
				'_wpnonce'               => 'valid:' . SettingsActions::SAVE_SLUG_ACTION,
				SettingsPage::FIELD_SLUG => 'partner',
			),
			$overrides
		);
	}
}
