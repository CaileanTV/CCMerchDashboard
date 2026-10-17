<?php
/**
 * Creator profit and payout of a sale price.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * The one calculation of ADR-049. The WooCommerce sale price is the final
 * customer price; it already contains the creator's profit and the VAT, so
 * nothing is ever added to it:
 *
 * - creator_profit = sale_price × markup % (rounded to the store precision);
 * - creator_payout = creator_profit, whether or not the creator is VAT
 *   registered (ADR-063 removed ADR-049's 20 % deduction for creators who
 *   are not VAT registered; no VAT rate is assumed or read).
 *
 * Example: 100.00 at 20 % → profit 20.00; payout 20.00.
 *
 * Exact integer arithmetic in 1/10 000 units, rounded half away from zero
 * (PricingMath::share()); signed, so refunds and reversals give negative
 * profit and payout. Pure: no WooCommerce price, product, rule or status is
 * read or written here.
 */
final class CreatorShare {

	/**
	 * The creator's profit of a sale price.
	 *
	 * @param int $sale_units Sale price (the amount the customer paid) in units, signed.
	 * @param int $bps        Creator share in basis points, 0–10 000.
	 * @param int $decimals   Store price decimals, 0–4.
	 * @throws InvalidArgumentException When an input is out of range.
	 * @throws OverflowException When the result does not fit into an integer.
	 */
	public static function profit( int $sale_units, int $bps, int $decimals ): int {
		return PricingMath::share( $sale_units, $bps, $decimals );
	}

	/**
	 * The creator's payout of a profit: the whole profit (ADR-063).
	 *
	 * @param int $profit_units Creator profit in units, signed.
	 * @param int $decimals     Store price decimals, 0–4.
	 * @throws InvalidArgumentException When the precision is out of range.
	 */
	public static function payout( int $profit_units, int $decimals ): int {
		PricingMath::step( $decimals );

		return $profit_units;
	}
}
