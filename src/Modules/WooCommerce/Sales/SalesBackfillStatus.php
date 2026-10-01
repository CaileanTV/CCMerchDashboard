<?php
/**
 * What the backfill did with one order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of the backfill (or its dry run) for one order (ADR-038, status
 * note "ROADMAP trin 7", D1). The backfill only registers orders without
 * ledger history; everything else is skipped with a reason.
 */
enum SalesBackfillStatus: string {

	/**
	 * The order had no ledger history and was registered (dry run: would be).
	 */
	case Registered = 'registered';

	/**
	 * The order already has ledger history; the backfill never touches it.
	 */
	case HasHistory = 'skipped_history';

	/**
	 * The order is not in a counted status now; no rows.
	 */
	case NotCounted = 'skipped_not_counted';

	/**
	 * No line of the order has a creator now; no rows.
	 */
	case NoCreator = 'skipped_no_creator';

	/**
	 * The order no longer exists, is not a shop order or is in the trash.
	 */
	case Missing = 'skipped_missing';

	/**
	 * The order is being synchronized in this request already.
	 */
	case Busy = 'skipped_busy';

	/**
	 * The synchronization failed (failure marker and log, like a live sync).
	 */
	case Failed = 'failed';
}
