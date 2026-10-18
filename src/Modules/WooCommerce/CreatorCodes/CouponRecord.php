<?php
/**
 * The parts of a WooCommerce coupon a creator code depends on.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce coupon as CouponLookup reads it (ADR-068, K5): its id and
 * its normalized code.
 */
final readonly class CouponRecord {

	/**
	 * Constructor.
	 *
	 * @param int    $id   Coupon id.
	 * @param string $code Normalized code.
	 */
	public function __construct( public int $id, public string $code ) {
	}
}
