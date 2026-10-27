<?php
/**
 * Tests for the plugin composition root.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit;

use ArrayObject;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\Installer;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Logging\PluginLogger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Access\AccessModule;
use MerchBoard\Modules\Admin\AdminModule;
use MerchBoard\Modules\News\NewsModule;
use MerchBoard\Modules\Notifications\NotificationsModule;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorsModule;
use MerchBoard\Modules\Dashboard\DashboardModule;
use MerchBoard\Modules\Settings\SettingsModule;
use MerchBoard\Modules\Alerts\AlertsModule;
use MerchBoard\Modules\WooCommerce\WooCommerceModule;
use MerchBoard\Modules\WooCommerce\WooCommerceSupport;
use MerchBoard\Plugin;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\InMemoryRoles;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use Mockery;
use ReflectionProperty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;
use wpdb;

#[CoversClass( Plugin::class )]
final class PluginTest extends TestCase {

	private const FILE = '/plugins/merchboard/merchboard.php';

	/**
	 * Extra dbDelta() calls beyond one per table: migration 21 changes the three
	 * chatbot tables and migration 22 two of them again, while migration 20
	 * skips them when they exist (as in this fake) — net +2.
	 */
	private const CHATBOT_REDELTAS = 2;

	private RecordingWpdb $wpdb;

	private InMemoryOptions $options;

	private InMemoryRoles $roles;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb               = new RecordingWpdb();
		$this->wpdb->tables_exist = true;
		// Schema is current unless a test says otherwise.
		$this->options = InMemoryOptions::install(
			array(
				Migrator::VERSION_OPTION    => (string) self::latest_schema_version(),
				RoleManager::VERSION_OPTION => (string) RoleManager::VERSION,
			)
		);
		$this->roles   = InMemoryRoles::install( array( 'administrator' => array( 'manage_options' => true ) ) );
	}

	public function test_activation_creates_roles_and_capabilities(): void {
		$this->plugin()->activate();

		self::assertSame(
			array(
				'read'                        => true,
				'merchboard_access_dashboard' => true,
			),
			$this->roles->capabilities( 'merchboard_creator' )
		);
		self::assertTrue( $this->roles->capabilities( 'administrator' )['manage_options'] );
		self::assertTrue( $this->roles->capabilities( 'administrator' )['merchboard_manage_creators'] );
	}

	public function test_textdomain_is_loaded_from_the_plugins_languages_folder(): void {
		Functions\expect( 'plugin_basename' )->once()->with( self::FILE )->andReturn( 'merchboard/merchboard.php' );
		Functions\expect( 'load_plugin_textdomain' )->once()->with( 'merchboard', false, 'merchboard/languages' );

		$this->plugin()->load_textdomain();
	}

	public function test_activation_and_deactivation_invalidate_the_rewrite_rules(): void {
		$this->options->options['rewrite_rules'] = array( 'old' => 'index.php' );
		$this->plugin()->activate();
		self::assertArrayNotHasKey( 'rewrite_rules', $this->options->options, 'Rebuilt with the dashboard route on the next request.' );

		$this->options->options['rewrite_rules']       = array( '^creator-dashboard(?:/(.*?))?/?$' => 'index.php' );
		$this->options->options['merchboard_settings'] = array( 'delete_data_on_uninstall' => true );
		$this->plugin()->deactivate();
		self::assertArrayNotHasKey( 'rewrite_rules', $this->options->options, 'Rebuilt without the dashboard route.' );
		self::assertSame( array( 'delete_data_on_uninstall' => true ), $this->options->options['merchboard_settings'], 'Deactivation deletes no data.' );
	}

	public function test_plugin_reload_with_current_roles_does_not_touch_roles(): void {
		$this->plugin()->boot();

		self::assertSame( 0, $this->roles->add_role_calls );
		self::assertSame( array( 'manage_options' => true ), $this->roles->capabilities( 'administrator' ) );
	}

	public function test_role_manager_service_is_registered(): void {
		$container = new Container();
		$this->plugin( $container );

		self::assertInstanceOf( RoleManager::class, $container->get( RoleManager::class ) );
	}

	public function test_register_hooks_adds_entry_hooks(): void {
		$plugin = $this->plugin();

		Functions\expect( 'register_activation_hook' )->once()->with( self::FILE, array( $plugin, 'activate' ) );
		Functions\expect( 'register_deactivation_hook' )->once()->with( self::FILE, array( $plugin, 'deactivate' ) );

		$plugin->register_hooks();

		self::assertSame( 10, has_action( 'plugins_loaded', array( $plugin, 'boot' ) ) );
		self::assertSame( 10, has_action( 'before_woocommerce_init', array( $plugin, 'declare_woocommerce_compatibility' ) ) );
		self::assertSame( 0, has_action( 'init', array( $plugin, 'load_textdomain' ) ), 'Translations load on init, not earlier.' );
	}

	public function test_register_hooks_registers_the_core_protected_meta_guard_without_resolving_the_logger(): void {
		$container = new Container();
		$plugin    = $this->plugin( $container );
		$resolved  = 0;
		$container->set(
			Logger::class,
			static function () use ( &$resolved ): Logger {
				++$resolved;
				return new RecordingLogger();
			}
		);
		Functions\when( 'register_activation_hook' )->justReturn( null );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );

		$plugin->register_hooks();

		$guard = $container->get( ProtectedProductMeta::class );
		self::assertSame( 1000, has_filter( 'update_post_metadata', array( $guard, 'filter_update' ) ), 'Hooked while merchboard.php loads, before plugins_loaded.' );
		self::assertSame( 1000, has_filter( 'delete_post_metadata', array( $guard, 'filter_delete' ) ) );
		self::assertSame( 1000, has_filter( 'update_post_metadata_by_mid', array( $guard, 'filter_update_by_mid' ) ) );
		self::assertSame( 0, $resolved, 'The shared logger (and its threshold filter) is not created this early.' );

		$guard->filter_update( null, 10, ProtectedProductMeta::LINK_KEY );
		self::assertSame( 1, $resolved, 'It is resolved when something is logged.' );
	}

	public function test_the_guard_stays_active_when_the_module_filter_returns_no_modules(): void {
		$container = new Container();
		$plugin    = $this->plugin( $container );
		Functions\when( 'register_activation_hook' )->justReturn( null );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( array() );

		$plugin->register_hooks();
		$plugin->boot();

		$guard = $container->get( ProtectedProductMeta::class );
		self::assertFalse( class_exists( 'WooCommerce', false ), 'No WooCommerce in this process.' );
		self::assertSame( 1000, has_filter( 'add_post_metadata', array( $guard, 'filter_add' ) ) );
		self::assertFalse( $guard->filter_update( null, 10, ProtectedProductMeta::LINK_KEY ), 'Generic writes are still denied.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_guard_is_active_with_a_too_old_woocommerce(): void {
		$merchboard_test_wc_version = '10.2.2';
		require_once dirname( __DIR__ ) . '/Fixtures/woocommerce-active.php';

		$container = new Container();
		$plugin    = $this->plugin( $container );
		Functions\when( 'register_activation_hook' )->justReturn( null );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		$this->capture_admin_notices();

		$plugin->register_hooks();
		$plugin->boot();

		$guard = $container->get( ProtectedProductMeta::class );
		self::assertFalse( $container->has( ProductCreatorLink::class ), 'The WooCommerce module is off.' );
		self::assertSame( 1000, has_filter( 'update_post_metadata', array( $guard, 'filter_update' ) ) );
		self::assertFalse( $guard->filter_delete( null, 10, ProtectedProductMeta::SOURCE_KEY ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_with_a_supported_woocommerce_the_module_reuses_the_one_core_guard(): void {
		$merchboard_test_wc_version = '10.3.8';
		require_once dirname( __DIR__ ) . '/Fixtures/woocommerce-active.php';

		$container = new Container();
		$plugin    = $this->plugin( $container );
		Functions\when( 'register_activation_hook' )->justReturn( null );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		$added = 0;
		Filters\expectAdded( 'update_post_metadata' )
			->zeroOrMoreTimes()
			->whenHappen(
				static function () use ( &$added ): void {
					++$added;
				}
			);

		$plugin->register_hooks();
		$plugin->boot();

		$guard = $container->get( ProtectedProductMeta::class );
		self::assertTrue( $container->has( ProductCreatorLink::class ), 'The WooCommerce module is on.' );
		self::assertSame( 1, $added, 'The guard is hooked once, by core.' );
		self::assertSame( $guard, ( new ReflectionProperty( ProductCreatorLink::class, 'guard' ) )->getValue( $container->get( ProductCreatorLink::class ) ) );
	}

	public function test_core_services_are_registered(): void {
		$container = new Container();
		$this->plugin( $container );

		self::assertTrue( $container->has( wpdb::class ) );
		self::assertInstanceOf( Installer::class, $container->get( Installer::class ) );
		self::assertInstanceOf( Migrator::class, $container->get( Migrator::class ) );
	}

	public function test_logger_service_works_without_woocommerce(): void {
		self::assertFalse( function_exists( 'wc_get_logger' ) );
		$container = new Container();
		$this->plugin( $container );

		$logger = $container->get( Logger::class );
		$logger->error( 'Test entry without WooCommerce.' );

		self::assertInstanceOf( PluginLogger::class, $logger );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_logger_service_uses_woocommerce_logger_when_available(): void {
		require_once dirname( __DIR__ ) . '/Fixtures/woocommerce-logger.php';
		do_action( 'woocommerce_init' );
		$container = new Container();
		$this->plugin( $container );

		$container->get( Logger::class )->error( 'Test entry with WooCommerce.', array( 'schema_version' => 1 ) );

		self::assertSame( 'error', \MerchBoardTestWcLogger::$entries[0]['level'] );
		self::assertSame( 'merchboard', \MerchBoardTestWcLogger::$entries[0]['context']['source'] );
	}

	public function test_activation_installs_the_schema(): void {
		unset( $this->options->options[ Migrator::VERSION_OPTION ] );
		// One dbDelta() per table, including the retired one (created by migration 6, dropped by 8),
		// plus the chatbot tables that migrations 21 and 22 change again (migration 20 skips existing tables).
		Functions\expect( 'dbDelta' )->times( count( Tables::all() ) + count( Tables::retired() ) + self::CHATBOT_REDELTAS );

		$this->plugin()->activate();

		self::assertSame( self::latest_schema_version(), $this->options->options[ Migrator::VERSION_OPTION ] );
	}

	public function test_boot_upgrades_an_outdated_schema_before_modules_register(): void {
		$this->options->options[ Migrator::VERSION_OPTION ] = '0';
		Functions\expect( 'dbDelta' )->times( count( Tables::all() ) + count( Tables::retired() ) + self::CHATBOT_REDELTAS );

		$this->plugin()->boot();

		self::assertSame( self::latest_schema_version(), $this->options->options[ Migrator::VERSION_OPTION ] );
	}

	public function test_upgrade_from_version_1_adds_only_the_missing_tables(): void {
		$this->options->options[ Migrator::VERSION_OPTION ] = '1';
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_audit_log \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_creatormeta \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_creator_categories \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_sales \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_sales_cycles \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_pricing_overrides \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_item_pricing \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_sales_line_pricing \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_sales_tax \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_payouts \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_payout_secrets \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_settlement_documents \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_settlement_document_rows \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_settlement_counters \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_order_gift_card_assessments \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_order_gift_card_payouts \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_order_gift_card_attempts \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_g1_state \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_operations \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_payout_recalculations \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_payout_recalculation_items \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_payout_adjustments \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_payout_adjustment_rows \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_payout_recalculation_exports \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_creator_vat_history \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_creator_codes \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_order_exclusions \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_events \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_overlays \(/' ) );
		// Migration 20 leaves existing chatbot tables alone (this fake reports every table as existing); migration 21 adds the platforms.
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_chatbot \(.*\n\s*platform /s' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_chat_messages \(.*\n\s*platform /s' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_chat_log \(.*\n\s*platform /s' ) );
		// Migration 22 widens the channel columns.
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_chatbot \(.*broadcaster_id varchar\(64\)/s' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_chat_log \(.*broadcaster_id varchar\(64\)/s' ) );

		// Migration 23 only adds indexes and 24 columns (no dbDelta()); 25 creates the news tables.
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_news \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_news_reads \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_api_keys \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_webhooks \(/' ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_alert_webhook_deliveries \(/' ) );
		// 28–31 change data and columns (no dbDelta()); 32 creates the category proposals table.
		Functions\expect( 'dbDelta' )->once()->ordered()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_category_proposals \(/' ) );

		$this->plugin()->boot();

		self::assertSame( 34, $this->options->options[ Migrator::VERSION_OPTION ] );
	}

	public function test_creators_module_registers_admin_hooks(): void {
		$this->plugin()->boot();

		self::assertNotFalse( has_action( 'admin_menu' ) );
		self::assertNotFalse( has_action( 'admin_init' ) );
	}

	public function test_boot_does_not_touch_a_current_schema(): void {
		Functions\expect( 'dbDelta' )->never();

		$this->plugin()->boot();

		self::assertSame( array(), $this->options->updates );
	}

	public function test_boot_registers_the_migration_failure_notice(): void {
		$container = new Container();
		$this->plugin( $container )->boot();

		self::assertSame( 10, has_action( 'admin_notices', array( $container->get( Installer::class ), 'render_failure_notice' ) ) );
	}

	public function test_default_modules_are_booted(): void {
		$container = new Container();

		Filters\expectApplied( 'merchboard_modules' )
			->once()
			->with( Mockery::on( static fn( $modules ): bool => is_array( $modules ) && 7 === count( $modules ) && $modules[0] instanceof CreatorsModule && $modules[1] instanceof AccessModule && $modules[2] instanceof DashboardModule && $modules[3] instanceof SettingsModule && $modules[4] instanceof AdminModule ) )
			->andReturnFirstArg();

		$this->plugin( $container )->boot();

		self::assertTrue( $container->has( CreatorRepository::class ) );
		self::assertTrue( $container->has( CreatorContext::class ) );
	}

	public function test_boot_registers_filtered_modules_with_the_container(): void {
		$container = new Container();
		$first     = $this->module( 'first' );
		$second    = $this->module( 'second' );

		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( array( $first, $second ) );

		$this->plugin( $container )->boot();

		self::assertSame( array( $container ), $first->registered_with );
		self::assertSame( array( $container ), $second->registered_with );
	}

	public function test_boot_fires_loaded_action_with_container(): void {
		$container = new Container();

		Actions\expectDone( 'merchboard_loaded' )->once()->with( $container );

		$this->plugin( $container )->boot();
	}

	public function test_boot_runs_only_once(): void {
		$module = $this->module( 'only' );

		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( array( $module ) );
		Actions\expectDone( 'merchboard_loaded' )->once();

		$plugin = $this->plugin();
		$plugin->boot();
		$plugin->boot();

		self::assertCount( 1, $module->registered_with );
	}

	public function test_boot_skips_items_that_are_not_modules(): void {
		$module = $this->module( 'valid' );

		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( array( new stdClass(), 'creators', $module ) );
		Functions\expect( '_doing_it_wrong' )->twice();

		$this->plugin()->boot();

		self::assertCount( 1, $module->registered_with );
	}

	public function test_boot_skips_duplicate_module_ids(): void {
		$first     = $this->module( 'same' );
		$duplicate = $this->module( 'same' );

		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( array( $first, $duplicate ) );
		Functions\expect( '_doing_it_wrong' )->once();
		Functions\stubEscapeFunctions();

		$this->plugin()->boot();

		self::assertCount( 1, $first->registered_with );
		self::assertCount( 0, $duplicate->registered_with );
	}

	public function test_boot_survives_a_filter_that_returns_a_non_array(): void {
		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( 'not-an-array' );
		Functions\expect( '_doing_it_wrong' )->once();
		Actions\expectDone( 'merchboard_loaded' )->once();

		$this->plugin()->boot();
	}

	public function test_woocommerce_declaration_is_skipped_without_woocommerce(): void {
		self::assertFalse( class_exists( FeaturesUtil::class, false ) );

		$this->plugin()->declare_woocommerce_compatibility();

		$this->addToAssertionCount( 1 );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_declares_hpos_compatibility_when_woocommerce_is_loaded(): void {
		require_once dirname( __DIR__ ) . '/Fixtures/FeaturesUtil.php';

		$this->plugin()->declare_woocommerce_compatibility();

		self::assertSame( array( array( 'custom_order_tables', self::FILE, true ) ), FeaturesUtil::$calls );
	}

	public function test_without_woocommerce_no_woocommerce_module_or_notice(): void {
		self::assertFalse( class_exists( 'WooCommerce', false ) );

		Filters\expectApplied( 'merchboard_modules' )
			->once()
			->with( Mockery::on( static fn( $modules ): bool => is_array( $modules ) && array() === array_filter( $modules, static fn( $module ): bool => $module instanceof WooCommerceModule ) ) )
			->andReturnFirstArg();
		$notices = $this->capture_admin_notices();

		$this->plugin()->boot();

		self::assertSame( array(), array_filter( $notices->getArrayCopy(), self::is_woocommerce_notice( ... ) ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_supported_woocommerce_boots_the_woocommerce_and_alerts_modules_without_notice(): void {
		$merchboard_test_wc_version = '10.3.8';
		require_once dirname( __DIR__ ) . '/Fixtures/woocommerce-active.php';

		Filters\expectApplied( 'merchboard_modules' )
			->once()
			->with( Mockery::on( static fn( $modules ): bool => is_array( $modules ) && 9 === count( $modules ) && $modules[5] instanceof NewsModule && $modules[6] instanceof NotificationsModule && $modules[7] instanceof WooCommerceModule && $modules[8] instanceof AlertsModule ) )
			->andReturnFirstArg();
		$notices = $this->capture_admin_notices();

		$this->plugin()->boot();

		self::assertSame( array(), array_filter( $notices->getArrayCopy(), self::is_woocommerce_notice( ... ) ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_too_old_woocommerce_keeps_the_module_off_and_registers_the_notice(): void {
		$merchboard_test_wc_version = '10.2.2';
		require_once dirname( __DIR__ ) . '/Fixtures/woocommerce-active.php';

		Filters\expectApplied( 'merchboard_modules' )
			->once()
			->with( Mockery::on( static fn( $modules ): bool => is_array( $modules ) && 7 === count( $modules ) && array() === array_filter( $modules, static fn( $module ): bool => $module instanceof WooCommerceModule || $module instanceof AlertsModule ) ) )
			->andReturnFirstArg();
		$notices = $this->capture_admin_notices();

		$this->plugin()->boot();

		self::assertCount( 1, array_filter( $notices->getArrayCopy(), self::is_woocommerce_notice( ... ) ) );
	}

	/**
	 * Records every callback added to admin_notices.
	 *
	 * @return ArrayObject<int, mixed>
	 */
	private function capture_admin_notices(): ArrayObject {
		$callbacks = new ArrayObject();

		Actions\expectAdded( 'admin_notices' )
			->zeroOrMoreTimes()
			->whenHappen(
				static function ( $callback ) use ( $callbacks ): void {
					$callbacks[] = $callback;
				}
			);

		return $callbacks;
	}

	/**
	 * Whether a hook callback is the WooCommerce version notice.
	 *
	 * @param mixed $callback Hook callback.
	 */
	private static function is_woocommerce_notice( $callback ): bool {
		return is_array( $callback ) && ( $callback[0] ?? null ) instanceof WooCommerceSupport && 'render_notice' === ( $callback[1] ?? null );
	}

	/**
	 * Schema version the code expects.
	 */
	private static function latest_schema_version(): int {
		return (int) max( array_merge( array( 0 ), array_keys( Schema::migrations() ) ) );
	}

	/**
	 * Creates the plugin with the recording database instead of global $wpdb.
	 *
	 * @param Container|null $container Container to use.
	 */
	private function plugin( ?Container $container = null ): Plugin {
		$container ??= new Container();
		$plugin      = new Plugin( self::FILE, $container );
		$wpdb        = $this->wpdb;

		$container->set( wpdb::class, static fn(): wpdb => $wpdb );

		return $plugin;
	}

	/**
	 * Creates a module double that records register() calls.
	 *
	 * @param string $id Module id.
	 */
	private function module( string $id ): RecordingModule {
		return new RecordingModule( $id );
	}
}
