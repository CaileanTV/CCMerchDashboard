<?php
/**
 * Merch Dashboard, phase 26: test data in WooCommerce Product Vendors for the migration.
 * Development tool: not part of the plugin package (bin/ is not shipped).
 *
 * Run by the project owner on a LOCAL site with WooCommerce and Product Vendors active:
 *   wp eval-file bin/mb-26-seed-product-vendors.php            creates the test data
 *   wp eval-file bin/mb-26-seed-product-vendors.php cleanup    removes it again
 *
 * Refuses unless wp_get_environment_type() is "local" or "development". Everything it makes is
 * named "MB26 …" / "mb26_…" and marked with the meta key `_mb26_seed`, and only marked data is
 * removed by cleanup. Users that a migration has linked to a creator are kept by cleanup (delete
 * the creator in Merch Dashboard first), so no creator is left with a missing user.
 *
 * Every vendor has its own account (`mb26_<vendor>`, Vendor Admin; the pending vendor's is
 * pending), except "MB26 No Admin", which tests a vendor without an administrator. All accounts get
 * the password PASSWORD, so you can log in as a vendor and, after the migration, as the creator;
 * the script prints the logins. That is why it refuses to run outside a local site.
 *
 * The vendors cover every case of the dry run (ADR-074): several administrators, a pending vendor,
 * no administrator, one user on two vendors (a Vendor Manager on both), simple and variable
 * products, a draft and a trashed product, and ten ordinary vendors for volume. Administrators are
 * written in `vendor_data` (Product Vendors 2.x); open a vendor under Products → Vendors and click
 * Update to have Product Vendors store them its own way. Running it again adds what is missing.
 *
 * @package MerchBoard
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! function_exists( 'merchboard_mb26_seed' ) ) {

	// The password of every test account (local sites only).
	define( 'MERCHBOARD_MB26_PASSWORD', 'MB26-test-1234' );

	/**
	 * The accounts of this run, for the printed list.
	 *
	 * @param array<string, string>|null $add Login, role and vendor to add; null to read.
	 * @return list<array<string, string>>
	 */
	function merchboard_mb26_accounts( ?array $add = null ): array {
		static $accounts = array();

		if ( null !== $add ) {
			$accounts[] = $add;
		}

		return $accounts;
	}

	/**
	 * A test account with a Product Vendors role and the test password.
	 *
	 * @param string $login  Login without the prefix.
	 * @param string $role   Product Vendors role.
	 * @param string $vendor Vendor(s) it belongs to, for the printed list.
	 */
	function merchboard_mb26_user( string $login, string $role, string $vendor ): int {
		$login    = 'mb26_' . $login;
		$existing = get_user_by( 'login', $login );

		merchboard_mb26_accounts(
			array(
				'login'  => $login,
				'role'   => str_replace( 'wc_product_vendors_', '', $role ),
				'vendor' => $vendor,
			)
		);

		if ( $existing instanceof WP_User ) {
			if ( '1' === get_user_meta( $existing->ID, '_mb26_seed', true ) ) {
				wp_set_password( MERCHBOARD_MB26_PASSWORD, $existing->ID );
			}

			return $existing->ID;
		}

		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $login . '@example.test',
				'user_pass'    => MERCHBOARD_MB26_PASSWORD,
				'display_name' => ucwords( str_replace( '_', ' ', substr( $login, 5 ) ) ),
				'role'         => $role,
			)
		);

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( 'Could not create ' . $login . ': ' . $id->get_error_message() );
		}

		update_user_meta( $id, '_mb26_seed', '1' );

		return $id;
	}

	/**
	 * Stores a vendor's administrators the way this Product Vendors version
	 * does: its own update_vendor_admins() or set_vendor_admin() (2.4.9 keeps
	 * them in its admin meta storage and ignores `vendor_data`'s admins). The
	 * parameter order is read from the method, as it is not documented.
	 * Returns what get_vendor_admins() reads back afterwards (null when that
	 * method does not exist).
	 *
	 * @phpstan-param list<int> $admins
	 *
	 * @param int   $term_id Vendor.
	 * @param int[] $admins  Administrator user ids.
	 * @return list<int>|null
	 */
	function merchboard_mb26_store_admins( int $term_id, array $admins ): ?array {
		$utils = 'WC_Product_Vendors_Utils';

		/*
		 * The arguments of a method, by the names of its parameters: the vendor
		 * (vendor/term) and the administrators (admin/user).
		 */
		$call = static function ( string $method, mixed $users ) use ( $utils, $term_id ): bool {
			if ( ! method_exists( $utils, $method ) ) {
				return false;
			}

			$arguments = array();

			foreach ( ( new ReflectionMethod( $utils, $method ) )->getParameters() as $parameter ) {
				$name = strtolower( $parameter->getName() );

				if ( str_contains( $name, 'vendor' ) || str_contains( $name, 'term' ) ) {
					$arguments[] = $term_id;
				} elseif ( str_contains( $name, 'admin' ) || str_contains( $name, 'user' ) ) {
					$arguments[] = $users;
				} elseif ( $parameter->isOptional() ) {
					break;
				} else {
					WP_CLI::warning( $method . '() has the unknown parameter $' . $parameter->getName() . '; administrators not stored.' );

					return false;
				}
			}

			call_user_func_array( array( $utils, $method ), $arguments );

			return true;
		};

		if ( ! $call( 'update_vendor_admins', $admins ) ) {
			foreach ( $admins as $admin ) {
				$call( 'set_vendor_admin', $admin );
			}
		}

		if ( ! method_exists( $utils, 'get_vendor_admins' ) ) {
			return null;
		}

		$stored = call_user_func( array( $utils, 'get_vendor_admins' ), $term_id );

		return array_values( array_map( 'intval', is_array( $stored ) ? $stored : explode( ',', (string) $stored ) ) );
	}

	/**
	 * A test vendor with its administrators in `vendor_data` (older versions)
	 * and in Product Vendors' own admin storage (newer versions).
	 *
	 * @phpstan-param list<int> $admins
	 *
	 * @param string $name   Vendor name without the prefix.
	 * @param int[]  $admins Administrator user ids.
	 */
	function merchboard_mb26_vendor( string $name, array $admins ): int {
		$name     = 'MB26 ' . $name;
		$existing = get_term_by( 'name', $name, 'wcpv_product_vendors' );

		if ( $existing instanceof WP_Term ) {
			if ( '1' === get_term_meta( $existing->term_id, '_mb26_seed', true ) ) {
				$data           = get_term_meta( $existing->term_id, 'vendor_data', true );
				$data           = is_array( $data ) ? $data : array();
				$data['admins'] = $admins;
				update_term_meta( $existing->term_id, 'vendor_data', $data );
				merchboard_mb26_check_admins( $name, $admins, merchboard_mb26_store_admins( $existing->term_id, $admins ) );
			}

			return $existing->term_id;
		}

		$term = wp_insert_term( $name, 'wcpv_product_vendors' );

		if ( is_wp_error( $term ) ) {
			WP_CLI::error( 'Could not create ' . $name . ': ' . $term->get_error_message() );
		}

		$slug = sanitize_title( $name );
		update_term_meta(
			$term['term_id'],
			'vendor_data',
			array(
				'admins'          => $admins,
				'email'           => $slug . '@example.test',
				'commission'      => '20',
				'commission_type' => 'percentage',
				'paypal'          => $slug . '@example.test',
				'profile'         => 'Test vendor for the Merch Dashboard migration.',
			)
		);
		update_term_meta( $term['term_id'], '_mb26_seed', '1' );
		merchboard_mb26_check_admins( $name, $admins, merchboard_mb26_store_admins( (int) $term['term_id'], $admins ) );

		return (int) $term['term_id'];
	}

	/**
	 * Warns when Product Vendors does not read back the administrators.
	 *
	 * @phpstan-param list<int> $expected
	 * @phpstan-param list<int>|null $stored
	 *
	 * @param string     $name     Vendor name.
	 * @param int[]      $expected Administrator user ids.
	 * @param int[]|null $stored   What get_vendor_admins() read back (null: not available).
	 */
	function merchboard_mb26_check_admins( string $name, array $expected, ?array $stored ): void {
		if ( null === $stored ) {
			return;
		}

		$stored = array_values( array_filter( $stored ) );
		sort( $stored );
		sort( $expected );

		if ( $stored !== $expected ) {
			WP_CLI::warning( $name . ': Product Vendors reads back administrators [' . implode( ', ', $stored ) . '], expected [' . implode( ', ', $expected ) . ']. Set them under Products → Vendors.' );
		}
	}

	/**
	 * A test product of a vendor.
	 *
	 * @param int    $vendor Vendor term id.
	 * @param string $name   Product name.
	 * @param string $status Post status.
	 * @param bool   $sizes  A variable product with sizes S and M.
	 */
	function merchboard_mb26_product( int $vendor, string $name, string $status = 'publish', bool $sizes = false ): int {
		$found = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'title'            => 'MB26 ' . $name,
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		if ( array() !== $found ) {
			return (int) $found[0];
		}

		$product = $sizes ? new WC_Product_Variable() : new WC_Product_Simple();
		$product->set_name( 'MB26 ' . $name );
		$product->set_status( 'trash' === $status ? 'publish' : $status );

		if ( $sizes ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( 'Size' );
			$attribute->set_options( array( 'S', 'M' ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$product->set_attributes( array( $attribute ) );
		} else {
			$product->set_regular_price( '199' );
		}

		$product->update_meta_data( '_mb26_seed', '1' );
		$id = $product->save();

		if ( $sizes ) {
			foreach ( array( 'S', 'M' ) as $size ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $id );
				$variation->set_attributes( array( 'size' => $size ) );
				$variation->set_regular_price( '249' );
				$variation->update_meta_data( '_mb26_seed', '1' );
				$variation->save();
			}
		}

		wp_set_object_terms( $id, array( $vendor ), 'wcpv_product_vendors' );

		if ( 'trash' === $status ) {
			wp_trash_post( $id );
		}

		return $id;
	}

	/**
	 * Creates the test data.
	 */
	function merchboard_mb26_seed(): void {
		$admin   = 'wc_product_vendors_admin_vendor';
		$manager = 'wc_product_vendors_manager_vendor';
		$pending = 'wc_product_vendors_pending_vendor';

		// Several administrators: the dry run proposes the Vendor Admin and lets you choose.
		$nova = merchboard_mb26_vendor( 'Nova Merch', array( merchboard_mb26_user( 'nova_admin', $admin, 'Nova Merch' ), merchboard_mb26_user( 'nova_manager', $manager, 'Nova Merch' ) ) );
		merchboard_mb26_product( $nova, 'Nova Hoodie' );
		merchboard_mb26_product( $nova, 'Nova Tee', 'publish', true );
		merchboard_mb26_product( $nova, 'Nova Cap (draft)', 'draft' );

		// Link mb26_orion to a creator before the dry run to see "uses the user's creator".
		$orion = merchboard_mb26_vendor( 'Orion Shop', array( merchboard_mb26_user( 'orion', $admin, 'Orion Shop' ) ) );
		merchboard_mb26_product( $orion, 'Orion Poster' );
		merchboard_mb26_product( $orion, 'Orion Mug' );

		// Waiting for approval: skipped.
		$pia = merchboard_mb26_vendor( 'Pending Pia', array( merchboard_mb26_user( 'pia', $pending, 'Pending Pia' ) ) );
		merchboard_mb26_product( $pia, 'Pia Sticker' );

		$ghost = merchboard_mb26_vendor( 'Ghost Store', array( merchboard_mb26_user( 'ghost_store', $admin, 'Ghost Store' ) ) );
		merchboard_mb26_product( $ghost, 'Ghost Bag' );
		merchboard_mb26_product( $ghost, 'Ghost Pin' );

		// No administrator on purpose: a creator without a user.
		$no_admin = merchboard_mb26_vendor( 'No Admin', array() );
		merchboard_mb26_product( $no_admin, 'No Admin Keychain' );

		// One user on two vendors (a Vendor Manager on both): choose it for both to see the second skipped.
		$shared = merchboard_mb26_user( 'shared_manager', $manager, 'Shared A + Shared B' );
		merchboard_mb26_product( merchboard_mb26_vendor( 'Shared A', array( merchboard_mb26_user( 'shared_a', $admin, 'Shared A' ), $shared ) ), 'Shared A Tee' );
		merchboard_mb26_product( merchboard_mb26_vendor( 'Shared B', array( merchboard_mb26_user( 'shared_b', $admin, 'Shared B' ), $shared ) ), 'Shared B Tee' );

		// A trashed product is never linked.
		$luna = merchboard_mb26_vendor( 'Luna Prints', array( merchboard_mb26_user( 'luna', $admin, 'Luna Prints' ) ) );
		merchboard_mb26_product( $luna, 'Luna Print A3' );
		merchboard_mb26_product( $luna, 'Luna Print A4' );
		merchboard_mb26_product( $luna, 'Luna Old Print (trash)', 'trash' );

		// A vendor without products.
		merchboard_mb26_vendor( 'Empty Vendor', array( merchboard_mb26_user( 'empty_admin', $admin, 'Empty Vendor' ) ) );

		// Volume.
		for ( $i = 1; $i <= 10; $i++ ) {
			$number = sprintf( '%02d', $i );
			$vendor = merchboard_mb26_vendor( 'Creator ' . $number, array( merchboard_mb26_user( 'creator_' . $number, $admin, 'Creator ' . $number ) ) );
			merchboard_mb26_product( $vendor, 'Creator ' . $number . ' Tee' );
			merchboard_mb26_product( $vendor, 'Creator ' . $number . ' Hoodie', 'publish', 0 === $i % 3 );
		}

		WP_CLI::success( 'Test data ready: 19 vendors, 20 accounts and their products (all named MB26 / mb26_).' );
		WP_CLI::log( 'Every account has the password ' . MERCHBOARD_MB26_PASSWORD . ' (local test site only):' );
		WP_CLI\Utils\format_items( 'table', merchboard_mb26_accounts(), array( 'login', 'role', 'vendor' ) );
		WP_CLI::log( 'Optional, before the dry run:' );
		WP_CLI::log( '- Merch Dashboard → Creators: create a creator linked to the user mb26_orion ("uses the user\'s creator").' );
		WP_CLI::log( '- Link "MB26 Orion Mug" to another creator on that creator\'s page ("left at another creator").' );
		WP_CLI::log( 'Then: Merch Dashboard → Import from Product Vendors → Dry run.' );
	}

	/**
	 * Removes the marked test data.
	 */
	function merchboard_mb26_cleanup(): void {
		$posts = get_posts(
			array(
				'post_type'        => array( 'product', 'product_variation' ),
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_key'         => '_mb26_seed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Development tool.
			)
		);

		foreach ( $posts as $id ) {
			wp_delete_post( (int) $id, true );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'wcpv_product_vendors',
				'hide_empty' => false,
				'meta_key'   => '_mb26_seed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Development tool.
				'fields'     => 'ids',
			)
		);

		foreach ( is_array( $terms ) ? $terms : array() as $id ) {
			wp_delete_term( (int) $id, 'wcpv_product_vendors' );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$kept = array();

		foreach ( get_users( array( 'meta_key' => '_mb26_seed' ) ) as $user ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Development tool.
			if ( in_array( 'merchboard_creator', $user->roles, true ) ) {
				$kept[] = $user->user_login;
				continue;
			}

			wp_delete_user( $user->ID );
		}

		WP_CLI::success( sprintf( 'Removed %d products and variations and %d vendors.', count( $posts ), is_array( $terms ) ? count( $terms ) : 0 ) );

		if ( array() !== $kept ) {
			WP_CLI::warning( 'Kept users linked to a creator (delete the creators in Merch Dashboard first, then run cleanup again): ' . implode( ', ', $kept ) );
		}
	}
}

if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	WP_CLI::error( 'Only for a local or development site (WP_ENVIRONMENT_TYPE is "' . wp_get_environment_type() . '").' );
}

if ( ! taxonomy_exists( 'wcpv_product_vendors' ) || ! class_exists( 'WC_Product_Simple' ) ) {
	WP_CLI::error( 'Activate WooCommerce and WooCommerce Product Vendors first.' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP-CLI passes the arguments of eval-file as $args.
if ( in_array( 'cleanup', $args ?? array(), true ) ) {
	merchboard_mb26_cleanup();
} else {
	merchboard_mb26_seed();
}
