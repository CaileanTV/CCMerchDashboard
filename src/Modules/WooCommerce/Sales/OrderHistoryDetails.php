<?php
/**
 * One order's ledger events, lines and cycles for the admin order history.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitDiagnosis;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricing;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Everything Merch Dashboard stores for one order (ADR-051), read with the
 * existing repositories: the ledger rows (SalesRepository), the order lines'
 * snapshots (SalesLinePricingRepository, once per line) and each row's
 * stored tax (SalesTaxRepository), plus the cycle events. Each row gets a
 * ProfitDiagnosis (RealizedProfit and, when unknown, the missing piece).
 * Read only; the number of rows of one order is small, so the per-line and
 * per-row reads are bounded by the order itself.
 */
final class OrderHistoryDetails {

	/**
	 * Constructor.
	 *
	 * @param SalesRepository            $ledger    Ledger.
	 * @param SalesLinePricingRepository $snapshots Snapshots.
	 * @param SalesTaxRepository         $taxes     Stored tax per row.
	 */
	public function __construct( private SalesRepository $ledger, private SalesLinePricingRepository $snapshots, private SalesTaxRepository $taxes ) {
	}

	/**
	 * The order's stored history, or null when the ledger has no row for it.
	 *
	 * @param int $order_id Order id.
	 * @return array{
	 *     events: list<array{entry: SalesEntry, tax: array{tax: int, currency: string, campaign_id?: int|null, designer_profit?: int|null, designer_payout?: int|null}|null, diagnosis: ProfitDiagnosis}>,
	 *     lines: array<int, array{snapshot: SalesLinePricing|null, invalid_snapshot: bool, events: int, net: int}>,
	 *     cycles: SalesCycleState
	 * }|null
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function for_order( int $order_id ): ?array {
		if ( $order_id < 1 ) {
			return null;
		}

		$entries = $this->ledger->entries_for_order( $order_id );

		if ( array() === $entries ) {
			return null;
		}

		$lines  = array();
		$events = array();

		foreach ( $entries as $entry ) {
			$item = $entry->order_item_id;

			if ( ! isset( $lines[ $item ] ) ) {
				$snapshot = null;
				$invalid  = false;

				try {
					$snapshot = $this->snapshots->find( $item );
				} catch ( DatabaseException ) {
					// A stored row that does not match the schema: shown as such, never repaired here.
					$invalid = true;
				}

				$lines[ $item ] = array(
					'snapshot'         => $snapshot,
					'invalid_snapshot' => $invalid,
					'events'           => 0,
					'net'              => 0,
				);
			}

			$tax = $this->taxes->find( $entry->event_key );

			++$lines[ $item ]['events'];
			$lines[ $item ]['net'] = SalesAmount::add( $lines[ $item ]['net'], SalesAmount::parse( $entry->net_amount ) );
			$events[]              = array(
				'entry'     => $entry,
				'tax'       => $tax,
				'diagnosis' => ProfitDiagnosis::of( $entry, $lines[ $item ]['snapshot'], $tax, $lines[ $item ]['invalid_snapshot'] ),
			);
		}

		return array(
			'events' => $events,
			'lines'  => $lines,
			'cycles' => $this->ledger->cycle_state( $order_id ),
		);
	}
}
