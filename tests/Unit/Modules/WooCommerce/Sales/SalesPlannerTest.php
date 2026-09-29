<?php
/**
 * Tests for the sales ledger state transition.
 *
 * Every scenario runs through sync(): plan, "write" the plan into an in-memory
 * ledger, then check that planning again changes nothing and that the ledger
 * invariant of ADR-038 holds.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesCorrectionReason;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleEvent;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleEventType;
use MerchBoard\Modules\WooCommerce\Sales\SalesCycleState;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntryType;
use MerchBoard\Modules\WooCommerce\Sales\SalesFailureCode;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerState;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerWrite;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanDraft;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanFailure;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanner;
use MerchBoard\Modules\WooCommerce\Sales\SalesPlanResult;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SalesPlanner::class )]
#[CoversClass( SalesPlanDraft::class )]
#[CoversClass( SalesPlanResult::class )]
#[CoversClass( SalesPlanFailure::class )]
final class SalesPlannerTest extends TestCase {

	private const ORDER   = 7;
	private const CREATOR = 3;
	private const OTHER   = 4;
	private const CREATED = '2026-09-01 10:00:00';
	private const PAID    = '2026-09-01 10:05:00';

	private SalesLedgerState $state;

	private int $clock = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->state = new SalesLedgerState( self::ORDER, array(), new SalesCycleState( self::ORDER, array() ) );
	}

	/**
	 * Scenario 1, 3: a counted order with a creator opens cycle 1 with the gross sale.
	 */
	public function test_new_counted_order_opens_cycle_1_with_the_gross_sale_at_the_payment_date(): void {
		$write = $this->sync( $this->order( array( self::line( 11, 2, '100' ) ) ) );

		self::assertSame( array( 's:11:1' ), self::keys( $write ) );
		$sale = $write->entries[0];
		self::assertSame( array( SalesEntryType::Sale, self::CREATOR, 1, 2, '100.0000', 'DKK', 20, 0, 'Line 11' ), array( $sale->type, $sale->creator_id, $sale->cycle, $sale->quantity, $sale->net_amount, $sale->currency, $sale->product_id, $sale->variation_id, $sale->item_name ) );
		self::assertSame( self::PAID, self::time( $sale->occurred_at ), 'Cycle 1 sale: date_paid.' );
		self::assertSame( array( '1:open:' . self::PAID ), self::events( $write ) );
	}

	public function test_cycle_1_uses_the_creation_date_without_a_payment_date(): void {
		$write = $this->sync( $this->order( array( self::line( 11, 1, '10' ) ), array(), true, null, 'DKK', false ) );

		self::assertSame( self::CREATED, self::time( $write->entries[0]->occurred_at ) );
	}

	/**
	 * Scenario 2.
	 */
	public function test_counted_order_without_a_creator_writes_nothing(): void {
		$write = $this->sync( $this->order( array( self::line( 11, 2, '100', null ) ) ) );

		self::assertSame( array(), $write->entries );
		self::assertSame( array(), $write->cycle_events, 'No cycle for an order without creator lines.' );
	}

	/**
	 * Scenario 4: refunds before the order ever counted; cycle 1 = gross + refunds.
	 */
	public function test_refund_before_the_order_counted_is_registered_with_the_gross_sale_in_cycle_1(): void {
		$lines   = array( self::line( 11, 2, '100' ) );
		$refunds = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-30' ) ) ) ) );

		self::assertSame( array(), self::keys( $this->sync( $this->order( $lines, $refunds, false ) ) ), 'Not counted, no cycle: ignored.' );

		$write = $this->sync( $this->order( $lines, $refunds ) );

		self::assertSame( array( 's:11:1', 'r:11:100' ), self::keys( $write ) );
		self::assertSame( '-30.0000', $write->entries[1]->net_amount );
		self::assertSame( '2026-09-02 09:00:00', self::time( $write->entries[1]->occurred_at ), 'Refund: its creation date.' );
		self::assertSame( 700000, $this->balance( 11 ) );
	}

	/**
	 * Scenario 6.
	 */
	public function test_partial_and_additional_refunds_on_the_open_cycle(): void {
		$lines = array( self::line( 11, 2, '100' ) );
		$this->sync( $this->order( $lines ) );
		$first = self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, 0, SalesAmount::parse( '-30' ) ) ) );

		self::assertSame( array( 'r:11:100' ), self::keys( $this->sync( $this->order( $lines, array( $first ) ) ) ) );

		$second = self::refund( 101, '2026-09-03 09:00:00', array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-20' ) ) ) );
		self::assertSame( array( 'r:11:101' ), self::keys( $this->sync( $this->order( $lines, array( $second, $first ) ) ) ) );
		self::assertSame( 500000, $this->balance( 11 ) );
		self::assertSame( 1, $this->quantity( 11 ) );
	}

	/**
	 * Scenario 5: full refund; WooCommerce moves the order to `refunded` (not counted).
	 */
	public function test_full_refund_closes_the_cycle_without_a_zero_reversal(): void {
		$lines = array( self::line( 11, 2, '100' ) );
		$this->sync( $this->order( $lines ) );
		$full = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, -2, SalesAmount::parse( '-100' ) ) ) ) );

		$write = $this->sync( $this->order( $lines, $full, false ) );

		self::assertSame( array( 'r:11:100' ), self::keys( $write ), 'No 0/0 reversal.' );
		self::assertSame( array( '1:close:' . $this->last_time() ), self::events( $write ) );

		// Counted again: nothing remains, so no new cycle.
		$again = $this->sync( $this->order( $lines, $full ) );
		self::assertSame( array(), self::keys( $again ) );
		self::assertSame( array(), $again->cycle_events );
	}

	/**
	 * Scenario 7.
	 */
	public function test_cancellation_reverses_the_balance_and_closes_the_cycle(): void {
		$lines = array( self::line( 11, 2, '100' ), self::line( 12, 1, '0', self::OTHER ) );
		$this->sync( $this->order( $lines ) );

		$write = $this->sync( $this->order( $lines, array(), false ) );

		self::assertSame( array( 'v:11:1', 'v:12:1' ), self::keys( $write ) );
		self::assertSame( array( -2, '-100.0000' ), array( $write->entries[0]->quantity, $write->entries[0]->net_amount ) );
		self::assertSame( array( -1, '0.0000' ), array( $write->entries[1]->quantity, $write->entries[1]->net_amount ), 'A free item still reverses its quantity; only 0/0 is left out.' );
		self::assertSame( $this->last_time(), self::time( $write->entries[0]->occurred_at ), 'Reversal: processing time.' );
		self::assertSame( array( '1:close:' . $this->last_time() ), self::events( $write ) );
	}

	/**
	 * Scenario 8, 9.
	 */
	public function test_recount_opens_a_new_cycle_with_the_remaining_net_at_processing_time(): void {
		$lines   = array( self::line( 11, 2, '100' ) );
		$refunds = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, 0, SalesAmount::parse( '-30' ) ) ) ) );
		$this->sync( $this->order( $lines, $refunds ) );
		self::assertSame( array( 'v:11:1' ), self::keys( $this->sync( $this->order( $lines, $refunds, false ) ) ) );

		$write = $this->sync( $this->order( $lines, $refunds ) );

		self::assertSame( array( 's:11:2' ), self::keys( $write ) );
		self::assertSame( array( 2, '70.0000' ), array( $write->entries[0]->quantity, $write->entries[0]->net_amount ) );
		self::assertSame( $this->last_time(), self::time( $write->entries[0]->occurred_at ) );
		self::assertSame( array( '2:open:' . $this->last_time() ), self::events( $write ) );
	}

	/**
	 * Scenario 10.
	 */
	public function test_quantity_changes_are_numbered_line_changed_corrections(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ) ) ) );

		$more = $this->sync( $this->order( array( self::line( 11, 3, '150' ) ) ) );
		$less = $this->sync( $this->order( array( self::line( 11, 1, '50' ) ) ) );

		self::assertSame( array( 'c-l:11:1:1' ), self::keys( $more ) );
		self::assertSame( array( SalesCorrectionReason::LineChanged, 1, '50.0000' ), array( $more->entries[0]->correction_reason, $more->entries[0]->quantity, $more->entries[0]->net_amount ) );
		self::assertSame( array( 'c-l:11:1:2' ), self::keys( $less ) );
		self::assertSame( array( -2, '-100.0000' ), array( $less->entries[0]->quantity, $less->entries[0]->net_amount ) );
	}

	/**
	 * Scenario 11.
	 */
	public function test_amount_change_without_quantity_change(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ) ) ) );

		$write = $this->sync( $this->order( array( self::line( 11, 2, '80.5' ) ) ) );

		self::assertSame( array( 0, '-19.5000' ), array( $write->entries[0]->quantity, $write->entries[0]->net_amount ) );
	}

	/**
	 * Scenario 12.
	 */
	public function test_removed_line_gets_a_line_removed_correction(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ), self::line( 12, 1, '40' ) ) ) );

		$write = $this->sync( $this->order( array( self::line( 12, 1, '40' ) ) ) );

		self::assertSame( array( 'c-l:11:1:1' ), self::keys( $write ) );
		self::assertSame( array( SalesCorrectionReason::LineRemoved, -2, '-100.0000' ), array( $write->entries[0]->correction_reason, $write->entries[0]->quantity, $write->entries[0]->net_amount ) );
	}

	/**
	 * Scenario 13.
	 */
	public function test_new_line_on_the_open_cycle_gets_its_first_sale(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ) ) ) );

		$write = $this->sync( $this->order( array( self::line( 11, 2, '100' ), self::line( 12, 1, '40', self::OTHER ) ) ) );

		self::assertSame( array( 's:12:1' ), self::keys( $write ) );
		self::assertSame( self::OTHER, $write->entries[0]->creator_id );
		self::assertSame( self::PAID, self::time( $write->entries[0]->occurred_at ), 'A sale in cycle 1 uses date_paid.' );
	}

	public function test_new_line_on_a_later_cycle_uses_processing_time(): void {
		$lines = array( self::line( 11, 2, '100' ) );
		$this->sync( $this->order( $lines ) );
		$this->sync( $this->order( $lines, array(), false ) );
		$this->sync( $this->order( $lines ) );

		$write = $this->sync( $this->order( array( self::line( 11, 2, '100' ), self::line( 12, 1, '40' ) ) ) );

		self::assertSame( array( 's:12:2' ), self::keys( $write ) );
		self::assertSame( $this->last_time(), self::time( $write->entries[0]->occurred_at ) );
	}

	/**
	 * Scenario 14.
	 */
	public function test_creator_added_for_the_first_time_on_the_open_cycle(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ), self::line( 12, 1, '40', null ) ) ) );

		$write = $this->sync( $this->order( array( self::line( 11, 2, '100' ), self::line( 12, 1, '40', self::OTHER ) ) ) );

		self::assertSame( array( 's:12:1' ), self::keys( $write ) );
		self::assertSame( self::OTHER, $write->entries[0]->creator_id );
	}

	/**
	 * Scenario 15.
	 */
	public function test_creator_change_after_history_keeps_the_snapshot(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ) ) ) );
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-50' ) ) ) ) );

		$write = $this->sync( $this->order( array( self::line( 11, 3, '150', self::OTHER ) ), $refund ) );

		self::assertSame( array( 'r:11:100', 'c-l:11:1:1' ), self::keys( $write ) );
		self::assertSame( array( self::CREATOR, self::CREATOR ), array_map( static fn( SalesEntry $e ): int => $e->creator_id, $write->entries ) );
		self::assertSame( array( self::CREATOR ), $this->creators(), 'No row ever went to the new creator.' );
	}

	/**
	 * Scenario 16.
	 */
	public function test_creator_introduced_while_the_cycle_is_closed_creates_no_retroactive_row(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ), self::line( 12, 1, '40', null ) ) ) );
		$this->sync( $this->order( array( self::line( 11, 2, '100' ), self::line( 12, 1, '40', null ) ), array(), false ) );

		$write = $this->sync( $this->order( array( self::line( 11, 2, '100' ), self::line( 12, 1, '40', self::OTHER ) ), array(), false ) );

		self::assertSame( array(), self::keys( $write ) );
		self::assertSame( array(), $write->cycle_events );
	}

	/**
	 * Scenario 17.
	 */
	public function test_refund_of_a_line_without_history_in_a_closed_cycle_is_not_registered(): void {
		$lines = array( self::line( 11, 2, '100' ), self::line( 12, 1, '40', null ) );
		$this->sync( $this->order( $lines ) );
		$this->sync( $this->order( $lines, array(), false ) );
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 12, -1, SalesAmount::parse( '-40' ) ) ) ) );

		$write = $this->sync( $this->order( array( self::line( 11, 2, '100' ), self::line( 12, 1, '40', self::OTHER ) ), $refund, false ) );

		self::assertSame( array(), self::keys( $write ) );
	}

	/**
	 * Scenario 18.
	 */
	public function test_refund_on_a_closed_cycle_with_history_is_offset_and_the_next_cycle_starts_net(): void {
		$lines = array( self::line( 11, 2, '100' ) );
		$this->sync( $this->order( $lines ) );
		$this->sync( $this->order( $lines, array(), false ) );
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, 0, SalesAmount::parse( '-30' ) ) ) ) );

		$write = $this->sync( $this->order( $lines, $refund, false ) );

		self::assertSame( array( 'r:11:100', 'c-ro:11:100' ), self::keys( $write ) );
		self::assertSame( array( 1, 1 ), array( $write->entries[0]->cycle, $write->entries[1]->cycle ) );
		self::assertSame( array( SalesCorrectionReason::ClosedCycleRefund, '30.0000' ), array( $write->entries[1]->correction_reason, $write->entries[1]->net_amount ) );
		self::assertSame( $this->last_time(), self::time( $write->entries[1]->occurred_at ), 'Correction: processing time.' );

		$recount = $this->sync( $this->order( $lines, $refund ) );
		self::assertSame( array( 's:11:2' ), self::keys( $recount ) );
		self::assertSame( '70.0000', $recount->entries[0]->net_amount );
	}

	public function test_refund_during_recount_goes_to_the_closed_cycle_before_the_new_one_opens(): void {
		$lines = array( self::line( 11, 2, '100' ) );
		$this->sync( $this->order( $lines ) );
		$this->sync( $this->order( $lines, array(), false ) );
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, 0, SalesAmount::parse( '-30' ) ) ) ) );

		$write = $this->sync( $this->order( $lines, $refund ) );

		self::assertSame( array( 'r:11:100', 'c-ro:11:100', 's:11:2' ), self::keys( $write ) );
		self::assertSame( '70.0000', $write->entries[2]->net_amount );
	}

	/**
	 * Scenario 19.
	 */
	public function test_refund_deletion_on_the_open_cycle(): void {
		$lines  = array( self::line( 11, 2, '100' ) );
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-30' ) ) ) ) );
		$this->sync( $this->order( $lines, $refund ) );

		$write = $this->sync( $this->order( $lines ) );

		self::assertSame( array( 'c-rd:11:100' ), self::keys( $write ) );
		self::assertSame( array( SalesCorrectionReason::RefundDeleted, 100, 1, '30.0000', 1 ), array( $write->entries[0]->correction_reason, $write->entries[0]->refund_id, $write->entries[0]->quantity, $write->entries[0]->net_amount, $write->entries[0]->cycle ) );
		self::assertSame( 1000000, $this->balance( 11 ) );
	}

	/**
	 * Scenario 20.
	 */
	public function test_refund_deletion_on_a_closed_cycle_is_offset_there(): void {
		$lines  = array( self::line( 11, 2, '100' ) );
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-30' ) ) ) ) );
		$this->sync( $this->order( $lines, $refund ) );
		$this->sync( $this->order( $lines, $refund, false ) );

		$write = $this->sync( $this->order( $lines, array(), false ) );

		self::assertSame( array( 'c-rd:11:100', 'c-rdo:11:100' ), self::keys( $write ) );
		self::assertSame( array( '30.0000', '-30.0000' ), array( $write->entries[0]->net_amount, $write->entries[1]->net_amount ) );
		self::assertSame( SalesCorrectionReason::ClosedCycleRefundDeleted, $write->entries[1]->correction_reason );

		$recount = $this->sync( $this->order( $lines ) );
		self::assertSame( array( 's:11:2' ), self::keys( $recount ) );
		self::assertSame( '100.0000', $recount->entries[0]->net_amount );
	}

	public function test_deleting_a_refund_that_was_offset_on_a_closed_cycle(): void {
		$lines  = array( self::line( 11, 2, '100' ) );
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, 0, SalesAmount::parse( '-30' ) ) ) ) );
		$this->sync( $this->order( $lines ) );
		$this->sync( $this->order( $lines, array(), false ) );
		$this->sync( $this->order( $lines, $refund, false ) );

		self::assertSame( array( 'c-rd:11:100', 'c-rdo:11:100' ), self::keys( $this->sync( $this->order( $lines, array(), false ) ) ) );
	}

	/**
	 * Scenario 21.
	 */
	public function test_known_deleted_refund_is_absent_even_when_woocommerce_still_lists_it(): void {
		$lines  = array( self::line( 11, 2, '100' ) );
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-30' ) ) ) ) );
		$this->sync( $this->order( $lines, $refund ) );

		$write = $this->sync( $this->order( $lines, $refund, true, 100 ) );

		self::assertSame( array( 'c-rd:11:100' ), self::keys( $write ) );
	}

	/**
	 * Scenario 22.
	 */
	public function test_currency_mismatch_writes_nothing(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ) ) ) );

		$result = $this->plan( $this->order( array( self::line( 11, 2, '100' ) ), array(), true, null, 'EUR' ) );

		self::assertNull( $result->write );
		self::assertSame( SalesFailureCode::CurrencyMismatch, $result->failure?->code );
		self::assertSame( self::ORDER, $result->failure->order_id );
	}

	/**
	 * Scenario 23.
	 */
	public function test_negative_balance_writes_nothing(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ) ) ) );
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-150' ) ) ) ) );

		$result = $this->plan( $this->order( array( self::line( 11, 2, '100' ) ), $refund ) );

		self::assertNull( $result->write );
		self::assertSame( array( SalesFailureCode::NegativeBalance, 11 ), array( $result->failure?->code, $result->failure?->item_id ) );
	}

	public function test_unresolved_refund_items_write_nothing(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ) ) ) );

		foreach ( array( 99, 0 ) as $item ) {
			$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( $item, -1, SalesAmount::parse( '-10' ) ) ) ) );
			$result = $this->plan( $this->order( array( self::line( 11, 2, '100' ) ), $refund ) );

			self::assertSame( array( SalesFailureCode::UnresolvedRefundItem, 100 ), array( $result->failure?->code, $result->failure?->refund_id ), 'Item ' . $item );
		}
	}

	public function test_positive_refund_line_and_other_order_are_unexpected(): void {
		$refund = array( self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, 1, SalesAmount::parse( '10' ) ) ) ) );

		self::assertSame( SalesFailureCode::Unexpected, $this->plan( $this->order( array( self::line( 11, 2, '100' ) ), $refund ) )->failure?->code );

		$other = new OrderSnapshot( 8, true, true, 'DKK', self::date( self::CREATED ), null, array(), array() );
		self::assertSame( SalesFailureCode::Unexpected, ( new SalesPlanner() )->plan( $other, $this->state, self::date( self::CREATED ) )->failure?->code );
	}

	public function test_deleted_order_is_closed_from_the_ledger_alone(): void {
		$this->sync( $this->order( array( self::line( 11, 2, '100' ) ) ) );

		$write = $this->sync( OrderSnapshot::deleted( self::ORDER ) );

		self::assertSame( array( 'v:11:1' ), self::keys( $write ) );
		self::assertSame( 'DKK', $write->entries[0]->currency, 'Currency from the ledger.' );
		self::assertSame( array( '1:close:' . $this->last_time() ), self::events( $write ) );
	}

	/**
	 * Scenario 24: the documented key model over a full lifecycle.
	 */
	public function test_event_keys_follow_the_documented_model(): void {
		$lines = array( self::line( 11, 2, '100' ) );
		$r100  = self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 11, 0, SalesAmount::parse( '-10' ) ) ) );
		$r101  = self::refund( 101, '2026-09-03 09:00:00', array( new RefundLineSnapshot( 11, 0, SalesAmount::parse( '-5' ) ) ) );
		$keys  = array();

		foreach ( array(
			$this->order( $lines ),
			$this->order( $lines, array( $r100 ) ),
			$this->order( array( self::line( 11, 3, '150' ) ), array( $r100 ) ),
			$this->order( array( self::line( 11, 3, '150' ) ), array( $r100 ), false ),
			$this->order( array( self::line( 11, 3, '150' ) ), array( $r100, $r101 ), false ),
			$this->order( array( self::line( 11, 3, '150' ) ), array( $r101 ), false ),
			$this->order( array( self::line( 11, 3, '150' ) ), array( $r101 ) ),
		) as $order ) {
			$keys = array_merge( $keys, self::keys( $this->sync( $order ) ) );
		}

		// r:11:100 lies in cycle 1, closed by then: its deletion is offset there (c-rdo).
		self::assertSame( array( 's:11:1', 'r:11:100', 'c-l:11:1:1', 'v:11:1', 'r:11:101', 'c-ro:11:101', 'c-rd:11:100', 'c-rdo:11:100', 's:11:2' ), $keys );
		self::assertSame( '145.0000', SalesAmount::format( $this->balance( 11 ) ) );
	}

	/**
	 * Scenario 26: same input, same plan; independent of the order WooCommerce lists lines and refunds.
	 */
	public function test_same_input_gives_the_same_plan(): void {
		$a       = self::line( 11, 2, '100' );
		$b       = self::line( 12, 1, '40', self::OTHER );
		$r100    = self::refund( 100, '2026-09-02 09:00:00', array( new RefundLineSnapshot( 12, 0, SalesAmount::parse( '-4' ) ), new RefundLineSnapshot( 11, -1, SalesAmount::parse( '-50' ) ) ) );
		$r101    = self::refund( 101, '2026-09-03 09:00:00', array( new RefundLineSnapshot( 11, 0, SalesAmount::parse( '-1' ) ) ) );
		$time    = self::date( '2026-09-05 12:00:00' );
		$planner = new SalesPlanner();

		$first    = $planner->plan( $this->order( array( $a, $b ), array( $r100, $r101 ) ), $this->state, $time );
		$second   = $planner->plan( $this->order( array( $a, $b ), array( $r100, $r101 ) ), $this->state, $time );
		$shuffled = $planner->plan( $this->order( array( $b, $a ), array( $r101, $r100 ) ), $this->state, $time );

		self::assertEquals( $first, $second );
		self::assertEquals( $first, $shuffled );
		self::assertSame( array( 's:11:1', 'r:11:100', 'r:11:101', 's:12:1', 'r:12:100' ), self::keys( $first->write ?? new SalesLedgerWrite() ) );
	}

	/**
	 * Plans, applies the plan to the in-memory ledger, and checks that
	 * planning again is empty and the invariant holds.
	 *
	 * @param OrderSnapshot $order Order.
	 */
	private function sync( OrderSnapshot $order ): SalesLedgerWrite {
		$result = $this->plan( $order );
		self::assertNull( $result->failure, 'Unexpected failure: ' . ( $result->failure?->code->value ?? '' ) );
		self::assertNotNull( $result->write );

		$this->apply( $result->write );

		// Same processing time: a repeated hook in the same moment.
		$again = ( new SalesPlanner() )->plan( $order, $this->state, self::date( $this->last_time() ) );
		self::assertEquals( SalesPlanResult::planned( new SalesLedgerWrite() ), $again, 'Planning again changes nothing.' );
		$this->assert_invariant( $order );

		return $result->write;
	}

	/**
	 * Plans with a new, later processing time.
	 *
	 * @param OrderSnapshot $order Order.
	 */
	private function plan( OrderSnapshot $order ): SalesPlanResult {
		++$this->clock;

		return ( new SalesPlanner() )->plan( $order, $this->state, self::date( $this->last_time() ) );
	}

	/**
	 * Processing time of the latest plan (and of the latest sync()).
	 */
	private function last_time(): string {
		return '2026-09-10 12:00:' . str_pad( (string) $this->clock, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * "Writes" a plan as the repository would (ids in insertion order).
	 *
	 * @param SalesLedgerWrite $write Plan.
	 */
	private function apply( SalesLedgerWrite $write ): void {
		$entries = $this->state->entries;
		$events  = $this->state->cycles->events;

		foreach ( $write->entries as $e ) {
			$entries[] = new SalesEntry( $e->event_key, $e->creator_id, $e->order_id, $e->order_item_id, $e->cycle, $e->refund_id, $e->product_id, $e->variation_id, $e->item_name, $e->type, $e->correction_reason, $e->quantity, $e->net_amount, $e->currency, $e->occurred_at, count( $entries ) + 1, $e->occurred_at );
		}

		foreach ( $write->cycle_events as $c ) {
			$events[] = new SalesCycleEvent( $c->order_id, $c->cycle, $c->event, $c->occurred_at, count( $events ) + 1, $c->occurred_at );
		}

		self::assertSame( count( $entries ), count( array_unique( array_map( static fn( SalesEntry $e ): string => $e->event_key, $entries ) ) ), 'Event keys stay unique.' );
		$this->state = new SalesLedgerState( self::ORDER, $entries, new SalesCycleState( self::ORDER, $events ) );
	}

	/**
	 * ADR-038 pkt. 1: counted → balance = WooCommerce' net per line with history;
	 * otherwise 0. Every closed cycle sums to 0 per line.
	 *
	 * @param OrderSnapshot $order Order.
	 */
	private function assert_invariant( OrderSnapshot $order ): void {
		$lines   = $order->lines_by_item();
		$refunds = $order->current_refunds();
		$items   = array_unique( array_map( static fn( SalesEntry $e ): int => $e->order_item_id, $this->state->entries ) );

		foreach ( $items as $item ) {
			$target = 0;

			if ( $order->is_counted && isset( $lines[ $item ] ) ) {
				$target = $lines[ $item ]->net;

				foreach ( $refunds as $refund ) {
					foreach ( $refund->lines as $line ) {
						$target += $line->refunded_item_id === $item ? $line->net : 0;
					}
				}
			}

			self::assertSame( $target, $this->balance( $item ), 'Invariant for line ' . $item );

			foreach ( $this->state->cycles->events as $event ) {
				if ( SalesCycleEventType::Close === $event->event ) {
					self::assertSame( 0, $this->balance( $item, $event->cycle ), 'Closed cycle ' . $event->cycle . ' sums to 0 for line ' . $item );
				}
			}
		}
	}

	/**
	 * Ledger balance of a line, optionally of one cycle.
	 *
	 * @param int      $item  Item id.
	 * @param int|null $cycle Cycle.
	 */
	private function balance( int $item, ?int $cycle = null ): int {
		$sum = 0;

		foreach ( $this->state->entries as $e ) {
			if ( $e->order_item_id === $item && ( null === $cycle || $e->cycle === $cycle ) ) {
				$sum += SalesAmount::parse( $e->net_amount );
			}
		}

		return $sum;
	}

	/**
	 * Ledger quantity of a line.
	 *
	 * @param int $item Item id.
	 */
	private function quantity( int $item ): int {
		return array_sum( array_map( static fn( SalesEntry $e ): int => $e->order_item_id === $item ? $e->quantity : 0, $this->state->entries ) );
	}

	/**
	 * Creators of all ledger rows.
	 *
	 * @return list<int>
	 */
	private function creators(): array {
		return array_values( array_unique( array_map( static fn( SalesEntry $e ): int => $e->creator_id, $this->state->entries ) ) );
	}

	/**
	 * An order snapshot.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 *
	 * @param OrderLineSnapshot[] $lines           Lines.
	 * @param RefundSnapshot[]    $refunds         Refunds.
	 * @param bool                $counted         Counted status.
	 * @param int|null            $deleted_refund  Known deleted refund.
	 * @param string              $currency        Currency.
	 * @param bool                $paid            Whether it has a payment date.
	 */
	private function order( array $lines, array $refunds = array(), bool $counted = true, ?int $deleted_refund = null, string $currency = 'DKK', bool $paid = true ): OrderSnapshot {
		return new OrderSnapshot( self::ORDER, true, $counted, $currency, self::date( self::CREATED ), $paid ? self::date( self::PAID ) : null, $lines, $refunds, $deleted_refund );
	}

	/**
	 * An order line of product 20.
	 *
	 * @param int      $item     Item id.
	 * @param int      $quantity Quantity.
	 * @param string   $net      Net amount.
	 * @param int|null $creator  Current creator.
	 */
	private static function line( int $item, int $quantity, string $net, ?int $creator = self::CREATOR ): OrderLineSnapshot {
		return new OrderLineSnapshot( $item, 20, 0, 'Line ' . $item, $quantity, SalesAmount::parse( $net ), $creator );
	}

	/**
	 * A refund.
	 *
	 * @param int                  $id    Refund id.
	 * @param string               $date  Creation date (UTC).
	 * @phpstan-param list<RefundLineSnapshot> $lines
	 *
	 * @param RefundLineSnapshot[] $lines Lines.
	 */
	private static function refund( int $id, string $date, array $lines ): RefundSnapshot {
		return new RefundSnapshot( $id, self::date( $date ), $lines );
	}

	/**
	 * UTC time.
	 *
	 * @param string $time Y-m-d H:i:s.
	 */
	private static function date( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Formats a time.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function time( DateTimeImmutable $time ): string {
		return $time->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Event keys of a plan.
	 *
	 * @param SalesLedgerWrite $write Plan.
	 * @return list<string>
	 */
	private static function keys( SalesLedgerWrite $write ): array {
		return array_map( static fn( SalesEntry $e ): string => $e->event_key, $write->entries );
	}

	/**
	 * Cycle events of a plan as "cycle:event:time".
	 *
	 * @param SalesLedgerWrite $write Plan.
	 * @return list<string>
	 */
	private static function events( SalesLedgerWrite $write ): array {
		return array_map( static fn( SalesCycleEvent $e ): string => $e->cycle . ':' . $e->event->value . ':' . self::time( $e->occurred_at ), $write->cycle_events );
	}
}
