<?php
/**
 * The designer's price and campaign sources, looked up when used.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Core\Container;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce module creates its hooks (the order line snapshot, the
 * creator code, the ledger synchronization) while it registers, before the
 * ProductDesigner module registers DesignerPriceSource and
 * DesignerCampaignSource. This proxy looks them up in the container at each
 * call instead, when every module is registered, so a designer product is
 * never priced by the share model because of the modules' order. Without
 * the ProductDesigner module there is no designer price and no campaign.
 */
final class ContainerDesignerSources implements DesignerPriceSource, DesignerCampaignSource {

	/**
	 * Constructor.
	 *
	 * @param Container $container The plugin's service container.
	 */
	public function __construct( private Container $container ) {
	}

	/**
	 * The designer price of a parent product.
	 *
	 * @param int $product_id Parent product.
	 * @phpstan-impure Depends on what is registered when it is called.
	 */
	public function price( int $product_id ): ?DesignerPrice {
		return $this->container->has( DesignerPriceSource::class ) ? $this->container->get( DesignerPriceSource::class )->price( $product_id ) : null;
	}

	/**
	 * The campaigns of the order's designer lines.
	 *
	 * @param OrderSnapshot $order Order.
	 * @return array<int, int>
	 * @phpstan-impure Depends on what is registered when it is called.
	 */
	public function campaigns( OrderSnapshot $order ): array {
		return $this->container->has( DesignerCampaignSource::class ) ? $this->container->get( DesignerCampaignSource::class )->campaigns( $order ) : array();
	}
}
