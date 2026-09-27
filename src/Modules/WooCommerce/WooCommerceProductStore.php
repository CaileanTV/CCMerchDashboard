<?php
/**
 * WooCommerce implementation of the product storage.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;
use WC_Cache_Helper;
use WC_Data_Store;
use WC_Product;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads products with `wc_get_product()` and writes the link only through
 * WooCommerce CRUD (`WC_Data` meta methods, never `update_post_meta()`),
 * which is HPOS-independent and updates WooCommerce's caches on a successful
 * write. After a failed (rolled-back) write, forget() drops the caches.
 *
 * A write is verified by re-reading the stored rows, because
 * `save_meta_data()` reports no errors.
 */
final class WooCommerceProductStore implements ProductStore {

	/**
	 * Post statuses listed and searched by category: every status but
	 * auto-draft.
	 */
	private const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object (verification reads only).
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The product, or null when no WooCommerce product has this id.
	 *
	 * @param int $product_id Product id.
	 */
	public function find( int $product_id ): ?ProductRecord {
		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$entries = array();
		$sources = array();

		foreach ( $product->get_meta_data() as $meta ) {
			$data  = $meta->get_data();
			$key   = is_string( $data['key'] ?? null ) ? $data['key'] : '';
			$entry = array(
				'key'   => $key,
				'value' => is_scalar( $data['value'] ?? null ) ? (string) $data['value'] : '',
			);

			if ( ProductCreatorLink::is_link_key( $key ) ) {
				$entries[] = $entry;
			} elseif ( ProductCreatorLink::is_source_key( $key ) ) {
				$sources[] = $entry;
			}
		}

		return new ProductRecord(
			$product->get_id(),
			$product->get_parent_id( 'edit' ),
			$product->is_type( 'variation' ),
			$product->get_status( 'edit' ),
			$entries,
			$product->get_name( 'edit' ),
			$product->get_sku( 'edit' ),
			$sources,
			array_values( array_map( 'intval', $product->get_category_ids( 'edit' ) ) )
		);
	}

	/**
	 * Parent products linked to the creator, via `wc_get_products()` (the
	 * query compares the key like MySQL, i.e. case-insensitively). Variations
	 * are not product types of this query, so only parents are returned.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $limit      Maximum number of products.
	 * @return list<ProductRecord>
	 */
	public function linked_to( int $creator_id, int $limit ): array {
		if ( $creator_id < 1 ) {
			return array();
		}

		$ids = wc_get_products(
			array(
				'status'     => self::STATUSES,
				'limit'      => $limit,
				'orderby'    => 'title',
				'order'      => 'ASC',
				'return'     => 'ids',
				'meta_key'   => ProductCreatorLink::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The link lookup; one creator's products.
				'meta_value' => (string) $creator_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The link lookup; one creator's products.
			)
		);

		return $this->records( is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Parent products matching the term: first WooCommerce's own product
	 * search (name, excerpt, description and SKU; the same search as
	 * WooCommerce's admin product selector), then the products directly
	 * assigned to a product category whose name contains the term, merged
	 * without duplicates. All statuses are included; which products can be
	 * linked is decided by ProductCreatorLink, not here.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Maximum number of products.
	 * @return list<ProductRecord>
	 */
	public function search( string $term, int $limit ): array {
		$term = trim( $term );

		if ( '' === $term || $limit < 1 ) {
			return array();
		}

		$ids = array_merge(
			WC_Data_Store::load( 'product' )->search_products( $term, '', false, true, $limit ),
			$this->category_product_ids( $term, $limit )
		);
		$ids = array_unique( array_map( 'intval', array_filter( $ids, 'is_numeric' ) ) );

		return array_slice( $this->records( $ids ), 0, $limit );
	}

	/**
	 * Products directly assigned to a `product_cat` term whose name contains
	 * the term, via `wc_get_products()`. `include_children` is off, so a
	 * product only in a child category is not found by the parent's name.
	 * Variations have no categories and are not product types of this query.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Maximum number of products.
	 * @return array<mixed>
	 */
	private function category_product_ids( string $term, int $limit ): array {
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'name__like' => $term,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( ! is_array( $categories ) || array() === $categories ) {
			return array();
		}

		$ids = wc_get_products(
			array(
				'status'    => self::STATUSES,
				'limit'     => $limit,
				'orderby'   => 'title',
				'order'     => 'ASC',
				'return'    => 'ids',
				'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Admin category search; limited.
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => array_map( 'intval', $categories ),
						'include_children' => false,
					),
				),
			)
		);

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Parent products directly assigned to a `product_cat` term, via
	 * `wc_get_products()` with `include_children` off. Trash and auto-draft are
	 * left out: no link is written for them (ProductCreatorLink).
	 *
	 * @param int $term_id Term id.
	 * @param int $limit   Page size.
	 * @param int $offset  Products to skip.
	 * @return list<int>
	 */
	public function in_category( int $term_id, int $limit, int $offset ): array {
		if ( $term_id < 1 || $limit < 1 ) {
			return array();
		}

		$ids = wc_get_products(
			array(
				'status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'limit'     => $limit,
				'offset'    => max( 0, $offset ),
				'orderby'   => 'ID',
				'order'     => 'ASC',
				'return'    => 'ids',
				'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One category's products, paged.
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => array( $term_id ),
						'include_children' => false,
					),
				),
			)
		);

		return is_array( $ids ) ? array_values( array_map( 'intval', array_filter( $ids, 'is_numeric' ) ) ) : array();
	}

	/**
	 * Loads records for product ids, skipping unknown ids and variations.
	 *
	 * @param array<mixed> $ids Product ids.
	 * @return list<ProductRecord>
	 */
	private function records( array $ids ): array {
		$records = array();

		foreach ( $ids as $id ) {
			$record = is_numeric( $id ) ? $this->find( (int) $id ) : null;

			if ( null !== $record && ! $record->is_variation ) {
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Replaces every link and origin entry with one each for the creator, or
	 * removes them all.
	 *
	 * @param int               $product_id Parent product id.
	 * @param int|null          $creator_id Creator id, or null to remove the link.
	 * @param ProductLinkSource $source     Origin stored with a link.
	 * @throws DatabaseException When the product is missing or the result is not stored as requested.
	 */
	public function write_creator( int $product_id, ?int $creator_id, ProductLinkSource $source = ProductLinkSource::Admin ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			throw new DatabaseException( 'The product for the creator link was not found.', array( 'product_id' => $product_id ) );
		}

		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = is_string( $data['key'] ?? null ) ? $data['key'] : '';

			if ( ( ProductCreatorLink::is_link_key( $key ) || ProductCreatorLink::is_source_key( $key ) ) && is_numeric( $data['id'] ?? null ) ) {
				$product->delete_meta_data_by_mid( (int) $data['id'] );
			}
		}

		if ( null !== $creator_id ) {
			$product->add_meta_data( ProductCreatorLink::META_KEY, (string) $creator_id, true );
			$product->add_meta_data( ProductCreatorLink::SOURCE_META_KEY, $source->value, true );
		}

		$product->save_meta_data();

		if ( ! $this->stored_as( $product_id, $creator_id, $source ) ) {
			throw new DatabaseException( 'The product creator link was not stored as requested.', array( 'product_id' => $product_id ) );
		}
	}

	/**
	 * Drops cached data of the product: WordPress' post and meta cache, the
	 * product transients and WooCommerce's object meta cache. WooCommerce
	 * keys the latter by the cache group `object_<id>` (WC_Data meta cache in
	 * the `products` group) and invalidates it the same way when meta is
	 * changed directly (WC_Post_Data::flush_object_meta_cache()).
	 *
	 * @param int $product_id Product id.
	 */
	public function forget( int $product_id ): void {
		clean_post_cache( $product_id );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'object_' . $product_id );
		}
	}

	/**
	 * Whether the database holds exactly the requested link: one row each with
	 * the exact link and origin keys and values, or no row of either key in
	 * any spelling.
	 *
	 * @param int               $product_id Product id.
	 * @param int|null          $creator_id Expected creator id, or null for none.
	 * @param ProductLinkSource $source     Expected origin with a link.
	 */
	private function stored_as( int $product_id, ?int $creator_id, ProductLinkSource $source ): bool {
		// Local $wpdb so WPCS can verify the query is prepared. The check must
		// see the stored rows, not a cache, and must match every key spelling.
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification read of the rows just written; a cache would defeat it.
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND LOWER(TRIM(meta_key)) IN (%s, %s) ORDER BY meta_key',
				$wpdb->postmeta,
				$product_id,
				ProductCreatorLink::META_KEY,
				ProductCreatorLink::SOURCE_META_KEY
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return false;
		}

		if ( null === $creator_id ) {
			return array() === $rows;
		}

		$stored = array();

		foreach ( $rows as $row ) {
			$stored[] = (string) ( $row['meta_key'] ?? '' ) . '=' . (string) ( $row['meta_value'] ?? '' );
		}

		sort( $stored );

		return array( ProductCreatorLink::META_KEY . '=' . $creator_id, ProductCreatorLink::SOURCE_META_KEY . '=' . $source->value ) === $stored;
	}
}
