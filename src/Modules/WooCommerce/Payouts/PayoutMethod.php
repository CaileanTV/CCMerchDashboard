<?php
/**
 * Payout method.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

defined( 'ABSPATH' ) || exit;

/**
 * How a payout is paid. Bank and PayPal need encrypted details (ADR-056);
 * a gift card payout has none: an admin creates the card in a gift card
 * plugin and registers it (ADR-057).
 */
enum PayoutMethod: string {

	/**
	 * Danish bank transfer: registration number and account number.
	 */
	case Bank = 'bank';

	/**
	 * PayPal: an e-mail address.
	 */
	case PayPal = 'paypal';

	/**
	 * A payout gift card of the payout amount, without VAT (ADR-057).
	 */
	case GiftCard = 'gift_card';
}
