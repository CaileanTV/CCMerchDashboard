<?php
/**
 * The stored mappings, read once.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only MappingLookup over all stored mappings, loaded with one query
 * (the table has at most one row per category), so evaluating many products
 * for display does not query the mappings per product.
 */
final class PreloadedMappings implements MappingLookup {

	/**
	 * Mappings by term id.
	 *
	 * @var array<int, CreatorCategory>
	 */
	private array $by_term = array();

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryRepository $stored Stored mappings.
	 * @throws DatabaseException When the mappings cannot be read.
	 */
	public function __construct( CreatorCategoryRepository $stored ) {
		foreach ( $stored->all() as $mapping ) {
			$this->by_term[ $mapping->term_id ] = $mapping;
		}
	}

	/**
	 * The mapping of a category, if any.
	 *
	 * @param int $term_id Term id.
	 */
	public function find( int $term_id ): ?CreatorCategory {
		return $this->by_term[ $term_id ] ?? null;
	}

	/**
	 * The categories among the ids that are mapped to the creator.
	 *
	 * @phpstan-param list<int> $term_ids
	 *
	 * @param int[] $term_ids   Term ids.
	 * @param int   $creator_id Creator id.
	 * @return list<int>
	 */
	public function mapped_to( array $term_ids, int $creator_id ): array {
		return array_values( array_filter( $term_ids, fn( int $id ): bool => ( $this->by_term[ $id ] ?? null )?->creator_id === $creator_id ) );
	}
}
