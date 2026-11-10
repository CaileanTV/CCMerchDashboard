<?php
/**
 * The creator's profit of a ledger row with a Twitch subscriber discount.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106 pkt. 7: the creator pays the whole Twitch subscriber discount (S)
 * on every product type; the company's part never changes.
 *
 * - **A sale** (a row paid ≥ 0) gets `max( 0, f( paid + S ) − S )`, where f
 *   is the line's own rule without the subscriber discount (the share,
 *   ADR-049; the designer rule in or outside a campaign, ADR-080) and S is
 *   the discount the row carries (the line's first positive row carries it
 *   all, later rows 0).
 * - **A refund or reversal** (a row paid < 0) takes back the line's profit
 *   in proportion to the refunded share of what was paid
 *   (DesignerRowAmounts::refund()), so a fully refunded line ends at 0.
 *
 * The payout follows DesignerRowAmounts::payout() (ADR-064). Pure: nothing
 * is read or written; amounts are signed 1/10 000 units.
 */
final class SubDiscountAmounts {

	/**
	 * The creator's profit of a row paid ≥ 0 with a subscriber discount.
	 *
	 * @phpstan-param callable(int): int $profit_of
	 *
	 * @param int      $paid_units The row's amount incl. VAT (net + actual tax), ≥ 0.
	 * @param int      $sub_units  The subscriber discount the row carries, in the same basis, ≥ 0.
	 * @param callable $profit_of  The line's own rule: the profit of a paid amount without the subscriber discount.
	 * @throws InvalidArgumentException When an amount is negative.
	 */
	public static function sale( int $paid_units, int $sub_units, callable $profit_of ): int {
		if ( $paid_units < 0 || $sub_units < 0 ) {
			throw new InvalidArgumentException( 'A sale and its subscriber discount cannot be negative.' );
		}

		return max( 0, SalesAmount::add( $profit_of( SalesAmount::add( $paid_units, $sub_units ) ), -$sub_units ) );
	}

	/**
	 * The subscriber discount a row carries: the rest of the line's discount
	 * on a positive row, 0 on any other.
	 *
	 * @param int $paid_units The row's amount incl. VAT, signed.
	 * @param int $line_units The line's subscriber discount, ≥ 0.
	 * @param int $given      What the line's earlier rows carried, ≥ 0.
	 */
	public static function carried( int $paid_units, int $line_units, int $given ): int {
		return $paid_units > 0 ? max( 0, SalesAmount::add( $line_units, -$given ) ) : 0;
	}
}
