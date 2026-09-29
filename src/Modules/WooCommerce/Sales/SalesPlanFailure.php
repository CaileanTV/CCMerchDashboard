<?php
/**
 * Why the sales planner refused to plan.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * A planning failure: nothing may be written for the order (ADR-038 pkt. 23).
 * Holds ids only, never amounts, names or other personal data, so it can go
 * into the log and the failure marker.
 */
final readonly class SalesPlanFailure {

	/**
	 * Constructor.
	 *
	 * @param SalesFailureCode $code      Failure code.
	 * @param int              $order_id  Order id.
	 * @param int|null         $item_id   Order item involved, if any.
	 * @param int|null         $refund_id Refund involved, if any.
	 */
	public function __construct(
		public SalesFailureCode $code,
		public int $order_id,
		public ?int $item_id = null,
		public ?int $refund_id = null
	) {
	}
}
