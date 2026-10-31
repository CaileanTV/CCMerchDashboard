<?php
/**
 * The states of a request to remove a legacy product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\ProductRemovals;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-083: a request waits for an administrator, who removes the product in
 * WooCommerce and closes it, or declines it with a note.
 */
enum ProductRemovalStatus: string {

	case Pending   = 'pending';
	case Completed = 'completed';
	case Declined  = 'declined';
}
