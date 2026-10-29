<?php
/**
 * Creator profit of a sales ledger row.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Realized profit (ADR-049): the creator's share of the WooCommerce sale
 * price the customer actually paid for the row.
 *
 * - Sale price = net_amount + tax: the row's amount after all discounts,
 *   excl. VAT (ADR-037), plus the row's actual WooCommerce tax from
 *   `merchboard_sales_tax` (ADR-048), i.e. the amount paid incl. VAT.
 * - Profit = round_half_away( sale price × share_bps / 10 000 ) to the store
 *   precision of the line's snapshot (CreatorShare).
 *
 * The tax only restores the amount the customer paid; it is never a VAT
 * rate and never a separate profit formula. Sale prices, coupons and manual
 * discounts are part of the paid amount; refunds, reversals and corrections
 * have signed amounts and taxes, so they reduce or reverse the profit with
 * the right sign. Rounding is per row.
 *
 * Only the immutable ledger row, its stored tax and the line's snapshot are
 * read, never today's markup, product share, VAT status or WooCommerce
 * price. Unknown (null) without a matching snapshot with a share (e.g. lines
 * from before schema version 8), or without the row's stored tax in the
 * row's currency (rows from before schema version 7).
 *
 * A designer product's row (snapshot `designer`, ADR-080 pkt. 8) follows
 * DesignerRowAmounts: the company keeps the base price, and a discount is
 * taken from the creator's profit, never below 0; in the company's own
 * campaign (`campaign_id` stored with the row's tax) the discount is shared.
 * The same amount is stored with the row when it is written, which the SQL
 * sums read.
 */
final class RealizedProfit {

	/**
	 * Profit of a stored ledger row in units, or null when unknown. The
	 * snapshot must match the row exactly: order, order line, creator,
	 * currency, parent product and variation. Refund, reversal and correction
	 * rows carry the original order line, so they match its snapshot. The
	 * row's amount is the line amount, so the quantity is never multiplied.
	 *
	 * @param SalesEntry                                                     $entry    Ledger row.
	 * @param SalesLinePricing|null                                          $snapshot The row's order line snapshot, null when there is none.
	 * @param array{tax: int, currency: string, campaign_id?: int|null}|null $tax      The row's stored actual tax, null when none.
	 */
	public static function for_entry( SalesEntry $entry, ?SalesLinePricing $snapshot, ?array $tax ): ?int {
		$sale = self::sale_units( $entry, $tax );

		if ( null === $snapshot
			|| null === $sale
			|| ! $snapshot->has_share()
			|| $snapshot->order_id !== $entry->order_id
			|| $snapshot->order_item_id !== $entry->order_item_id
			|| $snapshot->creator_id !== $entry->creator_id
			|| $snapshot->currency !== $entry->currency
			|| $snapshot->product_id !== $entry->product_id
			|| $snapshot->variation_id !== $entry->variation_id ) {
			return null;
		}

		if ( $snapshot->is_designer() ) {
			return DesignerRowAmounts::profit( $entry->quantity, $sale, $snapshot->base_units, $snapshot->profit_units, isset( $tax['campaign_id'] ), $snapshot->price_decimals );
		}

		return CreatorShare::profit( $sale, $snapshot->share_bps, $snapshot->price_decimals );
	}

	/**
	 * The row's sale price incl. VAT in units (net + actual tax), signed;
	 * null when the row has no stored tax in its currency.
	 *
	 * @param SalesEntry                                                     $entry Ledger row.
	 * @param array{tax: int, currency: string, campaign_id?: int|null}|null $tax   The row's stored actual tax.
	 */
	public static function sale_units( SalesEntry $entry, ?array $tax ): ?int {
		if ( null === $tax || $tax['currency'] !== $entry->currency ) {
			return null;
		}

		return SalesAmount::add( SalesAmount::parse( $entry->net_amount ), $tax['tax'] );
	}
}
