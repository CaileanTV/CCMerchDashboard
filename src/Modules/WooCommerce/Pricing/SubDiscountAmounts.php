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
 *   the discount the row carries (carried()).
 * - **A refund or reversal** (a row paid < 0) takes back the line's profit
 *   in proportion to the refunded share of what was paid
 *   (DesignerRowAmounts::refund()), so a fully refunded line ends at 0.
 *
 * The rows of a line carry S signed, so their sum is the discount the line
 * still has: a refund carries −S in proportion to what it takes back, a
 * later sale (a cancelled order paid again) carries the rest, and a row
 * after the discount was taken off the order carries −S back (never more
 * than the row paid).
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
	 * @param int      $sub_units  The subscriber discount the row carries, in the same basis (negative: given back, at most the row's amount).
	 * @param callable $profit_of  The line's own rule: the profit of a paid amount without the subscriber discount.
	 * @throws InvalidArgumentException When the row or the row plus its discount is negative.
	 */
	public static function sale( int $paid_units, int $sub_units, callable $profit_of ): int {
		if ( $paid_units < 0 || SalesAmount::add( $paid_units, $sub_units ) < 0 ) {
			throw new InvalidArgumentException( 'A sale and its amount before the subscriber discount cannot be negative.' );
		}

		return max( 0, SalesAmount::add( $profit_of( SalesAmount::add( $paid_units, $sub_units ) ), -$sub_units ) );
	}

	/**
	 * The subscriber discount a row carries, signed.
	 *
	 * - A refund or reversal: −(the line's outstanding discount × the share
	 *   of the line's outstanding amount it takes back).
	 * - A positive row: the line's discount now (0 when the order no longer
	 *   has it) less what the line still carries, never below −paid.
	 *
	 * @param int      $paid_units       The row's amount incl. VAT, signed.
	 * @param int|null $line_units       The line's subscriber discount on the order now, null when gone.
	 * @param int      $outstanding      The sum of what the line's earlier rows carried.
	 * @param int      $outstanding_paid The sum of what the line's earlier rows were paid.
	 */
	public static function carried( int $paid_units, ?int $line_units, int $outstanding, int $outstanding_paid ): int {
		if ( $paid_units < 0 ) {
			if ( $outstanding_paid <= 0 || 0 === $outstanding ) {
				return 0;
			}

			return 0 - (int) round( $outstanding * min( -$paid_units, $outstanding_paid ) / $outstanding_paid );
		}

		if ( 0 === $paid_units ) {
			return 0;
		}

		return max( -$paid_units, SalesAmount::add( $line_units ?? 0, -$outstanding ) );
	}
}
