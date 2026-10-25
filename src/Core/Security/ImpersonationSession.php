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
 * 27.2, ADR-075): the user meta META `{creator_id, started_at, expires_at,
 * token}` (Unix times; `token` is a hash of the WordPress login session it
 * was started in), written only by Modules\Access\Impersonation.
 *
 * A session counts only in the login session that started it (not on other
 * devices, nor for Application Passwords), while the logged-in user still
 * has `merchboard_manage_creators`, and until it expires; anything else is
 * no session (fail closed). Read-only, apart from the per-request mark that
 * this request is creator-facing (ImpersonationGuard), so Core (the audit
 * trail) and every module can ask it.
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
	 * Whether this request is a creator-facing one (`/merchboard/v1/me…`).
	 *
	 * @var bool
	 */
	private static bool $acting = false;

	/**
	 * The creator the logged-in user views and acts as, or null.
	 */
	public static function creator_id(): ?int {
		$session = self::stored( get_current_user_id() );

		if ( null === $session || $session['expires_at'] <= time() || ! hash_equals( $session['token'], self::token() ) ) {
			return null;
		}

		return current_user_can( Capabilities::MANAGE_CREATORS ) ? $session['creator_id'] : null;
	}

	/**
	 * The creator the administrator acts as in this request: only on a
	 * creator-facing request during a session (the audit trail's mark).
	 */
	public static function acting_creator_id(): ?int {
		return self::$acting ? self::creator_id() : null;
	}

	/**
	 * Marks this request as creator-facing (ImpersonationGuard), or not.
	 *
	 * @param bool $acting Whether it is.
	 */
	public static function act( bool $acting ): void {
		self::$acting = $acting;
	}

	/**
	 * The hash of the current WordPress login session ('' gives the hash of
	 * '', which a browser session never has).
	 */
	public static function token(): string {
		return hash( 'sha256', function_exists( 'wp_get_session_token' ) ? wp_get_session_token() : '' );
	}

	/**
	 * A user's stored session, also an expired one; null when there is none
	 * or it is malformed.
	 *
	 * @param int $user_id WordPress user id.
	 * @return array{creator_id: int, started_at: int, expires_at: int, token: string}|null
	 */
	public static function stored( int $user_id ): ?array {
		$value = $user_id > 0 ? get_user_meta( $user_id, self::META, true ) : null;

		if ( ! is_array( $value ) ) {
			return null;
		}

		$creator_id = $value['creator_id'] ?? null;
		$started_at = $value['started_at'] ?? null;
		$expires_at = $value['expires_at'] ?? null;
		$token      = $value['token'] ?? null;

		if ( ! is_int( $creator_id ) || $creator_id < 1 || ! is_int( $started_at ) || ! is_int( $expires_at ) || ! is_string( $token ) ) {
			return null;
		}

		return array(
			'creator_id' => $creator_id,
			'started_at' => $started_at,
			'expires_at' => $expires_at,
			'token'      => $token,
		);
	}
}
