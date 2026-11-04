<?php
/**
 * Notifications about new ledger rows.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the creators about the rows a live synchronization committed
 * (ADR-079 pkt. 9), never about a backfill:
 *
 * - `sale_new`: once per order, cycle and creator, with the creator's
 *   lines (name, quantity, amount) and their total;
 * - `sale_reversed`: refund, reversal and correction rows of one
 *   synchronization, per creator, with the summed change.
 *
 * Amounts are incl. VAT, as the customer paid them: a row's net amount plus
 * its actual tax (the owner's wish 2026-11-04). Only a line from before the
 * tax was recorded (schema version 7) has no known tax and counts its net.
 *
 * The lines of creators excluded by their own code (ADR-068) are not in the
 * write, so they are never notified. Only names, quantities and amounts are
 * passed: never customer data.
 */
final class SalesNotices {

	/**
	 * Fires the notifications of a committed write.
	 *
	 * @param SalesLedgerWrite $write The committed rows.
	 */
	public static function send( SalesLedgerWrite $write ): void {
		$sales    = array();
		$reversed = array();

		foreach ( $write->entries as $entry ) {
			if ( SalesEntryType::Sale === $entry->type ) {
				$sales[ $entry->order_id . ':' . $entry->cycle . ':' . $entry->creator_id ][] = $entry;
			} else {
				$reversed[ $entry->order_id . ':' . $entry->creator_id ][] = $entry;
			}
		}

		// A row as the customer paid it: net plus its actual tax.
		$gross = static fn( SalesEntry $entry ): int => SalesAmount::add( SalesAmount::parse( $entry->net_amount ), $write->taxes[ $entry->event_key ] ?? 0 );

		foreach ( $sales as $key => $entries ) {
			$first = $entries[0];
			$total = 0;
			$items = array();

			foreach ( $entries as $entry ) {
				$total   = SalesAmount::add( $total, $gross( $entry ) );
				$items[] = array(
					'name'     => $entry->item_name,
					'quantity' => (string) $entry->quantity,
					'amount'   => SalesAmount::format( $gross( $entry ) ),
				);
			}

			do_action(
				'merchboard_notify',
				'sale_new',
				$first->creator_id,
				array(
					'order_number' => self::order_number( $first->order_id ),
					'currency'     => $first->currency,
					'items'        => $items,
					'total'        => SalesAmount::format( $total ),
				),
				'order:' . $key
			);
		}

		foreach ( $reversed as $entries ) {
			$first  = $entries[0];
			$amount = 0;

			foreach ( $entries as $entry ) {
				$amount = SalesAmount::add( $amount, $gross( $entry ) );
			}

			do_action(
				'merchboard_notify',
				'sale_reversed',
				$first->creator_id,
				array(
					'order_number' => self::order_number( $first->order_id ),
					'amount'       => SalesAmount::format( $amount ),
					'currency'     => $first->currency,
				),
				'event:' . $first->event_key
			);
		}
	}

	/**
	 * The order number customers and the shop see (it may differ from the id).
	 *
	 * @param int $order_id Order.
	 */
	public static function order_number( int $order_id ): string {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		return $order instanceof \WC_Order ? (string) $order->get_order_number() : (string) $order_id;
	}
}
