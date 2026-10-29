<?php
/**
 * Profit of one ledger row with the reason when it is unknown.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnosis of a ledger row for the admin order history (ADR-051). The
 * profit itself always comes from RealizedProfit (ADR-049), so the diagnosis
 * can never disagree with the creator endpoints; only when it is unknown, the
 * first missing piece is named (ProfitGap). Nothing is guessed, filled in or
 * turned into 0.
 */
final readonly class ProfitDiagnosis {

	/**
	 * Constructor.
	 *
	 * @param int|null       $profit Profit in units, null when unknown.
	 * @param ProfitGap|null $gap    Why it is unknown; null when known.
	 */
	private function __construct( public ?int $profit, public ?ProfitGap $gap ) {
	}

	/**
	 * Diagnoses a ledger row.
	 *
	 * @param SalesEntry                                                                                                             $entry            Ledger row.
	 * @param SalesLinePricing|null                                                                                                  $snapshot         The row's order line snapshot, null when none (or unreadable).
	 * @param array{tax: int, currency: string, campaign_id?: int|null, designer_profit?: int|null, designer_payout?: int|null}|null $tax              The row's stored tax, null when none.
	 * @param bool                                                                                                                   $invalid_snapshot A snapshot row exists but could not be read.
	 */
	public static function of( SalesEntry $entry, ?SalesLinePricing $snapshot, ?array $tax, bool $invalid_snapshot = false ): self {
		$profit = RealizedProfit::for_entry( $entry, $snapshot, $tax );

		if ( null !== $profit ) {
			return new self( $profit, null );
		}

		return new self( null, self::gap( $entry, $snapshot, $tax, $invalid_snapshot ) );
	}

	/**
	 * The first missing piece, in RealizedProfit's order.
	 *
	 * @param SalesEntry                                                                                                             $entry            Ledger row.
	 * @param SalesLinePricing|null                                                                                                  $snapshot         Snapshot.
	 * @param array{tax: int, currency: string, campaign_id?: int|null, designer_profit?: int|null, designer_payout?: int|null}|null $tax              Stored tax.
	 * @param bool                                                                                                                   $invalid_snapshot Unreadable snapshot row.
	 */
	private static function gap( SalesEntry $entry, ?SalesLinePricing $snapshot, ?array $tax, bool $invalid_snapshot ): ProfitGap {
		if ( null === $snapshot ) {
			return $invalid_snapshot ? ProfitGap::InvalidSnapshot : ProfitGap::NoSnapshot;
		}

		if ( ! $snapshot->has_share() ) {
			return ProfitGap::LegacySnapshot;
		}

		if ( $snapshot->order_id !== $entry->order_id
			|| $snapshot->order_item_id !== $entry->order_item_id
			|| $snapshot->creator_id !== $entry->creator_id
			|| $snapshot->currency !== $entry->currency
			|| $snapshot->product_id !== $entry->product_id
			|| $snapshot->variation_id !== $entry->variation_id ) {
			return ProfitGap::SnapshotMismatch;
		}

		if ( null === $tax ) {
			return ProfitGap::NoTax;
		}

		return $tax['currency'] !== $entry->currency ? ProfitGap::TaxCurrency : ProfitGap::Other;
	}
}
