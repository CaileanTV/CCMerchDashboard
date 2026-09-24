<?php
/**
 * WordPress user account operations needed by creator administration.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

defined( 'ABSPATH' ) || exit;

/**
 * The small set of user operations the creator service needs, behind an
 * interface so business rules can be tested without WordPress. Users are
 * identified by their stable id only; labels are for display, never identity.
 */
interface UserAccounts {

	/**
	 * Whether a WordPress user with this id exists.
	 *
	 * @param int $user_id User id.
	 */
	public function exists( int $user_id ): bool;

	/**
	 * Adds the creator role as an extra role (existing roles are kept).
	 *
	 * @param int $user_id User id.
	 */
	public function grant_creator_role( int $user_id ): void;

	/**
	 * Removes the creator role, if present. Other roles and the user are kept.
	 *
	 * @param int $user_id User id.
	 */
	public function revoke_creator_role( int $user_id ): void;

	/**
	 * Display labels ("Display Name (login)") for the given users.
	 *
	 * @param int[] $user_ids User ids.
	 * @return array<int, string> Keyed by user id; unknown ids are omitted.
	 */
	public function labels( array $user_ids ): array;

	/**
	 * Users that can be selected in the admin form, by label.
	 *
	 * @param int $limit Maximum number of users.
	 * @return array<int, string> Label by user id.
	 */
	public function choices( int $limit ): array;
}
