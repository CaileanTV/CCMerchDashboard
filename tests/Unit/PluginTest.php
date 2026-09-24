<?php
/**
 * Tests for the plugin composition root.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Core\Lifecycle\Installer;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Logging\PluginLogger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorsModule;
use MerchBoard\Plugin;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\InMemoryRoles;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;
use wpdb;

#[CoversClass( Plugin::class )]
final class PluginTest extends TestCase {

	private const FILE = '/plugins/merchboard/merchboard.php';

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

		$plugin->register_hooks();

		self::assertSame( 10, has_action( 'plugins_loaded', array( $plugin, 'boot' ) ) );
		self::assertSame( 10, has_action( 'before_woocommerce_init', array( $plugin, 'declare_woocommerce_compatibility' ) ) );
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
		Functions\expect( 'dbDelta' )->times( self::latest_schema_version() );

		$this->plugin()->activate();

		self::assertSame( self::latest_schema_version(), $this->options->options[ Migrator::VERSION_OPTION ] );
	}

	public function test_boot_upgrades_an_outdated_schema_before_modules_register(): void {
		$this->options->options[ Migrator::VERSION_OPTION ] = '0';
		Functions\expect( 'dbDelta' )->times( self::latest_schema_version() );

		$this->plugin()->boot();

		self::assertSame( self::latest_schema_version(), $this->options->options[ Migrator::VERSION_OPTION ] );
	}

	public function test_upgrade_from_version_1_adds_only_the_audit_table(): void {
		$this->options->options[ Migrator::VERSION_OPTION ] = '1';
		Functions\expect( 'dbDelta' )->once()->with( \Mockery::pattern( '/^CREATE TABLE wp_merchboard_audit_log \(/' ) );

		$this->plugin()->boot();

		self::assertSame( 2, $this->options->options[ Migrator::VERSION_OPTION ] );
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

	public function test_creators_module_is_booted_by_default(): void {
		$container = new Container();

		Filters\expectApplied( 'merchboard_modules' )
			->once()
			->with( Mockery::on( static fn( $modules ): bool => is_array( $modules ) && 1 === count( $modules ) && $modules[0] instanceof CreatorsModule ) )
			->andReturnFirstArg();

		$this->plugin( $container )->boot();

		self::assertTrue( $container->has( CreatorRepository::class ) );
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
