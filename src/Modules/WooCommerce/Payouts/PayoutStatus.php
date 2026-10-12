<?php
/**
 * Payout status.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

defined( 'ABSPATH' ) || exit;

/**
 * The four statuses of every payout method (ADR-055). A payout is created
 * `pending`, which is its reservation, and moves forward exactly once:
 * `completed` or `rejected` by an admin, `cancelled` by the creator. There
 * is no separate approval step and no way back.
 */
enum PayoutStatus: string {

	/**
	 * Requested and reserved; waiting for an admin.
	 */
	case Pending = 'pending';

	/**
	 * Paid out; amount and status never change again.
	 */
	case Completed = 'completed';

	/**
	 * Rejected by an admin with a reason; the reservation is released.
	 */
	case Rejected = 'rejected';

	/**
	 * Cancelled by the creator; the reservation is released.
	 */
	case Cancelled = 'cancelled';
}
