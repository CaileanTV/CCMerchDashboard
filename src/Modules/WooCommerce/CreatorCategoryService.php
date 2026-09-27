<?php
/**
 * Creator ↔ product category mapping (ADR-033).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * The only write path for the creator ↔ product category mapping (ADR-033).
 * A mapping is an administrative rule, never ownership: this service does not
 * read or write `_merchboard_creator_id` and does not reconcile products.
 * Changing or removing a mapping changes no product link.
 *
 * - Authorization: `merchboard_manage_creators` (the creator-management
 *   capability); creators have no self-service.
 * - A mapping needs an existing `product_cat` term that is not the default
 *   category (`default_product_cat`), and an existing, active creator.
 * - One creator per category (the table's primary key) and, in phase 17, one
 *   category per creator (ADR-033 point 1). A category mapped to another
 *   creator is rejected, never moved silently: move it by unlinking first.
 * - Mapping an already mapped pair is a no-op, and so is removing a mapping
 *   that does not exist; neither writes audit nor fires an event.
 * - Committed changes fire `merchboard_creator_category_linked`/`_unlinked`,
 *   on which the WooCommerce module queues the reconciliation (ADR-033).
 * - Each change and its audit record are written in one transaction
 *   (ADR-023/024): on failure both are rolled back.
 * - A creator who becomes inactive keeps its mappings; it only cannot receive
 *   a new one until it is active again. Reactivation changes nothing here.
 */
final class CreatorCategoryService {

	/**
	 * Product category taxonomy.
	 */
	public const TAXONOMY = 'product_cat';

	/**
	 * Action fired after a mapping was created.
	 */
	public const LINKED_ACTION = 'merchboard_creator_category_linked';

	/**
	 * Action fired after a mapping was removed.
	 */
	public const UNLINKED_ACTION = 'merchboard_creator_category_unlinked';

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryRepository $mappings    Mapping storage.
	 * @param CreatorRepository         $creators    Creator storage.
	 * @param AuditTrail                $audit       Audit trail.
	 * @param Transaction               $transaction Database transaction.
	 */
	public function __construct(
		private CreatorCategoryRepository $mappings,
		private CreatorRepository $creators,
		private AuditTrail $audit,
		private Transaction $transaction
	) {
	}

	/**
	 * The mapping of a category, if any. Read-only.
	 *
	 * @param int $term_id Term id.
	 * @throws DatabaseException When the read fails.
	 */
	public function for_category( int $term_id ): ?CreatorCategory {
		return $term_id > 0 ? $this->mappings->find( $term_id ) : null;
	}

	/**
	 * The categories mapped to a creator. Read-only.
	 *
	 * @param int $creator_id Creator id.
	 * @return list<CreatorCategory>
	 * @throws DatabaseException When the read fails.
	 */
	public function for_creator( int $creator_id ): array {
		return $creator_id > 0 ? $this->mappings->for_creator( $creator_id ) : array();
	}

	/**
	 * Checks that the category may be mapped to the creator, without writing
	 * anything: the same rules as link(). Used before a dry run (ADR-033 point
	 * 10), so a preview is only shown for a mapping that could be created.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $term_id    `product_cat` term id.
	 * @throws CreatorCategoryException When the mapping is not allowed, also when it already exists.
	 * @throws DatabaseException When a read fails.
	 */
	public function check_link( int $creator_id, int $term_id ): void {
		$this->authorize();
		$this->assert_product_category( $term_id );

		$creator  = $this->existing_creator( $creator_id );
		$existing = $this->mappings->find( $term_id );

		$this->assert_new( $creator, $existing );
		$this->assert_mappable( $creator, $existing );
	}

	/**
	 * Maps a product category to a creator.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $term_id    `product_cat` term id.
	 * @return bool Whether a mapping was created (false: it already existed).
	 * @throws CreatorCategoryException When the request is not allowed or invalid (nothing changed).
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function link( int $creator_id, int $term_id ): bool {
		$this->authorize();
		$this->assert_product_category( $term_id );

		$creator  = $this->existing_creator( $creator_id );
		$existing = $this->mappings->find( $term_id );

		if ( null !== $existing && $existing->creator_id === $creator->id ) {
			return false;
		}

		$this->assert_mappable( $creator, $existing );

		$user_id = get_current_user_id();

		$this->transaction->run(
			function () use ( $creator, $term_id, $user_id ): void {
				$this->mappings->insert( $term_id, $creator->id, $user_id > 0 ? $user_id : null );
				$this->audit->record( AuditAction::CreatorCategoryLinked, $creator->id, array( 'term_id' => $term_id ) );
			}
		);

		/**
		 * Fires after a mapping was created and committed (ADR-033: the first
		 * reconciliation of the category is queued).
		 *
		 * @param int      $term_id         Term id.
		 * @param int      $creator_id      Creator id.
		 * @param int|null $trigger_user_id User who created it.
		 */
		do_action( 'merchboard_creator_category_linked', $term_id, $creator->id, $user_id > 0 ? $user_id : null );

		return true;
	}

	/**
	 * Removes a creator's mapping of a product category with the admin's
	 * explicit choice for its category links (ADR-033 point 10), recorded in
	 * the audit record. The service itself changes no product link: after the
	 * commit the reconciliation of the mapping's links is queued (event below).
	 * For `keep_as_manual` the caller must have converted those links first;
	 * CategoryMappingRemoval is that caller and removes the mapping only when no
	 * category link of it is left, so the reconciliation finds manual links it
	 * never touches. The term does not need to exist any more.
	 *
	 * @param int                  $creator_id Creator id.
	 * @param int                  $term_id    Term id.
	 * @param MappingRemovalChoice $choice     What happens to the category links.
	 * @return bool Whether a mapping was removed (false: there was none for this creator).
	 * @throws CreatorCategoryException When the request is not allowed (nothing changed).
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function unlink( int $creator_id, int $term_id, MappingRemovalChoice $choice ): bool {
		$this->authorize();

		$existing = $term_id > 0 && $creator_id > 0 ? $this->mappings->find( $term_id ) : null;

		if ( null === $existing || $existing->creator_id !== $creator_id ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $existing, $choice ): void {
				$this->mappings->delete( $existing->term_id, $existing->creator_id );
				$this->audit->record(
					AuditAction::CreatorCategoryUnlinked,
					$existing->creator_id,
					array(
						'term_id' => $existing->term_id,
						'reason'  => 'admin',
						'choice'  => $choice->value,
					)
				);
			}
		);

		$user_id = get_current_user_id();
		self::unlinked( $existing, 'admin', $user_id > 0 ? $user_id : null );

		return true;
	}

	/**
	 * System consequence of a deleted `product_cat` term (ADR-033 point 11):
	 * its mapping is removed with `reason: term_deleted`, audited as system
	 * work (actor NULL) with the deleting user as `trigger_user_id`.
	 *
	 * Not a user operation, so there is no capability check: WordPress already
	 * authorized the deletion. It only acts for a term that no longer exists,
	 * so it can never remove the mapping of a living category.
	 *
	 * @param int      $term_id         Deleted term id.
	 * @param int|null $trigger_user_id User who deleted the term, if known.
	 * @return bool Whether a mapping was removed.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function remove_for_deleted_term( int $term_id, ?int $trigger_user_id ): bool {
		if ( $term_id < 1 || get_term( $term_id ) instanceof WP_Term ) {
			return false;
		}

		$existing = $this->mappings->find( $term_id );

		if ( null === $existing ) {
			return false;
		}

		$context = array(
			'term_id' => $existing->term_id,
			'reason'  => 'term_deleted',
		);

		if ( null !== $trigger_user_id && $trigger_user_id > 0 ) {
			$context['trigger_user_id'] = $trigger_user_id;
		}

		$this->transaction->run(
			function () use ( $existing, $context ): void {
				$this->mappings->delete( $existing->term_id, $existing->creator_id );
				$this->audit->record_system( AuditAction::CreatorCategoryUnlinked, $existing->creator_id, $context );
			}
		);

		self::unlinked( $existing, 'term_deleted', $trigger_user_id );

		return true;
	}

	/**
	 * Removes the mapping of a category that no longer exists (deleted while
	 * the WooCommerce module was not loaded, so remove_for_deleted_term() never
	 * ran), with the admin's explicit choice. Treated like the deletion it
	 * stands for (ADR-033 point 11, `reason: term_deleted`), but as an admin
	 * operation: capability check, actor is the admin, `choice` recorded. A
	 * mapping whose category still exists is never removed here.
	 *
	 * @param int                  $creator_id Creator id.
	 * @param int                  $term_id    Deleted term id.
	 * @param MappingRemovalChoice $choice     What happens to the category links.
	 * @return bool Whether a mapping was removed.
	 * @throws CreatorCategoryException When the request is not allowed (nothing changed).
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function remove_orphaned( int $creator_id, int $term_id, MappingRemovalChoice $choice ): bool {
		$this->authorize();

		$existing = $term_id > 0 && $creator_id > 0 ? $this->mappings->find( $term_id ) : null;

		if ( null === $existing || $existing->creator_id !== $creator_id || ! self::is_orphaned( $existing ) ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $existing, $choice ): void {
				$this->mappings->delete( $existing->term_id, $existing->creator_id );
				$this->audit->record(
					AuditAction::CreatorCategoryUnlinked,
					$existing->creator_id,
					array(
						'term_id' => $existing->term_id,
						'reason'  => 'term_deleted',
						'choice'  => $choice->value,
					)
				);
			}
		);

		$user_id = get_current_user_id();
		self::unlinked( $existing, 'term_deleted', $user_id > 0 ? $user_id : null );

		return true;
	}

	/**
	 * Whether a mapping points at a category that no longer exists (or is no
	 * longer a `product_cat`). Such a mapping creates no ownership: no product
	 * can be in it.
	 *
	 * @param CreatorCategory $mapping Mapping.
	 */
	public static function is_orphaned( CreatorCategory $mapping ): bool {
		$term = get_term( $mapping->term_id );

		return ! $term instanceof WP_Term || self::TAXONOMY !== $term->taxonomy;
	}

	/**
	 * Fires the removal event after the commit.
	 *
	 * @param CreatorCategory $mapping         Removed mapping.
	 * @param string          $reason          `admin` or `term_deleted`.
	 * @param int|null        $trigger_user_id User behind it.
	 */
	private static function unlinked( CreatorCategory $mapping, string $reason, ?int $trigger_user_id ): void {
		/**
		 * Fires after a mapping was removed and committed (ADR-033: its category
		 * links are reconciled).
		 *
		 * @param int      $term_id         Term id.
		 * @param int      $creator_id      Creator id.
		 * @param string   $reason          `admin` (choice `remove_links`) or `term_deleted`.
		 * @param int|null $trigger_user_id User behind it.
		 */
		do_action( 'merchboard_creator_category_unlinked', $mapping->term_id, $mapping->creator_id, $reason, $trigger_user_id );
	}

	/**
	 * A preview is only for a mapping that does not exist yet.
	 *
	 * @param Creator              $creator  Creator.
	 * @param CreatorCategory|null $existing Current mapping of the category.
	 * @throws CreatorCategoryException When the category is already mapped to this creator.
	 */
	private function assert_new( Creator $creator, ?CreatorCategory $existing ): void {
		if ( null !== $existing && $existing->creator_id === $creator->id ) {
			throw new CreatorCategoryException( CreatorCategoryException::ALREADY_MAPPED );
		}
	}

	/**
	 * Requires the creator-management capability.
	 *
	 * @throws CreatorCategoryException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			throw new CreatorCategoryException( CreatorCategoryException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Loads an existing creator.
	 *
	 * @param int $creator_id Creator id.
	 * @throws CreatorCategoryException When the creator does not exist.
	 */
	private function existing_creator( int $creator_id ): Creator {
		$creator = $creator_id > 0 ? $this->creators->find( $creator_id ) : null;

		if ( null === $creator ) {
			throw new CreatorCategoryException( CreatorCategoryException::CREATOR_NOT_FOUND );
		}

		return $creator;
	}

	/**
	 * Checks that a new mapping may be created: the category is not mapped to
	 * another creator (never moved silently), the creator is active, and the
	 * creator has no category yet (phase 17: one per creator, ADR-033).
	 *
	 * @param Creator              $creator  Creator.
	 * @param CreatorCategory|null $existing Current mapping of the category (of another creator).
	 * @throws CreatorCategoryException When the mapping is not allowed.
	 */
	private function assert_mappable( Creator $creator, ?CreatorCategory $existing ): void {
		if ( null !== $existing ) {
			throw new CreatorCategoryException( CreatorCategoryException::CATEGORY_MAPPED_ELSEWHERE );
		}

		if ( CreatorStatus::Active !== $creator->status ) {
			throw new CreatorCategoryException( CreatorCategoryException::CREATOR_INACTIVE );
		}

		if ( array() !== $this->mappings->for_creator( $creator->id ) ) {
			throw new CreatorCategoryException( CreatorCategoryException::CREATOR_HAS_CATEGORY );
		}
	}

	/**
	 * Requires an existing `product_cat` term that is not the default category.
	 *
	 * @param int $term_id Term id.
	 * @throws CreatorCategoryException When the term is unknown, of another taxonomy or the default.
	 */
	private function assert_product_category( int $term_id ): void {
		$term = $term_id > 0 ? get_term( $term_id ) : null;

		if ( ! $term instanceof WP_Term ) {
			throw new CreatorCategoryException( CreatorCategoryException::TERM_NOT_FOUND );
		}

		if ( self::TAXONOMY !== $term->taxonomy ) {
			throw new CreatorCategoryException( CreatorCategoryException::NOT_PRODUCT_CATEGORY );
		}

		if ( (int) get_option( 'default_product_cat', 0 ) === $term->term_id ) {
			throw new CreatorCategoryException( CreatorCategoryException::DEFAULT_CATEGORY );
		}
	}
}
