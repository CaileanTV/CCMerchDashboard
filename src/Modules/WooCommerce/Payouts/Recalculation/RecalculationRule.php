<?php
/**
 * The recalculation of a settled row without ADR-049's 20 % deduction.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Recalculation;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorShare;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-063 pkt. 2: a settled row is recalculated by removing only the 20 %
 * deduction. Every other input stays as the document stored it: net, tax,
 * share, price decimals and VAT status of the row.
 *
 * Before a row is recalculated, its stored values must be what ADR-049
 * gives for its stored inputs: profit = (net + tax) × share at the row's
 * precision, and payout = profit × 80 % (not VAT registered). A row that
 * does not reproduce is never guessed: it needs a manual assessment. The
 * recalculated payout is the stored profit (ADR-063's rule, kept as it was
 * when R2 was executed). New recalculations are disabled since ADR-064
 * (PayoutRecalculations), which deducts the VAT again while not VAT
 * registered.
 */
final class RecalculationRule {

	/**
	 * The rule's id; regulated rows are recorded under it.
	 */
	public const RULE = 'adr063-remove-20';

	/**
	 * The calculation of the documents it recalculates (ADR-049, before ADR-063).
	 */
	public const LEGACY_CALCULATION = 'adr049-adr054-v1';

	/**
	 * ADR-049's payout share of a creator who was not VAT registered (80 %).
	 */
	public const LEGACY_PAYOUT_BPS = 8000;

	/**
	 * The recalculation of one stored row, or null when its stored values do
	 * not reproduce from its stored inputs (manual assessment).
	 *
	 * @param array<mixed> $row A settled row (net_amount, tax_amount, share_bps, price_decimals, profit_amount, payout_amount).
	 * @return array{original: int, recalculated: int, difference: int}|null Units.
	 */
	public static function row( array $row ): ?array {
		try {
			$decimals = self::integer( $row['price_decimals'] ?? null );
			$share    = self::integer( $row['share_bps'] ?? null );
			$sale     = SalesAmount::add( self::amount( $row['net_amount'] ?? null ), self::amount( $row['tax_amount'] ?? null ) );
			$profit   = CreatorShare::profit( $sale, $share, $decimals );
			$legacy   = PricingMath::share( $profit, self::LEGACY_PAYOUT_BPS, $decimals );

			if ( self::amount( $row['profit_amount'] ?? null ) !== $profit || self::amount( $row['payout_amount'] ?? null ) !== $legacy ) {
				return null;
			}

			// ADR-063's target: the whole profit. Fixed here, so ADR-064 (VAT deducted while not VAT registered) never changes R2's meaning.
			$recalculated = $profit;

			return array(
				'original'     => $legacy,
				'recalculated' => $recalculated,
				'difference'   => SalesAmount::add( $recalculated, SalesAmount::negate( $legacy ) ),
			);
		} catch ( InvalidArgumentException | OverflowException ) {
			return null;
		}
	}

	/**
	 * A stored decimal in units.
	 *
	 * @param mixed $value Column value.
	 * @throws InvalidArgumentException When it is not a decimal.
	 */
	private static function amount( mixed $value ): int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			throw new InvalidArgumentException( 'Not an amount.' );
		}

		return SalesAmount::parse( (string) $value );
	}

	/**
	 * A stored non-negative integer.
	 *
	 * @param mixed $value Column value.
	 * @throws InvalidArgumentException When it is not one.
	 */
	private static function integer( mixed $value ): int {
		if ( ( ! is_string( $value ) && ! is_int( $value ) ) || 1 !== preg_match( '/^\d{1,9}$/', (string) $value ) ) {
			throw new InvalidArgumentException( 'Not an integer.' );
		}

		return (int) $value;
	}
}
