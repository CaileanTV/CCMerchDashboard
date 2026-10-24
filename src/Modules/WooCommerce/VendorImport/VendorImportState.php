<?php
/**
 * What the migration from Product Vendors has done.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

defined( 'ABSPATH' ) || exit;

/**
 * The option OPTION (not autoloaded, deleted on opt-in uninstall), read
 * fresh and normalized (ADR-074):
 *
 * - `vendors`: vendor term → creator;
 * - `users`: vendor term → the user the migration linked (0: none); the
 *   role removal (26.3) only touches that user;
 * - `pending`: vendor term → chosen user, written before a creator is made,
 *   so an interrupted run is recognized (26.4 review);
 * - `created`: creators the migration made; `settled`: those whose
 *   settlement start was set (once);
 * - `products`: vendor term → products the migration linked; `categories`:
 *   vendor term → the category it mapped. A later run never links them
 *   again, so what the administrator removed afterwards stays removed (the
 *   project owner's choice 2026-10-25);
 * - `completed_at` and `last`: the last run.
 *
 * @phpstan-type State array{vendors: array<int, int>, users: array<int, int>, pending: array<int, int>, created: list<int>, settled: list<int>, products: array<int, list<int>>, categories: array<int, int>, completed_at?: string, last?: array<string, int>}
 */
final class VendorImportState {

	/**
	 * The option.
	 */
	public const OPTION = 'merchboard_vendor_import';

	/**
	 * The stored state, normalized.
	 *
	 * @return State
	 */
	public static function load(): array {
		wp_cache_delete( self::OPTION, 'options' );
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = array(
			'vendors'    => self::id_map( $stored['vendors'] ?? null, 1 ),
			'users'      => self::id_map( $stored['users'] ?? null, 0 ),
			'pending'    => self::id_map( $stored['pending'] ?? null, 0 ),
			'created'    => self::ids( $stored['created'] ?? null ),
			'settled'    => self::ids( $stored['settled'] ?? null ),
			'products'   => array(),
			'categories' => self::id_map( $stored['categories'] ?? null, 1 ),
		);

		foreach ( is_array( $stored['products'] ?? null ) ? $stored['products'] : array() as $term_id => $products ) {
			if ( is_int( $term_id ) && $term_id > 0 ) {
				$state['products'][ $term_id ] = self::ids( $products );
			}
		}

		if ( is_string( $stored['completed_at'] ?? null ) ) {
			$state['completed_at'] = $stored['completed_at'];
		}

		if ( is_array( $stored['last'] ?? null ) ) {
			$state['last'] = array_map( 'intval', array_filter( $stored['last'], 'is_numeric' ) );
		}

		return $state;
	}

	/**
	 * Stores the state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	public static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Positive ids from a stored list.
	 *
	 * @param mixed $value Stored value.
	 * @return list<int>
	 */
	private static function ids( mixed $value ): array {
		return array_values( array_filter( is_array( $value ) ? $value : array(), static fn( mixed $id ): bool => is_int( $id ) && $id > 0 ) );
	}

	/**
	 * Positive id → id map (values from $min).
	 *
	 * @param mixed $value Stored value.
	 * @param int   $min   Smallest value kept.
	 * @return array<int, int>
	 */
	private static function id_map( mixed $value, int $min ): array {
		$map = array();

		foreach ( is_array( $value ) ? $value : array() as $key => $id ) {
			if ( is_int( $key ) && $key > 0 && is_int( $id ) && $id >= $min ) {
				$map[ $key ] = $id;
			}
		}

		return $map;
	}
}
