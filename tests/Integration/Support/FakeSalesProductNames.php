<?php
/**
 * Current product names without WooCommerce (integration tests).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\WooCommerce\Dashboard\SalesProductNames;

/**
 * Stands in for WooCommerceSalesProductNames (tested on its own with the
 * WooCommerce stand-ins): the names in $names are the existing products;
 * every other id is a product that no longer exists. Records the ids of every
 * call.
 */
final class FakeSalesProductNames implements SalesProductNames {

	/**
	 * Product ids per current() call.
	 *
	 * @var list<list<int>>
	 */
	public array $calls = array();

	/**
	 * Current names of the existing products.
	 *
	 * @var array<int, string>
	 */
	public array $names = array();

	/**
	 * The current names of the given products that exist.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[] $product_ids Product ids.
	 * @return array<int, string>
	 */
	public function current( array $product_ids ): array {
		$this->calls[] = $product_ids;

		return array_intersect_key( $this->names, array_flip( $product_ids ) );
	}
}
