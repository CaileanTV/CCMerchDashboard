<?php
/**
 * The product designer module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Container;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Module;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Notifications\Admin\NotificationSwitches;
use MerchBoard\Modules\Notifications\NotificationSender;
use MerchBoard\Modules\Notifications\NotificationSettings;
use MerchBoard\Modules\Notifications\NotificationTypes;
use MerchBoard\Modules\ProductDesigner\Admin\BaseProductActions;
use MerchBoard\Modules\ProductDesigner\Admin\BaseProductsPage;
use MerchBoard\Modules\ProductDesigner\Admin\CampaignActions;
use MerchBoard\Modules\ProductDesigner\Admin\CampaignsPage;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerAssetDownload;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerNotificationActions;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerReviewActions;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerVersionFiles;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerMenu;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerSettingsActions;
use MerchBoard\Modules\ProductDesigner\Admin\DesignsPage;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Campaigns\CampaignRepository;
use MerchBoard\Modules\ProductDesigner\Campaigns\CampaignService;
use MerchBoard\Modules\ProductDesigner\Campaigns\DesignerCampaigns;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerDrafts;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerShop;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerApply;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerRepricing;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerRetirement;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerReview;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerSubmissions;
use MerchBoard\Modules\ProductDesigner\Files\ClipartLibrary;
use MerchBoard\Modules\ProductDesigner\Files\CreatorUploads;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulApi;
use MerchBoard\Modules\ProductDesigner\Providers\CatalogImport;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoAccount;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoApi;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoMockups;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulMockups;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulToken;
use MerchBoard\Modules\ProductDesigner\Providers\ProviderTaskRepository;
use MerchBoard\Modules\ProductDesigner\Providers\SignedPrintFiles;
use MerchBoard\Modules\ProductDesigner\Rest\MeDesignerController;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerMedia;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerOrderFiles;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerSettingsSection;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\JobQueue;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerCampaignSource;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPriceSource;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The creators' product designer (phase 30, ADR-080–082,
 * docs/features/product-designer.md). Loaded only with WooCommerce, because
 * designer products become WooCommerce products. Step 30.2 adds the
 * foundation: the switches on the settings screen and the creator cleanup;
 * 30.3 the price source of the settlement (always registered, also with the
 * designer switched off, so a designer product never falls back to the
 * share model); 30.4 the company's campaigns (Merch Dashboard → Campaigns
 * and the campaign of each designer line at the sale); 30.5 the legacy guard
 * and the only writer of designer products (used from 30.10 on); 30.6 the
 * base products and cliparts (Merch Dashboard → Base products); 30.8 the
 * designer in the creator's dashboard (section "Designer", `/me/designer`)
 * with uploads and drafts; the following steps add submission, approval
 * and the providers.
 */
final class ProductDesignerModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'product_designer';
	}

	/**
	 * Registers services and hooks.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$container->set( DesignerSettings::class, static fn( Container $c ): DesignerSettings => new DesignerSettings( $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		// Printful (step 30.12, ADR-081): the token, the API, the import and the mockups.
		$container->set( PrintfulToken::class, static fn( Container $c ): PrintfulToken => new PrintfulToken( $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( PrintfulApi::class, static fn( Container $c ): PrintfulApi => new PrintfulApi( $c->get( PrintfulToken::class ) ) );
		$container->set( ProviderTaskRepository::class, static fn( Container $c ): ProviderTaskRepository => new ProviderTaskRepository( $c->get( wpdb::class ) ) );
		$container->set( SignedPrintFiles::class, static fn( Container $c ): SignedPrintFiles => new SignedPrintFiles( $c->get( DesignerProductRepository::class ), $c->get( DesignerFiles::class ) ) );
		$container->set( PrintfulCatalog::class, static fn( Container $c ): PrintfulCatalog => new PrintfulCatalog( $c->get( PrintfulApi::class ) ) );
		$container->set(
			PrintfulMockups::class,
			static fn( Container $c ): PrintfulMockups => new PrintfulMockups(
				$c->get( wpdb::class ),
				$c->get( DesignerProductRepository::class ),
				$c->get( BaseProductRepository::class ),
				$c->get( PrintfulApi::class ),
				$c->get( ProviderTaskRepository::class ),
				self::downloads(),
				$c->get( JobQueue::class ),
				$c->get( Logger::class )
			)
		);
		// Gelato (step 30.13, ADR-082): the key and store, the API, the template import and the mockups.
		$container->set( GelatoAccount::class, static fn( Container $c ): GelatoAccount => new GelatoAccount( $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( GelatoApi::class, static fn( Container $c ): GelatoApi => new GelatoApi( $c->get( GelatoAccount::class ) ) );
		$container->set( GelatoCatalog::class, static fn( Container $c ): GelatoCatalog => new GelatoCatalog( $c->get( GelatoApi::class ) ) );
		// The catalog import (review 30.15): the providers' photos are our own downloads, moved, not taken as uploads.
		$container->set(
			CatalogImport::class,
			static fn( Container $c ): CatalogImport => new CatalogImport(
				$c->get( wpdb::class ),
				$c->get( BaseProductRepository::class ),
				new BaseProductService( $c->get( BaseProductRepository::class ), $c->get( DesignerAssetRepository::class ), self::downloads(), $c->get( AuditTrail::class ), $c->get( Transaction::class ), $c->get( PricingProductStore::class )->decimals(), get_woocommerce_currency() ),
				self::downloads(),
				$c->get( JobQueue::class ),
				$c->get( Logger::class ),
				$c->get( PricingProductStore::class )->decimals(),
				plugin_dir_url( dirname( __DIR__, 3 ) . '/merchboard.php' ) . 'assets/designer/',
				$c->get( PrintfulCatalog::class ),
				$c->get( GelatoCatalog::class )
			)
		);
		$container->set(
			GelatoMockups::class,
			static fn( Container $c ): GelatoMockups => new GelatoMockups(
				$c->get( wpdb::class ),
				$c->get( DesignerProductRepository::class ),
				$c->get( BaseProductRepository::class ),
				$c->get( GelatoApi::class ),
				$c->get( ProviderTaskRepository::class ),
				self::downloads(),
				$c->get( JobQueue::class ),
				$c->get( Logger::class )
			)
		);
		$container->set( DesignerSettingsSection::class, static fn( Container $c ): DesignerSettingsSection => new DesignerSettingsSection( $c->get( PrintfulToken::class ), $c->get( GelatoAccount::class ) ) );
		$container->set( DesignerMenu::class, static fn( Container $c ): DesignerMenu => new DesignerMenu( $c->get( DesignsPage::class ), $c->get( BaseProductsPage::class ), $c->get( CampaignsPage::class ), $c->get( DesignerSettingsSection::class ) ) );
		$container->set( DesignerShop::class, static fn( Container $c ): DesignerShop => new DesignerShop( $c->get( DesignerProductRepository::class ), $c->get( DesignerDrafts::class ), $c->get( DesignerProductGuard::class ), $c->get( WooCommerceDesignerWriter::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ), $c->get( DesignerFiles::class ), $c->get( BaseProductRepository::class ) ) );
		$container->set( ThreadPacks::class, static fn( Container $c ): ThreadPacks => new ThreadPacks( $c->get( AuditTrail::class ), $c->get( Transaction::class ), $c->get( BaseProductRepository::class ) ) );
		$container->set( DesignerSettingsActions::class, static fn( Container $c ): DesignerSettingsActions => new DesignerSettingsActions( $c->get( DesignerSettings::class ), $c->get( Logger::class ), $c->get( PrintfulToken::class ), $c->get( GelatoAccount::class ), $c->get( ThreadPacks::class ) ) );
		$container->set( DesignerPriceSource::class, static fn( Container $c ): DesignerPriceSource => new DesignerPrices( $c->get( wpdb::class ), $c->get( PricingProductStore::class ) ) );
		$container->set( CampaignRepository::class, static fn( Container $c ): CampaignRepository => new CampaignRepository( $c->get( wpdb::class ) ) );
		$container->set( CampaignService::class, static fn( Container $c ): CampaignService => new CampaignService( $c->get( CampaignRepository::class ), $c->get( CreatorCodeRepository::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( DesignerCampaignSource::class, static fn( Container $c ): DesignerCampaignSource => new DesignerCampaigns( $c->get( CampaignRepository::class ), new SalesLinePricingRepository( $c->get( wpdb::class ) ) ) );
		$container->set( CampaignsPage::class, static fn( Container $c ): CampaignsPage => new CampaignsPage( $c->get( CampaignRepository::class ) ) );
		$container->set( CampaignActions::class, static fn( Container $c ): CampaignActions => new CampaignActions( $c->get( CampaignService::class ), $c->get( Logger::class ) ) );
		// The legacy guard and the only writer of designer products (ADR-080 pkt. 7).
		$container->set( DesignerProductGuard::class, static fn( Container $c ): DesignerProductGuard => new DesignerProductGuard( $c->get( wpdb::class ), $c->get( ProtectedProductMeta::class ), $c->get( Logger::class ) ) );
		$container->set( WooCommerceDesignerWriter::class, static fn( Container $c ): WooCommerceDesignerWriter => new WooCommerceDesignerWriter( $c->get( DesignerProductGuard::class ) ) );
		// Base products, their template layers and the cliparts (ADR-080 pkt. 2, step 30.6).
		$container->set( DesignerFiles::class, static fn(): DesignerFiles => new DesignerFiles() );
		$container->set( DesignerAssetRepository::class, static fn( Container $c ): DesignerAssetRepository => new DesignerAssetRepository( $c->get( wpdb::class ) ) );
		$container->set( BaseProductRepository::class, static fn( Container $c ): BaseProductRepository => new BaseProductRepository( $c->get( wpdb::class ) ) );
		$container->set( BaseProductService::class, static fn( Container $c ): BaseProductService => new BaseProductService( $c->get( BaseProductRepository::class ), $c->get( DesignerAssetRepository::class ), $c->get( DesignerFiles::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ), $c->get( PricingProductStore::class )->decimals(), get_woocommerce_currency() ) );
		$container->set( ClipartLibrary::class, static fn( Container $c ): ClipartLibrary => new ClipartLibrary( $c->get( DesignerAssetRepository::class ), $c->get( DesignerFiles::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ) ) );
		$container->set( BaseProductsPage::class, static fn( Container $c ): BaseProductsPage => new BaseProductsPage( $c->get( BaseProductRepository::class ), $c->get( DesignerAssetRepository::class ), $c->get( PricingProductStore::class )->decimals(), get_woocommerce_currency(), dirname( __DIR__, 3 ) . '/', plugin_dir_url( dirname( __DIR__, 3 ) . '/merchboard.php' ), $c->get( PrintfulToken::class )->configured(), $c->get( GelatoAccount::class )->configured(), $c->get( CatalogImport::class ), $c->get( PrintfulCatalog::class ), $c->get( GelatoCatalog::class ) ) );
		$container->set( BaseProductActions::class, static fn( Container $c ): BaseProductActions => new BaseProductActions( $c->get( BaseProductService::class ), $c->get( ClipartLibrary::class ), $c->get( Logger::class ), $c->get( CatalogImport::class ) ) );
		$container->set( DesignerAssetDownload::class, static fn( Container $c ): DesignerAssetDownload => new DesignerAssetDownload( $c->get( DesignerAssetRepository::class ), $c->get( DesignerFiles::class ) ) );
		// The creator's designer: drafts, uploads and `/me/designer` (step 30.8).
		$container->set( DesignerProductRepository::class, static fn( Container $c ): DesignerProductRepository => new DesignerProductRepository( $c->get( wpdb::class ) ) );
		$container->set( DesignerDrafts::class, static fn( Container $c ): DesignerDrafts => new DesignerDrafts( $c->get( DesignerProductRepository::class ), $c->get( BaseProductRepository::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ), $c->get( PricingProductStore::class )->decimals(), $c->get( DesignerFiles::class ) ) );
		$container->set( DesignerSubmissions::class, static fn( Container $c ): DesignerSubmissions => new DesignerSubmissions( $c->get( DesignerProductRepository::class ), $c->get( BaseProductRepository::class ), $c->get( DesignerFiles::class ), $c->get( AuditTrail::class ), $c->get( Transaction::class ), $c->get( PricingProductStore::class )->decimals() ) );
		$container->set( CreatorUploads::class, static fn( Container $c ): CreatorUploads => new CreatorUploads( $c->get( DesignerAssetRepository::class ), $c->get( DesignerFiles::class ) ) );
		$container->set(
			MeDesignerController::class,
			static fn( Container $c ): MeDesignerController => new MeDesignerController(
				$c->get( CreatorContext::class ),
				$c->get( BaseProductRepository::class ),
				$c->get( DesignerProductRepository::class ),
				$c->get( DesignerDrafts::class ),
				$c->get( DesignerSubmissions::class ),
				$c->get( DesignerAssetRepository::class ),
				$c->get( DesignerFiles::class ),
				$c->get( CreatorUploads::class ),
				$c->get( AlertFonts::class ),
				$c->get( Logger::class ),
				$c->get( DesignerShop::class )
			)
		);
		// The approval queue and the designer's notifications (step 30.9).
		$container->set( DesignsPage::class, static fn( Container $c ): DesignsPage => new DesignsPage( $c->get( DesignerProductRepository::class ), $c->get( BaseProductRepository::class ), $c->get( CreatorRepository::class ), $c->get( NotificationTypes::class ), $c->get( NotificationSwitches::class ), $c->get( PricingProductStore::class )->decimals() ) );
		$container->set( DesignerNotificationActions::class, static fn( Container $c ): DesignerNotificationActions => new DesignerNotificationActions( $c->get( NotificationSettings::class ), $c->get( NotificationTypes::class ), $c->get( NotificationSender::class ), $c->get( Logger::class ) ) );
		// Approval and the apply pipeline into WooCommerce (step 30.10).
		$container->set( DesignerMedia::class, static fn( Container $c ): DesignerMedia => new DesignerMedia( $c->get( DesignerFiles::class ) ) );
		$container->set(
			DesignerApply::class,
			static fn( Container $c ): DesignerApply => new DesignerApply(
				$c->get( wpdb::class ),
				$c->get( DesignerProductRepository::class ),
				$c->get( BaseProductRepository::class ),
				$c->get( CreatorRepository::class ),
				$c->get( CreatorCategoryRepository::class ),
				$c->get( WooCommerceDesignerWriter::class ),
				$c->get( DesignerMedia::class ),
				static fn( int $product, int $creator, array $context ): array => $c->get( ProductCreatorLink::class )->apply_designer_link( $product, $creator, $context ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( Logger::class ),
				$c->get( PricingProductStore::class )->decimals(),
				static function ( int $product ) use ( $c ): void {
					$c->get( DesignerRepricing::class )->schedule( $product );
				},
				static function ( int $version ) use ( $c ): void {
					$c->get( JobQueue::class )->enqueue( DesignerApply::HOOK, array( $version ), 30 );
				}
			)
		);
		// A new base price and a retired base product (step 30.11).
		$container->set(
			DesignerRepricing::class,
			static fn( Container $c ): DesignerRepricing => new DesignerRepricing(
				$c->get( wpdb::class ),
				$c->get( DesignerProductRepository::class ),
				$c->get( BaseProductRepository::class ),
				$c->get( DesignerProductGuard::class ),
				$c->get( WooCommerceDesignerWriter::class ),
				$c->get( JobQueue::class ),
				$c->get( AuditTrail::class ),
				$c->get( Logger::class ),
				$c->get( PricingProductStore::class )->decimals()
			)
		);
		$container->set(
			DesignerRetirement::class,
			static fn( Container $c ): DesignerRetirement => new DesignerRetirement(
				$c->get( wpdb::class ),
				$c->get( DesignerProductRepository::class ),
				$c->get( DesignerProductGuard::class ),
				$c->get( WooCommerceDesignerWriter::class ),
				$c->get( JobQueue::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( Logger::class )
			)
		);
		$container->set(
			DesignerReview::class,
			static fn( Container $c ): DesignerReview => new DesignerReview(
				$c->get( DesignerProductRepository::class ),
				$c->get( BaseProductRepository::class ),
				$c->get( AuditTrail::class ),
				$c->get( Transaction::class ),
				$c->get( JobQueue::class ),
				$c->get( PricingProductStore::class )->decimals(),
				static function ( int $version ) use ( $c ): void {
					$c->get( DesignerApply::class )->run( $version );
				}
			)
		);
		$container->set( DesignerReviewActions::class, static fn( Container $c ): DesignerReviewActions => new DesignerReviewActions( $c->get( DesignerReview::class ), $c->get( Logger::class ) ) );
		$container->set( DesignerVersionFiles::class, static fn( Container $c ): DesignerVersionFiles => new DesignerVersionFiles( $c->get( DesignerProductRepository::class ), $c->get( DesignerFiles::class ) ) );
		$container->set( DesignerOrderFiles::class, static fn( Container $c ): DesignerOrderFiles => new DesignerOrderFiles( $c->get( DesignerProductRepository::class ), $c->get( DesignerProductGuard::class ), $c->get( Logger::class ) ) );
		$container->set(
			DesignerCreatorCleanup::class,
			static fn( Container $c ): DesignerCreatorCleanup => new DesignerCreatorCleanup(
				$c->get( wpdb::class ),
				$c->get( DesignerFiles::class ),
				static function ( int $design_product_id ) use ( $c ): bool {
					$product = $c->get( DesignerProductGuard::class )->for_row( $design_product_id );

					if ( null !== $product ) {
						$c->get( WooCommerceDesignerWriter::class )->unpublish( $product );
					}

					return null !== $product;
				}
			)
		);

		// Deleting a creator (ADR-076): designer data that never reached the shop.
		add_filter(
			'merchboard_creator_data_cleaners',
			static function ( mixed $cleaners ) use ( $container ): array {
				return array_merge( is_array( $cleaners ) ? $cleaners : array(), array( $container->get( DesignerCreatorCleanup::class ) ) );
			},
			40
		);

		add_action(
			'merchboard_designer_submitted',
			static function ( mixed $version ) use ( $container ): void {
				$container->get( PrintfulMockups::class )->submitted( $version );
				$container->get( GelatoMockups::class )->submitted( $version );
			}
		);
		add_action(
			PrintfulMockups::HOOK,
			static function ( mixed $version ) use ( $container ): void {
				$container->get( PrintfulMockups::class )->run( $version );
			}
		);
		add_action(
			GelatoMockups::HOOK,
			static function ( mixed $version ) use ( $container ): void {
				$container->get( GelatoMockups::class )->run( $version );
			}
		);
		add_action(
			CatalogImport::HOOK,
			static function ( mixed $base ) use ( $container ): void {
				$container->get( CatalogImport::class )->run( $base );
			}
		);
		add_action(
			'wp_ajax_' . BaseProductsPage::STATUS_ACTION,
			static function () use ( $container ): void {
				$container->get( BaseProductsPage::class )->handle_status();
			}
		);
		DesignerNotifications::register();
		( new DesignerDashboardSection() )->register();
		$container->get( DesignerOrderFiles::class )->register();
		add_action(
			DesignerApply::HOOK,
			static function ( mixed $version ) use ( $container ): void {
				$container->get( DesignerApply::class )->run( $version );
			}
		);
		add_action(
			DesignerRepricing::HOOK,
			static function ( mixed $product, mixed $attempt = 1 ) use ( $container ): void {
				$container->get( DesignerRepricing::class )->run( $product, $attempt );
			},
			10,
			2
		);
		add_action(
			DesignerRetirement::HOOK,
			static function ( mixed $product, mixed $attempt = 1 ) use ( $container ): void {
				$container->get( DesignerRetirement::class )->run( $product, $attempt );
			},
			10,
			2
		);
		add_action(
			BaseProductService::PRICE_CHANGED,
			static function ( mixed $base ) use ( $container ): void {
				$container->get( DesignerRepricing::class )->base_changed( $base );
			}
		);
		add_action(
			BaseProductService::RETIRED,
			static function ( mixed $base ) use ( $container ): void {
				$container->get( DesignerRetirement::class )->base_retired( $base );
			}
		);
		add_action(
			'merchboard_creator_renamed',
			static function ( mixed $id, mixed $before, mixed $after ) use ( $container ): void {
				$container->get( DesignerShop::class )->creator_renamed( (int) $id, (string) $before, (string) $after );
			},
			10,
			3
		);
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( MeDesignerController::class )->register_routes();
				$container->get( SignedPrintFiles::class )->register_routes();
			}
		);
		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( DesignerMenu::class )->add_menu();
			},
			CampaignsPage::PRIORITY
		);
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( DesignerSettingsActions::class )->register();
				$container->get( CampaignActions::class )->register();
				$container->get( BaseProductActions::class )->register();
				$container->get( DesignerAssetDownload::class )->register();
				$container->get( DesignerNotificationActions::class )->register();
				$container->get( DesignerReviewActions::class )->register();
				$container->get( DesignerVersionFiles::class )->register();
			}
		);
	}

	/**
	 * Designer files that come from our own downloads (Printful), moved into
	 * the private folder instead of being taken as uploads.
	 */
	private static function downloads(): DesignerFiles {
		return new DesignerFiles( static fn( string $from, string $to ): bool => rename( $from, $to ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Our own temporary download.
	}
}
