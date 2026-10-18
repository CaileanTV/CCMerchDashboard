<?php
/**
 * Creator profit and payout of a sale price.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * The one calculation of ADR-049. The WooCommerce sale price is the final
 * customer price; it already contains the creator's profit and the VAT, so
 * nothing is ever added to it:
 *
 * - creator_profit = sale_price × markup % (rounded to the store precision);
 * - creator_payout (ADR-064) = creator_profit when the creator was VAT
 *   registered at the sale; otherwise the same share of the sale price
 *   excluding the VAT actually paid on the sale (net × markup %), so the VAT
 *   is never paid out to a creator who is not VAT registered. No VAT rate is
 *   assumed: the deduction is the share of the sale's own VAT, at any rate.
 *
 * Example: 100.00 incl. 20.00 VAT at 20 % → profit 20.00; payout 20.00 when
 * VAT registered, 16.00 when not.
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
	 * The creator's payout of a sale (ADR-064): the profit of the sale price
	 * including VAT when the creator was VAT registered at the sale, otherwise
	 * the share of the sale price excluding VAT. Each is rounded once, so the
	 * VAT deducted is profit − payout.
	 *
	 * @param int  $net_units      Sale price excluding VAT in units, signed.
	 * @param int  $tax_units      The sale's VAT in units, signed.
	 * @param int  $bps            Creator share in basis points, 0–10 000.
	 * @param bool $vat_registered Whether the creator was VAT registered at the sale.
	 * @param int  $decimals       Store price decimals, 0–4.
	 * @throws InvalidArgumentException When an input is out of range.
	 * @throws OverflowException When the result does not fit into an integer.
	 */
	public static function payout( int $net_units, int $tax_units, int $bps, bool $vat_registered, int $decimals ): int {
		return PricingMath::share( $vat_registered ? SalesAmount::add( $net_units, $tax_units ) : $net_units, $bps, $decimals );
	}
}
