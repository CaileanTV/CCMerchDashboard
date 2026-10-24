<?php
/**
 * Stand-in for WooCommerce Product Vendors' helper class (its public 2.x
 * API), loaded only by the migration tests (phase 26, ADR-074), which also
 * define Product Vendors' version constant.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

if ( ! class_exists( 'WC_Product_Vendors_Utils' ) ) {
	/**
	 * The parts of WC_Product_Vendors_Utils the migration calls. Vendor data
	 * is the term meta `vendor_data`; EXTRA_ADMINS stands for administrators
	 * Product Vendors 2.1.80+ stores elsewhere, which only
	 * get_all_vendor_data() reveals.
	 */
	// phpcs:ignore PEAR.NamingConventions.ValidClassName.Invalid -- Product Vendors' class name.
	final class WC_Product_Vendors_Utils {

		/**
		 * Vendor term id → user ids stored outside `vendor_data`.
		 *
		 * @var array<int, list<int>>
		 */
		public static array $extra_admins = array();

		/**
		 * Vendor term id → user ids only get_vendor_admins() knows (2.4.9's
		 * admin meta storage).
		 *
		 * @var array<int, list<int>>
		 */
		public static array $meta_admins = array();

		/**
		 * A vendor's administrators from the admin meta storage (2.4.9).
		 *
		 * @param int $vendor_id Vendor term id.
		 * @return list<int>
		 */
		public static function get_vendor_admins( $vendor_id ): array {
			return self::$meta_admins[ (int) $vendor_id ] ?? array();
		}

		/**
		 * A vendor's data.
		 *
		 * @param int $vendor_id Vendor term id.
		 * @return array<string, mixed>
		 */
		public static function get_vendor_data_by_id( $vendor_id ): array {
			$data = get_term_meta( (int) $vendor_id, 'vendor_data', true );

			return is_array( $data ) ? $data : array();
		}

		/**
		 * The vendors a user administers, keyed by vendor term id.
		 *
		 * @param int $user_id User.
		 * @return array<int, array<string, mixed>>
		 */
		public static function get_all_vendor_data( $user_id ): array {
			$terms   = get_terms(
				array(
					'taxonomy'   => 'wcpv_product_vendors',
					'hide_empty' => false,
				)
			);
			$vendors = array();

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				$data   = self::get_vendor_data_by_id( $term->term_id );
				$admins = $data['admins'] ?? array();
				$admins = is_array( $admins ) ? $admins : explode( ',', (string) $admins );
				$admins = array_merge( array_map( 'intval', $admins ), self::$extra_admins[ $term->term_id ] ?? array() );

				if ( in_array( (int) $user_id, $admins, true ) ) {
					$vendors[ $term->term_id ] = $data;
				}
			}

			return $vendors;
		}
	}
}
