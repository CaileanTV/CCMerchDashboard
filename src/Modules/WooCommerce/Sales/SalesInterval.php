<?php
/**
 * Period length of sales statistics.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * Day, ISO week (Monday to Sunday) or calendar month, in the site's time
 * zone (ADR-040 pkt. 6-7).
 */
enum SalesInterval: string {
	case Day   = 'day';
	case Week  = 'week';
	case Month = 'month';
}
