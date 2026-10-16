<?php
/**
 * WooCommerce module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
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
use MerchBoard\Modules\WooCommerce\Admin\OrderHistoryActions;
use MerchBoard\Modules\WooCommerce\Admin\OrderHistoryEraser;
use MerchBoard\Modules\WooCommerce\Admin\OrderHistoryPage;
use MerchBoard\Modules\WooCommerce\Admin\OrderStatusLookup;
use MerchBoard\Modules\WooCommerce\Admin\PayoutActions;
use MerchBoard\Modules\WooCommerce\Admin\PayoutSettingsActions;
use MerchBoard\Modules\WooCommerce\Admin\PayoutSettingsSections;
use MerchBoard\Modules\WooCommerce\Admin\PayoutsPage;
use MerchBoard\Modules\WooCommerce\Admin\SettlementPdfDownload;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutCipher;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\PayoutGiftCardCouponGuard;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\PayoutGiftCardOrders;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\YithGiftCardProvider;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutGiftCards;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementPdf;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistory;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistoryDetails;
use MerchBoard\Modules\WooCommerce\Admin\CreatorPricingActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorPricingSection;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsSection;
use MerchBoard\Modules\WooCommerce\Admin\CreatorSalesSection;
use MerchBoard\Modules\WooCommerce\Admin\ProductListCreators;
use MerchBoard\Modules\WooCommerce\Admin\SalesLedgerSettingsActions;
use MerchBoard\Modules\WooCommerce\Admin\SalesLedgerSettingsSection;
use MerchBoard\Modules\WooCommerce\Cli\SalesBackfillCommand;
use MerchBoard\Modules\WooCommerce\Cli\WpCliOutput;
use MerchBoard\Modules\WooCommerce\Dashboard\CreatorProducts;
use MerchBoard\Modules\WooCommerce\Dashboard\DashboardSectionRegistration;
use MerchBoard\Modules\WooCommerce\Dashboard\OrderLineEnricher;
use MerchBoard\Modules\WooCommerce\Dashboard\ProductDetails;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesProductNames;
use MerchBoard\Modules\WooCommerce\Dashboard\CachedSalesReports;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReport;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReports;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceOrderLineEnricher;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceProductDetails;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceSalesProductNames;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorPricingSettings;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfits;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorProfits;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupResolver;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingHooks;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRecorder;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\Pricing\SalesProfit;
use MerchBoard\Modules\WooCommerce\Pricing\WooCommercePricingProductStore;
use MerchBoard\Modules\WooCommerce\Rest\MeOrdersController;
use MerchBoard\Modules\WooCommerce\Rest\MePayoutsController;
use MerchBoard\Modules\WooCommerce\Rest\MeProductsController;
use MerchBoard\Modules\WooCommerce\Rest\MeSalesController;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfill;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillJobs;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillOrders;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillScheduler;
use MerchBoard\Modules\WooCommerce\Sales\WooCommerceBackfillOrders;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatusSettings;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerHooks;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLines;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesCache;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesSynchronizer;
use MerchBoard\Modules\WooCommerce\Sales\SalesTaxRepository;
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

		// Creator pricing (ADR-049): read-only WooCommerce access (Merch
		// Dashboard never writes a price) and the share that applies to a
		// product. No hooks and no routes here.
		$container->set(
			PricingProductStore::class,
			static fn(): PricingProductStore => new WooCommercePricingProductStore()
		);

		$container->set(
			MarkupResolver::class,
			static fn( Container $c ): MarkupResolver => new MarkupResolver(
				$c->get( ProductCreatorLink::class ),
				new CreatorMarkupRepository( $c->get( wpdb::class ) ),
				new ProductMarkupRepository( $c->get( wpdb::class ) )
			)
		);

		// Share snapshot per new order line (ADR-047, ADR-049): system work on every
		// request, since orders are created in checkout, wp-admin, REST and CLI.
		$container->set(
			SalesLinePricingHooks::class,
			static fn( Container $c ): SalesLinePricingHooks => new SalesLinePricingHooks(
				new SalesLinePricingRecorder(
					$c->get( PricingProductStore::class ),
					$c->get( MarkupResolver::class ),
					new SalesLinePricingRepository( $c->get( wpdb::class ) ),
					$c->get( Logger::class ),
					new CreatorVatRepository( $c->get( wpdb::class ) )
				)
			)
		);
		$container->get( SalesLinePricingHooks::class )->register();

		// Realized profit and payout of ledger rows (ADR-049); read only, no hooks.
		$container->set(
			SalesProfit::class,
			static fn( Container $c ): SalesProfit => new SalesProfit( new SalesLinePricingRepository( $c->get( wpdb::class ) ), new SalesTaxRepository( $c->get( wpdb::class ) ) )
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
		// Object cache of the sales statistics (ADR-040 pkt. 10): the repository
		// invalidates it after every committed write.
		$container->set( SalesCache::class, static fn(): SalesCache => new SalesCache() );
		$container->set(
			SalesSynchronizer::class,
			static fn( Container $c ): SalesSynchronizer => new SalesSynchronizer(
				new SalesRepository( $c->get( wpdb::class ), $c->get( Transaction::class ), SalesRepository::LOCK_TIMEOUT, $c->get( SalesCache::class ) ),
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

		// Pricing on the creator's edit page (ADR-048, ADR-049).
		$container->set(
			CreatorPricingSettings::class,
			static fn( Container $c ): CreatorPricingSettings => new CreatorPricingSettings(
				$c->get( CreatorRepository::class ),
				new CreatorMarkupRepository( $c->get( wpdb::class ) ),
				new ProductMarkupRepository( $c->get( wpdb::class ) ),
				$c->get( ProductCreatorLink::class ),
				$c->get( PricingProductStore::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				new CreatorVatRepository( $c->get( wpdb::class ) )
			)
		);

		$container->set(
			CreatorPricingActions::class,
			static fn( Container $c ): CreatorPricingActions => new CreatorPricingActions(
				$c->get( CreatorPricingSettings::class ),
				$c->get( Logger::class )
			)
		);

		$container->set(
			CreatorPricingSection::class,
			static fn( Container $c ): CreatorPricingSection => new CreatorPricingSection(
				$c->get( ProductStore::class ),
				$c->get( PricingProductStore::class ),
				$c->get( MarkupResolver::class ),
				new CreatorMarkupRepository( $c->get( wpdb::class ) ),
				new CreatorVatRepository( $c->get( wpdb::class ) )
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
		// Sales of the edited creator (phase 20, ADR-040 pkt. 13): the same
		// SalesReports service and cache as `GET /me/sales`.
		$container->set(
			CreatorSalesSection::class,
			static fn( Container $c ): CreatorSalesSection => new CreatorSalesSection(
				$c->get( SalesReports::class ),
				$c->get( Logger::class )
			)
		);

		// Order history of the sales ledger (ADR-051): read only.
		$container->set(
			OrderHistoryPage::class,
			static fn( Container $c ): OrderHistoryPage => new OrderHistoryPage(
				new OrderHistory( $c->get( wpdb::class ) ),
				new OrderHistoryDetails(
					new SalesRepository( $c->get( wpdb::class ), $c->get( Transaction::class ) ),
					new SalesLinePricingRepository( $c->get( wpdb::class ) ),
					new SalesTaxRepository( $c->get( wpdb::class ) )
				),
				new OrderStatusLookup(),
				$c->get( CreatorRepository::class ),
				$c->get( Logger::class ),
				new SettlementRepository( $c->get( wpdb::class ) ),
				$c->get( PayoutGiftCardOrders::class )
			)
		);

		// Deletion of one order's history (ADR-052): the ledger's own lock,
		// transaction and cache invalidation, audited in the same transaction.
		$container->set(
			OrderHistoryActions::class,
			static fn( Container $c ): OrderHistoryActions => new OrderHistoryActions(
				new OrderHistoryEraser(
					new SalesRepository( $c->get( wpdb::class ), $c->get( Transaction::class ), SalesRepository::LOCK_TIMEOUT, $c->get( SalesCache::class ) ),
					new OrderStatusLookup(),
					$c->get( AuditTrail::class ),
					$c->get( Logger::class )
				)
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

		// Payouts (phase 23, ADR-054–056, ADR-058): balances, requests,
		// decisions and settings as services; one repository instance, so its
		// creator lock is shared. Creator routes: /me/payouts; admin page Payouts.
		$clock = static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$container->set(
			PayoutRepository::class,
			static fn( Container $c ): PayoutRepository => new PayoutRepository( $c->get( wpdb::class ) )
		);
		$container->set(
			PayoutCipher::class,
			static fn(): PayoutCipher => PayoutCipher::from_config()
		);
		$container->set(
			PayoutBalances::class,
			static fn( Container $c ): PayoutBalances => new PayoutBalances( new LedgerCreatorPayouts( $c->get( wpdb::class ) ), $c->get( PayoutRepository::class ), $clock )
		);
		// Settlement documents (ADR-060): issued with every completion.
		$container->set(
			SettlementDocuments::class,
			static fn( Container $c ): SettlementDocuments => new SettlementDocuments(
				new SettlementRepository( $c->get( wpdb::class ) ),
				$c->get( PayoutRepository::class ),
				new LedgerCreatorPayouts( $c->get( wpdb::class ) ),
				$c->get( CreatorRepository::class ),
				new CreatorVatRepository( $c->get( wpdb::class ) ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$clock
			)
		);
		// Their PDF, made from the stored document on every download (never stored).
		$container->set(
			SettlementPdf::class,
			static fn(): SettlementPdf => new SettlementPdf()
		);
		$container->set(
			SettlementPdfDownload::class,
			static fn( Container $c ): SettlementPdfDownload => new SettlementPdfDownload( $c->get( SettlementDocuments::class ), $c->get( SettlementPdf::class ), $c->get( Logger::class ) )
		);
		// Payout gift cards (ADR-057): the registered adapters (only YITH WooCommerce
		// Gift Cards 4.38.x, read only) and the one chosen in Settings.
		$container->set(
			GiftCardProviders::class,
			static fn( Container $c ): GiftCardProviders => new GiftCardProviders( array( new YithGiftCardProvider( $c->get( wpdb::class ), $c->get( PricingProductStore::class ), $clock ) ) )
		);
		// G1 (report 16.3): orders paid with payout gift cards (read only) and the
		// guard that refuses those cards as coupons on admin and REST API orders.
		$container->set(
			PayoutGiftCardOrders::class,
			static fn( Container $c ): PayoutGiftCardOrders => new PayoutGiftCardOrders( $c->get( PayoutRepository::class ), $c->get( GiftCardProviders::class ), $c->get( Logger::class ) )
		);
		$container->set(
			PayoutGiftCardCouponGuard::class,
			static fn( Container $c ): PayoutGiftCardCouponGuard => new PayoutGiftCardCouponGuard( static fn( string $code ): bool => $c->get( PayoutGiftCardOrders::class )->is_payout_card( $code ), $c->get( Logger::class ) )
		);
		$container->get( PayoutGiftCardCouponGuard::class )->register();
		$container->set(
			PayoutGiftCards::class,
			static fn( Container $c ): PayoutGiftCards => new PayoutGiftCards(
				$c->get( PayoutRepository::class ),
				$c->get( PayoutBalances::class ),
				$c->get( GiftCardProviders::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( Logger::class ),
				$clock,
				$c->get( SettlementDocuments::class )
			)
		);
		$container->set(
			PayoutRequests::class,
			static fn( Container $c ): PayoutRequests => new PayoutRequests(
				$c->get( PayoutRepository::class ),
				$c->get( PayoutBalances::class ),
				$c->get( PayoutCipher::class ),
				$c->get( PricingProductStore::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$clock,
				$c->get( GiftCardProviders::class )
			)
		);
		$container->set(
			PayoutDecisions::class,
			static fn( Container $c ): PayoutDecisions => new PayoutDecisions(
				$c->get( PayoutRepository::class ),
				$c->get( PayoutBalances::class ),
				$c->get( PayoutCipher::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$clock,
				$c->get( SettlementDocuments::class )
			)
		);
		$container->set(
			PayoutSettings::class,
			static fn( Container $c ): PayoutSettings => new PayoutSettings( $c->get( PayoutRepository::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ), $clock )
		);
		$container->set(
			MePayoutsController::class,
			static fn( Container $c ): MePayoutsController => new MePayoutsController(
				$c->get( CreatorContext::class ),
				$c->get( PayoutRequests::class ),
				$c->get( PayoutBalances::class ),
				$c->get( PayoutRepository::class ),
				$c->get( Logger::class ),
				$c->get( PayoutGiftCards::class ),
				$c->get( SettlementDocuments::class ),
				$c->get( SettlementPdf::class )
			)
		);
		$container->set(
			PayoutsPage::class,
			static fn( Container $c ): PayoutsPage => new PayoutsPage(
				$c->get( PayoutRepository::class ),
				$c->get( PayoutDecisions::class ),
				$c->get( PayoutCipher::class ),
				$c->get( CreatorRepository::class ),
				$c->get( Logger::class ),
				$c->get( GiftCardProviders::class ),
				$c->get( SettlementDocuments::class ),
				$c->get( PayoutBalances::class ),
				$c->get( PayoutGiftCardOrders::class )
			)
		);
		$container->set(
			PayoutActions::class,
			static fn( Container $c ): PayoutActions => new PayoutActions( $c->get( PayoutDecisions::class ), $c->get( Logger::class ), $c->get( PayoutGiftCards::class ) )
		);
		$container->set(
			PayoutSettingsActions::class,
			static fn( Container $c ): PayoutSettingsActions => new PayoutSettingsActions( $c->get( PayoutSettings::class ), $c->get( Logger::class ), $c->get( GiftCardProviders::class ) )
		);
		$container->set(
			PayoutSettingsSections::class,
			static fn( Container $c ): PayoutSettingsSections => new PayoutSettingsSections( $c->get( PayoutSettings::class ), $c->get( GiftCardProviders::class ) )
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
		// Realized profit of the creator's ledger rows (ADR-050): one
		// aggregation for /me/products, /me/orders and /me/sales.
		$container->set(
			CreatorProfits::class,
			static fn( Container $c ): CreatorProfits => new LedgerCreatorProfits( $c->get( wpdb::class ) )
		);
		$container->set(
			MeProductsController::class,
			static fn( Container $c ): MeProductsController => new MeProductsController(
				$c->get( CreatorContext::class ),
				$c->get( CreatorProducts::class ),
				$c->get( ProductDetails::class ),
				$c->get( Logger::class ),
				$c->get( CreatorProfits::class )
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
				$c->get( Logger::class ),
				$c->get( CreatorProfits::class )
			)
		);
		// Sales statistics (phase 20, ADR-040): the ledger read model, current
		// product names and `GET /me/sales` through the object cache.
		$container->set(
			SalesStatistics::class,
			static fn( Container $c ): SalesStatistics => new LedgerSalesStatistics( $c->get( wpdb::class ) )
		);
		$container->set(
			SalesProductNames::class,
			static fn(): SalesProductNames => new WooCommerceSalesProductNames()
		);
		$container->set(
			SalesReport::class,
			static fn( Container $c ): SalesReport => new SalesReport( $c->get( SalesStatistics::class ), $c->get( SalesProductNames::class ), $c->get( CreatorProfits::class ) )
		);
		$container->set(
			SalesReports::class,
			static fn( Container $c ): SalesReports => new CachedSalesReports( $c->get( SalesReport::class ), $c->get( SalesCache::class ) )
		);
		$container->set(
			MeSalesController::class,
			static fn( Container $c ): MeSalesController => new MeSalesController(
				$c->get( CreatorContext::class ),
				$c->get( SalesReports::class ),
				$c->get( Logger::class )
			)
		);
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( MeProductsController::class )->register_routes();
				$container->get( MeOrdersController::class )->register_routes();
				$container->get( MeSalesController::class )->register_routes();
				$container->get( MePayoutsController::class )->register_routes();
			}
		);
		// The dashboard sections that need WooCommerce (Products).
		( new DashboardSectionRegistration() )->register();

		// "Order history" (ADR-051). Hooked directly: WordPress fires
		// admin_menu before admin_init, so it cannot wait for admin_init.
		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( OrderHistoryPage::class )->add_menu();
			},
			OrderHistoryPage::PRIORITY
		);
		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( PayoutsPage::class )->add_menu();
			},
			PayoutsPage::PRIORITY
		);

		// Only wp-admin: the product category column in the Creators list, the
		// product category and product links on the
		// creator's edit page and the read-only column and filter in the
		// product list, the "Sales ledger" and "Payouts" settings sections
		// and the creator's payout settlement start. Nothing is
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
				$container->get( CreatorPricingActions::class )->register();
				$container->get( CreatorPricingSection::class )->register();
				$container->get( CreatorSalesSection::class )->register();
				$container->get( OrderHistoryActions::class )->register();
				$container->get( ProductListCreators::class )->register();
				$container->get( SalesLedgerSettingsActions::class )->register();
				$container->get( SalesLedgerSettingsSection::class )->register();
				$container->get( PayoutSettingsActions::class )->register();
				$container->get( PayoutActions::class )->register();
				$container->get( SettlementPdfDownload::class )->register();
				$container->get( PayoutSettingsSections::class )->register();
			}
		);
	}
}
