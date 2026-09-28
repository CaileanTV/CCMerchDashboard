<?php
/**
 * Types of sales ledger rows.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The `type` column of `merchboard_sales` and its sign rule (ADR-037 pkt. 3,
 * supplemented by ADR-038 pkt. 12).
 */
enum SalesEntryType: string {

	/**
	 * Sale of an order line in a cycle; quantity and amount are zero or positive.
	 */
	case Sale = 'sale';

	/**
	 * Refund of an order line; zero or negative.
	 */
	case Refund = 'refund';

	/**
	 * Reversal of the remaining balance when a cycle closes; zero or negative.
	 */
	case Reversal = 'reversal';

	/**
	 * Correction; positive or negative, always with a reason.
	 */
	case Correction = 'correction';

	/**
	 * Allowed sign of quantity and amount: 1 (≥ 0), -1 (≤ 0) or 0 (either).
	 */
	public function sign(): int {
		return match ( $this ) {
			self::Sale                      => 1,
			self::Refund, self::Reversal    => -1,
			self::Correction                => 0,
		};
	}
}
