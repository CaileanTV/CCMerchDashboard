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
use MerchBoard\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;

#[CoversClass( Plugin::class )]
final class PluginTest extends TestCase {

	private const FILE = '/plugins/merchboard/merchboard.php';

	public function test_register_hooks_adds_entry_hooks(): void {
		$plugin = new Plugin( self::FILE );
		$plugin->register_hooks();

		self::assertSame( 10, has_action( 'plugins_loaded', array( $plugin, 'boot' ) ) );
		self::assertSame( 10, has_action( 'before_woocommerce_init', array( $plugin, 'declare_woocommerce_compatibility' ) ) );
	}

	public function test_boot_registers_filtered_modules_with_the_container(): void {
		$container = new Container();
		$first     = $this->module( 'first' );
		$second    = $this->module( 'second' );

		Filters\expectApplied( 'merchboard_modules' )->once()->with( array() )->andReturn( array( $first, $second ) );

		( new Plugin( self::FILE, $container ) )->boot();

		self::assertSame( array( $container ), $first->registered_with );
		self::assertSame( array( $container ), $second->registered_with );
	}

	public function test_boot_fires_loaded_action_with_container(): void {
		$container = new Container();

		Actions\expectDone( 'merchboard_loaded' )->once()->with( $container );

		( new Plugin( self::FILE, $container ) )->boot();
	}

	public function test_boot_runs_only_once(): void {
		$module = $this->module( 'only' );

		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( array( $module ) );
		Actions\expectDone( 'merchboard_loaded' )->once();

		$plugin = new Plugin( self::FILE );
		$plugin->boot();
		$plugin->boot();

		self::assertCount( 1, $module->registered_with );
	}

	public function test_boot_skips_items_that_are_not_modules(): void {
		$module = $this->module( 'valid' );

		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( array( new stdClass(), 'creators', $module ) );
		Functions\expect( '_doing_it_wrong' )->twice();

		( new Plugin( self::FILE ) )->boot();

		self::assertCount( 1, $module->registered_with );
	}

	public function test_boot_skips_duplicate_module_ids(): void {
		$first     = $this->module( 'same' );
		$duplicate = $this->module( 'same' );

		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( array( $first, $duplicate ) );
		Functions\expect( '_doing_it_wrong' )->once();
		Functions\stubEscapeFunctions();

		( new Plugin( self::FILE ) )->boot();

		self::assertCount( 1, $first->registered_with );
		self::assertCount( 0, $duplicate->registered_with );
	}

	public function test_boot_survives_a_filter_that_returns_a_non_array(): void {
		Filters\expectApplied( 'merchboard_modules' )->once()->andReturn( 'not-an-array' );
		Functions\expect( '_doing_it_wrong' )->once();
		Actions\expectDone( 'merchboard_loaded' )->once();

		( new Plugin( self::FILE ) )->boot();
	}

	public function test_woocommerce_declaration_is_skipped_without_woocommerce(): void {
		self::assertFalse( class_exists( FeaturesUtil::class, false ) );

		( new Plugin( self::FILE ) )->declare_woocommerce_compatibility();

		$this->addToAssertionCount( 1 );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_declares_hpos_compatibility_when_woocommerce_is_loaded(): void {
		require_once dirname( __DIR__ ) . '/Fixtures/FeaturesUtil.php';

		( new Plugin( self::FILE ) )->declare_woocommerce_compatibility();

		self::assertSame( array( array( 'custom_order_tables', self::FILE, true ) ), FeaturesUtil::$calls );
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
