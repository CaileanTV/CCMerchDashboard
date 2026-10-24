<?php
/**
 * The dry run of the migration from Product Vendors.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what the migration would do, without writing anything (step
 * 26.1, ADR-074). Per vendor:
 *
 * - **imported:** an earlier migration made its creator (OPTION); only what
 *   is missing is added;
 * - **matched:** the chosen user is linked to a creator already, which is
 *   used (the project owner's choice: match by the user, never by name);
 * - **new:** a new active creator with the vendor's name and the chosen user;
 * - **no_admin:** no administrator; a creator without a user;
 * - skipped and shown: **pending** (only not approved administrators) and
 *   **user_taken** (the user is chosen for another vendor, or their creator
 *   came from another vendor).
 *
 * The chosen user is the administrator's choice when it is one of the
 * vendor's approved administrators, else the proposal: a user linked to a
 * creator already, then a Vendor Admin before a Vendor Manager, then the
 * oldest user.
 *
 * @phpstan-import-type VendorRow from VendorImportReport
 * @phpstan-import-type VendorUser from VendorImportReport
 */
class VendorImportPlan {

	/**
	 * Vendor → creator of earlier runs and the last run.
	 */
	public const OPTION = 'merchboard_vendor_import';

	/**
	 * Constructor.
	 *
	 * @param ProductVendorsSource $source   Product Vendors.
	 * @param CreatorRepository    $creators Creators.
	 * @param ProductCreatorLink   $links    Products' creators.
	 * @param PayoutSettings       $payouts  Settlement starts.
	 */
	public function __construct(
		private ProductVendorsSource $source,
		private CreatorRepository $creators,
		private ProductCreatorLink $links,
		private PayoutSettings $payouts
	) {
	}

	/**
	 * Vendor → creator from earlier runs.
	 *
	 * @return array<int, int>
	 */
	public static function mapping(): array {
		wp_cache_delete( self::OPTION, 'options' );
		$stored  = get_option( self::OPTION, array() );
		$mapping = array();

		foreach ( is_array( $stored ) && is_array( $stored['vendors'] ?? null ) ? $stored['vendors'] : array() as $term_id => $creator_id ) {
			if ( is_int( $term_id ) && $term_id > 0 && is_int( $creator_id ) && $creator_id > 0 ) {
				$mapping[ $term_id ] = $creator_id;
			}
		}

		return $mapping;
	}

	/**
	 * Refuses unless the current user may migrate and Product Vendors can be
	 * read.
	 *
	 * @throws VendorImportException When refused.
	 */
	public function check(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) || ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			self::refuse( VendorImportException::NOT_AUTHORIZED );
		}

		if ( ! $this->source->active() ) {
			self::refuse( VendorImportException::INACTIVE );
		}
	}

	/**
	 * The dry run.
	 *
	 * @param array<int, int> $choices The administrator's chosen user per vendor.
	 * @throws VendorImportException When refused or Product Vendors has no vendors.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function build( array $choices = array() ): VendorImportReport {
		$this->check();
		$vendors = $this->source->vendors();

		if ( array() === $vendors ) {
			self::refuse( VendorImportException::NO_DATA );
		}

		$mapping = self::mapping();
		$names   = array();

		foreach ( $this->creators->all() as $creator ) {
			$names[ self::name_key( $creator->display_name ) ] = true;
		}

		$taken = array();
		$rows  = array();

		foreach ( $vendors as $vendor ) {
			$row    = $this->row( $vendor['term_id'], $vendor['name'], $mapping, $choices[ $vendor['term_id'] ] ?? 0, $taken );
			$rows[] = array_merge( $row, array( 'same_name' => 0 === $row['creator_id'] && isset( $names[ self::name_key( $vendor['name'] ) ] ) ) );

			if ( $row['user_id'] > 0 && self::migrates_user( $row['status'] ) ) {
				$taken[ $row['user_id'] ] = true;
			}
		}

		return new VendorImportReport( $this->source->version(), $rows );
	}

	/**
	 * One vendor's row (without the name warning).
	 *
	 * @param int              $term_id Vendor.
	 * @param string           $name    Its name.
	 * @param array<int, int>  $mapping Vendor → creator of earlier runs.
	 * @param int              $choice  The administrator's chosen user (0: none).
	 * @param array<int, true> $taken   Users chosen for earlier vendors.
	 * @return array{term_id: int, name: string, status: string, users: list<VendorUser>, user_id: int, creator_id: int, creator_name: string, products: array{link: int, same: int, conflict: int}, settlement: string|null}
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function row( int $term_id, string $name, array $mapping, int $choice, array $taken ): array {
		$users   = $this->users( $term_id );
		$mapped  = isset( $mapping[ $term_id ] ) ? $this->creators->find( $mapping[ $term_id ] ) : null;
		$pending = array() === $users && $this->has_pending_admin( $term_id );
		$user_id = 0;
		$creator = null;

		if ( null !== $mapped ) {
			$status  = VendorImportReport::IMPORTED;
			$creator = $mapped;
			$user_id = $mapped->user_id ?? 0;
		} elseif ( $pending ) {
			$status = VendorImportReport::PENDING;
		} elseif ( array() === $users ) {
			$status = VendorImportReport::NO_ADMIN;
		} else {
			$user_id = self::chosen( $users, $choice );
			$creator = $this->creators->find_by_user_id( $user_id );
			$status  = null === $creator ? VendorImportReport::NEW : VendorImportReport::MATCHED;

			// A user can be migrated for one vendor only (ADR-020: one creator per user).
			if ( isset( $taken[ $user_id ] ) || ( null !== $creator && in_array( $creator->id, $mapping, true ) ) ) {
				$status  = VendorImportReport::USER_TAKEN;
				$creator = null;
			}
		}

		return array(
			'term_id'      => $term_id,
			'name'         => $name,
			'status'       => $status,
			'users'        => $users,
			'user_id'      => $user_id,
			'creator_id'   => $creator->id ?? 0,
			'creator_name' => $creator->display_name ?? '',
			'products'     => $this->products( $term_id, VendorImportReport::migrates( $status ) ? $creator : null ),
			'settlement'   => $this->settlement( $status, $creator ),
		);
	}

	/**
	 * A vendor's approved administrators (Vendor Admin or Vendor Manager),
	 * proposal first.
	 *
	 * @param int $term_id Vendor.
	 * @return list<VendorUser>
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function users( int $term_id ): array {
		$users = array();

		foreach ( $this->source->admins( $term_id ) as $user_id ) {
			$roles = $this->source->roles( $user_id );
			$role  = in_array( ProductVendorsSource::ADMIN_ROLE, $roles, true ) ? 'admin' : ( in_array( ProductVendorsSource::MANAGER_ROLE, $roles, true ) ? 'manager' : '' );
			$user  = get_userdata( $user_id );

			if ( '' === $role || ! $user instanceof WP_User ) {
				continue;
			}

			$users[] = array(
				'id'         => $user_id,
				'name'       => $user->display_name . ' (' . $user->user_login . ')',
				'role'       => $role,
				'creator_id' => $this->creators->find_by_user_id( $user_id )->id ?? 0,
			);
		}

		usort(
			$users,
			static fn( array $a, array $b ): int => array( 0 === $a['creator_id'], 'admin' !== $a['role'], $a['id'] ) <=> array( 0 === $b['creator_id'], 'admin' !== $b['role'], $b['id'] )
		);

		return $users;
	}

	/**
	 * Whether a vendor has an administrator who is not approved yet.
	 *
	 * @param int $term_id Vendor.
	 */
	private function has_pending_admin( int $term_id ): bool {
		foreach ( $this->source->admins( $term_id ) as $user_id ) {
			if ( in_array( ProductVendorsSource::PENDING_ROLE, $this->source->roles( $user_id ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The chosen user: the administrator's choice when it is one of the
	 * vendor's users, else the proposal (the first).
	 *
	 * @phpstan-param non-empty-list<VendorUser> $users
	 *
	 * @param array[] $users  The vendor's users, proposal first.
	 * @param int     $choice The administrator's choice (0: none).
	 */
	private static function chosen( array $users, int $choice ): int {
		foreach ( $users as $user ) {
			if ( $user['id'] === $choice ) {
				return $choice;
			}
		}

		return $users[0]['id'];
	}

	/**
	 * Product counts: to link, linked to this creator already, linked to
	 * another creator (conflict; never replaced).
	 *
	 * @param int          $term_id Vendor.
	 * @param Creator|null $creator The vendor's creator (null: a new one, or skipped).
	 * @return array{link: int, same: int, conflict: int}
	 */
	private function products( int $term_id, ?Creator $creator ): array {
		$counts = array(
			'link'     => 0,
			'same'     => 0,
			'conflict' => 0,
		);

		foreach ( $this->source->product_ids( $term_id ) as $product_id ) {
			$current = $this->links->creator_id( $product_id );

			if ( null === $current ) {
				++$counts['link'];
			} elseif ( null !== $creator && $current === $creator->id ) {
				++$counts['same'];
			} else {
				++$counts['conflict'];
			}
		}

		return $counts;
	}

	/**
	 * The settlement start shown in the dry run: 'new' for a creator the
	 * migration makes (set to the migration's time), the stored start of an
	 * existing creator ('' when it has none), null when skipped.
	 *
	 * @param string       $status  Vendor status.
	 * @param Creator|null $creator The vendor's creator.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function settlement( string $status, ?Creator $creator ): ?string {
		if ( in_array( $status, array( VendorImportReport::NEW, VendorImportReport::NO_ADMIN ), true ) ) {
			return 'new';
		}

		if ( null === $creator ) {
			return null;
		}

		try {
			$start = $this->payouts->settlement_start( $creator->id );
		} catch ( PayoutException ) {
			return '';
		}

		return $start instanceof DateTimeImmutable ? $start->format( 'Y-m-d H:i:s' ) : '';
	}

	/**
	 * Whether a status takes its chosen user (so no other vendor can).
	 *
	 * @param string $status Status.
	 */
	private static function migrates_user( string $status ): bool {
		return in_array( $status, array( VendorImportReport::NEW, VendorImportReport::MATCHED, VendorImportReport::IMPORTED ), true );
	}

	/**
	 * A name for comparison (trimmed, case-insensitive).
	 *
	 * @param string $name Name.
	 */
	private static function name_key( string $name ): string {
		return mb_strtolower( trim( $name ) );
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
