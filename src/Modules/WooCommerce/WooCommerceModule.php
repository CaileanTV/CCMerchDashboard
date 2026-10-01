<?php
/**
 * WooCommerce module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Admin\CategorySyncActions;
use MerchBoard\Modules\WooCommerce\Admin\CategorySyncScreen;
use MerchBoard\Modules\WooCommerce\Admin\CreatorCategoriesActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorCategoriesSection;
use MerchBoard\Modules\WooCommerce\Admin\CreatorListCategories;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsSection;
use MerchBoard\Modules\WooCommerce\Admin\ProductListCreators;
use MerchBoard\Modules\WooCommerce\Admin\SalesLedgerSettingsActions;
use MerchBoard\Modules\WooCommerce\Admin\SalesLedgerSettingsSection;
use MerchBoard\Modules\WooCommerce\Cli\SalesBackfillCommand;
use MerchBoard\Modules\WooCommerce\Cli\WpCliOutput;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\Dashboard\DashboardSectionRegistration;
use MerchBoard\Modules\WooCommerce\Dashboard\OrderLineEnricher;
use MerchBoard\Modules\WooCommerce\Dashboard\ProductDetails;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceOrderLineEnricher;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceProductDetails;
use MerchBoard\Modules\WooCommerce\Rest\MeOrdersController;
use MerchBoard\Modules\WooCommerce\Rest\MeProductsController;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfill;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillJobs;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillOrders;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillScheduler;
use MerchBoard\Modules\WooCommerce\Sales\WooCommerceBackfillOrders;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatusSettings;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerHooks;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLines;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Modules\WooCommerce\Sales\WooCommerceOrderSnapshotReader;
use MerchBoard\Modules\WooCommerce\Sales\WooCommerceSalesFailureMarker;
use WP_CLI;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The optional WooCommerce integration (docs/WOOCOMMERCE.md, ADR-011,
 * ADR-030, ADR-031). Plugin only boots it when WooCommerceSupport reports a
 * supported WooCommerce, so nothing here may be registered for an older or
 * missing WooCommerce.
 *
 * Phase 17 builds the product→creator link step by step: the domain service
 * (ProductCreatorLink) and the product links on the creator's edit page in
 * wp-admin (ADR-032), and a read-only creator column and filter in
 * WooCommerce's product list. The WooCommerce product editor gets no creator
 * UI. Creator ↔ category mappings are reconciled automatically through
 * Action Scheduler (ADR-033).
 */
final class WooCommerceModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'woocommerce';
	}

	/**
	 * Registers the module's services.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set(
			ProductStore::class,
			static fn( Container $c ): ProductStore => new WooCommerceProductStore( $c->get( wpdb::class ) )
		);

		$container->set(
			ProductCreatorLink::class,
			static fn( Container $c ): ProductCreatorLink => new ProductCreatorLink(
				$c->get( ProductStore::class ),
				$c->get( CreatorRepository::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( ProtectedProductMeta::class )
			)
		);

		// Creator ↔ product category mapping (ADR-033): storage and the only
		// write path. It writes no product link; its events queue the
		// reconciliation (below).
		$container->set(
			CreatorCategoryRepository::class,
			static fn( Container $c ): CreatorCategoryRepository => new CreatorCategoryRepository( $c->get( wpdb::class ) )
		);

		$container->set(
			CreatorCategoryService::class,
			static fn( Container $c ): CreatorCategoryService => new CreatorCategoryService(
				$c->get( CreatorCategoryRepository::class ),
				$c->get( CreatorRepository::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class )
			)
		);

		// Category-based ownership reconciliation (ADR-033).
		$container->set(
			CreatorCategoryReconciler::class,
			static fn( Container $c ): CreatorCategoryReconciler => new CreatorCategoryReconciler(
				$c->get( ProductStore::class ),
				$c->get( CreatorCategoryRepository::class ),
				$c->get( CreatorRepository::class ),
				$c->get( ProductCreatorLink::class ),
				$c->get( Logger::class )
			)
		);

		// Reconciliation in batches with dry run (ADR-033).
		$container->set(
			ReconciliationCandidates::class,
			static fn( Container $c ): ReconciliationCandidates => new ReconciliationCandidateRepository( $c->get( wpdb::class ) )
		);
		$container->set(
			CategoryReconciliationBatch::class,
			static fn( Container $c ): CategoryReconciliationBatch => new CategoryReconciliationBatch(
				$c->get( ReconciliationCandidates::class ),
				$c->get( CreatorCategoryReconciler::class ),
				$c->get( AuditTrail::class ),
				$c->get( Logger::class )
			)
		);

		// Automatic reconciliation (ADR-033 point 6): event handlers only queue
		// Action Scheduler jobs; the jobs call the services above.
		$container->set( JobQueue::class, static fn(): JobQueue => new ActionSchedulerQueue() );
		$container->set(
			CategoryReconciliationScheduler::class,
			static fn( Container $c ): CategoryReconciliationScheduler => new CategoryReconciliationScheduler( $c->get( JobQueue::class ), $c->get( Logger::class ) )
		);
		$container->set(
			CategoryReconciliationJobs::class,
			static fn( Container $c ): CategoryReconciliationJobs => new CategoryReconciliationJobs(
				$c->get( CreatorCategoryReconciler::class ),
				$c->get( CategoryReconciliationBatch::class ),
				$c->get( CategoryReconciliationScheduler::class ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			CategoryReconciliationHooks::class,
			static fn( Container $c ): CategoryReconciliationHooks => new CategoryReconciliationHooks(
				$c->get( CategoryReconciliationScheduler::class ),
				$c->get( CreatorCategoryService::class ),
				$c->get( Logger::class )
			)
		);

		// Every request (wp-admin, REST, CSV import, WP-CLI, cron and Action
		// Scheduler's runners): category changes can come from anywhere, and
		// the jobs run outside wp-admin.
		$container->get( CategoryReconciliationHooks::class )->register();
		$container->get( CategoryReconciliationJobs::class )->register();

		// Sales ledger (ADR-037, ADR-038): every request, since orders change in
		// checkout, wp-admin, REST, WP-CLI and cron. The counted statuses are
		// read from the setting on every synchronization (ADR-038 S6).
		$container->set( CountedOrderStatuses::class, static fn(): CountedOrderStatuses => new CountedOrderStatuses() );
		$container->set(
			SalesSynchronizer::class,
			static fn( Container $c ): SalesSynchronizer => new SalesSynchronizer(
				new SalesRepository( $c->get( wpdb::class ), $c->get( Transaction::class ) ),
				new SalesPlanner(),
				new WooCommerceOrderSnapshotReader( $c->get( ProductCreatorLink::class ), $c->get( CountedOrderStatuses::class ) ),
				new WooCommerceSalesFailureMarker(),
				$c->get( Logger::class )
			)
		);
		$container->set(
			SalesLedgerHooks::class,
			static fn( Container $c ): SalesLedgerHooks => new SalesLedgerHooks( $c->get( SalesSynchronizer::class ) )
		);
		$container->get( SalesLedgerHooks::class )->register();

		// Sales ledger backfill (ADR-037 pkt. 6; ADR-038, status note "ROADMAP
		// trin 7"): the job callback on every request (Action Scheduler runs it
		// from WP-Cron, async requests and WP-CLI); the command only in WP-CLI.
		$container->set( SalesBackfillOrders::class, static fn(): SalesBackfillOrders => new WooCommerceBackfillOrders() );
		$container->set(
			SalesBackfill::class,
			static fn( Container $c ): SalesBackfill => new SalesBackfill( $c->get( SalesSynchronizer::class ), $c->get( SalesBackfillOrders::class ) )
		);
		$container->set(
			SalesBackfillScheduler::class,
			static fn( Container $c ): SalesBackfillScheduler => new SalesBackfillScheduler( $c->get( JobQueue::class ), $c->get( CountedOrderStatuses::class ) )
		);
		$container->set(
			SalesBackfillJobs::class,
			static fn( Container $c ): SalesBackfillJobs => new SalesBackfillJobs(
				$c->get( SalesBackfill::class ),
				$c->get( SalesBackfillScheduler::class ),
				$c->get( AuditTrail::class ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			SalesBackfillCommand::class,
			static fn( Container $c ): SalesBackfillCommand => new SalesBackfillCommand(
				$c->get( SalesBackfill::class ),
				$c->get( SalesBackfillScheduler::class ),
				$c->get( CountedOrderStatuses::class ),
				new WpCliOutput()
			)
		);
		$container->get( SalesBackfillJobs::class )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI', false ) ) {
			WP_CLI::add_command( 'merchboard sales-backfill', $container->get( SalesBackfillCommand::class ) );
		}

		// The protected meta guard itself is a core service (ADR-035): Plugin
		// registers its one instance in register_hooks(), and ProductCreatorLink
		// gets that instance above; the module never registers it. The
		// WooCommerce entry-point checks apply to every request (wp-admin, REST,
		// cron, WP-CLI), so they are hooked right away.
		( new ProtectedMetaEntryPoints() )->register();

		$container->set(
			CreatorProductsActions::class,
			static fn( Container $c ): CreatorProductsActions => new CreatorProductsActions(
				$c->get( ProductCreatorLink::class ),
				$c->get( Logger::class )
			)
		);

		$container->set(
			CreatorProductsSection::class,
			static fn( Container $c ): CreatorProductsSection => new CreatorProductsSection(
				$c->get( ProductStore::class ),
				$c->get( CreatorRepository::class ),
				$c->get( CategoryLinkStatus::class )
			)
		);

		$container->set(
			CategoryLinkStatus::class,
			static fn( Container $c ): CategoryLinkStatus => new CategoryLinkStatus(
				$c->get( ProductStore::class ),
				$c->get( CreatorCategoryRepository::class ),
				$c->get( CreatorRepository::class ),
				$c->get( ProductCreatorLink::class ),
				$c->get( Logger::class )
			)
		);

		$container->set(
			ProductListCreators::class,
			static fn( Container $c ): ProductListCreators => new ProductListCreators(
				$c->get( ProductStore::class ),
				$c->get( CreatorRepository::class )
			)
		);

		// The mapping UI (ADR-033 point 10): dry runs and removal with a choice.
		$container->set(
			CategoryMappingPreview::class,
			static fn( Container $c ): CategoryMappingPreview => new CategoryMappingPreview(
				$c->get( CreatorCategoryService::class ),
				$c->get( CreatorCategoryRepository::class ),
				$c->get( ProductStore::class ),
				$c->get( CreatorRepository::class ),
				$c->get( ProductCreatorLink::class ),
				$c->get( ReconciliationCandidates::class ),
				$c->get( AuditTrail::class ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			CategoryMappingRemoval::class,
			static fn( Container $c ): CategoryMappingRemoval => new CategoryMappingRemoval(
				$c->get( CreatorCategoryService::class ),
				$c->get( CreatorCategoryRepository::class ),
				$c->get( CategoryMappingPreview::class ),
				$c->get( ProductCreatorLink::class ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			CreatorCategoriesActions::class,
			static fn( Container $c ): CreatorCategoriesActions => new CreatorCategoriesActions(
				$c->get( CreatorCategoryService::class ),
				$c->get( CategoryMappingRemoval::class ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			CreatorCategoriesSection::class,
			static fn( Container $c ): CreatorCategoriesSection => new CreatorCategoriesSection(
				$c->get( CreatorCategoryService::class ),
				$c->get( CreatorCategoryRepository::class ),
				$c->get( CategoryMappingPreview::class ),
				$c->get( CreatorRepository::class )
			)
		);

		// The full reconciliation view on the Creators page (ADR-033 safety net).
		$container->set(
			CategorySyncActions::class,
			static fn( Container $c ): CategorySyncActions => new CategorySyncActions(
				$c->get( CategoryReconciliationBatch::class ),
				$c->get( CategoryReconciliationScheduler::class ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			CategorySyncScreen::class,
			static fn( Container $c ): CategorySyncScreen => new CategorySyncScreen(
				$c->get( CategoryReconciliationBatch::class ),
				$c->get( ProductStore::class ),
				$c->get( CreatorCategoryRepository::class ),
				$c->get( CreatorRepository::class )
			)
		);

		$container->set(
			CreatorListCategories::class,
			static fn( Container $c ): CreatorListCategories => new CreatorListCategories(
				$c->get( CreatorCategoryRepository::class ),
				$c->get( Logger::class )
			)
		);

		// The "Sales ledger" section on the settings screen (ADR-038 S1-S14).
		$container->set(
			CountedOrderStatusSettings::class,
			static fn( Container $c ): CountedOrderStatusSettings => new CountedOrderStatusSettings(
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( CountedOrderStatuses::class )
			)
		);
		$container->set(
			SalesLedgerSettingsActions::class,
			static fn( Container $c ): SalesLedgerSettingsActions => new SalesLedgerSettingsActions( $c->get( CountedOrderStatusSettings::class ), $c->get( Logger::class ) )
		);
		$container->set(
			SalesLedgerSettingsSection::class,
			static fn( Container $c ): SalesLedgerSettingsSection => new SalesLedgerSettingsSection( $c->get( CountedOrderStatusSettings::class ) )
		);

		// Creator dashboard (phase 19, ADR-039): read-only product and order
		// data for the creator-scope REST routes. Registered only with
		// WooCommerce, so without it the routes do not exist.
		$container->set(
			CreatorProducts::class,
			static fn( Container $c ): CreatorProducts => new CreatorProducts( $c->get( wpdb::class ) )
		);
		$container->set(
			ProductDetails::class,
			static fn(): ProductDetails => new WooCommerceProductDetails()
		);
		$container->set(
			MeProductsController::class,
			static fn( Container $c ): MeProductsController => new MeProductsController(
				$c->get( CreatorContext::class ),
				$c->get( CreatorProducts::class ),
				$c->get( ProductDetails::class ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			SalesOrderLines::class,
			static fn( Container $c ): SalesOrderLines => new SalesOrderLines( $c->get( wpdb::class ) )
		);
		$container->set(
			OrderLineEnricher::class,
			static fn(): OrderLineEnricher => new WooCommerceOrderLineEnricher()
		);
		$container->set(
			MeOrdersController::class,
			static fn( Container $c ): MeOrdersController => new MeOrdersController(
				$c->get( CreatorContext::class ),
				$c->get( SalesOrderLines::class ),
				$c->get( OrderLineEnricher::class ),
				$c->get( Logger::class )
			)
		);
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( MeProductsController::class )->register_routes();
				$container->get( MeOrdersController::class )->register_routes();
			}
		);
		// The dashboard sections that need WooCommerce (Products).
		( new DashboardSectionRegistration() )->register();

		// Only wp-admin: the product category column in the Creators list, the
		// product category and product links on the
		// creator's edit page and the read-only column and filter in the
		// product list, and the "Sales ledger" settings section. Nothing is
		// hooked into WooCommerce's product editor (ADR-032).
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( CreatorCategoriesActions::class )->register();
				$container->get( CreatorCategoriesSection::class )->register();
				$container->get( CreatorListCategories::class )->register();
				$container->get( CategorySyncActions::class )->register();
				$container->get( CategorySyncScreen::class )->register();
				$container->get( CreatorProductsActions::class )->register();
				$container->get( CreatorProductsSection::class )->register();
				$container->get( ProductListCreators::class )->register();
				$container->get( SalesLedgerSettingsActions::class )->register();
				$container->get( SalesLedgerSettingsSection::class )->register();
			}
		);
	}
}
