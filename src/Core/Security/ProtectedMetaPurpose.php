<?php
/**
 * Why an internal operation may write the protected product meta.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * The approved internal write paths for the product → creator link
 * (ADR-030 D6, ADR-032, ADR-033). Every protected meta write names one.
 */
enum ProtectedMetaPurpose: string {

	/**
	 * A manual change through ProductCreatorLink (Merch Dashboard admin).
	 */
	case ManualLink = 'manual_link';

	/**
	 * The system category reconciliation of ADR-033 (implemented in a later
	 * step; runs without a current user).
	 */
	case CategoryReconciliation = 'category_reconciliation';

	/**
	 * Deleting a creator (phase 27, step 27.3, ADR-076): its products lose the
	 * link, whatever its origin.
	 */
	case CreatorDeletion = 'creator_deletion';
}
