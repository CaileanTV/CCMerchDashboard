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
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductLinkSource;
use WP_Term;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what the migration would do, without writing anything (step
 * 26.1, ADR-074). Per vendor:
 *
 * - **imported:** an earlier migration made its creator (VendorImportState);
 *   only what is new is added: products and a category the migration
 *   linked before are never linked again, so what the administrator removed
 *   afterwards stays removed (the project owner's choice 2026-10-25);
 * - **matched:** the chosen user is linked to a creator already, which is
 *   used (the project owner's choice: match by the user, never by name);
 * - **new:** a new active creator with the vendor's name and the chosen user;
 * - **no_admin:** no administrator at all; a creator without a user;
 * - skipped and shown: **pending** (only not approved administrators),
 *   **no_role** (administrators without a Product Vendors role, the project
 *   owner's choice 2026-10-25), **user_taken** (the user is chosen for
 *   another vendor, or their creator came from another vendor) and
 *   **interrupted** (an earlier run stopped while making this vendor's
 *   creator without a user).
 *
 * The chosen user is the administrator's choice when it is one of the
 * vendor's approved administrators, else the proposal: a free user (not
 * chosen for an earlier vendor), preferring one linked to a creator
 * already, then a Vendor Admin before a Vendor Manager, then the oldest.
 *
 * The product category (step 26.2.1): it links new products, and so new
 * sales, to the creator (ADR-033), and its reconciliation also links every
 * other unlinked product in it and makes products of another creator's
 * category ambiguous. The dry run shows both numbers for the category. A
 * creator that has a category keeps it (mapped). Otherwise the
 * administrator's choice (0: none) or the proposal is mapped, unless it is
 * mapped to another creator or chosen for an earlier vendor (taken). The
 * proposal is the free category most of the vendor's products are in
 * (never the default category) and only one that holds nothing but the
 * vendor's products; otherwise none (the project owner's choice
 * 2026-10-25).
 *
 * @phpstan-import-type VendorRow from VendorImportReport
 * @phpstan-import-type VendorUser from VendorImportReport
 * @phpstan-import-type State from VendorImportState
 */
class VendorImportPlan {

	/**
	 * The option of the migration's state (VendorImportState).
	 */
	public const OPTION = VendorImportState::OPTION;

	/**
	 * Products per vendor during one dry run.
	 *
	 * @var array<int, list<int>>
	 */
	private array $products_of = array();

	/**
	 * What mapping a category does to other products, per category and
	 * vendor, during one dry run.
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private array $impact_of = array();

	/**
	 * Constructor.
	 *
	 * @param ProductVendorsSource   $source     Product Vendors.
	 * @param CreatorRepository      $creators   Creators.
	 * @param ProductCreatorLink     $links      Products' creators.
	 * @param PayoutSettings         $payouts    Settlement starts.
	 * @param CreatorCategoryService $categories Creators' product categories.
	 */
	public function __construct(
		private ProductVendorsSource $source,
		private CreatorRepository $creators,
		private ProductCreatorLink $links,
		private PayoutSettings $payouts,
		private CreatorCategoryService $categories
	) {
	}

	/**
	 * Vendor → creator from earlier runs.
	 *
	 * @return array<int, int>
	 */
	public static function mapping(): array {
		return VendorImportState::load()['vendors'];
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
	 * @param array<int, int> $choices          The administrator's chosen user per vendor.
	 * @param array<int, int> $category_choices The administrator's chosen product category per vendor (0: none).
	 * @throws VendorImportException When refused or Product Vendors has no vendors.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function build( array $choices = array(), array $category_choices = array() ): VendorImportReport {
		$this->check();
		$vendors = $this->source->vendors();

		if ( array() === $vendors ) {
			self::refuse( VendorImportException::NO_DATA );
		}

		$this->products_of = array();
		$this->impact_of   = array();
		$state             = VendorImportState::load();
		$names             = array();

		foreach ( $this->creators->all() as $creator ) {
			$names[ self::name_key( $creator->display_name ) ] = true;
		}

		$taken = array();
		$used  = array();
		$rows  = array();

		foreach ( $vendors as $vendor ) {
			$term_id                      = $vendor['term_id'];
			$row                          = $this->row( $term_id, $vendor['name'], $state, $choices[ $term_id ] ?? 0, $taken );
			$owned                        = $this->vendor_categories( $term_id );
			[ $category, $kind, $impact ] = $this->category( $row, $owned, $category_choices[ $term_id ] ?? null, $used, $state );
			$rows[]                       = array_merge(
				$row,
				array(
					'same_name'          => 0 === $row['creator_id'] && isset( $names[ self::name_key( $vendor['name'] ) ] ),
					'categories'         => $owned,
					'category_id'        => $category,
					'category'           => $kind,
					'category_others'    => $impact[0],
					'category_ambiguous' => $impact[1],
				)
			);

			if ( $row['user_id'] > 0 && self::migrates_user( $row['status'] ) ) {
				$taken[ $row['user_id'] ] = true;
			}

			if ( VendorImportReport::CATEGORY_MAP === $kind ) {
				$used[ $category ] = true;
			}
		}

		return new VendorImportReport( $this->source->version(), $rows );
	}

	/**
	 * One vendor's row (without the name warning and the category).
	 *
	 * @phpstan-param State $state
	 *
	 * @param int              $term_id Vendor.
	 * @param string           $name    Its name.
	 * @param array            $state   The migration's state.
	 * @param int              $choice  The administrator's chosen user (0: none).
	 * @param array<int, true> $taken   Users chosen for earlier vendors.
	 * @return array{term_id: int, name: string, status: string, users: list<VendorUser>, user_id: int, creator_id: int, creator_name: string, creator_active: bool, products: array{link: int, same: int, conflict: int}, link_ids: list<int>, settlement: string|null}
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function row( int $term_id, string $name, array $state, int $choice, array $taken ): array {
		$admins  = $this->source->admins( $term_id );
		$users   = $this->users( $admins );
		$mapped  = isset( $state['vendors'][ $term_id ] ) ? $this->creators->find( $state['vendors'][ $term_id ] ) : null;
		$user_id = 0;
		$creator = null;

		if ( null !== $mapped ) {
			$status  = VendorImportReport::IMPORTED;
			$creator = $mapped;
			$user_id = $mapped->user_id ?? 0;
		} elseif ( array() === $users && $this->has_pending_admin( $admins ) ) {
			$status = VendorImportReport::PENDING;
		} elseif ( array() === $users && array() !== $admins ) {
			$status = VendorImportReport::NO_ROLE;
		} elseif ( array() === $users ) {
			$status = isset( $state['pending'][ $term_id ] ) ? VendorImportReport::INTERRUPTED : VendorImportReport::NO_ADMIN;
		} else {
			$user_id = self::chosen( $users, $choice, $taken, $state['vendors'] );
			$creator = $this->creators->find_by_user_id( $user_id );
			$status  = null === $creator ? VendorImportReport::NEW : VendorImportReport::MATCHED;

			// A user can be migrated for one vendor only (ADR-020: one creator per user).
			if ( isset( $taken[ $user_id ] ) || ( null !== $creator && in_array( $creator->id, $state['vendors'], true ) ) ) {
				$status  = VendorImportReport::USER_TAKEN;
				$creator = null;
			}
		}

		$migrates              = VendorImportReport::migrates( $status );
		[ $counts, $link_ids ] = $this->products( $term_id, $migrates ? $creator : null, $state['products'][ $term_id ] ?? array(), $migrates );

		return array(
			'term_id'        => $term_id,
			'name'           => $name,
			'status'         => $status,
			'users'          => $users,
			'user_id'        => $user_id,
			'creator_id'     => $creator->id ?? 0,
			'creator_name'   => $creator->display_name ?? '',
			'creator_active' => null === $creator || $creator->is_active(),
			'products'       => $counts,
			'link_ids'       => $link_ids,
			'settlement'     => $this->settlement( $status, $creator ),
		);
	}

	/**
	 * A vendor's products, read once per dry run with their caches primed.
	 *
	 * @param int $term_id Vendor.
	 * @return list<int>
	 */
	private function product_ids( int $term_id ): array {
		if ( ! isset( $this->products_of[ $term_id ] ) ) {
			$ids = $this->source->product_ids( $term_id );
			self::prime( $ids );
			$this->products_of[ $term_id ] = $ids;
		}

		return $this->products_of[ $term_id ];
	}

	/**
	 * Primes the post, meta and term caches of products (one query each
	 * instead of one per product).
	 *
	 * @phpstan-param list<int> $ids
	 * @param int[] $ids Products.
	 */
	private static function prime( array $ids ): void {
		if ( array() !== $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, true );
		}
	}

	/**
	 * The product categories of a vendor's products, most products first
	 * (never the default category).
	 *
	 * @param int $term_id Vendor.
	 * @return list<array{id: int, name: string, count: int}>
	 */
	private function vendor_categories( int $term_id ): array {
		$counts  = array();
		$default = (int) get_option( 'default_product_cat', 0 );

		foreach ( $this->product_ids( $term_id ) as $product_id ) {
			$terms = get_the_terms( $product_id, CreatorCategoryService::TAXONOMY );

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( $term->term_id !== $default ) {
					$counts[ $term->term_id ] = ( $counts[ $term->term_id ] ?? 0 ) + 1;
				}
			}
		}

		$categories = array();

		foreach ( $counts as $category => $count ) {
			$term = get_term( $category, CreatorCategoryService::TAXONOMY );

			if ( $term instanceof WP_Term ) {
				$categories[] = array(
					'id'    => $category,
					'name'  => $term->name,
					'count' => $count,
				);
			}
		}

		usort( $categories, static fn( array $a, array $b ): int => array( -$a['count'], $a['id'] ) <=> array( -$b['count'], $b['id'] ) );

		return $categories;
	}

	/**
	 * The product category of a vendor, its status and what mapping it does
	 * to other products.
	 *
	 * @phpstan-param array{term_id: int, status: string, creator_id: int} $row
	 * @phpstan-param list<array{id: int, name: string, count: int}> $owned
	 * @phpstan-param State $state
	 *
	 * @param array            $row    The vendor's row.
	 * @param array            $owned  The vendor's products' categories, most first.
	 * @param int|null         $choice The administrator's choice (0: none; null: none made).
	 * @param array<int, true> $used   Categories mapped for earlier vendors.
	 * @param array            $state  The migration's state.
	 * @return array{0: int, 1: string|null, 2: array{0: int, 1: int}} Category id, status (null: the vendor is skipped), [other products linked, products made ambiguous].
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function category( array $row, array $owned, ?int $choice, array $used, array $state ): array {
		if ( ! VendorImportReport::migrates( $row['status'] ) ) {
			return array( 0, null, array( 0, 0 ) );
		}

		$own = $row['creator_id'] > 0 ? $this->categories->for_creator( $row['creator_id'] ) : array();

		// One category per creator (ADR-033): a creator that has one keeps it.
		if ( array() !== $own ) {
			return array( $own[0]->term_id, VendorImportReport::CATEGORY_MAPPED, array( 0, 0 ) );
		}

		if ( null !== $choice && ( 0 === $choice || self::is_category( $choice ) ) ) {
			$category = $choice;
		} else {
			$category = $this->proposal( $row['term_id'], $row['creator_id'], $owned, $used, $state );
		}

		if ( 0 === $category ) {
			return array( 0, VendorImportReport::CATEGORY_NONE, array( 0, 0 ) );
		}

		$kind = isset( $used[ $category ] ) || null !== $this->categories->for_category( $category ) ? VendorImportReport::CATEGORY_TAKEN : VendorImportReport::CATEGORY_MAP;

		return array( $category, $kind, VendorImportReport::CATEGORY_MAP === $kind ? $this->impact( $category, $row['term_id'], $row['creator_id'] ) : array( 0, 0 ) );
	}

	/**
	 * The proposed category: the first free category of the vendor's
	 * products that holds nothing but them; none when the migration mapped
	 * a category for this vendor before (the administrator removed it).
	 *
	 * @phpstan-param list<array{id: int, name: string, count: int}> $owned
	 * @phpstan-param State $state
	 *
	 * @param int              $term_id    Vendor.
	 * @param int              $creator_id The vendor's creator (0: a new one).
	 * @param array            $owned      The vendor's products' categories, most first.
	 * @param array<int, true> $used       Categories mapped for earlier vendors.
	 * @param array            $state      The migration's state.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function proposal( int $term_id, int $creator_id, array $owned, array $used, array $state ): int {
		if ( isset( $state['categories'][ $term_id ] ) ) {
			return 0;
		}

		foreach ( $owned as $category ) {
			if ( isset( $used[ $category['id'] ] ) || null !== $this->categories->for_category( $category['id'] ) ) {
				continue;
			}

			if ( array( 0, 0 ) === $this->impact( $category['id'], $term_id, $creator_id ) ) {
				return $category['id'];
			}
		}

		return 0;
	}

	/**
	 * What mapping a category to the vendor's creator does to products that
	 * are not the vendor's: unlinked ones are linked to the creator, and
	 * ones linked to another creator by category become ambiguous (ADR-033).
	 *
	 * @param int $category   Product category.
	 * @param int $term_id    Vendor.
	 * @param int $creator_id The vendor's creator (0: a new one).
	 * @return array{0: int, 1: int} Other products linked, products made ambiguous.
	 */
	private function impact( int $category, int $term_id, int $creator_id ): array {
		$key = $category . ':' . $term_id;

		if ( isset( $this->impact_of[ $key ] ) ) {
			return $this->impact_of[ $key ];
		}

		$ids    = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One category, in the administrator's dry run.
					array(
						'taxonomy'         => CreatorCategoryService::TAXONOMY,
						'field'            => 'term_id',
						'terms'            => array( $category ),
						'include_children' => false,
					),
				),
			)
		);
		$others = array_values( array_diff( array_map( 'intval', $ids ), $this->product_ids( $term_id ) ) );
		$impact = array( 0, 0 );
		self::prime( $others );

		foreach ( $others as $product_id ) {
			$current = $this->links->creator_id( $product_id );

			if ( null === $current && ! self::has_link( $product_id ) ) {
				++$impact[0];
			} elseif ( null !== $current && $current !== $creator_id && ProductLinkSource::Category->value === get_post_meta( $product_id, ProductCreatorLink::SOURCE_META_KEY, true ) ) {
				++$impact[1];
			}
		}

		$this->impact_of[ $key ] = $impact;

		return $impact;
	}

	/**
	 * Whether a term is a product category other than the default one.
	 *
	 * @param int $term_id Term id.
	 */
	private static function is_category( int $term_id ): bool {
		return $term_id > 0 && get_term( $term_id, CreatorCategoryService::TAXONOMY ) instanceof WP_Term && (int) get_option( 'default_product_cat', 0 ) !== $term_id;
	}

	/**
	 * A vendor's approved administrators (Vendor Admin or Vendor Manager),
	 * proposal first.
	 *
	 * @phpstan-param list<int> $admins
	 * @param int[] $admins The vendor's administrators.
	 * @return list<VendorUser>
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function users( array $admins ): array {
		$users = array();

		foreach ( $admins as $user_id ) {
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
	 * Whether one of the administrators is not approved yet.
	 *
	 * @phpstan-param list<int> $admins
	 * @param int[] $admins The vendor's administrators.
	 */
	private function has_pending_admin( array $admins ): bool {
		foreach ( $admins as $user_id ) {
			if ( in_array( ProductVendorsSource::PENDING_ROLE, $this->source->roles( $user_id ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The chosen user: the administrator's choice when it is one of the
	 * vendor's users, else the first free one (not chosen for an earlier
	 * vendor, and not of a creator from another vendor), else the first.
	 *
	 * @phpstan-param non-empty-list<VendorUser> $users
	 *
	 * @param array[]          $users   The vendor's users, proposal first.
	 * @param int              $choice  The administrator's choice (0: none).
	 * @param array<int, true> $taken   Users chosen for earlier vendors.
	 * @param array<int, int>  $mapping Vendor → creator of earlier runs.
	 */
	private static function chosen( array $users, int $choice, array $taken, array $mapping ): int {
		foreach ( $users as $user ) {
			if ( $user['id'] === $choice ) {
				return $choice;
			}
		}

		foreach ( $users as $user ) {
			if ( ! isset( $taken[ $user['id'] ] ) && ! in_array( $user['creator_id'], $mapping, true ) ) {
				return $user['id'];
			}
		}

		return $users[0]['id'];
	}

	/**
	 * Product counts and the products to link: to link, linked to this
	 * creator already, linked to another creator or with an invalid link
	 * (conflict; never replaced). A product the migration linked before and
	 * the administrator unlinked afterwards is left out.
	 *
	 * @param int          $term_id  Vendor.
	 * @param Creator|null $creator  The vendor's creator (null: a new one, or skipped).
	 * @phpstan-param list<int> $done
	 * @param int[]        $done     Products the migration linked for this vendor before.
	 * @param bool         $migrates Whether the vendor is migrated.
	 * @return array{0: array{link: int, same: int, conflict: int}, 1: list<int>}
	 */
	private function products( int $term_id, ?Creator $creator, array $done, bool $migrates ): array {
		$counts = array(
			'link'     => 0,
			'same'     => 0,
			'conflict' => 0,
		);
		$link   = array();

		foreach ( $this->product_ids( $term_id ) as $product_id ) {
			$current = $this->links->creator_id( $product_id );

			if ( null === $current && ! self::has_link( $product_id ) ) {
				if ( ! in_array( $product_id, $done, true ) ) {
					++$counts['link'];
					$link[] = $product_id;
				}
			} elseif ( null !== $creator && $current === $creator->id ) {
				++$counts['same'];
			} else {
				++$counts['conflict'];
			}
		}

		return array( $counts, $migrates ? $link : array() );
	}

	/**
	 * Whether a product holds a link value at all (also an invalid one, which
	 * ProductCreatorLink::creator_id() reads as no creator).
	 *
	 * @param int $product_id Product.
	 */
	private static function has_link( int $product_id ): bool {
		return '' !== (string) get_post_meta( $product_id, ProductCreatorLink::META_KEY, true );
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
