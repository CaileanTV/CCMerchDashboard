<?php
/**
 * Notifications about a payout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the creator about their payout (ADR-079), after the transaction
 * that changed it has committed. Only the amount, the currency, the method
 * and the given extras are passed: never details, hints or references. One
 * notification per type and payout, so a repeated call (an idempotent
 * request, a second click) notifies once.
 */
final class PayoutNotice {

	/**
	 * Notifies the payout's creator.
	 *
	 * @param string                $type   Notification type, e.g. `payout_completed`.
	 * @param Payout                $payout The payout as stored after the change.
	 * @param array<string, string> $extra  Further data, e.g. the document number.
	 */
	public static function send( string $type, Payout $payout, array $extra = array() ): void {
		do_action(
			'merchboard_notify',
			$type,
			$payout->creator_id,
			array(
				'amount'   => SalesAmount::format( $payout->amount_units ),
				'currency' => $payout->currency,
				'method'   => $payout->method->value,
			) + $extra,
			'payout:' . $payout->id
		);
	}

	/**
	 * Tells the administrator about the payout (the creator is named by the
	 * notifications): its amount, currency and method.
	 *
	 * @param string $type   Administrator type, e.g. `admin_payout_requested`.
	 * @param Payout $payout The payout as stored after the change.
	 */
	public static function admin( string $type, Payout $payout ): void {
		self::send( $type, $payout );
	}
}
