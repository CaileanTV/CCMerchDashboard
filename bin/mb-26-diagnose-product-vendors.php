<?php
/**
 * Merch Dashboard, phase 26: READ-ONLY look at where WooCommerce Product Vendors keeps a vendor's
 * administrators in this version (from 2.1.80 the place is not public, ADR-074).
 * Development tool: not part of the plugin package (bin/ is not shipped).
 *
 * Run by the project owner on the local test site, after bin/mb-26-seed-product-vendors.php:
 *   wp eval-file bin/mb-26-diagnose-product-vendors.php
 *
 * Reads only, and only the test data ("MB26 …" vendors and "mb26_…" users), so the output holds
 * no real vendor's data. It prints Product Vendors' version, its helper methods, the term meta keys
 * of the test vendors with the shape of every value that may hold administrators, what Product
 * Vendors' own helpers return, and the vendor-related user meta of one test user.
 *
 * @package MerchBoard
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! function_exists( 'merchboard_mb26_shape' ) ) {

	/**
	 * A value's shape for the output: type and content, e-mail addresses masked.
	 *
	 * @param mixed $value Value.
	 */
	function merchboard_mb26_shape( mixed $value ): string {
		$json = wp_json_encode( $value );

		return gettype( $value ) . ' ' . (string) preg_replace( '/[^"\s,]+@[^"\s,]+/', '<e-mail>', (string) $json );
	}
}

$merchboard_utils = 'WC_Product_Vendors_Utils';

WP_CLI::log( '== Product Vendors' );
WP_CLI::log( 'Version: ' . ( defined( 'WC_PRODUCT_VENDORS_VERSION' ) ? (string) constant( 'WC_PRODUCT_VENDORS_VERSION' ) : 'unknown' ) );
WP_CLI::log( 'Taxonomy wcpv_product_vendors registered: ' . ( taxonomy_exists( 'wcpv_product_vendors' ) ? 'yes' : 'no' ) );

if ( ! class_exists( $merchboard_utils ) ) {
	WP_CLI::error( 'WC_Product_Vendors_Utils does not exist: is Product Vendors active?' );
}

$merchboard_methods = array_values( array_filter( get_class_methods( $merchboard_utils ), static fn( string $method ): bool => 1 === preg_match( '/vendor|admin|manager/i', $method ) ) );
WP_CLI::log( 'Helper methods with vendor/admin/manager: ' . implode( ', ', $merchboard_methods ) );

$merchboard_terms = get_terms(
	array(
		'taxonomy'   => 'wcpv_product_vendors',
		'hide_empty' => false,
		'name__like' => 'MB26',
		'number'     => 3,
	)
);

foreach ( is_array( $merchboard_terms ) ? $merchboard_terms : array() as $merchboard_term ) {
	if ( ! $merchboard_term instanceof WP_Term || ! str_starts_with( $merchboard_term->name, 'MB26 ' ) ) {
		continue;
	}

	WP_CLI::log( '' );
	WP_CLI::log( '== Vendor ' . $merchboard_term->name . ' (term ' . $merchboard_term->term_id . ')' );
	$merchboard_meta = get_term_meta( $merchboard_term->term_id );
	WP_CLI::log( 'Term meta keys: ' . implode( ', ', array_keys( is_array( $merchboard_meta ) ? $merchboard_meta : array() ) ) );

	foreach ( is_array( $merchboard_meta ) ? $merchboard_meta : array() as $merchboard_key => $merchboard_values ) {
		if ( 1 === preg_match( '/admin|manager|user|vendor_data/i', (string) $merchboard_key ) ) {
			foreach ( (array) $merchboard_values as $merchboard_value ) {
				$merchboard_value = maybe_unserialize( $merchboard_value );
				$merchboard_value = is_array( $merchboard_value ) && array_key_exists( 'admins', $merchboard_value ) ? array( 'admins' => $merchboard_value['admins'], 'other keys' => array_keys( $merchboard_value ) ) : $merchboard_value; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short output.
				WP_CLI::log( '  ' . $merchboard_key . ': ' . merchboard_mb26_shape( $merchboard_value ) );
			}
		}
	}

	if ( is_callable( array( $merchboard_utils, 'get_vendor_data_by_id' ) ) ) {
		$merchboard_data = call_user_func( array( $merchboard_utils, 'get_vendor_data_by_id' ), $merchboard_term->term_id );
		WP_CLI::log( 'get_vendor_data_by_id keys: ' . ( is_array( $merchboard_data ) ? implode( ', ', array_keys( $merchboard_data ) ) : gettype( $merchboard_data ) ) );
		WP_CLI::log( 'get_vendor_data_by_id admins: ' . merchboard_mb26_shape( is_array( $merchboard_data ) ? ( $merchboard_data['admins'] ?? null ) : null ) );
	}

	foreach ( $merchboard_methods as $merchboard_method ) {
		if ( 1 === preg_match( '/admins|managers|vendor_users|get_vendor_admins/i', $merchboard_method ) ) {
			try {
				WP_CLI::log( $merchboard_method . '( term ): ' . merchboard_mb26_shape( call_user_func( array( $merchboard_utils, $merchboard_method ), $merchboard_term->term_id ) ) );
			} catch ( Throwable $merchboard_e ) {
				WP_CLI::log( $merchboard_method . '( term ): ' . get_class( $merchboard_e ) );
			}
		}
	}
}

$merchboard_user = get_user_by( 'login', 'mb26_nova_admin' );

if ( $merchboard_user instanceof WP_User ) {
	WP_CLI::log( '' );
	WP_CLI::log( '== User mb26_nova_admin (' . $merchboard_user->ID . ')' );
	WP_CLI::log( 'Roles: ' . implode( ', ', $merchboard_user->roles ) );

	foreach ( get_user_meta( $merchboard_user->ID ) as $merchboard_key => $merchboard_values ) {
		if ( 1 === preg_match( '/wcpv|vendor/i', (string) $merchboard_key ) ) {
			WP_CLI::log( '  ' . $merchboard_key . ': ' . merchboard_mb26_shape( maybe_unserialize( $merchboard_values[0] ?? null ) ) );
		}
	}

	if ( is_callable( array( $merchboard_utils, 'get_all_vendor_data' ) ) ) {
		$merchboard_all = call_user_func( array( $merchboard_utils, 'get_all_vendor_data' ), $merchboard_user->ID );
		WP_CLI::log( 'get_all_vendor_data keys: ' . ( is_array( $merchboard_all ) ? implode( ', ', array_map( 'strval', array_keys( $merchboard_all ) ) ) : gettype( $merchboard_all ) ) );
	}
}

WP_CLI::success( 'Done. Nothing was changed.' );
