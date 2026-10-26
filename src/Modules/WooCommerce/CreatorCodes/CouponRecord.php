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
 * A WooCommerce coupon as CouponLookup reads it (ADR-068, K5): its id, its
 * normalized code and its discount type (ADR-078: a coupon the plugin
 * manages has the type CreatorProfitCoupon::TYPE).
 */
final readonly class CouponRecord {

	/**
	 * Constructor.
	 *
	 * @param int    $id            Coupon id.
	 * @param string $code          Normalized code.
	 * @param string $discount_type WooCommerce discount type ('' when unknown).
	 */
	public function __construct( public int $id, public string $code, public string $discount_type = '' ) {
	}

	/**
	 * Whether the plugin manages the coupon: its discount is the creator's
	 * profit (ADR-078).
	 */
	public function managed(): bool {
		return CreatorProfitCoupon::TYPE === $this->discount_type;
	}
}
