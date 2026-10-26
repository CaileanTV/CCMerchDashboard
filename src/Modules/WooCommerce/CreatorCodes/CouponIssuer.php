<?php
/**
 * Creates and retires the coupons of creator codes.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use MerchBoard\Modules\Creators\Creator;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce coupons the plugin manages for creator codes (ADR-078).
 */
interface CouponIssuer {

	/**
	 * Creates the coupon of a new creator code: discount type
	 * CreatorProfitCoupon::TYPE, individual use, no expiry and no limits.
	 *
	 * @param string  $code    Normalized code.
	 * @param Creator $creator The creator.
	 * @throws CreatorCodeException When the coupon cannot be created (COUPON_NOT_CREATED).
	 */
	public function create( string $code, Creator $creator ): CouponRecord;

	/**
	 * Moves a coupon to the trash, so its code no longer works (it can be
	 * restored by hand). True when it is in the trash or gone.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	public function retire( int $coupon_id ): bool;

	/**
	 * Deletes a coupon that was created but never used (a failed issue).
	 *
	 * @param int $coupon_id Coupon id.
	 */
	public function discard( int $coupon_id ): void;
}
