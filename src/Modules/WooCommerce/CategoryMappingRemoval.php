<?php
/**
 * Removing a mapping with the admin's explicit choice.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use LogicException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The two ways of ADR-033 point 10 to remove a mapping (also an orphaned
 * one, whose category was deleted while the module was not loaded):
 *
 * - remove_links(): the mapping is removed and the reconciliation of its
 *   links is queued (step 9b); the category links go, manual links stay.
 * - keep_as_manual(): the mapping's category links are first converted to
 *   manual ones through ProductCreatorLink::keep_as_manual(), one product per
 *   transaction, in the admin's request (owner decision, step 10: manual
 *   work needs the admin's `edit_product` per product). At most MAX_CONVERSIONS
 *   products per call. The mapping is removed only when a fresh scan finds no
 *   category link of it left and no product failed; otherwise it stays and
 *   the result says what is left, so the admin can run it again. A repeat
 *   converts nothing twice (already manual links are no-ops).
 *
 * The mapping is therefore never gone while its "keep" is unfinished: the
 * state is always "mapping present, some links converted" or "mapping gone,
 * all its links manual". There is no multi-product transaction.
 */
final class CategoryMappingRemoval {

	/**
	 * Most products converted per keep_as_manual() call.
	 */
	public const MAX_CONVERSIONS = 200;

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryService    $service Mapping writes.
	 * @param CreatorCategoryRepository $stored  Mapping reads.
	 * @param CategoryMappingPreview    $preview The links a removal affects.
	 * @param ProductCreatorLink        $links   The only writer of product links.
	 * @param Logger                    $logger  Technical log.
	 */
	public function __construct(
		private CreatorCategoryService $service,
		private CreatorCategoryRepository $stored,
		private CategoryMappingPreview $preview,
		private ProductCreatorLink $links,
		private Logger $logger
	) {
	}

	/**
	 * Removes the mapping; its category links are removed by the queued
	 * reconciliation.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $term_id    Term id.
	 * @return bool Whether a mapping was removed.
	 * @throws CreatorCategoryException When the request is not allowed (nothing changed).
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function remove_links( int $creator_id, int $term_id ): bool {
		$mapping = $this->mapping( $creator_id, $term_id );

		if ( null === $mapping ) {
			return false;
		}

		return $this->remove( $mapping, MappingRemovalChoice::RemoveLinks );
	}

	/**
	 * Converts the mapping's category links to manual ones and, when none is
	 * left, removes the mapping.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $term_id    Term id.
	 * @throws CreatorCategoryException When the request is not allowed (nothing changed).
	 * @throws DatabaseException When a read fails or the mapping cannot be removed.
	 * @throws LogicException When the paging does not advance.
	 */
	public function keep_as_manual( int $creator_id, int $term_id ): KeepAsManualResult {
		$mapping = $this->mapping( $creator_id, $term_id );

		if ( null === $mapping ) {
			return new KeepAsManualResult( 0, array(), false, false, false );
		}

		$context   = array(
			'term_id' => $mapping->term_id,
			'choice'  => MappingRemovalChoice::KeepAsManual->value,
		);
		$converted = 0;
		$failed    = array();
		$attempts  = 0;
		$after     = 0;

		do {
			$page = $this->preview->conversion_targets( $mapping, $after, self::MAX_CONVERSIONS - $attempts );

			foreach ( $page['ids'] as $product_id ) {
				++$attempts;

				try {
					if ( array() !== $this->links->keep_as_manual( $product_id, $mapping->creator_id, $context ) ) {
						++$converted;
					}
				} catch ( ProductCreatorLinkException $e ) {
					$failed[ $product_id ] = $e->reason();
				} catch ( DatabaseException $e ) {
					$this->logger->error(
						'Keep as manual failed for a product.',
						array(
							'product_id' => $product_id,
							'term_id'    => $mapping->term_id,
							'exception'  => $e,
						)
					);
					$failed[ $product_id ] = 'error';
				}
			}

			$after = $page['after_id'];
		} while ( ! $page['done'] && $attempts < self::MAX_CONVERSIONS );

		if ( array() !== $failed ) {
			return new KeepAsManualResult( $converted, $failed, ! $page['done'], false, true );
		}

		// Remove only when a fresh scan finds nothing left (e.g. a product that
		// got a category link meanwhile).
		if ( ! $page['done'] || array() !== $this->preview->conversion_targets( $mapping, 0, 1 )['ids'] ) {
			return new KeepAsManualResult( $converted, array(), true, false, true );
		}

		return new KeepAsManualResult( $converted, array(), false, $this->remove( $mapping, MappingRemovalChoice::KeepAsManual ), true );
	}

	/**
	 * The creator's mapping of the category, if any.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $term_id    Term id.
	 * @throws CreatorCategoryException When the current user may not manage creators.
	 * @throws DatabaseException When the read fails.
	 */
	private function mapping( int $creator_id, int $term_id ): ?CreatorCategory {
		self::authorize();

		$mapping = $term_id > 0 ? $this->stored->find( $term_id ) : null;

		return null !== $mapping && $mapping->creator_id === $creator_id ? $mapping : null;
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
	 * Removes the mapping with the choice: as a deleted term when it is
	 * orphaned, else as an admin removal.
	 *
	 * @param CreatorCategory      $mapping Mapping.
	 * @param MappingRemovalChoice $choice  Choice.
	 * @throws CreatorCategoryException When the request is not allowed.
	 * @throws DatabaseException When the change cannot be stored.
	 */
	private function remove( CreatorCategory $mapping, MappingRemovalChoice $choice ): bool {
		return CreatorCategoryService::is_orphaned( $mapping )
			? $this->service->remove_orphaned( $mapping->creator_id, $mapping->term_id, $choice )
			: $this->service->unlink( $mapping->creator_id, $mapping->term_id, $choice );
	}
}
