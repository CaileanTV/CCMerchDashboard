<?php
/**
 * Product storage used by the product link.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce operations the product link needs, behind an interface so
 * the business rules can be tested without WooCommerce.
 */
interface ProductStore {

	/**
	 * The product, or null when no WooCommerce product has this id.
	 *
	 * @param int $product_id Product id.
	 */
	public function find( int $product_id ): ?ProductRecord;

	/**
	 * Parent products whose stored link entries point at the creator (also
	 * invalid spellings of the key, so they can be seen and repaired), in any
	 * status except auto-draft. At most $limit products.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $limit      Maximum number of products.
	 * @return list<ProductRecord>
	 */
	public function linked_to( int $creator_id, int $limit ): array;

	/**
	 * Parent products matching a search term in name, description or SKU,
	 * in any status. At most $limit products.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Maximum number of products.
	 * @return list<ProductRecord>
	 */
	public function search( string $term, int $limit ): array;

	/**
	 * Replaces every stored creator link entry of the product (any key
	 * spelling) with one entry for the creator, or removes them all for null.
	 * Writes through WooCommerce CRUD and verifies the result.
	 *
	 * @param int      $product_id Parent product id.
	 * @param int|null $creator_id Creator id, or null to remove the link.
	 * @throws DatabaseException When the result cannot be stored as requested.
	 */
	public function write_creator( int $product_id, ?int $creator_id ): void;

	/**
	 * Drops cached data of the product, e.g. after a rolled-back write.
	 *
	 * @param int $product_id Product id.
	 */
	public function forget( int $product_id ): void;
}
