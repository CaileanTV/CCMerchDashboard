<?php
/**
 * Where an order keeps its Twitch subscriber discount.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\SubDiscount;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\WooCommerceAmount;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The Twitch subscriber discount's coupon and order data (ADR-106):
 *
 * - The discount is a virtual coupon of its own type with a reserved code,
 *   which the plugin adds and removes itself (pkt. 4).
 * - Each discounted line keeps its discount in the paid basis (incl. VAT,
 *   like the line's net + tax; `LINE_DISCOUNT`), its tier and its percent.
 * - The order keeps the customer's Twitch account, the time of the check
 *   and the tier per creator (`ORDER`, personal data).
 *
 * The ledger reads a line's discount only while the order still has the
 * coupon (pkt. 7), so a coupon removed in the order editor counts as none.
 */
final class SubDiscountMeta {

	/**
	 * The coupon's discount type.
	 */
	public const COUPON_TYPE = 'merchboard_twitch_sub';

	/**
	 * The coupon's reserved code (normalized: lower case).
	 */
	public const COUPON_CODE = 'merchboard-twitch-sub';

	/**
	 * Line meta: the line's discount in the paid basis, a decimal with up to 4 places.
	 */
	public const LINE_DISCOUNT = '_merchboard_sub_discount';

	/**
	 * Line meta: the customer's tier on the line's creator (1, 2 or 3).
	 */
	public const LINE_TIER = '_merchboard_sub_tier';

	/**
	 * Line meta: the creator's percent for that tier, in basis points.
	 */
	public const LINE_BPS = '_merchboard_sub_bps';

	/**
	 * Order meta: the customer's Twitch account and the tier per creator (JSON).
	 */
	public const ORDER = '_merchboard_twitch_sub';

	/**
	 * Order meta: the creator pays its lines' discount (`LINE_DISCOUNT`)
	 * without the coupon: a giveaway the creator bought in the shop at the base
	 * price (ADR-110), so the sale gives the creator no profit. The value is
	 * the giveaway's id.
	 */
	public const ORDER_CREATOR_PAID = '_merchboard_giveaway';

	/**
	 * The line's discount in units (1/10 000) in the paid basis, or null
	 * when the line has none.
	 *
	 * @param WC_Order_Item_Product $item Order line.
	 * @throws InvalidArgumentException When the stored value is not a plain non-negative decimal (unknown data: the ledger fails closed).
	 */
	public static function line_units( WC_Order_Item_Product $item ): ?int {
		$value = $item->get_meta( self::LINE_DISCOUNT, true, 'edit' );

		if ( '' === $value || null === $value ) {
			return null;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{1,15}(?:\.\d{1,4})?$/', $value ) ) {
			throw new InvalidArgumentException( 'Unreadable subscriber discount of an order line.' );
		}

		$units = WooCommerceAmount::units( $value );

		return $units > 0 ? $units : null;
	}
}
