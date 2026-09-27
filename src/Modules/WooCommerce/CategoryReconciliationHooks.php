<?php
/**
 * Turns WordPress, WooCommerce and Merch Dashboard events into queued
 * category reconciliation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * The event handlers of ADR-033 point 6. They only queue work through
 * CategoryReconciliationScheduler and never write a link, so nothing is
 * reconciled in the middle of WooCommerce's own product save (eventual
 * consistency). The one synchronous write is the removal of a deleted
 * category's mapping (CreatorCategoryService::remove_for_deleted_term()).
 *
 * - `set_object_terms` / `deleted_term_relationships` (`product_cat` of a
 *   parent `product`): reconcile that product. Every path that changes
 *   product categories ends in one of them (wp-admin, quick/bulk edit, CSV
 *   import, REST, WP-CLI, WooCommerce CRUD, term deletion).
 * - `transition_post_status`: a product restored from the trash or leaving
 *   auto-draft is reconciled again (trash itself changes nothing).
 * - `pre_delete_term` / `delete_term` (mapped `product_cat`): the products'
 *   relationship removals in between get `reason: term_deleted`; after the
 *   term is gone its mapping is removed as system work, which queues the
 *   reconciliation of the creator's remaining category links.
 * - Mapping created: reconcile that category (the confirmed first
 *   reconciliation, bounded to the category; never a full run).
 * - Mapping removed (`remove_links`): reconcile the category and every
 *   category link of the creator, also on products that left the category.
 * - Creator reactivated: reconcile each of its mapped categories.
 */
final class CategoryReconciliationHooks {

	private const TAXONOMY = CreatorCategoryService::TAXONOMY;

	/**
	 * Statuses a product is not reconciled in (ProductCreatorLink).
	 */
	private const INACTIVE_STATUSES = array( 'trash', 'auto-draft' );

	/**
	 * Mapped terms being deleted in this request: term_taxonomy_id => term_id.
	 *
	 * @var array<int, int>
	 */
	private array $deleting = array();

	/**
	 * Whether the handlers are hooked.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param CategoryReconciliationScheduler $scheduler Queues the work.
	 * @param CreatorCategoryService          $mappings  Mapping reads; removal for deleted terms.
	 * @param Logger                          $logger    Technical log.
	 */
	public function __construct(
		private CategoryReconciliationScheduler $scheduler,
		private CreatorCategoryService $mappings,
		private Logger $logger
	) {
	}

	/**
	 * Hooks the handlers once.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		add_action( 'set_object_terms', array( $this, 'terms_set' ), 10, 6 );
		add_action( 'deleted_term_relationships', array( $this, 'terms_removed' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'status_changed' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'term_deleting' ), 10, 2 );
		add_action( 'delete_term', array( $this, 'term_deleted' ), 10, 3 );
		add_action( CreatorCategoryService::LINKED_ACTION, array( $this, 'mapping_created' ), 10, 3 );
		add_action( CreatorCategoryService::UNLINKED_ACTION, array( $this, 'mapping_removed' ), 10, 4 );
		add_action( CreatorService::STATUS_CHANGED_ACTION, array( $this, 'creator_status_changed' ), 10, 3 );
	}

	/**
	 * `set_object_terms`: queues the product when its direct categories
	 * changed (an unchanged save queues nothing).
	 *
	 * @param mixed $object_id  Object id.
	 * @param mixed $terms      Terms as passed.
	 * @param mixed $tt_ids     New term_taxonomy ids.
	 * @param mixed $taxonomy   Taxonomy.
	 * @param mixed $append     Whether appended.
	 * @param mixed $old_tt_ids Previous term_taxonomy ids.
	 */
	public function terms_set( mixed $object_id, mixed $terms, mixed $tt_ids, mixed $taxonomy, mixed $append = false, mixed $old_tt_ids = array() ): void {
		$new = self::ids( $tt_ids );
		$old = self::ids( $old_tt_ids );

		if ( self::TAXONOMY !== $taxonomy || $new === $old ) {
			return;
		}

		$this->queue_product( $object_id, array_values( array_diff( $old, $new ) ) );
	}

	/**
	 * `deleted_term_relationships`: queues the product that left categories.
	 *
	 * @param mixed $object_id Object id.
	 * @param mixed $tt_ids    Removed term_taxonomy ids.
	 * @param mixed $taxonomy  Taxonomy.
	 */
	public function terms_removed( mixed $object_id, mixed $tt_ids, mixed $taxonomy ): void {
		if ( self::TAXONOMY === $taxonomy ) {
			$this->queue_product( $object_id, self::ids( $tt_ids ) );
		}
	}

	/**
	 * `transition_post_status`: restored from the trash or out of auto-draft.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 */
	public function status_changed( mixed $new_status, mixed $old_status, mixed $post ): void {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}

		if ( in_array( $old_status, self::INACTIVE_STATUSES, true ) && ! in_array( $new_status, self::INACTIVE_STATUSES, true ) ) {
			$this->scheduler->product( $post->ID, CategoryReconciliationTrigger::CategoryChanged, self::current_user() );
		}
	}

	/**
	 * `pre_delete_term`: remembers a mapped category that is being deleted.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function term_deleting( mixed $term_id, mixed $taxonomy ): void {
		if ( self::TAXONOMY !== $taxonomy || ! is_numeric( $term_id ) ) {
			return;
		}

		$term = get_term( (int) $term_id, self::TAXONOMY );

		if ( ! $term instanceof WP_Term || null === $this->mapping( $term->term_id ) ) {
			return;
		}

		$this->deleting[ $term->term_taxonomy_id ] = $term->term_id;
	}

	/**
	 * `delete_term`: the category is gone; its mapping is removed.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term_taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function term_deleted( mixed $term_id, mixed $tt_id, mixed $taxonomy ): void {
		if ( self::TAXONOMY !== $taxonomy || ! is_numeric( $term_id ) || ! in_array( (int) $term_id, $this->deleting, true ) ) {
			return;
		}

		$this->deleting = array_filter( $this->deleting, static fn( int $id ): bool => $id !== (int) $term_id );

		try {
			$this->mappings->remove_for_deleted_term( (int) $term_id, self::current_user() );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'The mapping of a deleted product category could not be removed.',
				array(
					'term_id'   => (int) $term_id,
					'exception' => $e,
				)
			);
		}
	}

	/**
	 * A mapping was created: reconcile the category.
	 *
	 * @param mixed $term_id         Term id.
	 * @param mixed $creator_id      Creator id.
	 * @param mixed $trigger_user_id User.
	 */
	public function mapping_created( mixed $term_id, mixed $creator_id, mixed $trigger_user_id = null ): void {
		if ( is_int( $term_id ) && $term_id > 0 ) {
			$this->scheduler->run( ReconciliationScope::category( $term_id ), CategoryReconciliationTrigger::MappingCreated, is_int( $trigger_user_id ) ? $trigger_user_id : null );
		}
	}

	/**
	 * A mapping was removed: reconcile the category (if it still exists) and
	 * the creator's category links wherever the products are now.
	 *
	 * @param mixed $term_id         Term id.
	 * @param mixed $creator_id      Creator id.
	 * @param mixed $reason          `admin` or `term_deleted`.
	 * @param mixed $trigger_user_id User.
	 */
	public function mapping_removed( mixed $term_id, mixed $creator_id, mixed $reason, mixed $trigger_user_id = null ): void {
		if ( ! is_int( $term_id ) || $term_id < 1 || ! is_int( $creator_id ) || $creator_id < 1 ) {
			return;
		}

		$user = is_int( $trigger_user_id ) ? $trigger_user_id : null;

		if ( 'term_deleted' === $reason ) {
			$this->scheduler->run( ReconciliationScope::mapping( null, $creator_id ), CategoryReconciliationTrigger::TermDeleted, $user );
		} elseif ( 'admin' === $reason ) {
			$this->scheduler->run( ReconciliationScope::mapping( $term_id, $creator_id ), CategoryReconciliationTrigger::MappingRemoved, $user );
		}
	}

	/**
	 * A creator became active again: reconcile each of its categories.
	 *
	 * @param mixed $creator_id Creator id.
	 * @param mixed $status     New status.
	 * @param mixed $previous   Previous status.
	 */
	public function creator_status_changed( mixed $creator_id, mixed $status, mixed $previous ): void {
		if ( ! is_int( $creator_id ) || CreatorStatus::Active->value !== $status || CreatorStatus::Inactive->value !== $previous ) {
			return;
		}

		try {
			$mappings = $this->mappings->for_creator( $creator_id );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'The categories of a reactivated creator could not be read.',
				array(
					'creator_id' => $creator_id,
					'exception'  => $e,
				)
			);

			return;
		}

		foreach ( $mappings as $mapping ) {
			$this->scheduler->run( ReconciliationScope::category( $mapping->term_id ), CategoryReconciliationTrigger::CreatorActivated, self::current_user() );
		}
	}

	/**
	 * Queues a parent product; `term_deleted` when it left a category that is
	 * being deleted.
	 *
	 * @phpstan-param list<int> $removed
	 *
	 * @param mixed $object_id Object id.
	 * @param int[] $removed   Term_taxonomy ids it left.
	 */
	private function queue_product( mixed $object_id, array $removed ): void {
		if ( ! is_numeric( $object_id ) || (int) $object_id < 1 || 'product' !== get_post_type( (int) $object_id ) ) {
			return;
		}

		$deleted = array() !== array_intersect( $removed, array_keys( $this->deleting ) );

		$this->scheduler->product(
			(int) $object_id,
			$deleted ? CategoryReconciliationTrigger::TermDeleted : CategoryReconciliationTrigger::CategoryChanged,
			self::current_user()
		);
	}

	/**
	 * The mapping of a term, or null (also when it cannot be read).
	 *
	 * @param int $term_id Term id.
	 */
	private function mapping( int $term_id ): ?CreatorCategory {
		try {
			return $this->mappings->for_category( $term_id );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'The mapping of a product category could not be read.',
				array(
					'term_id'   => $term_id,
					'exception' => $e,
				)
			);

			return null;
		}
	}

	/**
	 * Sorted, unique positive ids.
	 *
	 * @param mixed $ids Ids.
	 * @return list<int>
	 */
	private static function ids( mixed $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * The current user, or null (WP-CLI, cron).
	 */
	private static function current_user(): ?int {
		$user_id = get_current_user_id();

		return $user_id > 0 ? $user_id : null;
	}
}
