<?php
/**
 * In-memory coupons and gift cards for the creator code tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponLookup;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponRecord;

/**
 * Stands in for WooCommerce' coupons and the gift card providers (the
 * integration suite has no WooCommerce, ADR-038 Q4).
 */
final class FakeCouponLookup implements CouponLookup {

	/**
	 * Coupons by normalized code.
	 *
	 * @var array<string, CouponRecord>
	 */
	public array $coupons = array();

	/**
	 * Gift card codes; null makes the gift card check fail.
	 *
	 * @var list<string>|null
	 */
	public ?array $gift_cards = array();

	/**
	 * Adds a coupon.
	 *
	 * @param int    $id   Coupon id.
	 * @param string $code Normalized code.
	 */
	public function add( int $id, string $code ): void {
		$this->coupons[ $code ] = new CouponRecord( $id, $code );
	}

	/**
	 * Sets the gift card codes; null makes the gift card check fail.
	 *
	 * @phpstan-param list<string>|null $codes
	 *
	 * @param string[]|null $codes Gift card codes.
	 */
	public function set_gift_cards( ?array $codes ): void {
		$this->gift_cards = $codes;
	}

	/**
	 * The coupon with this normalized code, or null.
	 *
	 * @param string $code Normalized code.
	 */
	public function find( string $code ): ?CouponRecord {
		return $this->coupons[ $code ] ?? null;
	}

	/**
	 * Whether the code is a gift card code; null when the check fails.
	 *
	 * @param string $code Normalized code.
	 */
	public function is_gift_card( string $code ): ?bool {
		return null === $this->gift_cards ? null : in_array( $code, $this->gift_cards, true );
	}
}
