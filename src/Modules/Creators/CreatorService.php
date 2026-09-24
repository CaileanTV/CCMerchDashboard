<?php
/**
 * Creator administration use cases.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Business rules for creating and changing creators (ADR-023).
 *
 * - Authorization: every operation requires `merchboard_manage_creators` for
 *   the current user, independent of any check in the calling UI.
 * - Validation: the creator must exist, the display name must be valid, a user
 *   to link must exist and must not be linked to another creator (the UNIQUE
 *   index is the last safety net).
 * - Consistency: each creator change and its audit record(s) are written in one
 *   database transaction; if the audit record cannot be written, the change is
 *   rolled back and the error propagates (the caller logs it).
 * - Roles: after commit, a linked user gets the creator role as an extra role;
 *   an unlinked user loses it. Done after commit so a rollback can never leave
 *   cached roles out of sync. If it fails, access fails safe: creator access
 *   (phase 8) requires both the role and an active linked record.
 * - Audit context holds no personal data: field names, statuses and user ids only.
 */
final class CreatorService {

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository $creators    Creator storage.
	 * @param AuditTrail        $audit       Audit trail.
	 * @param Transaction       $transaction Database transaction.
	 * @param UserAccounts      $users       WordPress user operations.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private AuditTrail $audit,
		private Transaction $transaction,
		private UserAccounts $users
	) {
	}

	/**
	 * Creates a creator, optionally linked to a WordPress user.
	 *
	 * @param string        $display_name Display name.
	 * @param CreatorStatus $status       Initial status.
	 * @param int|null      $user_id      User to link, or null.
	 * @throws CreatorException On authorization or validation failure.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function create( string $display_name, CreatorStatus $status, ?int $user_id ): Creator {
		$this->authorize();
		$display_name = $this->valid_display_name( $display_name );

		if ( null !== $user_id ) {
			$this->assert_linkable( $user_id, null );
		}

		$creator = $this->transaction->run(
			function () use ( $display_name, $status, $user_id ): Creator {
				$creator = $this->creators->create( $display_name, $status, $user_id );
				$this->audit->record(
					AuditAction::CreatorCreated,
					$creator->id,
					array(
						'status'  => $status->value,
						'user_id' => $user_id,
					)
				);

				return $creator;
			}
		);

		if ( null !== $user_id ) {
			$this->users->grant_creator_role( $user_id );
		}

		return $creator;
	}

	/**
	 * Applies the admin edit form: display name, status and user link.
	 * Writes one audit record per semantic change; nothing when unchanged.
	 *
	 * @param int           $creator_id   Creator id.
	 * @param string        $display_name Display name.
	 * @param CreatorStatus $status       Status.
	 * @param int|null      $user_id      User to link, or null to unlink.
	 * @return list<AuditAction> The changes made (empty when nothing changed).
	 * @throws CreatorException On authorization or validation failure.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function update( int $creator_id, string $display_name, CreatorStatus $status, ?int $user_id ): array {
		$this->authorize();
		$current      = $this->existing( $creator_id );
		$display_name = $this->valid_display_name( $display_name );

		if ( null !== $user_id && $user_id !== $current->user_id ) {
			$this->assert_linkable( $user_id, $current->id );
		}

		$rename   = $display_name !== $current->display_name;
		$restatus = $status !== $current->status;
		$relink   = $user_id !== $current->user_id;

		if ( ! $rename && ! $restatus && ! $relink ) {
			return array();
		}

		$changes = $this->transaction->run(
			function () use ( $current, $display_name, $status, $user_id, $rename, $restatus, $relink ): array {
				$changes = array();

				if ( $rename ) {
					$this->creators->rename( $current->id, $display_name );
					$changes[] = $this->record( AuditAction::CreatorUpdated, $current->id, array( 'changed_fields' => array( 'display_name' ) ) );
				}

				if ( $restatus ) {
					$this->creators->set_status( $current->id, $status );
					$changes[] = $this->record(
						CreatorStatus::Active === $status ? AuditAction::CreatorActivated : AuditAction::CreatorDeactivated,
						$current->id,
						array( 'previous_status' => $current->status->value )
					);
				}

				if ( $relink && null !== $current->user_id ) {
					$this->creators->unlink_user( $current->id );
					$changes[] = $this->record( AuditAction::CreatorUserUnlinked, $current->id, array( 'user_id' => $current->user_id ) );
				}

				if ( $relink && null !== $user_id ) {
					$this->creators->link_user( $current->id, $user_id );
					$changes[] = $this->record( AuditAction::CreatorUserLinked, $current->id, array( 'user_id' => $user_id ) );
				}

				return $changes;
			}
		);

		if ( $relink && null !== $current->user_id ) {
			$this->users->revoke_creator_role( $current->user_id );
		}

		if ( $relink && null !== $user_id ) {
			$this->users->grant_creator_role( $user_id );
		}

		return $changes;
	}

	/**
	 * Activates or deactivates a creator (row action).
	 *
	 * @param int           $creator_id Creator id.
	 * @param CreatorStatus $status     New status.
	 * @return list<AuditAction> The changes made.
	 * @throws CreatorException On authorization or validation failure.
	 * @throws DatabaseException When the change cannot be stored.
	 */
	public function set_status( int $creator_id, CreatorStatus $status ): array {
		$this->authorize();
		$current = $this->existing( $creator_id );

		return $this->update( $current->id, $current->display_name, $status, $current->user_id );
	}

	/**
	 * Requires the creator-management capability.
	 *
	 * @throws CreatorException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			throw new CreatorException( CreatorException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Loads an existing creator.
	 *
	 * @param int $creator_id Creator id.
	 * @throws CreatorException When the id is invalid or unknown.
	 */
	private function existing( int $creator_id ): Creator {
		$creator = $creator_id > 0 ? $this->creators->find( $creator_id ) : null;

		if ( null === $creator ) {
			throw new CreatorException( CreatorException::NOT_FOUND );
		}

		return $creator;
	}

	/**
	 * Validates a display name.
	 *
	 * @param string $display_name Display name.
	 * @throws CreatorException When invalid.
	 */
	private function valid_display_name( string $display_name ): string {
		try {
			return CreatorRepository::normalize_display_name( $display_name );
		} catch ( InvalidArgumentException ) {
			throw new CreatorException( CreatorException::INVALID_DISPLAY_NAME );
		}
	}

	/**
	 * Checks that a user exists and is not linked to another creator.
	 *
	 * @param int      $user_id    User id.
	 * @param int|null $creator_id Creator the user will be linked to (null when creating).
	 * @throws CreatorException When the user is unknown or already linked elsewhere.
	 */
	private function assert_linkable( int $user_id, ?int $creator_id ): void {
		if ( $user_id < 1 || ! $this->users->exists( $user_id ) ) {
			throw new CreatorException( CreatorException::USER_NOT_FOUND );
		}

		$linked = $this->creators->find_by_user_id( $user_id );

		if ( null !== $linked && $linked->id !== $creator_id ) {
			throw new CreatorException( CreatorException::USER_ALREADY_LINKED );
		}
	}

	/**
	 * Records an audit event and returns its action.
	 *
	 * @param AuditAction          $action     Action.
	 * @param int                  $creator_id Creator id.
	 * @param array<string, mixed> $context    Context.
	 */
	private function record( AuditAction $action, int $creator_id, array $context ): AuditAction {
		$this->audit->record( $action, $creator_id, $context );

		return $action;
	}
}
