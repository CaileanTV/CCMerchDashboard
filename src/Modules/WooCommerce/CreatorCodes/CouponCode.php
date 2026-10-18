<?php
/**
 * Normalization of WooCommerce coupon codes.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * A coupon code as WooCommerce compares it (ADR-068, K1):
 * wc_format_coupon_code() and then wc_strtolower(), so a registered code
 * matches the code on an order whatever its case. The same normalization is
 * used for registrations and for the codes read from orders, so the two can
 * never be compared differently.
 */
final class CouponCode {

	/**
	 * Longest normalized code that can be registered (the column's length).
	 */
	public const MAX_LENGTH = 191;

	/**
	 * The normalized code; '' when nothing is left.
	 *
	 * @param string $code Raw code.
	 */
	public static function normalize( string $code ): string {
		$code = function_exists( 'wc_format_coupon_code' ) ? (string) wc_format_coupon_code( $code ) : trim( $code );

		return function_exists( 'wc_strtolower' ) ? (string) wc_strtolower( $code ) : mb_strtolower( $code );
	}
}
