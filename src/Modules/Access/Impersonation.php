<?php
/**
 * Starts and ends "view as creator" sessions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * "View as creator" (phase 27, step 27.2, ADR-075): an administrator with
 * `merchboard_manage_creators` opens the creator dashboard as an active
 * creator with a WordPress user sees it, and may act there (the project
 * owner's choice 2026-10-25), for at most ImpersonationSession::TTL.
 *
 * The session is the administrator's user meta; CreatorContext::current()
 * returns the viewed creator while it lasts. Start and end are audited
 * (`creator_impersonation_started` / `_ended` with the reason), each in one
 * transaction with the meta. Changes made during a session are audited with
 * the administrator as actor and `impersonating` in the context
 * (AuditTrail). ImpersonationGuard refuses what must stay the creator's own.
 */
class Impersonation {

	/**
	 * Why a session ended.
	 */
	public const ENDED_BY_ADMIN = 'stopped';
	public const ENDED_EXPIRED  = 'expired';
	public const ENDED_LOGOUT   = 'logout';
	public const ENDED_SWITCHED = 'switched';

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository $creators    Creators.
	 * @param AuditTrail        $audit       Audit trail.
	 * @param Transaction       $transaction Database transaction.
	 */
	public function __construct( private CreatorRepository $creators, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * Starts viewing as a creator; a running session for another creator ends.
	 *
	 * @param int $creator_id Creator.
	 * @throws ImpersonationException When not allowed or the creator cannot be viewed.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function start( int $creator_id ): Creator {
		$user_id = get_current_user_id();

		if ( $user_id < 1 || ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			self::refuse( ImpersonationException::NOT_AUTHORIZED );
		}

		$creator = $creator_id > 0 ? $this->creators->find( $creator_id ) : null;

		// Only an active creator with a user: the dashboard is that user's view.
		if ( null === $creator || ! $creator->is_active() || null === $creator->user_id ) {
			self::refuse( ImpersonationException::UNAVAILABLE );
		}

		$previous = ImpersonationSession::stored( $user_id );
		$now      = time();
		$session  = array(
			'creator_id' => $creator->id,
			'started_at' => $now,
			'expires_at' => $now + ImpersonationSession::TTL,
			'token'      => ImpersonationSession::token(),
		);

		$this->transaction->run(
			function () use ( $user_id, $previous, $session, $creator ): void {
				if ( null !== $previous && $previous['creator_id'] !== $creator->id ) {
					$this->audit->record( AuditAction::CreatorImpersonationEnded, $previous['creator_id'], array( 'reason' => self::ENDED_SWITCHED ) );
				}

				self::store( $user_id, $session );
				$this->audit->record( AuditAction::CreatorImpersonationStarted, $creator->id, array( 'minutes' => intdiv( ImpersonationSession::TTL, 60 ) ) );
			}
		);

		// The creator can see that support looked (never who).
		do_action( 'merchboard_notify', 'impersonation_started', $creator->id, array(), '' );

		return $creator;
	}

	/**
	 * Ends the logged-in user's session.
	 *
	 * @param string $reason One of the ENDED_ constants.
	 * @return int|null The creator viewed, or null when there was no session.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function end( string $reason ): ?int {
		return $this->end_for( get_current_user_id(), $reason );
	}

	/**
	 * Ends an expired session of the logged-in user (a running one stays).
	 *
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function expire(): void {
		$user_id = get_current_user_id();
		$session = ImpersonationSession::stored( $user_id );

		if ( null !== $session && $session['expires_at'] <= time() ) {
			$this->end_for( $user_id, self::ENDED_EXPIRED );
		}
	}

	/**
	 * Ends a user's session.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $reason  One of the ENDED_ constants.
	 * @return int|null The creator viewed, or null when there was no session.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function end_for( int $user_id, string $reason ): ?int {
		if ( $user_id < 1 || '' === get_user_meta( $user_id, ImpersonationSession::META, true ) ) {
			return null;
		}

		$session = ImpersonationSession::stored( $user_id );

		$this->transaction->run(
			function () use ( $user_id, $session, $reason ): void {
				if ( ! delete_user_meta( $user_id, ImpersonationSession::META ) ) {
					throw new DatabaseException( 'Could not end the impersonation session.' );
				}

				if ( null !== $session ) {
					$this->audit->record( AuditAction::CreatorImpersonationEnded, $session['creator_id'], array( 'reason' => $reason ) );
				}
			}
		);

		return $session['creator_id'] ?? null;
	}

	/**
	 * Refuses.
	 *
	 * @param string $reason ImpersonationException constant.
	 * @throws ImpersonationException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new ImpersonationException( $reason );
	}

	/**
	 * Stores the session.
	 *
	 * @param int                                                                     $user_id WordPress user id.
	 * @param array{creator_id: int, started_at: int, expires_at: int, token: string} $session Session.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private static function store( int $user_id, array $session ): void {
		update_user_meta( $user_id, ImpersonationSession::META, $session );

		if ( ImpersonationSession::stored( $user_id ) !== $session ) {
			throw new DatabaseException( 'Could not store the impersonation session.' );
		}
	}
}
