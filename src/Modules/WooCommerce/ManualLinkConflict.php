<?php
/**
 * How a manual product link deviates from the product's creator categories.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * A manual link that deviates from the category-based evaluation (ADR-033
 * point 3 and 8: the manual link wins and is never changed, but the conflict
 * is shown as "needs attention"). Diagnostic only; never written anywhere.
 *
 * No conflict: the product's direct categories map to no creator, or only to
 * the manually linked creator (active or inactive).
 */
enum ManualLinkConflict: string {

	/**
	 * The direct categories map to exactly one active creator, not the linked one.
	 */
	case OtherCreator = 'manual_other_creator';

	/**
	 * The direct categories map to two or more creators.
	 */
	case Ambiguous = 'manual_ambiguous';

	/**
	 * The direct categories map to exactly one creator, not the linked one,
	 * and that creator is inactive.
	 */
	case InactiveCreator = 'manual_inactive_creator';
}
