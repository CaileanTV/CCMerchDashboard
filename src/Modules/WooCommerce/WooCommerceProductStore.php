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

		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = is_string( $data['key'] ?? null ) ? $data['key'] : '';

			if ( ProductCreatorLink::is_link_key( $key ) ) {
				$entries[] = array(
					'key'   => $key,
					'value' => is_scalar( $data['value'] ?? null ) ? (string) $data['value'] : '',
				);
			}
		}

		return new ProductRecord(
			$product->get_id(),
			$product->get_parent_id( 'edit' ),
			$product->is_type( 'variation' ),
			$product->get_status( 'edit' ),
			$entries
		);
	}

	/**
	 * Replaces every link entry with one for the creator, or removes them all.
	 *
	 * @param int      $product_id Parent product id.
	 * @param int|null $creator_id Creator id, or null to remove the link.
	 * @throws DatabaseException When the product is missing or the result is not stored as requested.
	 */
	public function write_creator( int $product_id, ?int $creator_id ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			throw new DatabaseException( 'The product for the creator link was not found.', array( 'product_id' => $product_id ) );
		}

		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();

			if ( is_string( $data['key'] ?? null ) && ProductCreatorLink::is_link_key( $data['key'] ) && is_numeric( $data['id'] ?? null ) ) {
				$product->delete_meta_data_by_mid( (int) $data['id'] );
			}
		}

		if ( null !== $creator_id ) {
			$product->add_meta_data( ProductCreatorLink::META_KEY, (string) $creator_id, true );
		}

		$product->save_meta_data();

		if ( ! $this->stored_as( $product_id, $creator_id ) ) {
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
	 * Whether the database holds exactly the requested link: one row with the
	 * exact key and value, or no row in any key spelling.
	 *
	 * @param int      $product_id Product id.
	 * @param int|null $creator_id Expected creator id, or null for none.
	 */
	private function stored_as( int $product_id, ?int $creator_id ): bool {
		// Local $wpdb so WPCS can verify the query is prepared. The check must
		// see the stored rows, not a cache, and must match every key spelling.
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verification read of the rows just written; a cache would defeat it.
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND LOWER(TRIM(meta_key)) = %s',
				$wpdb->postmeta,
				$product_id,
				ProductCreatorLink::META_KEY
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return false;
		}

		if ( null === $creator_id ) {
			return array() === $rows;
		}

		$row = 1 === count( $rows ) ? $rows[0] : array();

		return ProductCreatorLink::META_KEY === ( $row['meta_key'] ?? null )
			&& 0 === strcmp( (string) $creator_id, (string) ( $row['meta_value'] ?? '' ) );
	}
}
