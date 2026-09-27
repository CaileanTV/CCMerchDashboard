<?php
/**
 * Read access to the creator ↔ category mappings.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * What the reconciliation reads about mappings: the mapping of one category.
 * CreatorCategoryRepository is the stored state; HypotheticalMappings layers
 * a planned change on top for a dry run, so previews use the same rules
 * without writing a mapping first (ADR-033 point 10).
 */
interface MappingLookup {

	/**
	 * The mapping of a category, if any.
	 *
	 * @param int $term_id `product_cat` term id.
	 * @throws DatabaseException When the mapping cannot be read.
	 */
	public function find( int $term_id ): ?CreatorCategory;
}
