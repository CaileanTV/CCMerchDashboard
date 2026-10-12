<?php
/**
 * Status of a gift card as the payout logic sees it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\GiftCards;

defined( 'ABSPATH' ) || exit;

/**
 * An adapter maps the plugin's own states to these (ADR-057).
 */
enum GiftCardStatus: string {

	/**
	 * Usable in the shop.
	 */
	case Active = 'active';

	/**
	 * Disabled or dismissed in the plugin.
	 */
	case Disabled = 'disabled';

	/**
	 * Past its expiry.
	 */
	case Expired = 'expired';

	/**
	 * Any other state of the plugin.
	 */
	case Other = 'other';
}
