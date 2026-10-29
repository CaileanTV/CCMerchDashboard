<?php
/**
 * Creator payout of a sales ledger row.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Realized payout (ADR-049, ADR-064): what the creator is owed for one
 * ledger row as calculated now (CreatorShare::payout()): the realized profit
 * when the creator was VAT registered at the sale (snapshot), otherwise the
 * share of the row's amount excluding its stored actual VAT. A row that a
 * settlement document has settled keeps the payout stored there (ADR-060),
 * see LedgerCreatorPayouts.
 *
 * No VAT rate is assumed: the row's own stored tax is used. Signs follow the
 * row.
 *
 * Unknown (null) when the profit is unknown, or when the snapshot has no
 * VAT status (taken before schema version 7; ADR-049 pkt. 7 is unchanged).
 * Never derived from the creator's current status, current prices or tax
 * settings.
 */
final class RealizedPayout {

	/**
	 * Payout of one ledger row in units (1/10 000), or null when unknown.
	 *
	 * @param SalesEntry                                                                                                             $entry    Ledger row.
	 * @param SalesLinePricing|null                                                                                                  $snapshot The row's order line snapshot.
	 * @param array{tax: int, currency: string, campaign_id?: int|null, designer_profit?: int|null, designer_payout?: int|null}|null $tax      The row's stored actual tax, null when none.
	 */
	public static function for_entry( SalesEntry $entry, ?SalesLinePricing $snapshot, ?array $tax ): ?int {
		$profit = RealizedProfit::for_entry( $entry, $snapshot, $tax );

		// A known profit implies a matching snapshot with a share and the row's tax in its currency.
		if ( null === $profit || null === $snapshot || null === $tax || null === $snapshot->vat_registered || null === $snapshot->price_decimals || null === $snapshot->share_bps ) {
			return null;
		}

		$net = SalesAmount::parse( $entry->net_amount );

		// A designer product (ADR-080): the profit, without the sale's VAT when not VAT registered; the stored amount when there is one.
		if ( $snapshot->is_designer() ) {
			if ( array_key_exists( 'designer_payout', $tax ) && array_key_exists( 'designer_profit', $tax ) ) {
				return $tax['designer_payout'];
			}

			return DesignerRowAmounts::payout( $profit, $net, SalesAmount::add( $net, $tax['tax'] ), $snapshot->vat_registered, $snapshot->price_decimals );
		}

		return CreatorShare::payout( $net, $tax['tax'], $snapshot->share_bps, $snapshot->vat_registered, $snapshot->price_decimals );
	}
}
