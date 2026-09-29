<?php
/**
 * Internal: stops planning with a failure code.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown inside SalesPlanner (and its draft) only, and always turned into a
 * SalesPlanResult failure there; it never leaves the planner.
 *
 * @internal
 */
final class SalesPlanAbort extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param SalesFailureCode $failure_code Failure code.
	 * @param int|null         $item_id   Order item involved, if any.
	 * @param int|null         $refund_id Refund involved, if any.
	 */
	public function __construct( public readonly SalesFailureCode $failure_code, public readonly ?int $item_id = null, public readonly ?int $refund_id = null ) {
		parent::__construct( 'Sales planning stopped: ' . $failure_code->value );
	}
}
