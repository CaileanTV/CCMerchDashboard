<?php
/**
 * Reasons of correction rows in the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The `correction_reason` column of `merchboard_sales` (ADR-038 pkt. 15-16,
 * docs/DATABASE.md). Required for `correction` rows, NULL for all others.
 */
enum SalesCorrectionReason: string {

	/**
	 * Offset of a refund registered on a closed cycle, so the cycle stays 0.
	 */
	case ClosedCycleRefund = 'closed_cycle_refund';

	/**
	 * A refund was deleted in WooCommerce.
	 */
	case RefundDeleted = 'refund_deleted';

	/**
	 * Offset of a deleted refund that was registered on a closed cycle.
	 */
	case ClosedCycleRefundDeleted = 'closed_cycle_refund_deleted';

	/**
	 * Quantity or amount of an order line in a counted order changed.
	 */
	case LineChanged = 'line_changed';

	/**
	 * An order line was removed from a counted order.
	 */
	case LineRemoved = 'line_removed';

	/**
	 * Whether rows with this reason belong to a refund (`refund_id` > 0);
	 * otherwise `refund_id` is 0.
	 */
	public function concerns_refund(): bool {
		return match ( $this ) {
			self::ClosedCycleRefund, self::RefundDeleted, self::ClosedCycleRefundDeleted => true,
			self::LineChanged, self::LineRemoved => false,
		};
	}
}
