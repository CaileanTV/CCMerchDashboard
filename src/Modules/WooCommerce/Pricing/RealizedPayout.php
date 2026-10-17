<?php
/**
 * Creator payout of a sales ledger row.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Realized payout (ADR-049, ADR-063): what the creator is owed for one
 * ledger row as calculated now: the realized profit, whether or not the
 * creator was VAT registered (ADR-063 removed the 20 % deduction). A row
 * that a settlement document has settled keeps the payout stored there
 * (ADR-060), see LedgerCreatorPayouts.
 *
 * No VAT rate is assumed or read, and the row's tax plays no other role
 * than in the profit's sale price (RealizedProfit). Signs follow the row.
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
	 * @param SalesEntry                             $entry    Ledger row.
	 * @param SalesLinePricing|null                  $snapshot The row's order line snapshot.
	 * @param array{tax: int, currency: string}|null $tax      The row's stored actual tax, null when none.
	 */
	public static function for_entry( SalesEntry $entry, ?SalesLinePricing $snapshot, ?array $tax ): ?int {
		$profit = RealizedProfit::for_entry( $entry, $snapshot, $tax );

		if ( null === $profit || null === $snapshot || null === $snapshot->vat_registered || null === $snapshot->price_decimals ) {
			return null;
		}

		return CreatorShare::payout( $profit, $snapshot->price_decimals );
	}
}
