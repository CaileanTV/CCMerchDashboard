<?php
/**
 * WooCommerce coupons and gift cards, as creator codes need them.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only access to WooCommerce for CreatorCodeService and the deviation
 * check (ADR-068, K5); a seam for the unit tests.
 */
interface CouponLookup {

	/**
	 * The coupon with this normalized code, or null when there is none.
	 *
	 * @param string $code Normalized code.
	 */
	public function find( string $code ): ?CouponRecord;

	/**
	 * Whether the code is a gift card code of an available gift card
	 * provider.
	 *
	 * @param string $code Normalized code.
	 * @return bool|null Null when an available provider could not check it.
	 */
	public function is_gift_card( string $code ): ?bool;
}
