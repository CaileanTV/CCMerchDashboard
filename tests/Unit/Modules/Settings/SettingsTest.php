<?php
/**
 * Tests for the settings: option, service, admin handler and page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Settings;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Settings\Admin\SettingsActions;
use MerchBoard\Modules\Settings\Admin\SettingsPage;
use MerchBoard\Modules\Settings\SettingsException;
use MerchBoard\Modules\Settings\SettingsModule;
use MerchBoard\Modules\Settings\SettingsService;
use MerchBoard\Modules\Settings\SlugAvailability;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( Settings::class )]
#[CoversClass( SettingsService::class )]
#[CoversClass( SettingsActions::class )]
#[CoversClass( SettingsPage::class )]
#[CoversClass( SettingsException::class )]
#[CoversClass( SettingsModule::class )]
final class SettingsTest extends TestCase {

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

	protected function setUp(): void {
		parent::setUp();
		$this->db      = new FakeDatabase();
		$this->options = InMemoryOptions::install();
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
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'trailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/' ) . '/' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'plugins_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-content/plugins/merchboard/' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.
	}

	/**
	 * Values that must not enable deletion.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function forged_values(): array {
		return array(
			'string 1'   => array( '1' ),
			'string yes' => array( 'yes' ),
			'int 1'      => array( 1 ),
			'string on'  => array( 'on' ),
			'array'      => array( array( true ) ),
		);
	}

	#[DataProvider( 'forged_values' )]
	public function test_only_exactly_true_enables_deletion( mixed $value ): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::DELETE_DATA_ON_UNINSTALL => $value );

		self::assertFalse( Settings::delete_data_on_uninstall() );
	}

	public function test_default_is_off_and_corrupt_options_are_ignored(): void {
		self::assertFalse( Settings::delete_data_on_uninstall() );

		$this->options->options[ Settings::OPTION ] = 'garbage';
		self::assertSame( Settings::defaults(), Settings::all() );

		$this->options->options[ Settings::OPTION ] = array(
			Settings::DELETE_DATA_ON_UNINSTALL => true,
			'unknown'                          => 'x',
		);
		self::assertSame(
			array(
				Settings::DELETE_DATA_ON_UNINSTALL => true,
				Settings::DASHBOARD_SLUG           => Settings::DEFAULT_DASHBOARD_SLUG,
				Settings::COUNTED_ORDER_STATUSES   => array( 'processing', 'completed' ),
				Settings::LOGO_ATTACHMENT_ID       => 0,
			),
			Settings::all()
		);
	}

	/**
	 * Stored logo values: only a positive integer id counts (ADR-042).
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function stored_logo_ids(): array {
		return array(
			'id'           => array( 42, 42 ),
			'missing'      => array( null, 0 ),
			'zero'         => array( 0, 0 ),
			'negative'     => array( -3, 0 ),
			'numeric text' => array( '42', 0 ),
			'url'          => array( 'https://evil.test/logo.png', 0 ),
			'float'        => array( 4.2, 0 ),
			'array'        => array( array( 42 ), 0 ),
		);
	}

	/**
	 * The stored logo id is sanitized on every read.
	 *
	 * @param mixed $stored   Stored value.
	 * @param int   $expected Expected id.
	 */
	#[DataProvider( 'stored_logo_ids' )]
	public function test_logo_attachment_id_is_only_a_positive_integer( mixed $stored, int $expected ): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::LOGO_ATTACHMENT_ID => $stored );

		self::assertSame( $expected, Settings::logo_attachment_id() );
		self::assertSame( 0, Settings::defaults()[ Settings::LOGO_ATTACHMENT_ID ] );
	}

	public function test_counted_order_statuses_default_to_processing_and_completed(): void {
		self::assertSame( array( 'processing', 'completed' ), Settings::DEFAULT_COUNTED_ORDER_STATUSES );
		self::assertSame( array( 'processing', 'completed' ), Settings::counted_order_statuses() );
		self::assertSame( array( 'processing', 'completed' ), Settings::defaults()[ Settings::COUNTED_ORDER_STATUSES ] );
	}

	public function test_counted_order_statuses_keep_a_valid_list_in_its_order(): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::COUNTED_ORDER_STATUSES => array( 'on-hold', 'completed', 'shipped-2' ) );

		self::assertSame( array( 'on-hold', 'completed', 'shipped-2' ), Settings::counted_order_statuses() );
	}

	/**
	 * Stored values and the list Core Settings makes of them (format only).
	 *
	 * @return array<string, array{mixed, list<string>}>
	 */
	public static function stored_status_lists(): array {
		return array(
			'not an array'          => array( 'completed', array( 'processing', 'completed' ) ),
			'empty list'            => array( array(), array( 'processing', 'completed' ) ),
			'only invalid entries'  => array( array( 'Completed', 'on hold', 'on.hold', 'wc-completed', 'wc-', '', 1, null, array( 'completed' ) ), array( 'processing', 'completed' ) ),
			'invalid entries mixed' => array( array( 'BAD', 'completed', 7, 'Awaiting_Shipment', 'on-hold' ), array( 'completed', 'on-hold' ) ),
			'underscores kept'      => array( array( 'awaiting_shipment', 'partial_paid-2', 'completed' ), array( 'awaiting_shipment', 'partial_paid-2', 'completed' ) ),
			'sanitize_key charset'  => array( array( '-x', 'a--b', 'x_', '_' ), array( '-x', 'a--b', 'x_', '_' ) ),
			'17 characters fit'     => array( array( str_repeat( 'a', 17 ) ), array( str_repeat( 'a', 17 ) ) ),
			'18 characters do not'  => array( array( str_repeat( 'a', 18 ), 'completed' ), array( 'completed' ) ),
			'duplicates'            => array( array( 'completed', 'on-hold', 'completed', 'on-hold' ), array( 'completed', 'on-hold' ) ),
			'associative keys'      => array(
				array(
					'x' => 'on-hold',
					'y' => 'completed',
				),
				array( 'on-hold', 'completed' ),
			),
			'denied is format-ok'   => array( array( 'pending' ), array( 'pending' ) ),
		);
	}

	/**
	 * Core only checks the format and removes duplicates deterministically;
	 * the deny list and WooCommerce' registrations are the WooCommerce
	 * module's job.
	 *
	 * @param mixed    $stored   Stored value.
	 * @param string[] $expected Resulting list.
	 */
	#[DataProvider( 'stored_status_lists' )]
	public function test_counted_order_statuses_are_format_checked_and_deduplicated( mixed $stored, array $expected ): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::COUNTED_ORDER_STATUSES => $stored );

		self::assertSame( $expected, Settings::counted_order_statuses() );
		self::assertSame( $expected, Settings::sanitize( array( Settings::COUNTED_ORDER_STATUSES => $stored ) )[ Settings::COUNTED_ORDER_STATUSES ] );
	}

	public function test_status_slug_limit_fits_the_prefixed_status_in_both_order_stores(): void {
		// wp_posts.post_status and wc_orders.status are varchar(20) and hold "wc-" + slug.
		self::assertSame( 20, Settings::STATUS_SLUG_MAX_LENGTH + strlen( 'wc-' ) );
		self::assertTrue( Settings::valid_status_slug( 'awaiting_shipment' ) );
		self::assertFalse( Settings::valid_status_slug( 'Awaiting_shipment' ), 'Capitals are rejected.' );
		self::assertFalse( Settings::valid_status_slug( 'wc-shipped' ), 'Stored slugs never carry the prefix.' );
		self::assertFalse( Settings::valid_status_slug( '' ) );
	}

	public function test_saving_other_settings_keeps_the_counted_order_statuses(): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::COUNTED_ORDER_STATUSES => array( 'on-hold' ) );

		self::assertTrue( $this->service->set_delete_data_on_uninstall( true, 'DELETE' ) );

		self::assertSame( array( 'on-hold' ), Settings::counted_order_statuses() );
	}

	public function test_enabling_requires_the_typed_confirmation(): void {
		foreach ( array( '', 'delete', 'yes', ' DELET ' ) as $confirmation ) {
			try {
				$this->service->set_delete_data_on_uninstall( true, $confirmation );
				self::fail( 'Expected SettingsException for "' . $confirmation . '".' );
			} catch ( SettingsException $e ) {
				self::assertSame( SettingsException::CONFIRMATION_REQUIRED, $e->reason() );
			}
		}

		self::assertFalse( Settings::delete_data_on_uninstall() );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_enabling_stores_the_setting_and_writes_an_audit_record(): void {
		self::assertTrue( $this->service->set_delete_data_on_uninstall( true, ' DELETE ' ) );

		self::assertTrue( Settings::delete_data_on_uninstall() );
		$audit = $this->db->rows( 'merchboard_audit_log' );
		self::assertCount( 1, $audit );
		self::assertSame( 'settings_updated', $audit[0]['action'] );
		self::assertSame( '1', $audit[0]['actor_user_id'] );
		self::assertNull( $audit[0]['creator_id'] );
		self::assertSame( '{"setting":"delete_data_on_uninstall","enabled":true}', $audit[0]['context'] );
	}

	public function test_disabling_needs_no_confirmation_and_is_audited(): void {
		$this->service->set_delete_data_on_uninstall( true, 'DELETE' );

		self::assertTrue( $this->service->set_delete_data_on_uninstall( false ) );

		self::assertFalse( Settings::delete_data_on_uninstall() );
		self::assertSame( '{"setting":"delete_data_on_uninstall","enabled":false}', $this->db->rows( 'merchboard_audit_log' )[1]['context'] );
	}

	public function test_unchanged_value_writes_nothing(): void {
		self::assertFalse( $this->service->set_delete_data_on_uninstall( false ) );
		self::assertSame( array(), $this->options->updates );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_failed_option_write_rolls_back_the_audit_record(): void {
		Functions\when( 'update_option' )->justReturn( false );

		try {
			$this->service->set_delete_data_on_uninstall( true, 'DELETE' );
			self::fail( 'Expected DatabaseException.' );
		} catch ( \MerchBoard\Core\Database\DatabaseException $e ) {
			self::assertSame( 'Could not save settings.', $e->getMessage() );
		}

		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	/**
	 * Users that must not change settings.
	 *
	 * @return array<string, array{array<int, string>}>
	 */
	public static function unauthorized(): array {
		return array(
			'subscriber'          => array( array( 'read' ) ),
			'creator'             => array( array( 'read', Capabilities::ACCESS_DASHBOARD ) ),
			'creator manager'     => array( array( Capabilities::MANAGE, Capabilities::MANAGE_CREATORS ) ),
			'WordPress admin cap' => array( array( 'manage_options', 'activate_plugins', 'delete_plugins' ) ),
		);
	}

	/**
	 * Service and handler refuse users without merchboard_manage_settings.
	 *
	 * @param array<int, string> $caps Capabilities.
	 */
	#[DataProvider( 'unauthorized' )]
	public function test_users_without_the_settings_capability_are_rejected( array $caps ): void {
		$this->caps = $caps;

		try {
			$this->service->set_delete_data_on_uninstall( true, 'DELETE' );
			self::fail( 'Expected SettingsException.' );
		} catch ( SettingsException $e ) {
			self::assertSame( SettingsException::NOT_AUTHORIZED, $e->reason() );
		}

		try {
			$this->actions()->save( $this->form() );
			self::fail( 'Expected WpDie.' );
		} catch ( WpDie $e ) {
			self::assertSame( 403, $e->response );
		}

		self::assertFalse( Settings::delete_data_on_uninstall() );
	}

	public function test_handler_enables_with_nonce_and_confirmation(): void {
		$url = $this->actions()->save( $this->form() );

		self::assertStringContainsString( 'page=merchboard-settings&merchboard_notice=saved', $url );
		self::assertTrue( Settings::delete_data_on_uninstall() );
	}

	/**
	 * Nonces that must be rejected.
	 *
	 * @return array<string, array{string|null}>
	 */
	public static function bad_nonces(): array {
		return array(
			'missing' => array( null ),
			'forged'  => array( 'forged' ),
			'other'   => array( 'valid:merchboard_save_creator_0' ),
		);
	}

	#[DataProvider( 'bad_nonces' )]
	public function test_handler_rejects_missing_or_invalid_nonces( ?string $nonce ): void {
		$form = $this->form();
		unset( $form['_wpnonce'] );

		if ( null !== $nonce ) {
			$form['_wpnonce'] = $nonce;
		}

		try {
			$this->actions()->save( $form );
			self::fail( 'Expected WpDie.' );
		} catch ( WpDie $e ) {
			self::assertSame( 403, $e->response );
		}

		self::assertFalse( Settings::delete_data_on_uninstall() );
	}

	public function test_handler_without_confirmation_reports_it_and_changes_nothing(): void {
		$url = $this->actions()->save( $this->form( array( SettingsPage::FIELD_CONFIRM => '' ) ) );

		self::assertStringContainsString( 'merchboard_notice=confirmation_required', $url );
		self::assertFalse( Settings::delete_data_on_uninstall() );
	}

	public function test_handler_treats_only_the_checkbox_value_1_as_enable_and_ignores_extra_fields(): void {
		$url = $this->actions()->save(
			$this->form(
				array(
					SettingsPage::FIELD_DELETE => array( '1' ),
					'merchboard_settings'      => array( 'delete_data_on_uninstall' => true ),
				)
			)
		);

		self::assertStringContainsString( 'merchboard_notice=no_changes', $url );
		self::assertFalse( Settings::delete_data_on_uninstall() );
	}

	public function test_handler_logs_technical_failures_and_shows_a_neutral_notice(): void {
		$this->db->engines['merchboard_audit_log'] = 'MyISAM';

		$url = $this->actions()->save( $this->form() );

		self::assertStringContainsString( 'merchboard_notice=error', $url );
		self::assertSame( array( 'error' ), array_column( $this->logger->entries, 'level' ) );
		self::assertFalse( Settings::delete_data_on_uninstall() );
	}

	public function test_page_warns_clearly_and_escapes_output(): void {
		$html = $this->render_page();
		self::assertStringContainsString( 'Permanent deletion is off', $html );
		self::assertStringContainsString( 'name="confirm_deletion"', $html );
		self::assertStringContainsString( 'nonce:merchboard_save_settings', $html );
		self::assertStringContainsString( 'Never removed: WordPress users', $html );

		$this->options->options[ Settings::OPTION ] = array( Settings::DELETE_DATA_ON_UNINSTALL => true );
		$html                                       = $this->render_page();
		self::assertStringContainsString( 'Permanent deletion is ENABLED.', $html );
		self::assertStringContainsString( 'checked', $html );
		self::assertStringNotContainsString( 'name="confirm_deletion"', $html, 'Disabling needs no confirmation.' );
	}

	public function test_page_refuses_users_without_the_capability(): void {
		$this->caps = array( Capabilities::MANAGE_CREATORS );
		$sections   = 0;
		Functions\when( 'do_action' )->alias(
			static function () use ( &$sections ): void {
				++$sections;
			}
		);

		try {
			$this->render_page();
			self::fail( 'Expected WpDie.' );
		} catch ( WpDie $e ) {
			self::assertSame( 403, $e->response );
		}

		self::assertSame( 0, $sections, 'Sections are never rendered without the capability.' );
	}

	public function test_other_modules_add_sections_between_the_slug_and_data_on_uninstall(): void {
		Functions\when( 'do_action' )->alias(
			static function ( string $hook ): void {
				echo '[section:' . $hook . ']'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);

		$html    = $this->render_page();
		$section = strpos( $html, '[section:merchboard_settings_sections]' );

		self::assertNotFalse( $section );
		self::assertSame( 1, substr_count( $html, '[section:' ) );
		self::assertGreaterThan( (int) strpos( $html, 'Save dashboard URL' ), $section );
		self::assertLessThan( (int) strpos( $html, 'Data on uninstall' ), $section );
	}

	public function test_only_whitelisted_notices_are_shown(): void {
		self::assertNull( SettingsPage::notice_message( '<script>' ) );
		self::assertNotNull( SettingsPage::notice_message( 'saved' ) );
	}

	public function test_module_registers_services_and_admin_hooks(): void {
		$container = new Container();
		$container->set( AuditTrail::class, fn(): AuditTrail => new AuditTrail( new AuditRepository( $this->db ) ) );
		$container->set( Transaction::class, fn(): Transaction => new Transaction( $this->db, new TransactionSupport( $this->db ) ) );
		$container->set( Logger::class, fn(): Logger => $this->logger );

		( new SettingsModule() )->register( $container );

		self::assertSame( 'settings', ( new SettingsModule() )->id() );
		self::assertInstanceOf( SettingsActions::class, $container->get( SettingsActions::class ) );
		self::assertNotFalse( has_action( 'admin_menu' ) );
		self::assertNotFalse( has_action( 'admin_init' ) );
	}

	private function actions(): SettingsActions {
		return new SettingsActions( $this->service, $this->logger );
	}

	/**
	 * A valid "enable deletion" submission.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function form( array $overrides = array() ): array {
		return array_merge(
			array(
				'_wpnonce'                  => 'valid:' . SettingsActions::SAVE_ACTION,
				SettingsPage::FIELD_DELETE  => '1',
				SettingsPage::FIELD_CONFIRM => 'DELETE',
			),
			$overrides
		);
	}

	private function render_page(): string {
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action ): void {
				echo '<input type="hidden" name="_wpnonce" value="nonce:' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'checked' )->alias( static fn( mixed $a, mixed $b ): string => (string) $a === (string) $b ? " checked='checked'" : '' );
		Functions\when( 'submit_button' )->alias(
			static function ( string $text ): void {
				echo '<button>' . $text . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);

		ob_start();

		try {
			( new SettingsPage() )->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}
}
