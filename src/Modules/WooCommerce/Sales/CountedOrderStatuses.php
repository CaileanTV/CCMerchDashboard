<?php
/**
 * Order statuses that count as sales.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that decides which WooCommerce order statuses count
 * (ADR-037 pkt. 4, ADR-038 status note 2026-09-28). ROADMAP trin 5 uses the
 * fixed list `processing` and `completed`; trin 6 can replace it with the
 * configurable setting. WooCommerce' `wc_get_is_paid_statuses()` is not used:
 * it can be changed by a filter.
 */
final class CountedOrderStatuses {

	/**
	 * Counted statuses, without the `wc-` prefix.
	 */
	public const DEFAULT = array( 'processing', 'completed' );

	/**
	 * Whether an order status counts.
	 *
	 * @param string $status Order status as WooCommerce' `get_status( 'edit' )` returns it (no `wc-` prefix).
	 */
	public function counts( string $status ): bool {
		return in_array( $status, self::DEFAULT, true );
	}
}
