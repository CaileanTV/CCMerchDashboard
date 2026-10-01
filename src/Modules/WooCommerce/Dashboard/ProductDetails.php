<?php
/**
 * Product data for the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what `GET /me/products` shows of a product already selected by
 * CreatorProducts, behind an interface so the endpoint can be tested without
 * WooCommerce.
 */
interface ProductDetails {

	/**
	 * The product's summary, or null when it no longer exists, is a variation
	 * or is not `publish`/`private` (it changed after the selection).
	 *
	 * @param int $product_id Product id.
	 */
	public function find( int $product_id ): ?ProductSummary;
}
