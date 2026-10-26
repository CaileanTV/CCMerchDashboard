<?php
/**
 * Test double of the coupons the plugin issues.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponIssuer;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponRecord;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorProfitCoupon;

/**
 * Stands in for WooCommerce' coupons (the integration suite has no
 * WooCommerce): created coupons get ids from 501 on and appear in the
 * FakeCouponLookup; retired ones disappear from it.
 */
final class FakeCouponIssuer implements CouponIssuer {

	/**
	 * Retired coupon ids.
	 *
	 * @var list<int>
	 */
	public array $retired = array();

	/**
	 * Discarded coupon ids.
	 *
	 * @var list<int>
	 */
	public array $discarded = array();

	/**
	 * Whether creating fails.
	 *
	 * @var bool
	 */
	public bool $fail = false;

	/**
	 * Whether retiring fails.
	 *
	 * @var bool
	 */
	public bool $fail_retire = false;

	private int $next = 501;

	/**
	 * Constructor.
	 *
	 * @param FakeCouponLookup $coupons The coupons it adds to.
	 */
	public function __construct( private FakeCouponLookup $coupons ) {
	}

	/**
	 * Creates a coupon.
	 *
	 * @param string  $code    Normalized code.
	 * @param Creator $creator The creator.
	 * @throws CreatorCodeException When creating fails.
	 */
	public function create( string $code, Creator $creator ): CouponRecord {
		if ( $this->fail ) {
			throw new CreatorCodeException( CreatorCodeException::COUPON_NOT_CREATED );
		}

		$record                          = new CouponRecord( $this->next++, $code, CreatorProfitCoupon::TYPE );
		$this->coupons->coupons[ $code ] = $record;

		return $record;
	}

	/**
	 * Retires a coupon.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	public function retire( int $coupon_id ): bool {
		if ( $this->fail_retire ) {
			return false;
		}

		$this->retired[]        = $coupon_id;
		$this->coupons->coupons = array_filter( $this->coupons->coupons, static fn( CouponRecord $coupon ): bool => $coupon->id !== $coupon_id );

		return true;
	}

	/**
	 * Discards a coupon.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	public function discard( int $coupon_id ): void {
		$this->discarded[]      = $coupon_id;
		$this->coupons->coupons = array_filter( $this->coupons->coupons, static fn( CouponRecord $coupon ): bool => $coupon->id !== $coupon_id );
	}
}
