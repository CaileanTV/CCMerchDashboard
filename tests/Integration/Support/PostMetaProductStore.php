<?php
/**
 * A ProductStore on WordPress' post, meta and term APIs, for integration
 * tests without WooCommerce.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductLinkSource;
use MerchBoard\Modules\WooCommerce\ProductRecord;
use MerchBoard\Modules\WooCommerce\ProductStore;
use WP_Post;
use WP_Query;

/**
 * Products are `product` posts, variations `product_variation` posts. Writes
 * use the generic metadata API, so the booted core guard decides whether they
 * go through: only inside ProductCreatorLink's grant for that product.
 */
final class PostMetaProductStore implements ProductStore {

	/**
	 * Products written, in order.
	 *
	 * @var list<int>
	 */
	public array $writes = array();

	public function find( int $product_id ): ?ProductRecord {
		$post = get_post( $product_id );

		if ( null === $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return null;
		}

		$links   = array();
		$sources = array();

		foreach ( get_post_meta( $product_id ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				$entry = array(
					'key'   => (string) $key,
					'value' => (string) $value,
				);

				if ( ProductCreatorLink::is_link_key( (string) $key ) ) {
					$links[] = $entry;
				} elseif ( ProductCreatorLink::is_source_key( (string) $key ) ) {
					$sources[] = $entry;
				}
			}
		}

		$terms = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		return new ProductRecord(
			$product_id,
			$post->post_parent,
			'product_variation' === $post->post_type,
			$post->post_status,
			$links,
			$post->post_title,
			'',
			$sources,
			is_array( $terms ) ? array_map( 'intval', $terms ) : array()
		);
	}

	/**
	 * Parent products with a link entry for the creator, except auto-draft.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $limit      Maximum number of products.
	 * @return list<ProductRecord>
	 */
	public function linked_to( int $creator_id, int $limit ): array {
		$query    = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => ProductCreatorLink::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $creator_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$products = array();

		foreach ( $query->posts ?? array() as $post ) {
			$product = $this->find( $post instanceof WP_Post ? $post->ID : $post );

			if ( null !== $product ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	public function search( string $term, int $limit ): array {
		return array();
	}

	public function in_category( int $term_id, int $limit, int $offset ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => array( $term_id ),
						'include_children' => false,
					),
				),
			)
		);

		$ids = array();

		foreach ( $query->posts ?? array() as $post ) {
			$ids[] = $post instanceof WP_Post ? $post->ID : $post;
		}

		return $ids;
	}

	public function write_creator( int $product_id, ?int $creator_id, ProductLinkSource $source = ProductLinkSource::Admin ): void {
		$this->writes[] = $product_id;
		$ok             = true;

		foreach ( array( ProductCreatorLink::META_KEY, ProductCreatorLink::SOURCE_META_KEY ) as $key ) {
			if ( metadata_exists( 'post', $product_id, $key ) ) {
				$ok = delete_post_meta( $product_id, $key ) && $ok;
			}
		}

		if ( null !== $creator_id ) {
			$ok = false !== add_post_meta( $product_id, ProductCreatorLink::META_KEY, (string) $creator_id ) && $ok;
			$ok = false !== add_post_meta( $product_id, ProductCreatorLink::SOURCE_META_KEY, $source->value ) && $ok;
		}

		if ( ! $ok ) {
			throw new DatabaseException( 'The creator link could not be stored.' );
		}
	}

	public function forget( int $product_id ): void {
		wp_cache_delete( $product_id, 'post_meta' );
	}
}
