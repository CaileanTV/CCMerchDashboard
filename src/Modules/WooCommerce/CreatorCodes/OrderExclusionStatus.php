<?php
/**
 * Decision on an order that used a creator's own discount code.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * The stored decision of `merchboard_order_exclusions` (ADR-068). Under
 * either value, none of the order's unsettled ledger rows count (M2).
 */
enum OrderExclusionStatus: string {

	/**
	 * Excluded: the order counts nowhere (sales, profit, payouts, new
	 * settlement documents).
	 */
	case Excluded = 'excluded';

	/**
	 * Open case (K2): the order already had rows in an issued settlement
	 * document. The settled rows are not touched; the case awaits manual
	 * handling (holding back and "Confirm"/"Revoke" are a later step).
	 */
	case Open = 'open';
}
