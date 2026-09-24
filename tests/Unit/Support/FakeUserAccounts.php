<?php
/**
 * UserAccounts test double.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Modules\Creators\UserAccounts;

/**
 * Users by id with creator-role bookkeeping.
 */
final class FakeUserAccounts implements UserAccounts {

	/**
	 * Users that have the creator role.
	 *
	 * @var array<int, true>
	 */
	public array $creator_role = array();

	/**
	 * Role changes in order: "grant:ID" / "revoke:ID".
	 *
	 * @var list<string>
	 */
	public array $role_log = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $users Label by user id.
	 */
	public function __construct( public array $users = array() ) {
	}

	/**
	 * Whether a user exists.
	 *
	 * @param int $user_id User id.
	 */
	public function exists( int $user_id ): bool {
		return isset( $this->users[ $user_id ] );
	}

	/**
	 * Grants the creator role.
	 *
	 * @param int $user_id User id.
	 */
	public function grant_creator_role( int $user_id ): void {
		$this->creator_role[ $user_id ] = true;
		$this->role_log[]               = 'grant:' . $user_id;
	}

	/**
	 * Revokes the creator role.
	 *
	 * @param int $user_id User id.
	 */
	public function revoke_creator_role( int $user_id ): void {
		unset( $this->creator_role[ $user_id ] );
		$this->role_log[] = 'revoke:' . $user_id;
	}

	/**
	 * Labels.
	 *
	 * @param int[] $user_ids User ids.
	 * @return array<int, string>
	 */
	public function labels( array $user_ids ): array {
		return array_intersect_key( $this->users, array_flip( $user_ids ) );
	}

	/**
	 * Login: the part in parentheses of the label ("Nova (nova)" → "nova").
	 *
	 * @param int $user_id User id.
	 */
	public function login( int $user_id ): ?string {
		if ( ! isset( $this->users[ $user_id ] ) ) {
			return null;
		}

		return 1 === preg_match( '/\(([^)]+)\)$/', $this->users[ $user_id ], $m ) ? $m[1] : null;
	}

	/**
	 * Id by exact login.
	 *
	 * @param string $login Login.
	 */
	public function find_by_login( string $login ): ?int {
		foreach ( array_keys( $this->users ) as $user_id ) {
			if ( $this->login( $user_id ) === $login ) {
				return $user_id;
			}
		}

		return null;
	}

	/**
	 * Substring search in labels (login and display name).
	 *
	 * @param string $term  Term.
	 * @param int    $limit Limit.
	 * @return list<array{id: int, login: string, label: string}>
	 */
	public function search( string $term, int $limit ): array {
		if ( mb_strlen( trim( $term ) ) < 2 ) {
			return array();
		}

		$found = array();

		foreach ( $this->users as $user_id => $label ) {
			if ( str_contains( strtolower( $label ), strtolower( trim( $term ) ) ) ) {
				$found[] = array(
					'id'    => $user_id,
					'login' => (string) $this->login( $user_id ),
					'label' => $label,
				);
			}
		}

		return array_slice( $found, 0, $limit );
	}
}
