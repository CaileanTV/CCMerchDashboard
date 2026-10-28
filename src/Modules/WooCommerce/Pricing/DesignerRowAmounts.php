<?php
/**
 * The creator's profit and payout of a designer product's ledger row.
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
 * ADR-080 pkt. 8: the company always keeps the base price; only the
 * company's own discounts may take it below.
 *
 * - **Outside a campaign** a row's profit is
 *   `sign × min( q × profit, max( 0, |paid| − q × base ) )` with q = |quantity|
 *   and paid = the row's amount incl. VAT (net + actual tax). A discount is
 *   taken from the creator's profit first and never makes it negative.
 *   A refund or reversal of whole units takes back exactly their profit.
 *   A row without a quantity (an amount-only refund, an order correction)
 *   changes nothing for the creator. It is the company's own decision, so
 *   the company bears it.
 * - **In a campaign** the creator shares the discount: the profit is
 *   paid × profit ÷ list price, rounded once to the store precision.
 * - **Payout (ADR-064):** a creator who was VAT registered at the sale gets
 *   the profit. Otherwise the payout is the profit without the sale's VAT
 *   (profit × net ÷ paid), rounded once.
 *
 * Exact integer arithmetic in 1/10 000 units; base and profit per unit are
 * at the store precision, so the profit needs no rounding outside campaigns.
 * The stored amounts are what the SQL sums read (SalesRepository), so no
 * division happens in SQL.
 * Pure: nothing is read or written.
 */
final class DesignerRowAmounts {

	/**
	 * The creator's profit of a row in units, signed.
	 *
	 * @param int  $quantity     The row's quantity (signed; 0 for an amount-only row).
	 * @param int  $paid_units   The row's amount incl. VAT (net + actual tax), signed.
	 * @param int  $base_units   Base price per unit incl. VAT (snapshot).
	 * @param int  $profit_units The creator's profit per unit incl. VAT (snapshot).
	 * @param bool $campaign     Whether one of the company's own campaigns covers the row.
	 * @param int  $decimals     Store price decimals, 0–4.
	 * @throws InvalidArgumentException When an input is out of range.
	 */
	public static function profit( int $quantity, int $paid_units, int $base_units, int $profit_units, bool $campaign, int $decimals ): int {
		if ( $base_units < 0 || $profit_units < 0 || SalesAmount::add( $base_units, $profit_units ) < 1 ) {
			throw new InvalidArgumentException( 'Base price and profit cannot be negative, and the list price must be positive.' );
		}

		if ( $campaign ) {
			$step = PricingMath::step( $decimals );

			return PricingMath::mul_div_round( $paid_units, $profit_units, SalesAmount::add( $base_units, $profit_units ) * $step ) * $step;
		}

		$units = abs( $quantity );

		if ( 0 === $units || 0 === $paid_units ) {
			return 0;
		}

		$above  = SalesAmount::add( abs( $paid_units ), -PricingMath::mul_div_round( $units, $base_units, 1 ) );
		$profit = min( PricingMath::mul_div_round( $units, $profit_units, 1 ), max( 0, $above ) );

		return $paid_units < 0 ? -$profit : $profit;
	}

	/**
	 * The creator's payout of a row in units, signed (ADR-064).
	 *
	 * @param int  $profit_units   The row's profit (profit()).
	 * @param int  $net_units      The row's amount excl. VAT, signed.
	 * @param int  $paid_units     The row's amount incl. VAT, signed.
	 * @param bool $vat_registered Whether the creator was VAT registered at the sale.
	 * @param int  $decimals       Store price decimals, 0–4.
	 * @throws InvalidArgumentException When the precision is unsupported.
	 * @throws OverflowException When the result does not fit into an integer.
	 */
	public static function payout( int $profit_units, int $net_units, int $paid_units, bool $vat_registered, int $decimals ): int {
		if ( $vat_registered || 0 === $profit_units ) {
			return $profit_units;
		}

		if ( 0 === $paid_units ) {
			return 0;
		}

		$step  = PricingMath::step( $decimals );
		$steps = PricingMath::mul_div_round( abs( $profit_units ), abs( $net_units ), abs( $paid_units ) * $step );

		return ( $profit_units < 0 ? -$steps : $steps ) * $step;
	}

	/**
	 * The creator's share of the list price in basis points (for campaigns
	 * and the order history): profit ÷ price, rounded half away from zero.
	 *
	 * @param int $profit_units Profit per unit.
	 * @param int $price_units  List price per unit (base + profit), positive.
	 * @throws InvalidArgumentException When the price is not positive or the profit exceeds it.
	 */
	public static function share_bps( int $profit_units, int $price_units ): int {
		if ( $price_units < 1 || $profit_units < 0 || $profit_units > $price_units ) {
			throw new InvalidArgumentException( 'The profit must be between 0 and the price.' );
		}

		return PricingMath::mul_div_round( $profit_units, 10000, $price_units );
	}
}
