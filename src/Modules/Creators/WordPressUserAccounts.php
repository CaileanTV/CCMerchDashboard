<?php
/**
 * UserAccounts backed by WordPress' user API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use MerchBoard\Core\Security\Capabilities;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Uses get_userdata()/get_users() and WP_User::add_role()/remove_role().
 * Only ID, display_name and user_login are read; e-mail addresses, password
 * hashes and meta are never loaded for display.
 */
final class WordPressUserAccounts implements UserAccounts {

	/**
	 * Whether a WordPress user with this id exists.
	 *
	 * @param int $user_id User id.
	 */
	public function exists( int $user_id ): bool {
		return $user_id > 0 && get_userdata( $user_id ) instanceof WP_User;
	}

	/**
	 * Adds the creator role as an extra role.
	 *
	 * @param int $user_id User id.
	 */
	public function grant_creator_role( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( $user instanceof WP_User && ! in_array( Capabilities::CREATOR_ROLE, $user->roles, true ) ) {
			$user->add_role( Capabilities::CREATOR_ROLE );
		}
	}

	/**
	 * Removes the creator role, if present.
	 *
	 * @param int $user_id User id.
	 */
	public function revoke_creator_role( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( $user instanceof WP_User && in_array( Capabilities::CREATOR_ROLE, $user->roles, true ) ) {
			$user->remove_role( Capabilities::CREATOR_ROLE );
		}
	}

	/**
	 * Display labels for the given users.
	 *
	 * @param int[] $user_ids User ids.
	 * @return array<int, string>
	 */
	public function labels( array $user_ids ): array {
		$user_ids = array_values( array_filter( array_map( 'intval', $user_ids ), static fn( int $id ): bool => $id > 0 ) );

		if ( array() === $user_ids ) {
			return array();
		}

		return $this->label_map(
			get_users(
				array(
					'include' => $user_ids,
					'fields'  => array( 'ID', 'display_name', 'user_login' ),
				)
			)
		);
	}

	/**
	 * Users selectable in the admin form.
	 *
	 * @param int $limit Maximum number of users.
	 * @return array<int, string>
	 */
	public function choices( int $limit ): array {
		return $this->label_map(
			get_users(
				array(
					'number'  => max( 1, $limit ),
					'orderby' => 'display_name',
					'order'   => 'ASC',
					'fields'  => array( 'ID', 'display_name', 'user_login' ),
				)
			)
		);
	}

	/**
	 * Builds "Display Name (login)" labels.
	 *
	 * @param array<mixed> $users Result of get_users() with fields.
	 * @return array<int, string>
	 */
	private function label_map( array $users ): array {
		$labels = array();

		foreach ( $users as $user ) {
			if ( ! is_object( $user ) || ! isset( $user->ID, $user->display_name, $user->user_login ) ) {
				continue;
			}

			$labels[ (int) $user->ID ] = sprintf( '%s (%s)', (string) $user->display_name, (string) $user->user_login );
		}

		return $labels;
	}
}
