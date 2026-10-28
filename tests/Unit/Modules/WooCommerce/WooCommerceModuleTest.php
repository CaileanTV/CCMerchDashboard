<?php
/**
 * Tests for the WooCommerce module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Closure;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Admin\CategorySyncActions;
use MerchBoard\Modules\WooCommerce\Admin\CategorySyncScreen;
use MerchBoard\Modules\WooCommerce\Admin\CreatorCategoriesActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorCategoriesSection;
use MerchBoard\Modules\WooCommerce\Admin\CreatorListCategories;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsSection;
use MerchBoard\Modules\WooCommerce\Admin\CreatorSalesSection;
use MerchBoard\Modules\WooCommerce\Admin\OrderHistoryActions;
use MerchBoard\Modules\WooCommerce\Admin\OrderHistoryPage;
use MerchBoard\Modules\WooCommerce\Admin\ProductListCreators;
use MerchBoard\Modules\WooCommerce\Admin\SalesLedgerSettingsActions;
use MerchBoard\Modules\WooCommerce\Admin\SalesLedgerSettingsSection;
use MerchBoard\Modules\WooCommerce\ActionSchedulerQueue;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationHooks;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationJobs;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationScheduler;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\Dashboard\OrderLineEnricher;
use MerchBoard\Modules\WooCommerce\Dashboard\ProductDetails;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesProductNames;
use MerchBoard\Modules\WooCommerce\Dashboard\CachedSalesReports;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReport;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReports;
use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceOrderLineEnricher;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceProductDetails;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceSalesProductNames;
use MerchBoard\Modules\WooCommerce\Rest\MeOrdersController;
use MerchBoard\Modules\WooCommerce\Rest\MeProductsController;
use MerchBoard\Modules\WooCommerce\Rest\MeSalesController;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLines;
use MerchBoard\Modules\WooCommerce\JobQueue;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductStore;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Cli\SalesBackfillCommand;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillJobs;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillScheduler;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingHooks;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerHooks;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\WooCommerce\WooCommerceModule;
use MerchBoard\Modules\WooCommerce\WooCommerceProductStore;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery;
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

	public function test_the_creator_dashboard_routes_are_registered_on_rest_api_init(): void {
		$container = self::core_container();
		$container->set( CreatorContext::class, static fn( Container $c ): CreatorContext => new CreatorContext( $c->get( CreatorRepository::class ), $c->get( Logger::class ) ) );
		Actions\expectAdded( 'rest_api_init' )->once();

		( new WooCommerceModule() )->register( $container );

		self::assertInstanceOf( WooCommerceProductDetails::class, $container->get( ProductDetails::class ) );
		self::assertInstanceOf( CreatorProducts::class, $container->get( CreatorProducts::class ) );
		self::assertInstanceOf( MeProductsController::class, $container->get( MeProductsController::class ) );
		self::assertInstanceOf( SalesOrderLines::class, $container->get( SalesOrderLines::class ) );
		self::assertInstanceOf( WooCommerceOrderLineEnricher::class, $container->get( OrderLineEnricher::class ) );
		self::assertInstanceOf( MeOrdersController::class, $container->get( MeOrdersController::class ) );
		self::assertInstanceOf( LedgerSalesStatistics::class, $container->get( SalesStatistics::class ) );
		self::assertInstanceOf( WooCommerceSalesProductNames::class, $container->get( SalesProductNames::class ) );
		self::assertInstanceOf( SalesReport::class, $container->get( SalesReport::class ) );
		self::assertInstanceOf( MeSalesController::class, $container->get( MeSalesController::class ) );
		self::assertInstanceOf( CachedSalesReports::class, $container->get( SalesReports::class ), '/me/sales goes through the object cache.' );
		self::assertInstanceOf( SalesCache::class, $container->get( SalesCache::class ) );
	}

	public function test_product_links_are_wired_to_the_creator_edit_page_only(): void {
		$container = self::core_container();
		self::run_admin_init();
		( new WooCommerceModule() )->register( $container );

		self::assertSame( 10, has_action( 'admin_post_' . CreatorProductsActions::LINK_ACTION, array( $container->get( CreatorProductsActions::class ), 'handle_link_request' ) ) );
		self::assertSame( 10, has_action( 'admin_post_' . CreatorProductsActions::UNLINK_ACTION, array( $container->get( CreatorProductsActions::class ), 'handle_unlink_request' ) ) );
		self::assertSame( 10, has_action( 'merchboard_creators_edit_sections', array( $container->get( CreatorProductsSection::class ), 'render' ) ) );
		self::assertSame( 10, has_action( 'admin_post_' . CreatorCategoriesActions::MAP_ACTION, array( $container->get( CreatorCategoriesActions::class ), 'handle_map_request' ) ) );
		self::assertSame( 10, has_action( 'admin_post_' . CreatorCategoriesActions::UNMAP_ACTION, array( $container->get( CreatorCategoriesActions::class ), 'handle_unmap_request' ) ) );
		self::assertSame( 5, has_action( 'merchboard_creators_edit_sections', array( $container->get( CreatorCategoriesSection::class ), 'render' ) ), 'The category above the products.' );
		self::assertSame( 20, has_action( 'merchboard_creators_edit_sections', array( $container->get( CreatorSalesSection::class ), 'render' ) ), 'Sales below the products.' );
		self::assertSame( 10, has_filter( 'merchboard_creators_list_columns', array( $container->get( CreatorListCategories::class ), 'columns' ) ) );
		self::assertSame( 10, has_action( 'merchboard_creators_list_items', array( $container->get( CreatorListCategories::class ), 'load' ) ) );
		self::assertSame( 10, has_filter( 'merchboard_creators_list_column', array( $container->get( CreatorListCategories::class ), 'render' ) ) );
		self::assertSame( 10, has_action( 'admin_post_' . CategorySyncActions::ACTION, array( $container->get( CategorySyncActions::class ), 'handle_request' ) ) );
		self::assertSame( 10, has_action( 'merchboard_creators_view_' . CategorySyncActions::VIEW, array( $container->get( CategorySyncScreen::class ), 'render' ) ) );
		self::assertSame( 10, has_action( 'merchboard_creators_list_tools', array( $container->get( CategorySyncScreen::class ), 'render_link' ) ) );
		self::assertFalse( has_action( 'admin_post_nopriv_' . CategorySyncActions::ACTION ) );
		self::assertSame( 10, has_action( 'admin_post_' . OrderHistoryActions::ACTION, array( $container->get( OrderHistoryActions::class ), 'handle_request' ) ) );
		self::assertFalse( has_action( 'admin_post_nopriv_' . OrderHistoryActions::ACTION ), 'Deleting order history needs a logged-in user.' );
		self::assertFalse( has_action( 'wp_ajax_' . OrderHistoryActions::ACTION ), 'Only admin-post; no AJAX or REST route.' );
		self::assertFalse( has_action( 'wp_ajax_' . CreatorCategoriesActions::MAP_ACTION ), 'Only admin-post; no AJAX or REST route.' );
		self::assertFalse( has_action( 'admin_post_nopriv_' . CreatorCategoriesActions::MAP_ACTION ) );
	}

	public function test_the_order_history_submenu_is_hooked_on_admin_menu_without_waiting_for_admin_init(): void {
		$container = self::core_container();
		$menu      = null;
		// WordPress fires admin_menu before admin_init, so the hook must not be added from admin_init.
		Actions\expectAdded( 'admin_init' )->zeroOrMoreTimes();
		Actions\expectAdded( 'admin_menu' )
			->once()
			->with( Mockery::type( Closure::class ), OrderHistoryPage::PRIORITY )
			->whenHappen(
				static function ( callable $callback ) use ( &$menu ): void {
					$menu = $callback;
				}
			);

		( new WooCommerceModule() )->register( $container );

		self::assertIsCallable( $menu );
		Functions\stubTranslationFunctions();
		Functions\expect( 'add_submenu_page' )
			->once()
			->with( 'merchboard', Mockery::type( 'string' ), Mockery::type( 'string' ), Capabilities::MANAGE_CREATORS, OrderHistoryPage::SLUG, Mockery::type( 'array' ) );
		$menu();
	}

	public function test_the_sales_ledger_settings_section_is_wired_to_the_settings_page_only(): void {
		$container = self::core_container();
		( new WooCommerceModule() )->register( $container );

		self::assertFalse( has_action( 'merchboard_settings_sections' ), 'Only after admin_init.' );

		self::run_admin_init();
		$container = self::core_container();
		( new WooCommerceModule() )->register( $container );

		self::assertSame( 10, has_action( 'admin_post_' . SalesLedgerSettingsActions::SAVE_ACTION, array( $container->get( SalesLedgerSettingsActions::class ), 'handle_save_request' ) ) );
		self::assertSame( 10, has_action( 'merchboard_settings_sections', array( $container->get( SalesLedgerSettingsSection::class ), 'render' ) ) );
		self::assertFalse( has_action( 'admin_post_nopriv_' . SalesLedgerSettingsActions::SAVE_ACTION ) );
		self::assertFalse( has_action( 'wp_ajax_' . SalesLedgerSettingsActions::SAVE_ACTION ), 'Only admin-post; no AJAX or REST route.' );
		self::assertInstanceOf( CountedOrderStatuses::class, $container->get( CountedOrderStatuses::class ), 'One reader of the setting for sync and settings.' );
	}

	public function test_the_backfill_job_is_hooked_for_every_request_and_the_command_only_in_wp_cli(): void {
		$container = self::core_container();

		( new WooCommerceModule() )->register( $container );
		$jobs = $container->get( SalesBackfillJobs::class );

		self::assertSame( 10, has_action( SalesBackfillScheduler::HOOK, array( $jobs, 'run' ) ), 'Not waiting for admin_init: Action Scheduler runs from cron, async requests and WP-CLI.' );
		self::assertInstanceOf( SalesBackfillCommand::class, $container->get( SalesBackfillCommand::class ) );
		self::assertFalse( defined( 'WP_CLI' ), 'The unit suite has no WP-CLI, so no command is registered.' );
	}

	public function test_the_sales_ledger_hooks_are_registered_once_for_every_request(): void {
		$container = self::core_container();

		( new WooCommerceModule() )->register( $container );
		$hooks = $container->get( SalesLedgerHooks::class );

		self::assertSame( $hooks, $container->get( SalesLedgerHooks::class ), 'One instance.' );
		self::assertSame( 10, has_action( 'woocommerce_after_order_object_save', array( $hooks, 'order_saved' ) ) );
		self::assertSame( SalesLedgerHooks::DELETE_POST_PRIORITY, has_action( 'before_delete_post', array( $hooks, 'post_deleting' ) ) );
		self::assertSame( 10, has_action( 'woocommerce_refund_deleted', array( $hooks, 'refund_deleted' ) ) );
	}

	public function test_the_pricing_snapshot_hook_is_registered_once_and_pricing_has_no_other_hooks(): void {
		$container = self::core_container();

		( new WooCommerceModule() )->register( $container );
		$hooks = $container->get( SalesLinePricingHooks::class );

		self::assertSame( $hooks, $container->get( SalesLinePricingHooks::class ), 'One instance.' );
		self::assertSame( 10, has_action( 'woocommerce_new_order_item', array( $hooks, 'item_created' ) ) );
		self::assertFalse( has_filter( 'woocommerce_product_get_price' ), 'No price filters.' );
		self::assertFalse( has_filter( 'woocommerce_product_get_regular_price' ) );
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
		self::assertFalse( has_action( 'before_delete_post', array( $guard, 'deleting_post' ) ), 'Only the sales ledger listens to before_delete_post here.' );
	}

	public function test_the_woocommerce_entry_point_checks_are_hooked_for_every_request(): void {
		( new WooCommerceModule() )->register( self::core_container() );

		self::assertNotFalse( has_filter( 'woocommerce_product_import_process_item_data' ), 'Not waiting for admin_init: REST, cron and WP-CLI are covered too.' );
		self::assertNotFalse( has_filter( 'woocommerce_rest_pre_insert_product_object' ) );
		self::assertNotFalse( has_filter( 'woocommerce_duplicate_product_exclude_meta' ) );
	}

	public function test_the_automatic_reconciliation_is_hooked_for_every_request_with_action_scheduler(): void {
		$container = self::core_container();
		( new WooCommerceModule() )->register( $container );
		$hooks = $container->get( CategoryReconciliationHooks::class );
		$jobs  = $container->get( CategoryReconciliationJobs::class );

		self::assertInstanceOf( ActionSchedulerQueue::class, $container->get( JobQueue::class ) );
		self::assertSame( 10, has_action( 'set_object_terms', array( $hooks, 'terms_set' ) ), 'Not waiting for admin_init: REST, CSV import, WP-CLI and cron change categories too.' );
		self::assertSame( 10, has_action( 'deleted_term_relationships', array( $hooks, 'terms_removed' ) ) );
		self::assertSame( 10, has_action( 'transition_post_status', array( $hooks, 'status_changed' ) ) );
		self::assertSame( 10, has_action( 'pre_delete_term', array( $hooks, 'term_deleting' ) ) );
		self::assertSame( 10, has_action( 'delete_term', array( $hooks, 'term_deleted' ) ) );
		self::assertSame( 10, has_action( CategoryReconciliationScheduler::PRODUCT_HOOK, array( $jobs, 'run_product' ) ) );
		self::assertSame( 10, has_action( CategoryReconciliationScheduler::RUN_HOOK, array( $jobs, 'run_page' ) ) );
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
		// The daily balance check's scheduling skips AJAX requests (ADR-079).
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
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
