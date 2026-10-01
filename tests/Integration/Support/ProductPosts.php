<?php
/**
 * Product posts with creator links for integration tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use RuntimeException;
use wpdb;

/**
 * Creates posts (products, variations or other post types) and stores their
 * creator link entries directly: no WooCommerce is loaded, and the meta guard
 * (ADR-034/035) refuses generic link writes and deletes. remove_all() deletes
 * every product and variation and the posts created here, with their meta.
 */
final class ProductPosts {

	/**
	 * Title prefix of posts that are not products.
	 */
	private const OTHER_PREFIX = 'MB-T19 ';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * A post with creator link entries.
	 *
	 * @phpstan-param list<string> $links
	 *
	 * @param string   $name      Title.
	 * @param string   $status    Post status.
	 * @param string[] $links     Link entry values (one row each).
	 * @param string   $post_type Post type.
	 * @param int      $parent_id Parent post id.
	 * @throws RuntimeException When the post cannot be created.
	 */
	public function add( string $name, string $status, array $links, string $post_type = 'product', int $parent_id = 0 ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_status' => $status,
				'post_title'  => in_array( $post_type, array( 'product', 'product_variation' ), true ) ? $name : self::OTHER_PREFIX . $name,
				'post_parent' => $parent_id,
			)
		);

		if ( $id < 1 ) {
			throw new RuntimeException( 'Could not create the test post.' );
		}

		foreach ( $links as $value ) {
			$this->meta( $id, ProductCreatorLink::META_KEY, $value );
		}

		return $id;
	}

	/**
	 * Stores one meta row directly.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param string $value   Meta value.
	 */
	public function meta( int $post_id, string $key, string $value ): void {
		$this->wpdb->insert(
			$this->wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test data.
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test data.
			),
			array( '%d', '%s', '%s' )
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Removes every product and variation and the other test posts, with
	 * their meta.
	 */
	public function remove_all(): void {
		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type IN ('product', 'product_variation') OR post_title LIKE %s", $wpdb->posts, $wpdb->esc_like( self::OTHER_PREFIX ) . '%' ) );

		foreach ( $ids as $id ) {
			$wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $id ), array( '%d' ) );
			$wpdb->delete( $wpdb->posts, array( 'ID' => (int) $id ), array( '%d' ) );
		}

		wp_cache_flush();
	}
}
