<?php
/**
 * Deviations of a registered coupon from its registration.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a registration's coupon is still the registered one (ADR-068, K5):
 * it exists under the registered code with the registered coupon id. A
 * deviation is only shown to the admin and flagged on order decisions; it
 * never ends the registration and never changes a stored decision (K5).
 * Read-only, no capability needed (the callers decide who sees the result).
 */
final class CreatorCodeDeviations {

	public const COUPON_MISSING = 'coupon_missing';
	public const COUPON_CHANGED = 'coupon_changed';

	/**
	 * Constructor.
	 *
	 * @param CouponLookup $coupons WooCommerce coupons.
	 */
	public function __construct( private CouponLookup $coupons ) {
	}

	/**
	 * The deviations of a registration now, empty when there are none.
	 *
	 * @param CreatorCode $registration Registration.
	 * @return list<string> Codes of the class constants.
	 */
	public function of( CreatorCode $registration ): array {
		$coupon = $this->coupons->find( $registration->code );

		if ( null === $coupon ) {
			return array( self::COUPON_MISSING );
		}

		return $coupon->id === $registration->coupon_id ? array() : array( self::COUPON_CHANGED );
	}
}
