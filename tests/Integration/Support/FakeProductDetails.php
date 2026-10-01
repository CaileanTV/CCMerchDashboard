<?php
/**
 * Product data from the post alone (no WooCommerce in integration tests).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\WooCommerce\Dashboard\ProductDetails;
use MerchBoard\Modules\WooCommerce\Dashboard\ProductSummary;
use WP_Post;

/**
 * Stands in for WooCommerceProductDetails (tested on its own with the
 * WooCommerce stand-in): name and status from the post, no price or image.
 * Records which products the endpoint asked for.
 */
final class FakeProductDetails implements ProductDetails {

	/**
	 * Requested product ids, in order.
	 *
	 * @var list<int>
	 */
	public array $requested = array();

	/**
	 * Product ids reported as gone (changed after the selection).
	 *
	 * @var list<int>
	 */
	public array $gone = array();

	/**
	 * The post's summary, or null without a post or for a gone product.
	 *
	 * @param int $product_id Product id.
	 */
	public function find( int $product_id ): ?ProductSummary {
		$this->requested[] = $product_id;
		$post              = get_post( $product_id );

		if ( ! $post instanceof WP_Post || in_array( $product_id, $this->gone, true ) ) {
			return null;
		}

		return new ProductSummary( $product_id, $post->post_title, $post->post_status, null, 'instock', null );
	}
}
