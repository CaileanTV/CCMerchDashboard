<?php
/**
 * Where the price of a designer product comes from.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The price of a designer product now (ADR-080), implemented by the
 * ProductDesigner module, so the WooCommerce module does not depend on it.
 * Null for every other product: legacy products keep the share model.
 */
interface DesignerPriceSource {

	/**
	 * The designer price of a parent product, or null when it is not a live
	 * designer product.
	 *
	 * @param int $product_id Parent product.
	 * @throws DatabaseException When the designer's data cannot be read.
	 */
	public function price( int $product_id ): ?DesignerPrice;
}
