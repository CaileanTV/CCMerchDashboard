<?php
/**
 * Storage behind the WooCommerce product stand-in (tests/Fixtures/woocommerce-product.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

/**
 * Products and their post meta rows as the stand-in WC_Data persists them.
 */
final class WcProductStorage {

	/**
	 * Products by id.
	 *
	 * @var array<int, array{type: string, parent_id: int, status: string, name: string, sku: string, categories: int[]}>
	 */
	public static array $products = array();

	/**
	 * Product categories (product_cat terms) by term id.
	 *
	 * @var array<int, array{name: string, parent: int}>
	 */
	public static array $categories = array();

	/**
	 * Arguments of every wc_get_products() call.
	 *
	 * @var list<array<string, mixed>>
	 */
	public static array $queries = array();

	/**
	 * Arguments of every search_products() call.
	 *
	 * @var list<array<int, mixed>>
	 */
	public static array $searches = array();

	/**
	 * Meta rows by product id.
	 *
	 * @var array<int, list<array{id: int, key: string, value: string}>>
	 */
	public static array $meta = array();

	/**
	 * Next meta id.
	 *
	 * @var int
	 */
	public static int $next_meta_id = 100;

	/**
	 * Cache groups invalidated through WC_Cache_Helper.
	 *
	 * @var list<string>
	 */
	public static array $invalidated_groups = array();

	/**
	 * When true, save_meta_data() silently stores nothing (like a failed write).
	 *
	 * @var bool
	 */
	public static bool $drop_writes = false;

	/**
	 * Adds a product with meta rows.
	 *
	 * @param int                   $id        Product id.
	 * @param array<string, string> $meta      Meta key => value (one row each).
	 * @param string                $type      Product type.
	 * @param int                   $parent_id Parent id.
	 * @param string                $name      Product name.
	 * @param string                $sku       SKU.
	 * @param string                $status    Post status.
	 * @param int[]                 $categories Directly assigned product_cat term ids.
	 */
	public static function add( int $id, array $meta = array(), string $type = 'simple', int $parent_id = 0, string $name = '', string $sku = '', string $status = 'publish', array $categories = array() ): void {
		self::$products[ $id ] = array(
			'type'       => $type,
			'parent_id'  => $parent_id,
			'status'     => $status,
			'name'       => '' === $name ? 'Product ' . $id : $name,
			'sku'        => $sku,
			'categories' => $categories,
		);
		self::$meta[ $id ]     = array();

		foreach ( $meta as $key => $value ) {
			self::$meta[ $id ][] = array(
				'id'    => self::$next_meta_id++,
				'key'   => (string) $key,
				'value' => $value,
			);
		}
	}

	/**
	 * Mirrors get_terms() for product_cat with name__like and fields ids:
	 * term ids whose name contains the text, case-insensitively.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return list<int>
	 */
	public static function category_ids_like( array $args ): array {
		$ids = array();

		foreach ( self::$categories as $id => $category ) {
			if ( 'product_cat' === $args['taxonomy'] && false !== stripos( $category['name'], (string) $args['name__like'] ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * The category and, recursively, its children.
	 *
	 * @param int $id Term id.
	 * @return list<int>
	 */
	public static function category_with_children( int $id ): array {
		$ids = array( $id );

		foreach ( self::$categories as $child => $category ) {
			if ( $category['parent'] === $id ) {
				$ids = array_merge( $ids, self::category_with_children( $child ) );
			}
		}

		return $ids;
	}
}
