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
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsSection;
use MerchBoard\Modules\WooCommerce\Admin\ProductListCreators;
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
 * UI.
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
				$c->get( Transaction::class )
			)
		);

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
				$c->get( CreatorRepository::class )
			)
		);

		$container->set(
			ProductListCreators::class,
			static fn( Container $c ): ProductListCreators => new ProductListCreators(
				$c->get( ProductStore::class ),
				$c->get( CreatorRepository::class )
			)
		);

		// Only wp-admin: the product links on the creator's edit page and the
		// read-only column and filter in the product list. Nothing is hooked
		// into WooCommerce's product editor (ADR-032).
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( CreatorProductsActions::class )->register();
				$container->get( CreatorProductsSection::class )->register();
				$container->get( ProductListCreators::class )->register();
			}
		);
	}
}
