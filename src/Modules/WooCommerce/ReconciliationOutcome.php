<?php
/**
 * What the category reconciliation did with one product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * One outcome per product (ADR-033).
 */
enum ReconciliationOutcome: string {

	/**
	 * A category link was created (the product had none).
	 */
	case Linked = 'linked';

	/**
	 * A category link moved to another creator (`category_moved`).
	 */
	case Moved = 'moved';

	/**
	 * A category link was removed; no category justifies it any more.
	 */
	case Unlinked = 'unlinked';

	/**
	 * Nothing to change.
	 */
	case Unchanged = 'unchanged';

	/**
	 * A manual (`admin`, no origin) or invalid link; never touched.
	 */
	case SkippedManual = 'skipped_manual';

	/**
	 * The mapped categories point to two or more creators: no category link
	 * (an existing one was removed with reason `ambiguous`).
	 */
	case Ambiguous = 'ambiguous';

	/**
	 * The only mapped creator is inactive: no new link.
	 */
	case SkippedInactive = 'skipped_inactive';

	/**
	 * The product could not be reconciled (logged); nothing was changed.
	 */
	case Error = 'errors';
}
