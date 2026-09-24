<?php
/**
 * Plugin composition root.
 *
 * @package CCMerch
 */

declare( strict_types=1 );

namespace CCMerch;

use CCMerch\Core\Container;
use CCMerch\Core\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Wires WordPress hooks to the plugin and boots the internal modules.
 *
 * Created once by the main plugin file. There is intentionally no static
 * instance accessor; code that needs services receives the container via
 * Module::register() or the `ccmerch_loaded` action.
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
	}

	/**
	 * Registers the plugin's entry hooks. Called while the main file is loaded.
	 */
	public function register_hooks(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
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

		/**
		 * Filters the modules to boot.
		 *
		 * @param Module[] $modules Module instances.
		 */
		$modules = apply_filters( 'ccmerch_modules', $this->default_modules() );

		foreach ( $this->valid_modules( $modules ) as $module ) {
			$module->register( $this->container );
		}

		/**
		 * Fires after all modules have registered.
		 *
		 * @param Container $container Shared service container.
		 */
		do_action( 'ccmerch_loaded', $this->container );
	}

	/**
	 * Built-in modules. Empty until the first module lands (roadmap phase 3+).
	 *
	 * @return Module[]
	 */
	private function default_modules(): array {
		return array();
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
			_doing_it_wrong( __METHOD__, 'The ccmerch_modules filter must return an array.', '0.1.0' );
			return array();
		}

		$valid = array();

		foreach ( $modules as $module ) {
			if ( ! $module instanceof Module ) {
				_doing_it_wrong( __METHOD__, 'Items returned by the ccmerch_modules filter must implement CCMerch\Core\Module.', '0.1.0' );
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
