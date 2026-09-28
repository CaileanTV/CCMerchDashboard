<?php
/**
 * Events of a sales cycle.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The `event` column of `merchboard_sales_cycles` (ADR-038 pkt. 14).
 */
enum SalesCycleEventType: string {

	/**
	 * The order became counted; the cycle starts.
	 */
	case Open = 'open';

	/**
	 * The order stopped counting or was deleted; the cycle ends.
	 */
	case Close = 'close';
}
