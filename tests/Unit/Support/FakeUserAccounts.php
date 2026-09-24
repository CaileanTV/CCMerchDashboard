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
	 * Choices.
	 *
	 * @param int $limit Limit.
	 * @return array<int, string>
	 */
	public function choices( int $limit ): array {
		return array_slice( $this->users, 0, $limit, true );
	}
}
