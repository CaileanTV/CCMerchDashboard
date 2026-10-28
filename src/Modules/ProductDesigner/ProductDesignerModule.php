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
use MerchBoard\Modules\ProductDesigner\Admin\DesignerSettingsActions;
use MerchBoard\Modules\ProductDesigner\Admin\DesignerSettingsSection;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPriceSource;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The creators' product designer (phase 30, ADR-080–082,
 * docs/features/product-designer.md). Loaded only with WooCommerce, because
 * designer products become WooCommerce products. Step 30.2 adds the
 * foundation: the switches on the settings screen and the creator cleanup;
 * 30.3 the price source of the settlement (always registered, also with the
 * designer switched off, so a designer product never falls back to the
 * share model); the following steps add base products, the designer, approval and the
 * providers.
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
			'admin_init',
			static function () use ( $container ): void {
				$container->get( DesignerSettingsActions::class )->register();
			}
		);
	}
}
