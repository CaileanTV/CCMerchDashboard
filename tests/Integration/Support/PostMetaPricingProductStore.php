<?php
/**
 * Pricing product access on plain posts for integration tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Pricing\PricingUnitRecord;

/**
 * No WooCommerce is loaded in the integration suite (ADR-038 Q4). This store
 * reads the prices from the real `wp_postmeta` rows WooCommerce uses
 * (`_regular_price`, `_sale_price`, `_price`). Read only, like the real store
 * (ADR-049). The product type is stored in the test meta `_mb_test_type`
 * (default: simple for products, variation for variations).
 */
final class PostMetaPricingProductStore implements PricingProductStore {

	public const TYPE_KEY = '_mb_test_type';

	/**
	 * Store currency.
	 *
	 * @var string
	 */
	public string $currency = 'DKK';

	/**
	 * Store price decimals.
	 *
	 * @var int
	 */
	public int $decimals = 2;

	/**
	 * A unit.
	 *
	 * @param int $item_id Product or variation id.
	 * @phpstan-impure Reads the database.
	 */
	public function find( int $item_id ): ?PricingUnitRecord {
		$post = get_post( $item_id );

		if ( null === $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return null;
		}

		$variation = 'product_variation' === $post->post_type;
		$type      = (string) get_post_meta( $item_id, self::TYPE_KEY, true );
		$parent    = $variation && $post->post_parent > 0 ? get_post( $post->post_parent ) : null;

		return new PricingUnitRecord(
			$item_id,
			$post->post_parent,
			'' === $type ? ( $variation ? 'variation' : 'simple' ) : $type,
			$post->post_status,
			$variation ? ( null === $parent ? '' : $parent->post_status ) : $post->post_status,
			(string) get_post_meta( $item_id, '_regular_price', true ),
			(string) get_post_meta( $item_id, '_sale_price', true ),
			(string) get_post_meta( $item_id, '_price', true ),
			$post->post_title
		);
	}

	/**
	 * Variations of a variable product (test type meta), not trashed, by id.
	 *
	 * @param int $product_id Parent product id.
	 * @return list<int>
	 */
	public function variation_ids( int $product_id ): array {
		if ( 'variable' !== $this->find( $product_id )?->type ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_parent'    => $product_id,
				'post_status'    => array( 'publish', 'private' ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Store currency.
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * Store decimals.
	 */
	public function decimals(): int {
		return $this->decimals;
	}
}
