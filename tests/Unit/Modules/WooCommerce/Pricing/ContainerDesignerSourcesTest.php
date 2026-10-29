<?php
/**
 * Tests for the designer sources looked up when used.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use MerchBoard\Core\Container;
use MerchBoard\Modules\WooCommerce\Pricing\ContainerDesignerSources;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerCampaignSource;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPrice;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPriceSource;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshot;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ContainerDesignerSources::class )]
final class ContainerDesignerSourcesTest extends TestCase {

	/**
	 * The WooCommerce module creates its hooks before the ProductDesigner
	 * module registers its sources; the proxy must still reach them.
	 */
	public function test_sources_registered_after_the_proxy_was_created_are_used(): void {
		$container = new Container();
		$sources   = new ContainerDesignerSources( $container );
		$order     = OrderSnapshot::deleted( 900 );

		self::assertNull( $sources->price( 20 ), 'Without the ProductDesigner module: no designer price.' );
		self::assertSame( array(), $sources->campaigns( $order ), 'Without the ProductDesigner module: no campaign.' );

		$container->set(
			DesignerPriceSource::class,
			static fn(): DesignerPriceSource => new class() implements DesignerPriceSource {

				/**
				 * Price.
				 *
				 * @param int $product_id Product.
				 */
				public function price( int $product_id ): ?DesignerPrice {
					return 20 === $product_id ? new DesignerPrice( 7, 1500000, 500000, 'DKK' ) : null;
				}
			}
		);
		$container->set(
			DesignerCampaignSource::class,
			static fn(): DesignerCampaignSource => new class() implements DesignerCampaignSource {

				/**
				 * Campaigns.
				 *
				 * @param OrderSnapshot $order Order.
				 * @return array<int, int>
				 */
				public function campaigns( OrderSnapshot $order ): array {
					return array( 501 => $order->order_id );
				}
			}
		);

		$price = $sources->price( 20 );
		self::assertNotNull( $price );
		self::assertSame( 7, $price->creator_id );
		self::assertNull( $sources->price( 21 ) );
		self::assertSame( array( 501 => 900 ), $sources->campaigns( $order ) );
	}
}
