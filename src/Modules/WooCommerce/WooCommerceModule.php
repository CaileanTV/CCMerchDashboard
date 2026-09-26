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
use MerchBoard\Core\Module;
use MerchBoard\Modules\Creators\CreatorRepository;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The optional WooCommerce integration (docs/WOOCOMMERCE.md, ADR-011,
 * ADR-030, ADR-031). Plugin only boots it when WooCommerceSupport reports a
 * supported WooCommerce, so nothing here may be registered for an older or
 * missing WooCommerce.
 *
 * Phase 17 builds the product→creator link step by step. So far the module
 * only registers the domain service (ProductCreatorLink); it adds no hooks,
 * screens or other write paths yet.
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
	}
}
