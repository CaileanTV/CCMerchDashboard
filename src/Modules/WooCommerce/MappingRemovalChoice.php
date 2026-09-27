<?php
/**
 * What happens to category links when an admin removes a mapping.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The explicit choice ADR-033 point 10 requires when a mapping is removed;
 * there is no default. Recorded in `creator_category_unlinked`.
 */
enum MappingRemovalChoice: string {

	/**
	 * Remove the category links the mapping justified (reconciled with
	 * `reason: mapping_removed`).
	 */
	case RemoveLinks = 'remove_links';

	/**
	 * Turn them into manual links (same creator, origin `admin`) before the
	 * mapping is removed: CategoryMappingRemoval::keep_as_manual().
	 */
	case KeepAsManual = 'keep_as_manual';
}
