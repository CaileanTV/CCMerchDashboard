<?php
/**
 * Plans the ledger rows of one order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * The state transition of the sales ledger (ADR-037, ADR-038 with status
 * notes): order snapshot + stored ledger state + processing time → the rows
 * and cycle events that bring the ledger in line with the invariant.
 *
 * Pure: no database, no WooCommerce, no clock, no global state. The same input
 * gives the same plan, and planning again after the plan was written gives an
 * empty plan. Rows are never changed; differences become new rows.
 *
 * One run, in this order:
 *
 * 1. Deleted refunds (not among the current refunds, or the known deleted
 *    one): `c-rd` mirrors the stored `refund` row in its cycle; is that cycle
 *    closed, `c-rdo` offsets it there.
 * 2. New refunds of lines with history: `refund` in the latest cycle; is it
 *    closed, `c-ro` offsets it there. Refunds of lines without history are
 *    only registered together with the line's first sale (steps 3-4), never
 *    in a closed cycle.
 * 3. Counted without an open cycle: cycle 1 opens with the gross sale and the
 *    existing refunds of every line with a creator. A later cycle opens only
 *    for a positive new balance: lines with history start with their
 *    remaining net, new lines with gross + refunds.
 * 4. Counted with an open cycle: lines with history are corrected to
 *    WooCommerce' net (`line_changed`, or `line_removed` when the line is
 *    gone); a line with a creator and no history gets its first sale.
 * 5. Not counted (or deleted) with an open cycle: `reversal` of every line's
 *    balance (0 is left out) and `close`.
 *
 * Creator (ADR-038, B2/B3): a line's creator is its snapshot from its first
 * row. A line without history can get its first creator only in the open
 * cycle (or the one opening now); never in a closed one.
 */
final class SalesPlanner {

	/**
	 * Plans the rows for one order.
	 *
	 * @param OrderSnapshot     $order        WooCommerce data of the order.
	 * @param SalesLedgerState  $ledger       Stored ledger state of the order.
	 * @param DateTimeImmutable $processed_at Processing time, the `occurred_at` of rows that have no WooCommerce date.
	 */
	public function plan( OrderSnapshot $order, SalesLedgerState $ledger, DateTimeImmutable $processed_at ): SalesPlanResult {
		if ( $order->order_id !== $ledger->order_id ) {
			return SalesPlanResult::failed( new SalesPlanFailure( SalesFailureCode::Unexpected, $order->order_id ) );
		}

		try {
			$draft  = new SalesPlanDraft( $ledger, $order->exists ? $order->currency : '' );
			$active = $draft->cycles()->active_cycle();

			if ( $order->exists ) {
				$refunds = $order->current_refunds();
				$lines   = $order->lines_by_item();

				$this->plan_deleted_refunds( $draft, $refunds, $processed_at );
				$this->plan_refunds_of_lines_with_history( $draft, $lines, $refunds, $processed_at );

				if ( $order->is_counted && null === $active ) {
					$this->plan_new_cycle( $draft, $order, $lines, $refunds, $processed_at );
				} elseif ( $order->is_counted ) {
					$this->plan_open_cycle( $draft, $order, $active, $lines, $refunds, $processed_at );
				}
			}

			if ( ! $order->is_counted && null !== $active ) {
				$this->plan_close( $draft, $active, $processed_at );
			}

			return SalesPlanResult::planned( $draft->write() );
		} catch ( SalesPlanAbort $abort ) {
			return SalesPlanResult::failed( new SalesPlanFailure( $abort->failure_code, $order->order_id, $abort->item_id, $abort->refund_id ) );
		} catch ( InvalidArgumentException | OverflowException ) {
			// A row that breaks a SalesEntry invariant, or an amount out of range.
			return SalesPlanResult::failed( new SalesPlanFailure( SalesFailureCode::Unexpected, $order->order_id ) );
		}
	}

	/**
	 * Step 1: stored refunds that no longer exist in WooCommerce.
	 *
	 * @param SalesPlanDraft             $draft        Draft.
	 * @param array<int, RefundSnapshot> $refunds      Current refunds by id.
	 * @param DateTimeImmutable          $processed_at Processing time.
	 */
	private function plan_deleted_refunds( SalesPlanDraft $draft, array $refunds, DateTimeImmutable $processed_at ): void {
		foreach ( $draft->refund_rows() as $item_id => $by_refund ) {
			foreach ( $by_refund as $refund_id => $row ) {
				$key = 'c-rd:' . $item_id . ':' . $refund_id;

				if ( isset( $refunds[ $refund_id ] ) || $draft->has_key( $key ) ) {
					continue;
				}

				$net = SalesAmount::parse( $row->net_amount );
				$draft->add( SalesEntryType::Correction, SalesCorrectionReason::RefundDeleted, $key, $item_id, $row->cycle, $refund_id, -$row->quantity, SalesAmount::negate( $net ), $processed_at );

				// Planned together with c-rd only: a closed cycle must stay at 0.
				if ( $draft->cycles()->is_closed( $row->cycle ) ) {
					$draft->add( SalesEntryType::Correction, SalesCorrectionReason::ClosedCycleRefundDeleted, 'c-rdo:' . $item_id . ':' . $refund_id, $item_id, $row->cycle, $refund_id, $row->quantity, $net, $processed_at );
				}
			}
		}
	}

	/**
	 * Step 2: refunds of lines with history that the ledger does not have yet.
	 *
	 * @param SalesPlanDraft                $draft        Draft.
	 * @param array<int, OrderLineSnapshot> $lines        Lines by item id.
	 * @param array<int, RefundSnapshot>    $refunds      Current refunds by id.
	 * @param DateTimeImmutable             $processed_at Processing time (for the `c-ro` correction).
	 * @throws SalesPlanAbort When a refund line cannot be resolved or has a positive quantity or amount.
	 */
	private function plan_refunds_of_lines_with_history( SalesPlanDraft $draft, array $lines, array $refunds, DateTimeImmutable $processed_at ): void {
		$latest = $draft->cycles()->latest_cycle();
		$closed = null === $draft->cycles()->active_cycle();

		foreach ( $refunds as $refund ) {
			foreach ( self::sorted_refund_lines( $refund ) as $line ) {
				$item_id = self::resolve_refund_item( $draft, $lines, $refund, $line );

				if ( ! $draft->has_history( $item_id ) ) {
					continue;
				}

				$key = 'r:' . $item_id . ':' . $refund->refund_id;

				if ( $draft->has_key( $key ) || $draft->has_key( 'c-rd:' . $item_id . ':' . $refund->refund_id ) ) {
					continue;
				}

				$draft->add( SalesEntryType::Refund, null, $key, $item_id, $latest, $refund->refund_id, $line->quantity, $line->net, $refund->date_created );

				if ( $closed ) {
					$draft->add( SalesEntryType::Correction, SalesCorrectionReason::ClosedCycleRefund, 'c-ro:' . $item_id . ':' . $refund->refund_id, $item_id, $latest, $refund->refund_id, -$line->quantity, SalesAmount::negate( $line->net ), $processed_at );
				}
			}
		}
	}

	/**
	 * Step 3: a counted order without an open cycle.
	 *
	 * @param SalesPlanDraft                $draft        Draft.
	 * @param OrderSnapshot                 $order        Order.
	 * @param array<int, OrderLineSnapshot> $lines        Lines by item id.
	 * @param array<int, RefundSnapshot>    $refunds      Current refunds by id.
	 * @param DateTimeImmutable             $processed_at Processing time.
	 * @throws SalesPlanAbort When a line would get a negative balance.
	 */
	private function plan_new_cycle( SalesPlanDraft $draft, OrderSnapshot $order, array $lines, array $refunds, DateTimeImmutable $processed_at ): void {
		$cycle     = $draft->cycles()->next_cycle();
		$first     = 1 === $cycle;
		$sale_time = $first ? self::first_sale_time( $order ) : $processed_at;
		$remaining = array();
		$new_lines = array();
		$balance   = 0;

		foreach ( $lines as $item_id => $line ) {
			if ( $draft->has_history( $item_id ) ) {
				$delta = self::delta( $draft, $item_id, self::target( $line, $refunds ) );

				if ( $delta['net'] < 0 || $delta['quantity'] < 0 ) {
					throw new SalesPlanAbort( SalesFailureCode::NegativeBalance, $item_id );
				}

				if ( 0 !== $delta['net'] || 0 !== $delta['quantity'] ) {
					$remaining[ $item_id ] = $delta;
					$balance               = SalesAmount::add( $balance, $delta['net'] );
				}
			} elseif ( null !== $line->creator_id ) {
				$target = self::target( $line, $refunds );

				if ( $target['net'] < 0 || $target['quantity'] < 0 ) {
					throw new SalesPlanAbort( SalesFailureCode::NegativeBalance, $item_id );
				}

				$new_lines[ $item_id ] = $line;
				$balance               = SalesAmount::add( $balance, $target['net'] );
			}
		}

		// Cycle 1 opens for any line with a creator; a later one only for a positive new balance.
		if ( ( $first && array() === $new_lines ) || ( ! $first && $balance <= 0 ) ) {
			return;
		}

		$draft->cycle_event( $cycle, SalesCycleEventType::Open, $sale_time );

		foreach ( $remaining as $item_id => $delta ) {
			$draft->add( SalesEntryType::Sale, null, 's:' . $item_id . ':' . $cycle, $item_id, $cycle, 0, $delta['quantity'], $delta['net'], $sale_time );
		}

		foreach ( $new_lines as $line ) {
			$this->register_line( $draft, $line, $refunds, $cycle, $sale_time );
		}
	}

	/**
	 * Step 4: a counted order with an open cycle.
	 *
	 * @param SalesPlanDraft                $draft        Draft.
	 * @param OrderSnapshot                 $order        Order.
	 * @param int                           $cycle        The open cycle.
	 * @param array<int, OrderLineSnapshot> $lines        Lines by item id.
	 * @param array<int, RefundSnapshot>    $refunds      Current refunds by id.
	 * @param DateTimeImmutable             $processed_at Processing time.
	 * @throws SalesPlanAbort When a line would get a negative balance.
	 */
	private function plan_open_cycle( SalesPlanDraft $draft, OrderSnapshot $order, int $cycle, array $lines, array $refunds, DateTimeImmutable $processed_at ): void {
		foreach ( $draft->history_items() as $item_id ) {
			$line   = $lines[ $item_id ] ?? null;
			$target = null === $line ? array(
				'quantity' => 0,
				'net'      => 0,
			) : self::target( $line, $refunds );

			if ( $target['net'] < 0 || $target['quantity'] < 0 ) {
				throw new SalesPlanAbort( SalesFailureCode::NegativeBalance, $item_id );
			}

			$delta = self::delta( $draft, $item_id, $target );

			if ( 0 === $delta['net'] && 0 === $delta['quantity'] ) {
				continue;
			}

			$draft->add(
				SalesEntryType::Correction,
				null === $line ? SalesCorrectionReason::LineRemoved : SalesCorrectionReason::LineChanged,
				$draft->next_line_correction_key( $item_id, $cycle ),
				$item_id,
				$cycle,
				0,
				$delta['quantity'],
				$delta['net'],
				$processed_at
			);
		}

		$sale_time = 1 === $cycle ? self::first_sale_time( $order ) : $processed_at;

		foreach ( $lines as $item_id => $line ) {
			if ( ! $draft->has_history( $item_id ) && null !== $line->creator_id ) {
				$this->register_line( $draft, $line, $refunds, $cycle, $sale_time );
			}
		}
	}

	/**
	 * Step 5: reversal of every line's balance and close.
	 *
	 * @param SalesPlanDraft    $draft        Draft.
	 * @param int               $cycle        The open cycle.
	 * @param DateTimeImmutable $processed_at Processing time.
	 * @throws SalesPlanAbort When a line has a negative balance.
	 */
	private function plan_close( SalesPlanDraft $draft, int $cycle, DateTimeImmutable $processed_at ): void {
		foreach ( $draft->history_items() as $item_id ) {
			$total = $draft->total( $item_id );

			if ( $total['net'] < 0 || $total['quantity'] < 0 ) {
				throw new SalesPlanAbort( SalesFailureCode::NegativeBalance, $item_id );
			}

			// No 0/0 reversal: the close event alone marks the end (ADR-038 pkt. 3).
			if ( 0 !== $total['net'] || 0 !== $total['quantity'] ) {
				$draft->add( SalesEntryType::Reversal, null, 'v:' . $item_id . ':' . $cycle, $item_id, $cycle, 0, -$total['quantity'], SalesAmount::negate( $total['net'] ), $processed_at );
			}
		}

		$draft->cycle_event( $cycle, SalesCycleEventType::Close, $processed_at );
	}

	/**
	 * First registration of a line without history: gross sale + its refunds,
	 * all in $cycle, with the line's current creator as snapshot.
	 *
	 * @param SalesPlanDraft             $draft     Draft.
	 * @param OrderLineSnapshot          $line      Line (with a creator).
	 * @param array<int, RefundSnapshot> $refunds   Current refunds by id.
	 * @param int                        $cycle     Open cycle.
	 * @param DateTimeImmutable          $sale_time `occurred_at` of the sale.
	 * @throws SalesPlanAbort When the line would get a negative balance.
	 */
	private function register_line( SalesPlanDraft $draft, OrderLineSnapshot $line, array $refunds, int $cycle, DateTimeImmutable $sale_time ): void {
		$target = self::target( $line, $refunds );

		if ( null === $line->creator_id || $line->net < 0 || $line->quantity < 0 || $target['net'] < 0 || $target['quantity'] < 0 ) {
			throw new SalesPlanAbort( SalesFailureCode::NegativeBalance, $line->item_id );
		}

		$attribution = SalesPlanDraft::attribution( $line->creator_id, $line->product_id, $line->variation_id, $line->name );
		$draft->add( SalesEntryType::Sale, null, 's:' . $line->item_id . ':' . $cycle, $line->item_id, $cycle, 0, $line->quantity, $line->net, $sale_time, $attribution );

		foreach ( $refunds as $refund ) {
			foreach ( $refund->lines as $refund_line ) {
				if ( $refund_line->refunded_item_id === $line->item_id ) {
					$draft->add( SalesEntryType::Refund, null, 'r:' . $line->item_id . ':' . $refund->refund_id, $line->item_id, $cycle, $refund->refund_id, $refund_line->quantity, $refund_line->net, $refund->date_created );
				}
			}
		}
	}

	/**
	 * WooCommerce' current quantity and net of a line: the line minus its
	 * current refunds.
	 *
	 * @param OrderLineSnapshot          $line    Line.
	 * @param array<int, RefundSnapshot> $refunds Current refunds by id.
	 * @return array{quantity: int, net: int}
	 */
	private static function target( OrderLineSnapshot $line, array $refunds ): array {
		$quantity = $line->quantity;
		$net      = $line->net;

		foreach ( $refunds as $refund ) {
			foreach ( $refund->lines as $refund_line ) {
				if ( $refund_line->refunded_item_id === $line->item_id ) {
					$quantity = SalesAmount::add( $quantity, $refund_line->quantity );
					$net      = SalesAmount::add( $net, $refund_line->net );
				}
			}
		}

		return array(
			'quantity' => $quantity,
			'net'      => $net,
		);
	}

	/**
	 * Target minus the ledger's total of a line.
	 *
	 * @param SalesPlanDraft                 $draft   Draft.
	 * @param int                            $item_id Order item id.
	 * @param array{quantity: int, net: int} $target  Target.
	 * @return array{quantity: int, net: int}
	 */
	private static function delta( SalesPlanDraft $draft, int $item_id, array $target ): array {
		$total = $draft->total( $item_id );

		return array(
			'quantity' => SalesAmount::add( $target['quantity'], SalesAmount::negate( $total['quantity'] ) ),
			'net'      => SalesAmount::add( $target['net'], SalesAmount::negate( $total['net'] ) ),
		);
	}

	/**
	 * The order line a refund line refunds.
	 *
	 * @param SalesPlanDraft                $draft  Draft.
	 * @param array<int, OrderLineSnapshot> $lines  Lines by item id.
	 * @param RefundSnapshot                $refund Refund.
	 * @param RefundLineSnapshot            $line   Refund line.
	 * @throws SalesPlanAbort When the line is unknown to WooCommerce and the ledger, or the refund line is positive.
	 */
	private static function resolve_refund_item( SalesPlanDraft $draft, array $lines, RefundSnapshot $refund, RefundLineSnapshot $line ): int {
		$item_id = $line->refunded_item_id;

		if ( 0 === $item_id || ( ! isset( $lines[ $item_id ] ) && ! $draft->has_history( $item_id ) ) ) {
			throw new SalesPlanAbort( SalesFailureCode::UnresolvedRefundItem, 0 === $item_id ? null : $item_id, $refund->refund_id );
		}

		if ( $line->quantity > 0 || $line->net > 0 ) {
			throw new SalesPlanAbort( SalesFailureCode::Unexpected, $item_id, $refund->refund_id );
		}

		return $item_id;
	}

	/**
	 * Refund lines by order item id, ascending.
	 *
	 * @param RefundSnapshot $refund Refund.
	 * @return list<RefundLineSnapshot>
	 */
	private static function sorted_refund_lines( RefundSnapshot $refund ): array {
		$lines = $refund->lines;
		usort( $lines, static fn( RefundLineSnapshot $a, RefundLineSnapshot $b ): int => $a->refunded_item_id <=> $b->refunded_item_id );

		return $lines;
	}

	/**
	 * `occurred_at` of a sale in cycle 1: `date_paid`, else `date_created`
	 * (never `date_completed`, ADR-038 pkt. 19).
	 *
	 * @param OrderSnapshot $order Existing order.
	 * @throws SalesPlanAbort When the order has neither date.
	 */
	private static function first_sale_time( OrderSnapshot $order ): DateTimeImmutable {
		$time = $order->date_paid ?? $order->date_created;

		if ( null === $time ) {
			throw new SalesPlanAbort( SalesFailureCode::Unexpected );
		}

		return $time;
	}
}
