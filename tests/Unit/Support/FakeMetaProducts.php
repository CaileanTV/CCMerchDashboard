<?php
/**
 * A stand-in for Meta for WooCommerce's Products class.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook;

/**
 * Records the products whose sync is switched off (MetaCatalogExclusionTest).
 */
final class Products {

	/**
	 * Products whose sync was switched off, by id.
	 *
	 * @var list<int>
	 */
	public static array $disabled = array();

	/**
	 * Mirrors Products::disable_sync_for_products().
	 *
	 * @param array<\WC_Product> $products Products.
	 */
	public static function disable_sync_for_products( array $products ): void {
		foreach ( $products as $product ) {
			self::$disabled[] = $product->get_id();
		}
	}
}
