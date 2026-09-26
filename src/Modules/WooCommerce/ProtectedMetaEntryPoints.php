<?php
/**
 * Clear rejections of the protected product meta at WooCommerce's entry points.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use Exception;
use WC_Data;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * ProtectedProductMeta already denies every generic write of the protected
 * meta at the metadata layer, but silently: the rest of a save goes through.
 * These hooks reject the attempt up front with a clear error, where
 * WooCommerce 10.3 allows it (phase 17 step 1):
 * - REST create/update/batch of products and variations
 *   (`woocommerce_rest_pre_insert_*_object`): 400 when the object's
 *   protected entries differ from the stored ones (add, change, rename via
 *   meta id or delete). Not on `/duplicate`, which does not check for errors.
 * - REST `…/variations/generate` (`rest_request_before_callbacks`): 400 when
 *   `meta_data` names a protected key; that route has no pre-insert filter.
 * - CSV import (`woocommerce_product_import_process_item_data`): the row
 *   fails with a message; other rows and other meta import normally.
 * - Duplicate (`woocommerce_duplicate_product_exclude_meta`): the copy starts
 *   without the protected meta.
 */
final class ProtectedMetaEntryPoints {

	/**
	 * Error code of rejected REST requests.
	 */
	public const ERROR_CODE = 'merchboard_protected_product_meta';

	/**
	 * Hooks the checks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'check_rest_object' ), 10, 2 );
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( $this, 'check_rest_object' ), 10, 2 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'check_variation_generation' ), 10, 3 );
		add_filter( 'woocommerce_product_import_process_item_data', array( $this, 'check_import_row' ) );
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'exclude_from_duplicate' ) );
	}

	/**
	 * Rejects a REST save whose protected meta differs from the stored meta.
	 *
	 * @param mixed $product Object about to be saved (or an earlier WP_Error).
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function check_rest_object( mixed $product, mixed $request = null ): mixed {
		if ( ! $product instanceof WC_Data ) {
			return $product;
		}

		if ( $request instanceof WP_REST_Request && 1 === preg_match( '#/duplicate/?$#', $request->get_route() ) ) {
			return $product;
		}

		$wanted = array();

		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = $data['key'] ?? null;

			if ( is_string( $key ) && ProtectedProductMeta::is_protected_key( $key ) ) {
				$wanted[] = $key . "\0" . ( is_scalar( $data['value'] ?? null ) ? (string) $data['value'] : '' );
			}
		}

		return $this->stored( $product->get_id() ) === self::sorted( $wanted ) ? $product : self::error();
	}

	/**
	 * Rejects `…/variations/generate` requests that set protected meta.
	 *
	 * @param mixed $response Response so far (null to continue).
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function check_variation_generation( mixed $response, mixed $handler, mixed $request ): mixed {
		if ( null !== $response || ! $request instanceof WP_REST_Request || 1 !== preg_match( '#^/wc/v\d+/products/\d+/variations/generate/?$#', $request->get_route() ) ) {
			return $response;
		}

		return self::names_protected_key( $request->get_param( 'meta_data' ) ) ? self::error() : $response;
	}

	/**
	 * Fails a CSV import row that sets protected meta.
	 *
	 * @param mixed $data Parsed row.
	 * @return mixed
	 * @throws Exception When the row names a protected key (WooCommerce reports it for the row).
	 */
	public function check_import_row( mixed $data ): mixed {
		if ( is_array( $data ) && self::names_protected_key( $data['meta_data'] ?? null ) ) {
			throw new Exception( esc_html__( 'The creator of a product can only be changed in Merch Dashboard.', 'merchboard' ) );
		}

		return $data;
	}

	/**
	 * Keeps the protected meta out of a duplicated product and its variations.
	 *
	 * @param mixed $exclude Meta keys WooCommerce does not copy.
	 * @return list<mixed>
	 */
	public function exclude_from_duplicate( mixed $exclude ): array {
		return array_values( array_unique( array_merge( is_array( $exclude ) ? $exclude : array(), ProtectedProductMeta::KEYS ) ) );
	}

	/**
	 * Stored protected entries of a product as sorted "key\0value" strings.
	 *
	 * @param int $product_id Product id (0 for a new product).
	 * @return list<string>
	 */
	private function stored( int $product_id ): array {
		$entries = array();

		foreach ( $product_id > 0 ? get_post_meta( $product_id ) : array() as $key => $values ) {
			if ( ProtectedProductMeta::is_protected_key( $key ) && is_array( $values ) ) {
				foreach ( $values as $value ) {
					$entries[] = $key . "\0" . ( is_scalar( $value ) ? (string) $value : '' );
				}
			}
		}

		return self::sorted( $entries );
	}

	/**
	 * Whether a `meta_data` list names a protected key.
	 *
	 * @param mixed $meta_data List of `{key, value}` entries.
	 */
	private static function names_protected_key( mixed $meta_data ): bool {
		if ( ! is_array( $meta_data ) ) {
			return false;
		}

		foreach ( $meta_data as $meta ) {
			if ( is_array( $meta ) && ProtectedProductMeta::is_protected_key( $meta['key'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sorted copy.
	 *
	 * @param string[] $entries Entries.
	 * @return list<string>
	 */
	private static function sorted( array $entries ): array {
		sort( $entries );

		return $entries;
	}

	/**
	 * The REST rejection.
	 */
	private static function error(): WP_Error {
		return new WP_Error(
			self::ERROR_CODE,
			__( 'The creator of a product can only be changed in Merch Dashboard.', 'merchboard' ),
			array( 'status' => 400 )
		);
	}
}
