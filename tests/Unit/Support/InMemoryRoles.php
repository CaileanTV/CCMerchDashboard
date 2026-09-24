<?php
/**
 * In-memory WordPress role store.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use Brain\Monkey\Functions;
use WP_Role;

/**
 * Backs get_role(), add_role() and remove_role() with an array of WP_Role
 * objects, with WordPress' semantics (add_role() returns null when the role
 * already exists). Also offers a role-based capability check that mirrors
 * how WordPress resolves a user's capabilities from their roles, so tests can
 * assert positive and negative permission outcomes of the stored roles.
 */
final class InMemoryRoles {

	/**
	 * Roles by name.
	 *
	 * @var array<string, WP_Role>
	 */
	public array $roles = array();

	/**
	 * Display names by role.
	 *
	 * @var array<string, string>
	 */
	public array $names = array();

	/**
	 * Number of add_role() calls.
	 *
	 * @var int
	 */
	public int $add_role_calls = 0;

	/**
	 * Installs the Brain Monkey aliases. Call inside a test (after setUp).
	 *
	 * @param array<string, array<string, bool>> $roles        Initial roles: name => capabilities.
	 * @param bool                               $fail_add_role Make add_role() fail.
	 */
	public static function install( array $roles = array(), bool $fail_add_role = false ): self {
		$store = new self();

		foreach ( $roles as $name => $capabilities ) {
			$store->roles[ $name ] = new WP_Role( $name, $capabilities );
			$store->names[ $name ] = ucfirst( $name );
		}

		Functions\when( 'get_role' )->alias(
			static fn( string $name ): ?WP_Role => $store->roles[ $name ] ?? null
		);
		Functions\when( 'add_role' )->alias(
			static function ( string $name, string $display_name, array $capabilities = array() ) use ( $store, $fail_add_role ): ?WP_Role {
				++$store->add_role_calls;

				if ( $fail_add_role || '' === $name || isset( $store->roles[ $name ] ) ) {
					return null;
				}

				$store->roles[ $name ] = new WP_Role( $name, $capabilities );
				$store->names[ $name ] = $display_name;

				return $store->roles[ $name ];
			}
		);
		Functions\when( 'remove_role' )->alias(
			static function ( string $name ) use ( $store ): void {
				unset( $store->roles[ $name ], $store->names[ $name ] );
			}
		);

		return $store;
	}

	/**
	 * Capabilities of a role (granted and denied).
	 *
	 * @param string $name Role name.
	 * @return array<string, bool>
	 */
	public function capabilities( string $name ): array {
		return isset( $this->roles[ $name ] ) ? $this->roles[ $name ]->capabilities : array();
	}

	/**
	 * Whether a user with the given roles has a capability, resolved like
	 * WordPress does for role capabilities (any role granting it, no explicit deny).
	 *
	 * @param string[] $user_roles Roles of the user.
	 * @param string   $capability Capability.
	 */
	public function user_with_roles_can( array $user_roles, string $capability ): bool {
		$allcaps = array();

		foreach ( $user_roles as $role ) {
			$allcaps = array_merge( $allcaps, $this->capabilities( $role ) );
		}

		return ! empty( $allcaps[ $capability ] );
	}
}
