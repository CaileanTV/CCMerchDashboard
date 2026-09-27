<?php
/**
 * The products a category reconciliation run must look at.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * Parent products (not trash or auto-draft) a reconciliation run looks at
 * (ADR-033), limited by a ReconciliationScope:
 * - all: directly assigned to a mapped `product_cat` term, or holding a
 *   stored link origin, so category links of products that left their
 *   category (or whose mapping is gone) are found too. Other products can
 *   never gain or lose a category link;
 * - a category: directly assigned to that term;
 * - a creator: holding a category link (`category` origin) to that creator.
 */
interface ReconciliationCandidates {

	/**
	 * The next page of candidate ids after $after_id, ascending (keyset
	 * pagination: stable while the run writes, no duplicates, no offset).
	 *
	 * @param ReconciliationScope $scope    Which products.
	 * @param int                 $after_id Last id of the previous page (0 to start).
	 * @param int                 $limit    Page size.
	 * @return list<int>
	 * @throws DatabaseException When the query fails.
	 */
	public function after( ReconciliationScope $scope, int $after_id, int $limit ): array;
}
