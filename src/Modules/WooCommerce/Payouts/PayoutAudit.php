<?php
/**
 * Audit context of a payout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * The only payout data written to the audit trail (ADR-058): ids, amount,
 * currency, method and flags. Never account numbers, e-mail addresses,
 * hints, references or reason texts.
 */
final class PayoutAudit {

	/**
	 * Context of a payout, plus flags.
	 *
	 * @param Payout              $payout Payout.
	 * @param array<string, bool> $flags  Flags such as `has_reason`.
	 * @return array<string, int|string|bool>
	 */
	public static function context( Payout $payout, array $flags = array() ): array {
		return array_merge(
			array(
				'payout_id' => $payout->id,
				'amount'    => SalesAmount::format( $payout->amount_units ),
				'currency'  => $payout->currency,
				'method'    => $payout->method->value,
			),
			$flags
		);
	}
}
