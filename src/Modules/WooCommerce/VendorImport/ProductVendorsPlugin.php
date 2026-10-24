<?php
/**
 * WooCommerce Product Vendors, read through its own API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Product Vendors while it is active (ADR-074 pkt. 2): its vendor
 * taxonomy, its own `WC_Product_Vendors_Utils` for the administrators (from
 * version 2.1.80 they are stored where only Product Vendors knows) and the
 * products with the vendor's term. Never writes.
 *
 * A vendor's administrators are the union of `get_vendor_admins()` (newer
 * versions, e.g. 2.4.9 with its admin meta storage, where `vendor_data`'s
 * own `admins` is ignored), the vendor's data
 * (`get_vendor_data_by_id()['admins']`, older versions) and the vendors
 * Product Vendors gives each user with one of its roles
 * (`get_all_vendor_data()`). Each value is normalized as Product Vendors
 * does: a list, a comma-separated text or one id.
 */
final class ProductVendorsPlugin implements ProductVendorsSource {

	/**
	 * Product Vendors' vendor taxonomy.
	 */
	public const TAXONOMY = 'wcpv_product_vendors';

	/**
	 * Product Vendors' helper class.
	 */
	public const UTILS = 'WC_Product_Vendors_Utils';

	/**
	 * Vendors per user (filled once per instance).
	 *
	 * @var array<int, list<int>>|null
	 */
	private ?array $by_user = null;

	/**
	 * Whether Product Vendors is active.
	 */
	public function active(): bool {
		return class_exists( self::UTILS ) && taxonomy_exists( self::TAXONOMY );
	}

	/**
	 * Product Vendors' version.
	 */
	public function version(): string {
		return defined( 'WC_PRODUCT_VENDORS_VERSION' ) && is_scalar( constant( 'WC_PRODUCT_VENDORS_VERSION' ) ) ? (string) constant( 'WC_PRODUCT_VENDORS_VERSION' ) : '';
	}

	/**
	 * Every vendor, by name.
	 *
	 * @return list<array{term_id: int, name: string}>
	 * @throws VendorImportException When the vendors cannot be read.
	 */
	public function vendors(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( ! is_array( $terms ) ) {
			throw new VendorImportException( VendorImportException::UNREADABLE );
		}

		$vendors = array();

		foreach ( $terms as $term ) {
			$vendors[] = array(
				'term_id' => $term->term_id,
				'name'    => $term->name,
			);
		}

		return $vendors;
	}

	/**
	 * The user ids of a vendor's administrators.
	 *
	 * @param int $term_id Vendor.
	 * @return list<int>
	 */
	public function admins( int $term_id ): array {
		$data  = self::call( 'get_vendor_data_by_id', $term_id );
		$ids   = array_merge( self::admin_ids( self::call( 'get_vendor_admins', $term_id ) ), self::admin_ids( is_array( $data ) ? ( $data['admins'] ?? null ) : null ) );
		$users = $this->vendors_by_user();

		foreach ( $users as $user_id => $vendors ) {
			if ( in_array( $term_id, $vendors, true ) ) {
				$ids[] = $user_id;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return array_values( array_filter( $ids, static fn( int $id ): bool => get_userdata( $id ) instanceof WP_User ) );
	}

	/**
	 * Product Vendors' roles of a user.
	 *
	 * @param int $user_id User.
	 * @return list<string>
	 */
	public function roles( int $user_id ): array {
		$user = get_userdata( $user_id );

		return $user instanceof WP_User ? array_values( array_intersect( self::ROLES, $user->roles ) ) : array();
	}

	/**
	 * A vendor's parent products.
	 *
	 * @param int $term_id Vendor.
	 * @return list<int>
	 */
	public function product_ids( int $term_id ): array {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One vendor, in the administrator's dry run.
					array(
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_id',
						'terms'            => array( $term_id ),
						'include_children' => false,
					),
				),
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * User ids from Product Vendors' admins value: a list, a comma-separated
	 * text or one id, as Product Vendors itself reads it.
	 *
	 * @param mixed $admins The value.
	 * @return list<int>
	 */
	public static function admin_ids( mixed $admins ): array {
		if ( is_int( $admins ) || ( is_string( $admins ) && '' !== trim( $admins ) ) ) {
			$admins = explode( ',', (string) $admins );
		}

		if ( ! is_array( $admins ) ) {
			return array();
		}

		$ids = array();

		foreach ( $admins as $admin ) {
			$id = is_int( $admin ) || ( is_string( $admin ) && ctype_digit( trim( $admin ) ) ) ? (int) trim( (string) $admin ) : 0;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The vendors Product Vendors gives each user with one of its roles.
	 *
	 * @return array<int, list<int>>
	 */
	private function vendors_by_user(): array {
		if ( null !== $this->by_user ) {
			return $this->by_user;
		}

		$this->by_user = array();
		$users         = get_users(
			array(
				'role__in' => self::ROLES,
				'fields'   => 'ID',
			)
		);

		foreach ( $users as $user_id ) {
			$user_id = (int) $user_id;
			$data    = self::call( 'get_all_vendor_data', $user_id );
			$vendors = array();

			foreach ( is_array( $data ) ? $data : array() as $key => $vendor ) {
				$term_id = is_array( $vendor ) && is_numeric( $vendor['term_id'] ?? null ) ? (int) $vendor['term_id'] : ( is_int( $key ) ? $key : 0 );

				if ( $term_id > 0 ) {
					$vendors[] = $term_id;
				}
			}

			$this->by_user[ $user_id ] = array_values( array_unique( $vendors ) );
		}

		return $this->by_user;
	}

	/**
	 * Calls a static method of Product Vendors' helper class; null when it
	 * does not exist.
	 *
	 * @param string $method Method.
	 * @param int    $id     Its argument.
	 */
	private static function call( string $method, int $id ): mixed {
		$callback = array( self::UTILS, $method );

		return is_callable( $callback ) ? call_user_func( $callback, $id ) : null;
	}
}
