<?php
/**
 * WooCommerce coupons of creator codes.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use MerchBoard\Modules\Creators\Creator;
use Throwable;
use WC_Coupon;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the coupons of creator codes through WooCommerce' CRUD (ADR-078)
 * and moves retired ones to the trash. The only place the plugin writes
 * coupons.
 */
final class WooCommerceCouponIssuer implements CouponIssuer {

	/**
	 * Post meta on a coupon the plugin created: the creator id.
	 */
	public const CREATOR_META = '_merchboard_creator_code';

	/**
	 * Creates the coupon of a new creator code.
	 *
	 * @param string  $code    Normalized code.
	 * @param Creator $creator The creator.
	 * @throws CreatorCodeException When the coupon cannot be created.
	 */
	public function create( string $code, Creator $creator ): CouponRecord {
		try {
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( CreatorProfitCoupon::TYPE );
			$coupon->set_amount( 0 );
			$coupon->set_individual_use( true );
			/* translators: %s: creator name. */
			$coupon->set_description( sprintf( __( 'Merch Dashboard: the discount code of %s. The discount is the creator\'s profit on their own products; managed by Merch Dashboard.', 'merchboard' ), $creator->display_name ) );
			$coupon->update_meta_data( self::CREATOR_META, (string) $creator->id );
			$id = (int) $coupon->save();
		} catch ( Throwable ) {
			throw new CreatorCodeException( CreatorCodeException::COUPON_NOT_CREATED );
		}

		if ( $id < 1 ) {
			throw new CreatorCodeException( CreatorCodeException::COUPON_NOT_CREATED );
		}

		return new CouponRecord( $id, $code, CreatorProfitCoupon::TYPE );
	}

	/**
	 * Moves a coupon to the trash.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	public function retire( int $coupon_id ): bool {
		if ( 'shop_coupon' !== get_post_type( $coupon_id ) ) {
			return true;
		}

		return 'trash' === get_post_status( $coupon_id ) || null !== wp_trash_post( $coupon_id );
	}

	/**
	 * Deletes a coupon of a failed issue.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	public function discard( int $coupon_id ): void {
		if ( 'shop_coupon' === get_post_type( $coupon_id ) ) {
			wp_delete_post( $coupon_id, true );
		}
	}
}
