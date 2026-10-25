<?php
/**
 * Creator access policy.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The single answer to "may this WordPress user use Merch Dashboard as a
 * creator, and as which creator?" (docs/USER-ROLES.md, ADR-007).
 *
 * Access requires all of:
 * 1. an existing WordPress user (logged in, for current());
 * 2. the capability `merchboard_access_dashboard` (normally via the
 *    `merchboard_creator` role, checked with WordPress' own user_can());
 * 3. a creator record linked to that user (`merchboard_creators.user_id`);
 * 4. that creator's status is `active`.
 *
 * The role alone never grants access: deactivating a creator removes access
 * on the next request without touching roles, and a role given by mistake
 * grants nothing without an active record. Access grants no administrative
 * capability; administration stays behind `merchboard_manage_creators`.
 *
 * The creator is always resolved server-side from a WordPress user id, never
 * from a creator id supplied by a client. Services that receive a creator id
 * must still authorize it themselves (ownership against current()).
 *
 * Not memoized: each call reflects the current record, so a deactivation is
 * effective immediately. A database failure is logged and means "no access"
 * (fail closed); ordinary "no access" never throws.
 */
final class CreatorContext {

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository $creators Creator storage.
	 * @param Logger            $logger   Technical log.
	 */
	public function __construct( private CreatorRepository $creators, private Logger $logger ) {
	}

	/**
	 * The active creator of the logged-in user, or null without access.
	 * During an administrator's "view as creator" session (phase 27, step
	 * 27.2, ADR-075) it is the viewed creator, while that creator is active.
	 */
	public function current(): ?Creator {
		$impersonated = ImpersonationSession::creator_id();

		return null === $impersonated ? $this->for_user( get_current_user_id() ) : $this->active( $impersonated );
	}

	/**
	 * A creator by id while it is active, or null (fail closed).
	 *
	 * @param int $creator_id Creator.
	 */
	private function active( int $creator_id ): ?Creator {
		try {
			$creator = $this->creators->find( $creator_id );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Creator access check failed; access denied.', array_merge( $e->context(), array( 'creator_id' => $creator_id ) ) );

			return null;
		}

		return null !== $creator && CreatorStatus::Active === $creator->status ? $creator : null;
	}

	/**
	 * Whether the logged-in user has creator access.
	 */
	public function current_user_has_access(): bool {
		return null !== $this->current();
	}

	/**
	 * The active creator of a WordPress user, or null without access.
	 *
	 * For the current request use current(); this is for server-side
	 * decisions about a known user id (never an id taken from a request).
	 *
	 * @param int $user_id WordPress user id.
	 */
	public function for_user( int $user_id ): ?Creator {
		// user_can() is false for users that do not exist.
		if ( $user_id < 1 || ! user_can( $user_id, Capabilities::ACCESS_DASHBOARD ) ) {
			return null;
		}

		try {
			$creator = $this->creators->find_by_user_id( $user_id );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'Creator access check failed; access denied.',
				array_merge(
					$e->context(),
					array(
						'user_id' => $user_id,
						'reason'  => $e->getMessage(),
					)
				)
			);

			return null;
		}

		return null !== $creator && CreatorStatus::Active === $creator->status && null !== $creator->user_id ? $creator : null;
	}

	/**
	 * Whether a WordPress user has creator access.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public function user_has_access( int $user_id ): bool {
		return null !== $this->for_user( $user_id );
	}
}
