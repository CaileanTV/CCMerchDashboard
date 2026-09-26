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
	 * @var array<int, array{type: string, parent_id: int, status: string}>
	 */
	public static array $products = array();

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
	 */
	public static function add( int $id, array $meta = array(), string $type = 'simple', int $parent_id = 0 ): void {
		self::$products[ $id ] = array(
			'type'      => $type,
			'parent_id' => $parent_id,
			'status'    => 'publish',
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
}
