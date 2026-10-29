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
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\ProductDesigner\Admin\BaseProductActions;
use MerchBoard\Modules\ProductDesigner\Admin\BaseProductsPage;
use MerchBoard\Modules\ProductDesigner\Admin\CampaignActions;
use MerchBoard\Modules\ProductDesigner\Admin\CampaignsPage;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerAssetDownload;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerSettingsActions;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Campaigns\CampaignRepository;
use MerchBoard\Modules\ProductDesigner\Campaigns\CampaignService;
use MerchBoard\Modules\ProductDesigner\Campaigns\DesignerCampaigns;
use MerchBoard\Modules\ProductDesigner\Files\ClipartLibrary;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerSettingsSection;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerCampaignSource;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPriceSource;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;
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
 * base products and cliparts (Merch Dashboard → Base products); the
 * following steps add the designer, approval and the providers.
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
		$container->set( DesignerSettingsActions::class, static fn( Container $c ): DesignerSettingsActions => new DesignerSettingsActions( $c->get( DesignerSettings::class ), $c->get( Logger::class ) ) );
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
		$container->set( BaseProductsPage::class, static fn( Container $c ): BaseProductsPage => new BaseProductsPage( $c->get( BaseProductRepository::class ), $c->get( DesignerAssetRepository::class ), $c->get( PricingProductStore::class )->decimals(), get_woocommerce_currency() ) );
		$container->set( BaseProductActions::class, static fn( Container $c ): BaseProductActions => new BaseProductActions( $c->get( BaseProductService::class ), $c->get( ClipartLibrary::class ), $c->get( Logger::class ) ) );
		$container->set( DesignerAssetDownload::class, static fn( Container $c ): DesignerAssetDownload => new DesignerAssetDownload( $c->get( DesignerAssetRepository::class ), $c->get( DesignerFiles::class ) ) );
		$container->set( DesignerCreatorCleanup::class, static fn( Container $c ): DesignerCreatorCleanup => new DesignerCreatorCleanup( $c->get( wpdb::class ) ) );

		// Deleting a creator (ADR-076): designer data that never reached the shop.
		add_filter(
			'merchboard_creator_data_cleaners',
			static function ( mixed $cleaners ) use ( $container ): array {
				return array_merge( is_array( $cleaners ) ? $cleaners : array(), array( $container->get( DesignerCreatorCleanup::class ) ) );
			},
			40
		);

		( new DesignerSettingsSection() )->register();
		add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( BaseProductsPage::class )->add_menu();
				$container->get( CampaignsPage::class )->add_menu();
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
			}
		);
	}
}
