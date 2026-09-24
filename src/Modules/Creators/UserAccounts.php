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
	 * Login name of a user (shown in the admin form, as in labels()).
	 *
	 * @param int $user_id User id.
	 * @return string|null Null when the user does not exist.
	 */
	public function login( int $user_id ): ?string;

	/**
	 * Id of the user with exactly this login name.
	 *
	 * @param string $login Login name.
	 * @return int|null Null when no such user exists.
	 */
	public function find_by_login( string $login ): ?int;

	/**
	 * Users whose login or display name contains the term (never searched by
	 * e-mail and never returning it), for the admin user selector.
	 *
	 * @param string $term  Search term (at least 2 characters, else no results).
	 * @param int    $limit Maximum number of results (1-50).
	 * @return list<array{id: int, login: string, label: string}>
	 */
	public function search( string $term, int $limit ): array;
}
