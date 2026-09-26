<?php
/**
 * In-memory product storage for the product link tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductRecord;
use MerchBoard\Modules\WooCommerce\ProductStore;

/**
 * Products with their stored link entries; records writes and cache drops,
 * and can be told to fail a write.
 */
final class FakeProductStore implements ProductStore {

	/**
	 * Products by id.
	 *
	 * @var array<int, ProductRecord>
	 */
	private array $products = array();

	/**
	 * Writes as "product:creator" (creator "null" for removals).
	 *
	 * @var list<string>
	 */
	public array $writes = array();

	/**
	 * Products whose cached data was dropped.
	 *
	 * @var list<int>
	 */
	public array $forgotten = array();

	/**
	 * Whether the next write fails (nothing is stored).
	 *
	 * @var bool
	 */
	public bool $fail_write = false;

	/**
	 * Called with the product id at the start of every write (to observe the
	 * state the write runs in).
	 *
	 * @var (callable(int): void)|null
	 */
	public $on_write = null;

	/**
	 * Search terms asked for.
	 *
	 * @var list<string>
	 */
	public array $searches = array();

	/**
	 * Adds a product.
	 *
	 * @param int                                     $id           Product id.
	 * @param list<array{key: string, value: string}> $entries      Stored link entries.
	 * @param string                                  $status       Post status.
	 * @param int                                     $parent_id    Parent id (variations).
	 * @param string                                  $name         Product name.
	 * @param string                                  $sku          SKU.
	 */
	public function add( int $id, array $entries = array(), string $status = 'publish', int $parent_id = 0, string $name = '', string $sku = '' ): void {
		$this->products[ $id ] = new ProductRecord( $id, $parent_id, $parent_id > 0, $status, $entries, '' === $name ? 'Product ' . $id : $name, $sku );
	}

	/**
	 * Adds a product linked to a creator with the exact key.
	 *
	 * @param int $id         Product id.
	 * @param int $creator_id Creator id.
	 */
	public function add_linked( int $id, int $creator_id ): void {
		$this->add(
			$id,
			array(
				array(
					'key'   => ProductCreatorLink::META_KEY,
					'value' => (string) $creator_id,
				),
			)
		);
	}

	/**
	 * Stored link entries of a product.
	 *
	 * @param int $id Product id.
	 * @return list<array{key: string, value: string}>
	 */
	public function entries( int $id ): array {
		return $this->products[ $id ]->link_entries ?? array();
	}

	/**
	 * The product, or null.
	 *
	 * @param int $product_id Product id.
	 */
	public function find( int $product_id ): ?ProductRecord {
		return $this->products[ $product_id ] ?? null;
	}

	/**
	 * Parent products with a link entry (any key spelling) for the creator.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $limit      Maximum number of products.
	 * @return list<ProductRecord>
	 */
	public function linked_to( int $creator_id, int $limit ): array {
		$linked = array_filter(
			$this->products,
			static fn( ProductRecord $p ): bool => ! $p->is_variation && in_array( (string) $creator_id, array_column( $p->link_entries, 'value' ), true )
		);

		return array_slice( array_values( $linked ), 0, $limit );
	}

	/**
	 * Parent products whose name contains the term or whose SKU equals it.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Maximum number of products.
	 * @return list<ProductRecord>
	 */
	public function search( string $term, int $limit ): array {
		$this->searches[] = $term;
		$found            = array_filter(
			$this->products,
			static fn( ProductRecord $p ): bool => ! $p->is_variation && '' !== $term && ( false !== stripos( $p->name, $term ) || $p->sku === $term )
		);

		return array_slice( array_values( $found ), 0, $limit );
	}

	/**
	 * Replaces the product's link entries.
	 *
	 * @param int      $product_id Product id.
	 * @param int|null $creator_id Creator id or null.
	 * @throws DatabaseException When told to fail.
	 */
	public function write_creator( int $product_id, ?int $creator_id ): void {
		if ( null !== $this->on_write ) {
			( $this->on_write )( $product_id );
		}

		if ( $this->fail_write ) {
			throw new DatabaseException( 'Simulated product write failure.' );
		}

		$current                       = $this->products[ $product_id ];
		$this->products[ $product_id ] = new ProductRecord(
			$current->id,
			$current->parent_id,
			$current->is_variation,
			$current->status,
			null === $creator_id ? array() : array(
				array(
					'key'   => ProductCreatorLink::META_KEY,
					'value' => (string) $creator_id,
				),
			),
			$current->name,
			$current->sku
		);
		$this->writes[]                = $product_id . ':' . ( null === $creator_id ? 'null' : $creator_id );
	}

	/**
	 * Records a cache drop.
	 *
	 * @param int $product_id Product id.
	 */
	public function forget( int $product_id ): void {
		$this->forgotten[] = $product_id;
	}
}
