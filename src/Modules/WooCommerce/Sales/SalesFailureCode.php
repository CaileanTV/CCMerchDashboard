<?php
/**
 * Failure codes of the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The `code` of the failure marker `_merchboard_ledger_failure` (ADR-038
 * pkt. 20 and B7). The planner reports the data-related codes; the lock,
 * database and connection codes belong to the synchronization (trin 5).
 */
enum SalesFailureCode: string {

	/**
	 * The order's ledger lock was held by another request beyond the timeout.
	 */
	case LockTimeout = 'lock_timeout';

	/**
	 * A database error (rolled back).
	 */
	case DbError = 'db_error';

	/**
	 * An open transaction on the connection (kept as a code; there is no automatic detection, B4).
	 */
	case InTransaction = 'in_transaction';

	/**
	 * The connection changed while the lock was held (rolled back).
	 */
	case ConnectionChanged = 'connection_changed';

	/**
	 * A refund line whose order line is unknown to WooCommerce and the ledger.
	 */
	case UnresolvedRefundItem = 'unresolved_refund_item';

	/**
	 * A line would get a negative quantity or balance.
	 */
	case NegativeBalance = 'negative_balance';

	/**
	 * The order's currency differs from its ledger rows.
	 */
	case CurrencyMismatch = 'currency_mismatch';

	/**
	 * Any other inconsistency.
	 */
	case Unexpected = 'unexpected';
}
