<?php
/**
 * Removes Product Vendors' roles from migrated users.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Step 26.3 (ADR-074 pkt. 8): after the migration, Product Vendors' three
 * roles are removed from the users linked to a migrated creator, so they
 * keep only their Merch Dashboard access (with a Product Vendors role they
 * keep wp-admin, ADR-026). An exception to ADR-022: only these three roles
 * and only these users; every other role stays.
 *
 * The users are the migrated creators' users (OPTION's vendors) that still
 * hold one of the roles. While Product Vendors is active, a user who also
 * administers a vendor that was not migrated is shown with those vendors:
 * removing the role ends that access too. Each user's change and its
 * `vendor_roles_removed` record are one transaction.
 *
 * @phpstan-type RoleUser array{user_id: int, login: string, roles: list<string>, creator_id: int, creator_name: string, other_vendors: list<string>}
 */
class VendorRoleCleanup {

	/**
	 * Constructor.
	 *
	 * @param ProductVendorsSource $source      Product Vendors (for the warning only).
	 * @param CreatorRepository    $creators    Creators.
	 * @param AuditTrail           $audit       Audit trail.
	 * @param Transaction          $transaction Database transaction.
	 */
	public function __construct(
		private ProductVendorsSource $source,
		private CreatorRepository $creators,
		private AuditTrail $audit,
		private Transaction $transaction
	) {
	}

	/**
	 * The migrated users that still hold a Product Vendors role.
	 *
	 * @return list<RoleUser>
	 * @throws DatabaseException When the creators cannot be read.
	 */
	public function users(): array {
		$mapping  = VendorImportPlan::mapping();
		$migrated = array_flip( array_keys( $mapping ) );
		$others   = $this->vendors_by_admin( $migrated );
		$users    = array();

		foreach ( array_unique( array_values( $mapping ) ) as $creator_id ) {
			$creator = $this->creators->find( $creator_id );
			$user    = null === $creator || null === $creator->user_id ? false : get_userdata( $creator->user_id );

			if ( null === $creator || ! $user instanceof WP_User ) {
				continue;
			}

			$roles = array_values( array_intersect( ProductVendorsSource::ROLES, $user->roles ) );

			if ( array() === $roles ) {
				continue;
			}

			$users[] = array(
				'user_id'       => $user->ID,
				'login'         => $user->user_login,
				'roles'         => $roles,
				'creator_id'    => $creator->id,
				'creator_name'  => $creator->display_name,
				'other_vendors' => $others[ $user->ID ] ?? array(),
			);
		}

		return $users;
	}

	/**
	 * Removes Product Vendors' roles from the chosen users that are still
	 * among users().
	 *
	 * @phpstan-param list<int> $user_ids
	 *
	 * @param int[] $user_ids Users the administrator confirmed.
	 * @return int Users changed.
	 * @throws VendorImportException When the current user may not change roles.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function remove( array $user_ids ): int {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) || ! current_user_can( 'promote_users' ) ) {
			self::refuse( VendorImportException::NOT_AUTHORIZED );
		}

		$changed = 0;

		foreach ( $this->users() as $candidate ) {
			if ( ! in_array( $candidate['user_id'], $user_ids, true ) ) {
				continue;
			}

			$this->transaction->run(
				function () use ( $candidate ): void {
					$user = new WP_User( $candidate['user_id'] );

					foreach ( $candidate['roles'] as $role ) {
						$user->remove_role( $role );
					}

					$this->audit->record( AuditAction::VendorRolesRemoved, $candidate['creator_id'], array( 'roles' => $candidate['roles'] ) );
				}
			);
			++$changed;
		}

		return $changed;
	}

	/**
	 * Vendors that were not migrated, by their administrators (only while
	 * Product Vendors is active).
	 *
	 * @param array<int, int> $migrated Migrated vendor term ids as keys.
	 * @return array<int, list<string>>
	 */
	private function vendors_by_admin( array $migrated ): array {
		if ( ! $this->source->active() ) {
			return array();
		}

		$others = array();

		try {
			$vendors = $this->source->vendors();
		} catch ( VendorImportException ) {
			return array();
		}

		foreach ( $vendors as $vendor ) {
			if ( isset( $migrated[ $vendor['term_id'] ] ) ) {
				continue;
			}

			foreach ( $this->source->admins( $vendor['term_id'] ) as $user_id ) {
				$others[ $user_id ][] = $vendor['name'];
			}
		}

		return $others;
	}

	/**
	 * Refuses.
	 *
	 * @param string $reason VendorImportException constant.
	 * @throws VendorImportException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new VendorImportException( $reason );
	}
}
