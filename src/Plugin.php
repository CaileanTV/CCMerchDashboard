<?php
/**
 * Plugin composition root.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard;

use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Lifecycle\Deactivator;
use MerchBoard\Core\Lifecycle\Installer;
use MerchBoard\Core\Logging\LazyLogger;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Logging\PluginLogger;
use MerchBoard\Core\Module;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Modules\Access\AccessModule;
use MerchBoard\Modules\Admin\AdminModule;
use MerchBoard\Modules\Creators\CreatorsModule;
use MerchBoard\Modules\Dashboard\DashboardModule;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Settings\SettingsModule;
use MerchBoard\Modules\WooCommerce\WooCommerceModule;
use MerchBoard\Modules\WooCommerce\WooCommerceSupport;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Wires WordPress hooks to the plugin and boots the internal modules.
 *
 * Created once by the main plugin file. There is intentionally no static
 * instance accessor; code that needs services receives the container via
 * Module::register() or the `merchboard_loaded` action.
 */
final class Plugin {

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Shared service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor.
	 *
	 * @param string         $file      Absolute path to the main plugin file.
	 * @param Container|null $container Service container (injectable for tests).
	 */
	public function __construct( string $file, ?Container $container = null ) {
		$this->file      = $file;
		$this->container = $container ?? new Container();

		$this->register_core_services();
	}

	/**
	 * Registers the plugin's entry hooks. Called while the main file is loaded,
	 * which is also when the activation and deactivation hooks must be registered.
	 */
	public function register_hooks(): void {
		// Protected product meta (ADR-034/035): hooked now, while merchboard.php
		// loads, so it is active without WooCommerce and cannot be filtered
		// away with `merchboard_modules`, which only runs in boot().
		$this->container->get( ProtectedProductMeta::class )->register();

		register_activation_hook( $this->file, array( $this, 'activate' ) );
		register_deactivation_hook( $this->file, array( $this, 'deactivate' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
		// Translations must be loaded on `init` or later (WordPress 6.7+).
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
	}

	/**
	 * Loads the plugin's own translations from `languages/` (English source
	 * strings, Danish translation). Script translations are registered with
	 * the dashboard bundle (DashboardAssets).
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'merchboard', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}

	/**
	 * Activation: installs or upgrades the database schema and synchronises
	 * roles/capabilities (idempotent).
	 *
	 * On multisite, network activation only installs the current site; other
	 * sites install lazily on their first request via boot(). Multisite is not
	 * officially supported (docs/ARCHITECTURE.md).
	 */
	public function activate(): void {
		$this->container->get( Installer::class )->install();

		// WordPress rebuilds the rules, with the dashboard route, on the next request.
		DashboardRoute::invalidate_rewrite_rules();
	}

	/**
	 * Deactivation: removes the dashboard route; never deletes data.
	 */
	public function deactivate(): void {
		( new Deactivator() )->deactivate();
	}

	/**
	 * Declares compatibility with WooCommerce High-Performance Order Storage.
	 *
	 * The plugin does not access order storage yet, and later phases will only use
	 * WooCommerce CRUD APIs (see docs/WOOCOMMERCE.md). Without this declaration
	 * WooCommerce flags the plugin as incompatible when HPOS is enabled.
	 */
	public function declare_woocommerce_compatibility(): void {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';

		if ( class_exists( $features_util ) ) {
			call_user_func( array( $features_util, 'declare_compatibility' ), 'custom_order_tables', $this->file, true );
		}
	}

	/**
	 * Boots the modules. Runs on `plugins_loaded` so all plugins (e.g. WooCommerce)
	 * are loaded before modules decide what to register.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		// Plugin updates do not run the activation hook, so detect schema and role
		// version changes on normal requests (two autoloaded option reads).
		$installer = $this->container->get( Installer::class );
		$installer->maybe_upgrade();
		add_action( 'admin_notices', array( $installer, 'render_failure_notice' ) );

		/**
		 * Filters the modules to boot.
		 *
		 * @param Module[] $modules Module instances.
		 */
		$modules = apply_filters( 'merchboard_modules', $this->default_modules() );

		foreach ( $this->valid_modules( $modules ) as $module ) {
			$module->register( $this->container );
		}

		/**
		 * Fires after all modules have registered.
		 *
		 * @param Container $container Shared service container.
		 */
		do_action( 'merchboard_loaded', $this->container );
	}

	/**
	 * Core services every module may use.
	 */
	private function register_core_services(): void {
		$this->container->set(
			wpdb::class,
			static function (): wpdb {
				global $wpdb;
				return $wpdb;
			}
		);

		$this->container->set(
			Migrator::class,
			static fn( Container $c ): Migrator => new Migrator( $c->get( wpdb::class ), Schema::migrations() )
		);

		$this->container->set(
			Logger::class,
			static fn(): Logger => PluginLogger::for_wordpress()
		);

		// The one instance of the protected meta guard; the logger is resolved
		// on first use (LazyLogger), not while merchboard.php loads. The plugin
		// file gives the guard this plugin's basename for ADR-036's uninstall
		// window.
		$file = $this->file;
		$this->container->set(
			ProtectedProductMeta::class,
			static fn( Container $c ): ProtectedProductMeta => new ProtectedProductMeta(
				new LazyLogger( static fn(): Logger => $c->get( Logger::class ) ),
				$file
			)
		);

		$this->container->set(
			TransactionSupport::class,
			static fn( Container $c ): TransactionSupport => new TransactionSupport( $c->get( wpdb::class ) )
		);

		$this->container->set(
			Transaction::class,
			static fn( Container $c ): Transaction => new Transaction( $c->get( wpdb::class ), $c->get( TransactionSupport::class ) )
		);

		$this->container->set(
			AuditRepository::class,
			static fn( Container $c ): AuditRepository => new AuditRepository( $c->get( wpdb::class ) )
		);

		$this->container->set(
			AuditTrail::class,
			static fn( Container $c ): AuditTrail => new AuditTrail( $c->get( AuditRepository::class ) )
		);

		$this->container->set(
			RoleManager::class,
			static fn( Container $c ): RoleManager => new RoleManager( $c->get( Logger::class ) )
		);

		$this->container->set(
			Installer::class,
			static fn( Container $c ): Installer => new Installer(
				$c->get( Migrator::class ),
				$c->get( RoleManager::class ),
				$c->get( Logger::class )
			)
		);
	}

	/**
	 * Built-in modules. The WooCommerce module is only included with a
	 * supported WooCommerce; an older one gets a notice instead (ADR-030).
	 *
	 * @return Module[]
	 */
	private function default_modules(): array {
		$modules = array(
			new CreatorsModule(),
			new AccessModule(),
			new DashboardModule(),
			new SettingsModule(),
			new AdminModule(),
		);

		$woocommerce = WooCommerceSupport::from_environment();

		if ( $woocommerce->is_supported() ) {
			$modules[] = new WooCommerceModule();
		}

		$woocommerce->register_notice();

		return $modules;
	}

	/**
	 * Drops filter results that are not modules or reuse an id, so a faulty
	 * third-party filter cannot break the boot sequence silently.
	 *
	 * @param mixed $modules Filtered module list.
	 * @return array<string, Module> Modules keyed by id.
	 */
	private function valid_modules( $modules ): array {
		if ( ! is_array( $modules ) ) {
			_doing_it_wrong( __METHOD__, 'The merchboard_modules filter must return an array.', '0.1.0' );
			return array();
		}

		$valid = array();

		foreach ( $modules as $module ) {
			if ( ! $module instanceof Module ) {
				_doing_it_wrong( __METHOD__, 'Items returned by the merchboard_modules filter must implement MerchBoard\Core\Module.', '0.1.0' );
				continue;
			}

			$id = $module->id();

			if ( isset( $valid[ $id ] ) ) {
				_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Duplicate module id "%s" ignored.', $id ) ), '0.1.0' );
				continue;
			}

			$valid[ $id ] = $module;
		}

		return $valid;
	}
}
