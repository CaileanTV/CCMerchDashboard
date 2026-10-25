<?php
/**
 * An administrator's "view as creator" session.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the impersonation session of the logged-in user (phase 27, step
 * 27.2, ADR-075): the user meta META `{creator_id, started_at, expires_at}`
 * (Unix times), written only by Modules\Access\Impersonation.
 *
 * A session counts only while the logged-in user still has
 * `merchboard_manage_creators` and it has not expired; anything else is no
 * session (fail closed). Read-only and free of side effects, so Core (the
 * audit trail) and every module can ask it.
 */
final class ImpersonationSession {

	/**
	 * The user meta of the session.
	 */
	public const META = 'merchboard_impersonating';

	/**
	 * How long a session lasts (seconds).
	 */
	public const TTL = 3600;

	/**
	 * The creator the logged-in user views and acts as, or null.
	 */
	public static function creator_id(): ?int {
		$session = self::stored( get_current_user_id() );

		if ( null === $session || $session['expires_at'] <= time() ) {
			return null;
		}

		return current_user_can( Capabilities::MANAGE_CREATORS ) ? $session['creator_id'] : null;
	}

	/**
	 * A user's stored session, also an expired one; null when there is none
	 * or it is malformed.
	 *
	 * @param int $user_id WordPress user id.
	 * @return array{creator_id: int, started_at: int, expires_at: int}|null
	 */
	public static function stored( int $user_id ): ?array {
		$value = $user_id > 0 ? get_user_meta( $user_id, self::META, true ) : null;

		if ( ! is_array( $value ) ) {
			return null;
		}

		$creator_id = $value['creator_id'] ?? null;
		$started_at = $value['started_at'] ?? null;
		$expires_at = $value['expires_at'] ?? null;

		if ( ! is_int( $creator_id ) || $creator_id < 1 || ! is_int( $started_at ) || ! is_int( $expires_at ) ) {
			return null;
		}

		return array(
			'creator_id' => $creator_id,
			'started_at' => $started_at,
			'expires_at' => $expires_at,
		);
	}
}
