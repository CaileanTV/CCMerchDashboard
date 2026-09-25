<?php
/**
 * WooCommerce module.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Container;
use MerchBoard\Core\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The optional WooCommerce integration (docs/WOOCOMMERCE.md, ADR-011,
 * ADR-030). Plugin only boots it when WooCommerceSupport reports a supported
 * WooCommerce, so nothing here may be registered for an older or missing
 * WooCommerce.
 *
 * Phase 17 adds the product→creator link to this module step by step; the
 * skeleton registers no hooks yet.
 */
final class WooCommerceModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'woocommerce';
	}

	/**
	 * Registers the module's services and hooks (none yet).
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
	}
}
