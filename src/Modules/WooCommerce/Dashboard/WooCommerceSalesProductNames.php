<?php
/**
 * WooCommerce implementation of the current product names.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Looks products up through WooCommerce CRUD (`wc_get_product()`), at most
 * once per distinct id and never for id 0 (the ledger's group of rows without
 * a product).
 */
final class WooCommerceSalesProductNames implements SalesProductNames {

	/**
	 * The current names of the products that still exist.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[] $product_ids Distinct parent product ids.
	 * @return array<int, string> Name by product id.
	 */
	public function current( array $product_ids ): array {
		$names = array();

		foreach ( array_unique( $product_ids ) as $product_id ) {
			if ( $product_id <= 0 ) {
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$name = PlainText::of( $product->get_name() );

			if ( '' !== $name ) {
				$names[ $product_id ] = $name;
			}
		}

		return $names;
	}
}
