<?php
/**
 * CouponLookup on WooCommerce' CRUD API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use Closure;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use Throwable;
use WC_Coupon;

defined( 'ABSPATH' ) || exit;

/**
 * Coupons through wc_get_coupon_id_by_code() and WC_Coupon (the same for
 * HPOS and legacy storage, since coupons are posts either way), gift cards
 * through every registered gift card provider that is available (a gift
 * card code is not a WooCommerce coupon, but it is entered in the same
 * field). Nothing is written.
 */
final class WooCommerceCouponLookup implements CouponLookup {

	/**
	 * Gift card providers, resolved on first use.
	 *
	 * @var Closure(): GiftCardProviders
	 */
	private Closure $gift_cards;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): GiftCardProviders $gift_cards
	 *
	 * @param Closure $gift_cards Gift card providers, resolved on first use (the sales synchronization is built before them).
	 */
	public function __construct( Closure $gift_cards ) {
		$this->gift_cards = $gift_cards;
	}

	/**
	 * The coupon with this normalized code, or null when there is none.
	 *
	 * @param string $code Normalized code.
	 */
	public function find( string $code ): ?CouponRecord {
		if ( '' === $code || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return null;
		}

		$id = (int) wc_get_coupon_id_by_code( $code );

		if ( $id < 1 ) {
			return null;
		}

		$coupon = new WC_Coupon( $id );

		if ( $coupon->get_id() !== $id ) {
			return null;
		}

		return new CouponRecord( $id, CouponCode::normalize( (string) $coupon->get_code( 'edit' ) ) );
	}

	/**
	 * Whether the code is a gift card code of an available gift card provider.
	 *
	 * @param string $code Normalized code.
	 * @return bool|null Null when an available provider could not check it.
	 */
	public function is_gift_card( string $code ): ?bool {
		foreach ( ( $this->gift_cards )()->all() as $provider ) {
			try {
				if ( ! $provider->availability()->available ) {
					continue;
				}

				if ( null !== $provider->find_by_code( $code ) ) {
					return true;
				}
			} catch ( Throwable ) {
				return null;
			}
		}

		return false;
	}
}
