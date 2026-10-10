<?php
/**
 * WooCommerce CRUD implementation of the pricing product access.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Reads units with `wc_get_product()` and the `edit` context (stored values,
 * no filters). Read only (ADR-049): no setter and no `save()` is ever
 * called, no SQL against WooCommerce data and no price filters.
 */
final class WooCommercePricingProductStore implements PricingProductStore {

	/**
	 * A simple product or variation, or null.
	 *
	 * @param int $item_id Product or variation id.
	 */
	public function find( int $item_id ): ?PricingUnitRecord {
		$product = $item_id > 0 ? wc_get_product( $item_id ) : null;

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$parent_id = $product->get_parent_id( 'edit' );
		$status    = (string) $product->get_status( 'edit' );
		$type      = (string) $product->get_type();

		return new PricingUnitRecord(
			$product->get_id(),
			$parent_id,
			$type,
			$status,
			'variation' === $type ? self::status_of( $parent_id ) : $status,
			self::text( $product->get_regular_price( 'edit' ) ),
			self::text( $product->get_sale_price( 'edit' ) ),
			self::text( $product->get_price( 'edit' ) ),
			self::text( $product->get_name( 'edit' ) )
		);
	}

	/**
	 * The children of a variable product through CRUD.
	 *
	 * @param int $product_id Parent product id.
	 * @return list<int>
	 */
	public function variation_ids( int $product_id ): array {
		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof WC_Product || 'variable' !== $product->get_type() ) {
			return array();
		}

		return array_values( array_map( 'intval', array_filter( $product->get_children(), 'is_numeric' ) ) );
	}

	/**
	 * The store currency.
	 */
	public function currency(): string {
		return (string) get_woocommerce_currency();
	}

	/**
	 * The store's price decimals.
	 */
	public function decimals(): int {
		return (int) wc_get_price_decimals();
	}

	/**
	 * Post status of a parent product through CRUD, '' when it is missing.
	 *
	 * @param int $parent_id Parent id.
	 */
	private static function status_of( int $parent_id ): string {
		$parent = $parent_id > 0 ? wc_get_product( $parent_id ) : null;

		return $parent instanceof WC_Product ? (string) $parent->get_status( 'edit' ) : '';
	}

	/**
	 * A stored price as a string ('' for anything that is not scalar).
	 *
	 * @param mixed $value Stored value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
