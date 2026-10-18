<?php
/**
 * An order and its ledger state without the excluded creators' lines.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerState;

defined( 'ABSPATH' ) || exit;

/**
 * What the planner sees of an order with exclusions (ADR-068): the order
 * lines of the excluded creators, their ledger rows and the refund lines of
 * those order lines are left out, so the planner neither writes rows for
 * them nor reverses or corrects their existing rows (M2), while the other
 * creators' lines are planned as usual. An order line belongs to an excluded
 * creator when its current creator is excluded or when its stored rows
 * carry an excluded creator.
 */
final readonly class OrderExclusionView {

	/**
	 * Constructor.
	 *
	 * @param OrderSnapshot    $order Order without the excluded lines.
	 * @param SalesLedgerState $state Ledger state without the excluded lines' rows.
	 */
	private function __construct( public OrderSnapshot $order, public SalesLedgerState $state ) {
	}

	/**
	 * The order and state without the given creators' lines.
	 *
	 * @phpstan-param list<int> $creator_ids
	 *
	 * @param OrderSnapshot    $order       Order.
	 * @param SalesLedgerState $state       Stored ledger state.
	 * @param int[]            $creator_ids Excluded creators.
	 */
	public static function without( OrderSnapshot $order, SalesLedgerState $state, array $creator_ids ): self {
		$excluded = array_flip( $creator_ids );
		$items    = array();

		foreach ( $order->lines as $line ) {
			if ( null !== $line->creator_id && isset( $excluded[ $line->creator_id ] ) ) {
				$items[ $line->item_id ] = true;
			}
		}

		foreach ( $state->entries as $entry ) {
			if ( isset( $excluded[ $entry->creator_id ] ) ) {
				$items[ $entry->order_item_id ] = true;
			}
		}

		if ( array() === $items ) {
			return new self( $order, $state );
		}

		$entries = array_values( array_filter( $state->entries, static fn( SalesEntry $entry ): bool => ! isset( $items[ $entry->order_item_id ] ) ) );
		$keys    = array_flip( array_map( static fn( SalesEntry $entry ): string => $entry->event_key, $entries ) );
		$refunds = array_map(
			static fn( RefundSnapshot $refund ): RefundSnapshot => new RefundSnapshot(
				$refund->refund_id,
				$refund->date_created,
				array_values( array_filter( $refund->lines, static fn( RefundLineSnapshot $line ): bool => ! isset( $items[ $line->refunded_item_id ] ) ) )
			),
			$order->refunds
		);

		return new self(
			new OrderSnapshot(
				$order->order_id,
				$order->exists,
				$order->is_counted,
				$order->currency,
				$order->date_created,
				$order->date_paid,
				array_values( array_filter( $order->lines, static fn( OrderLineSnapshot $line ): bool => ! isset( $items[ $line->item_id ] ) ) ),
				$refunds,
				$order->deleted_refund_id,
				$order->coupon_codes
			),
			new SalesLedgerState( $state->order_id, $entries, $state->cycles, array_intersect_key( $state->taxes, $keys ) )
		);
	}

	/**
	 * Whether anything is left to plan: a line with a creator or a stored row.
	 */
	public function has_lines(): bool {
		if ( array() !== $this->state->entries ) {
			return true;
		}

		foreach ( $this->order->lines as $line ) {
			if ( null !== $line->creator_id ) {
				return true;
			}
		}

		return false;
	}
}
