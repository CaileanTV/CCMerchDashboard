<?php
/**
 * Dry runs of mapping changes.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use LogicException;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * What creating or removing a mapping would do, before anything is written
 * (ADR-033 point 10). The planned mapping is layered over the stored ones
 * (HypotheticalMappings) and the existing reconciliation (CreatorCategoryReconciler,
 * CategoryReconciliationBatch::preview()) evaluates it, so the preview uses
 * exactly the rules the confirmed change will run with. Nothing here writes:
 * no mapping, no product meta, no audit.
 *
 * Only for users with `merchboard_manage_creators`.
 */
final class CategoryMappingPreview {

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryService    $service    Mapping rules (check_link()).
	 * @param CreatorCategoryRepository $stored     Stored mappings.
	 * @param ProductStore              $products   Product reads.
	 * @param CreatorRepository         $creators   Creator reads.
	 * @param ProductCreatorLink        $links      Required by the reconciler; not called by a dry run.
	 * @param ReconciliationCandidates  $candidates Products to look at.
	 * @param AuditTrail                $audit      Required by the batch; not written by a dry run.
	 * @param Logger                    $logger     Technical log.
	 */
	public function __construct(
		private CreatorCategoryService $service,
		private CreatorCategoryRepository $stored,
		private ProductStore $products,
		private CreatorRepository $creators,
		private ProductCreatorLink $links,
		private ReconciliationCandidates $candidates,
		private AuditTrail $audit,
		private Logger $logger
	) {
	}

	/**
	 * Dry run of mapping the category to the creator: what the first
	 * reconciliation of the category would do. The mapping must be allowed.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $term_id    `product_cat` term id.
	 * @throws CreatorCategoryException When the mapping is not allowed (nothing is evaluated).
	 * @throws DatabaseException When a read fails.
	 * @throws LogicException When the paging does not advance.
	 */
	public function for_new_mapping( int $creator_id, int $term_id ): CategorySyncReport {
		$this->service->check_link( $creator_id, $term_id );

		return $this->batch( HypotheticalMappings::with( $this->stored, $term_id, $creator_id ) )
			->preview( CategoryReconciliationTrigger::MappingCreated, self::user(), ReconciliationScope::category( $term_id ) );
	}

	/**
	 * Dry run of removing the mapping with `remove_links`: the category and
	 * every category link of the creator, also on products that left the
	 * category. For an orphaned mapping, as the category deletion it stands for.
	 *
	 * @param CreatorCategory $mapping Stored mapping.
	 * @throws CreatorCategoryException When the current user may not manage creators.
	 * @throws DatabaseException When a read fails.
	 * @throws LogicException When the paging does not advance.
	 */
	public function for_removal( CreatorCategory $mapping ): CategorySyncReport {
		self::authorize();

		$trigger = CreatorCategoryService::is_orphaned( $mapping ) ? CategoryReconciliationTrigger::TermDeleted : CategoryReconciliationTrigger::MappingRemoved;

		return $this->batch( HypotheticalMappings::without( $this->stored, $mapping->term_id ) )
			->preview( $trigger, self::user(), ReconciliationScope::mapping( $mapping->term_id, $mapping->creator_id ) );
	}

	/**
	 * The category links a removal of the mapping would take away from its
	 * creator: products whose link to the creator is category-managed and no
	 * longer justified without this mapping (removed or moved). These are the
	 * links "Keep as manual" converts. Manual links are never among them.
	 * Scans the removal scope after the cursor until $limit links are found.
	 *
	 * @param CreatorCategory $mapping  Stored mapping.
	 * @param int             $after_id Cursor (0 to start).
	 * @param int             $limit    Most product ids to return.
	 * @return array{ids: list<int>, after_id: int, done: bool} Ids, the cursor to continue from, and whether the scope was scanned to its end.
	 * @throws CreatorCategoryException When the current user may not manage creators.
	 * @throws DatabaseException When a read fails.
	 * @throws LogicException When the paging does not advance.
	 */
	public function conversion_targets( CreatorCategory $mapping, int $after_id, int $limit ): array {
		self::authorize();

		$reconciler = $this->reconciler( HypotheticalMappings::without( $this->stored, $mapping->term_id ) );
		$scope      = ReconciliationScope::mapping( $mapping->term_id, $mapping->creator_id );
		$ids        = array();
		$after      = max( 0, $after_id );

		do {
			$page  = $this->candidates->after( $scope, $after, CategoryReconciliationBatch::PAGE );
			$count = count( $page );

			foreach ( $page as $product_id ) {
				self::assert_advances( $product_id, $after );
				$after = $product_id;
				$plan  = $reconciler->plan_product( $product_id, CategoryReconciliationTrigger::MappingRemoved );

				if ( $plan->writes && $plan->from === $mapping->creator_id ) {
					$ids[] = $product_id;
				}

				if ( count( $ids ) >= $limit ) {
					return array(
						'ids'      => $ids,
						'after_id' => $after,
						'done'     => false,
					);
				}
			}
		} while ( CategoryReconciliationBatch::PAGE === $count );

		return array(
			'ids'      => $ids,
			'after_id' => $after,
			'done'     => true,
		);
	}

	/**
	 * Number of category links "Keep as manual" would convert.
	 *
	 * @param CreatorCategory $mapping Stored mapping.
	 * @throws CreatorCategoryException When the current user may not manage creators.
	 * @throws DatabaseException When a read fails.
	 * @throws LogicException When the paging does not advance.
	 */
	public function conversion_count( CreatorCategory $mapping ): int {
		return count( $this->conversion_targets( $mapping, 0, PHP_INT_MAX )['ids'] );
	}

	/**
	 * A batch evaluating with the given mappings.
	 *
	 * @param MappingLookup $mappings Mappings to evaluate with.
	 */
	private function batch( MappingLookup $mappings ): CategoryReconciliationBatch {
		return new CategoryReconciliationBatch( $this->candidates, $this->reconciler( $mappings ), $this->audit, $this->logger );
	}

	/**
	 * A reconciler evaluating with the given mappings.
	 *
	 * @param MappingLookup $mappings Mappings to evaluate with.
	 */
	private function reconciler( MappingLookup $mappings ): CreatorCategoryReconciler {
		return new CreatorCategoryReconciler( $this->products, $mappings, $this->creators, $this->links, $this->logger );
	}

	/**
	 * Candidates come in ascending order after the cursor: never twice, never looping.
	 *
	 * @param int $product_id Candidate.
	 * @param int $after      Cursor.
	 * @throws LogicException When the paging does not advance.
	 */
	private static function assert_advances( int $product_id, int $after ): void {
		if ( $product_id <= $after ) {
			throw new LogicException( 'Reconciliation candidates did not advance.' );
		}
	}

	/**
	 * Requires the creator-management capability.
	 *
	 * @throws CreatorCategoryException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			throw new CreatorCategoryException( CreatorCategoryException::NOT_AUTHORIZED );
		}
	}

	/**
	 * The current user, or null.
	 */
	private static function user(): ?int {
		$user_id = get_current_user_id();

		return $user_id > 0 ? $user_id : null;
	}
}
