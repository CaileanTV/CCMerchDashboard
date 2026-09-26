<?php
/**
 * Tests for the WooCommerce module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Actions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsSection;
use MerchBoard\Modules\WooCommerce\Admin\ProductListCreators;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductStore;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\WooCommerce\WooCommerceModule;
use MerchBoard\Modules\WooCommerce\WooCommerceProductStore;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use wpdb;

#[CoversClass( WooCommerceModule::class )]
final class WooCommerceModuleTest extends TestCase {

	public function test_module_id(): void {
		self::assertSame( 'woocommerce', ( new WooCommerceModule() )->id() );
	}

	public function test_registers_the_product_link_service_with_the_woocommerce_store(): void {
		$container = self::core_container();

		( new WooCommerceModule() )->register( $container );

		self::assertInstanceOf( WooCommerceProductStore::class, $container->get( ProductStore::class ) );
		self::assertInstanceOf( ProductCreatorLink::class, $container->get( ProductCreatorLink::class ) );
		// The module never widens the creator role (ADR-030).
		self::assertSame( array( 'read', Capabilities::ACCESS_DASHBOARD ), Capabilities::creator_capabilities() );
	}

	public function test_product_links_are_wired_to_the_creator_edit_page_only(): void {
		$container = self::core_container();
		self::run_admin_init();
		( new WooCommerceModule() )->register( $container );

		self::assertSame( 10, has_action( 'admin_post_' . CreatorProductsActions::LINK_ACTION, array( $container->get( CreatorProductsActions::class ), 'handle_link_request' ) ) );
		self::assertSame( 10, has_action( 'admin_post_' . CreatorProductsActions::UNLINK_ACTION, array( $container->get( CreatorProductsActions::class ), 'handle_unlink_request' ) ) );
		self::assertSame( 10, has_action( 'merchboard_creators_edit_sections', array( $container->get( CreatorProductsSection::class ), 'render' ) ) );
	}

	public function test_the_module_reuses_the_core_guard_and_never_registers_it(): void {
		$container = self::core_container();
		$guard     = $container->get( ProtectedProductMeta::class );

		( new WooCommerceModule() )->register( $container );
		$link = $container->get( ProductCreatorLink::class );

		self::assertSame( $guard, $container->get( ProtectedProductMeta::class ), 'The core instance is not replaced.' );
		self::assertSame( $guard, ( new ReflectionProperty( ProductCreatorLink::class, 'guard' ) )->getValue( $link ), 'ProductCreatorLink writes through the core instance.' );
		self::assertFalse( has_filter( 'update_post_metadata' ), 'Registering the guard is core\'s job (ADR-035).' );
		self::assertFalse( has_filter( 'delete_post_metadata_by_mid' ) );
		self::assertFalse( has_action( 'before_delete_post' ) );
	}

	public function test_the_woocommerce_entry_point_checks_are_hooked_for_every_request(): void {
		( new WooCommerceModule() )->register( self::core_container() );

		self::assertNotFalse( has_filter( 'woocommerce_product_import_process_item_data' ), 'Not waiting for admin_init: REST, cron and WP-CLI are covered too.' );
		self::assertNotFalse( has_filter( 'woocommerce_rest_pre_insert_product_object' ) );
		self::assertNotFalse( has_filter( 'woocommerce_duplicate_product_exclude_meta' ) );
	}

	public function test_product_creator_link_uses_the_core_owned_keys(): void {
		self::assertSame( ProtectedProductMeta::LINK_KEY, ProductCreatorLink::META_KEY );
		self::assertSame( ProtectedProductMeta::SOURCE_KEY, ProductCreatorLink::SOURCE_META_KEY );
	}

	public function test_the_product_list_column_and_filter_wait_for_the_product_list_screen(): void {
		$container = self::core_container();
		self::run_admin_init();
		( new WooCommerceModule() )->register( $container );

		self::assertSame( 10, has_action( 'current_screen', array( $container->get( ProductListCreators::class ), 'on_screen' ) ) );
		self::assertFalse( has_filter( 'manage_product_posts_columns' ), 'Nothing is added before the product list screen is known.' );
		self::assertFalse( has_filter( 'request' ) );
	}

	/**
	 * Hooks WooCommerce offers for product editor fields, meta boxes and saves.
	 *
	 * @return array<string, array{string}>
	 */
	public static function product_editor_hooks(): array {
		$hooks = array(
			'woocommerce_product_options_general_product_data',
			'woocommerce_product_options_advanced',
			'woocommerce_product_data_tabs',
			'woocommerce_product_data_panels',
			'woocommerce_process_product_meta',
			'woocommerce_admin_process_product_object',
			'woocommerce_product_after_variable_attributes',
			'woocommerce_save_product_variation',
			'add_meta_boxes',
			'add_meta_boxes_product',
			'save_post',
			'save_post_product',
		);

		return array_combine( $hooks, array_map( static fn( string $h ): array => array( $h ), $hooks ) );
	}

	#[DataProvider( 'product_editor_hooks' )]
	public function test_nothing_is_hooked_into_the_woocommerce_product_editor( string $hook ): void {
		self::run_admin_init();
		( new WooCommerceModule() )->register( self::core_container() );

		self::assertFalse( has_action( $hook ), 'ADR-032: no creator UI or save hook on the WooCommerce product editor.' );
	}

	/**
	 * Runs every admin_init callback the module adds (Brain Monkey does not
	 * run hooks on do_action()).
	 */
	private static function run_admin_init(): void {
		Actions\expectAdded( 'admin_init' )
			->zeroOrMoreTimes()
			->whenHappen(
				static function ( callable $callback ): void {
					$callback();
				}
			);
	}

	/**
	 * A container with the core and Creators services the module uses.
	 */
	private static function core_container(): Container {
		$db        = new FakeDatabase();
		$container = new Container();
		$container->set( wpdb::class, static fn(): wpdb => $db );
		$container->set( Logger::class, static fn(): Logger => new RecordingLogger() );
		$container->set( CreatorRepository::class, static fn(): CreatorRepository => new CreatorRepository( $db ) );
		$container->set( AuditTrail::class, static fn(): AuditTrail => new AuditTrail( new AuditRepository( $db ) ) );
		$container->set( Transaction::class, static fn(): Transaction => new Transaction( $db, new TransactionSupport( $db ) ) );
		// Core service (ADR-035); Plugin registers it, not the module.
		$container->set( ProtectedProductMeta::class, static fn(): ProtectedProductMeta => new ProtectedProductMeta( new RecordingLogger() ) );

		return $container;
	}
}
