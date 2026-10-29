<?php
/**
 * A WooCommerce product proven to be a designer product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

defined( 'ABSPATH' ) || exit;

/**
 * The legacy guard's proof (ADR-080 pkt. 7): the WooCommerce product has a
 * row in `merchboard_designer_products`, the protected marker and a marker
 * that equals the row's creation token. Created only by DesignerProductGuard
 * (an architecture test fails otherwise), and the only argument through
 * which WooCommerceDesignerWriter changes an existing product, so no code
 * path can write a legacy product's price or data.
 */
final readonly class GuardedProduct {

	/**
	 * Constructor. Use DesignerProductGuard::guard().
	 *
	 * @param int $design_product_id Row in `merchboard_designer_products`.
	 * @param int $wc_product_id     WooCommerce parent product.
	 * @param int $creator_id        Owner.
	 */
	public function __construct(
		public int $design_product_id,
		public int $wc_product_id,
		public int $creator_id
	) {
	}
}
