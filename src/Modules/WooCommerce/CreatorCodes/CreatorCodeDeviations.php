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
	 * The coupon's discount is not the creator's profit (ADR-078): a code
	 * registered by hand before 1.3.0, or a coupon whose type was changed in
	 * WooCommerce. Issuing a new code fixes it.
	 */
	public const COUPON_NOT_MANAGED = 'coupon_not_managed';

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

	/**
	 * Whether the registration's coupon gives a discount other than the
	 * creator's profit (COUPON_NOT_MANAGED, ADR-078): shown on the creator's
	 * page only, never part of the order assessment.
	 *
	 * @param CreatorCode $registration Registration.
	 */
	public function not_managed( CreatorCode $registration ): bool {
		$coupon = $this->coupons->find( $registration->code );

		return null !== $coupon && $coupon->id === $registration->coupon_id && ! $coupon->managed();
	}
}
