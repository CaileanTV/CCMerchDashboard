<?php
/**
 * Decides which creators' lines of an order are excluded by their own
 * discount code.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use DateTimeImmutable;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\Payouts\CreatorRowLocks;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesCacheInvalidator;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerState;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The assessment in the sales ledger synchronization (ADR-068), run under
 * the order's lock inside the synchronization's transaction, before the
 * planner:
 *
 * 1. The exclusion is per creator (the owner's decision of 2026-10-18): an
 *    order that used creator A's own code excludes A's lines only; creator
 *    B's lines of the same order count normally.
 * 2. A stored decision is permanent: later changes, refunds or a removed
 *    coupon do not bring that creator's lines back, and no new ledger rows
 *    are written for them.
 * 3. Otherwise the order's coupon codes (normalized, read through
 *    WooCommerce CRUD) are matched against every registration of those
 *    codes; a registration covers the order when its [valid_from, valid_to)
 *    contains the order's WooCommerce creation time (K1, design decision 5),
 *    and it is decided only when the order has lines or ledger rows of the
 *    registration's creator. The trigger is the use of the code, not the
 *    amount. Unknown coupon data is never read as "no creator code": the
 *    synchronization fails and writes nothing.
 * 4. A creator decided now whose rows of the order already exist has its
 *    creator row locked FOR UPDATE (ascending ids; the approved lock
 *    protocol: order, then creator rows), so its settlement document cannot
 *    run at the same time; then a locking read decides `open` (its rows are
 *    in an issued settlement document, K2) or `excluded`. Each decision and
 *    its audit record are written on the lock's connection in the same
 *    transaction.
 *
 * Existing ledger rows are never changed or reversed (M2): the read models
 * leave the excluded creator's rows out through OrderExclusionRepository's
 * filter, and the synchronizer plans the order without them
 * (OrderExclusionView).
 */
final class OrderExclusionAssessor {

	/**
	 * Constructor.
	 *
	 * @param CreatorCodeRepository    $codes      Registrations.
	 * @param OrderExclusionRepository $exclusions Decisions.
	 * @param CreatorRowLocks          $locks      R1 of the lock protocol.
	 * @param CreatorCodeDeviations    $deviations K5 deviation check.
	 * @param AuditTrail               $audit      Audit trail.
	 * @param SalesCacheInvalidator    $cache      Cached sales statistics.
	 */
	public function __construct(
		private CreatorCodeRepository $codes,
		private OrderExclusionRepository $exclusions,
		private CreatorRowLocks $locks,
		private CreatorCodeDeviations $deviations,
		private AuditTrail $audit,
		private SalesCacheInvalidator $cache
	) {
	}

	/**
	 * The order's exclusions, decided now when needed, or null when no
	 * creator's lines are excluded. Must run inside the synchronization's
	 * transaction under the order's lock held by $sales.
	 *
	 * @param OrderSnapshot     $order Order.
	 * @param SalesLedgerState  $state The order's stored ledger state.
	 * @param SalesRepository   $sales Storage holding the order's lock.
	 * @param DateTimeImmutable $now   Decision time.
	 * @throws DatabaseException When a read or write fails, or a creator row cannot be locked.
	 */
	public function assess( OrderSnapshot $order, SalesLedgerState $state, SalesRepository $sales, DateTimeImmutable $now ): ?OrderExclusionAssessment {
		$stored  = $this->exclusions->for_order( $order->order_id );
		$decided = array_flip( array_map( static fn( OrderExclusion $exclusion ): int => $exclusion->creator_id, $stored ) );
		$new     = array();

		foreach ( $this->covering( $order, $state ) as $registration ) {
			if ( ! isset( $decided[ $registration->creator_id ] ) ) {
				$new[ $registration->creator_id ] = $registration;
			}
		}

		if ( array() === $new ) {
			return array() === $stored ? null : new OrderExclusionAssessment( $stored );
		}

		$with_rows = array_values( array_intersect( array_keys( $new ), self::creators_of( $state ) ) );

		if ( array() !== $with_rows ) {
			$with_rows = $this->locks->acquire_for_order( $sales, $order->order_id, $with_rows );
		}

		$connection = $sales->lock_connection( $order->order_id );
		$all        = $stored;
		ksort( $new );

		foreach ( $new as $creator_id => $registration ) {
			$settled   = in_array( $creator_id, $with_rows, true ) && $this->exclusions->has_settled_rows( $order->order_id, $creator_id );
			$exclusion = new OrderExclusion(
				$order->order_id,
				$settled ? OrderExclusionStatus::Open : OrderExclusionStatus::Excluded,
				$registration->id,
				$creator_id,
				$registration->code,
				array() !== $this->deviations->of( $registration ),
				$order->date_created ?? $now,
				$now
			);

			if ( ! $this->exclusions->insert_on_connection( $exclusion, $connection ) ) {
				throw new DatabaseException( 'The order already has an exclusion decision for the creator.', array( 'order_id' => $order->order_id ) );
			}

			$this->audit->record_system_on_connection(
				AuditAction::OrderExcludedByCreatorCode,
				$creator_id,
				array(
					'order_id'         => $order->order_id,
					'creator_code_id'  => $registration->id,
					'decision'         => $exclusion->status->value,
					'settled_rows'     => $settled,
					'ledger_rows'      => count( array_filter( $state->entries, static fn( $entry ): bool => $entry->creator_id === $creator_id ) ),
					'coupon_deviation' => $exclusion->coupon_deviation,
				),
				$connection
			);

			$all[] = $exclusion;
		}

		return new OrderExclusionAssessment( $all, $with_rows );
	}

	/**
	 * The creators whose lines a backfill would find excluded, read-only
	 * (its dry run): stored decisions and covering registrations of creators
	 * with lines in the order.
	 *
	 * @param OrderSnapshot $order Order.
	 * @return list<int>
	 * @throws InvalidArgumentException When the order's coupon data is unknown.
	 * @throws DatabaseException When a read fails.
	 */
	public function excluded_creators( OrderSnapshot $order ): array {
		$ids = array_map( static fn( OrderExclusion $exclusion ): int => $exclusion->creator_id, $this->exclusions->for_order( $order->order_id ) );

		foreach ( $this->covering( $order, null ) as $registration ) {
			$ids[] = $registration->creator_id;
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * After COMMIT of decisions made now: the creators whose existing rows
	 * they took out get a new cache generation of their statistics.
	 *
	 * @param OrderExclusionAssessment $assessment Assessment.
	 */
	public function committed( OrderExclusionAssessment $assessment ): void {
		if ( array() !== $assessment->invalidate ) {
			$this->cache->invalidate( $assessment->invalidate );
		}
	}

	/**
	 * For each creator, the oldest registration whose code the order used
	 * within its period, when the order has lines (or, with $state, ledger
	 * rows) of that creator.
	 *
	 * @param OrderSnapshot         $order Order.
	 * @param SalesLedgerState|null $state Stored ledger state, null to look at the lines only.
	 * @return array<int, CreatorCode> By creator id.
	 * @throws InvalidArgumentException When the coupon data of an existing order is unknown.
	 */
	private function covering( OrderSnapshot $order, ?SalesLedgerState $state ): array {
		if ( ! $order->exists ) {
			return array();
		}

		if ( null === $order->coupon_codes ) {
			throw new InvalidArgumentException( 'The order\'s coupon data is unknown.' );
		}

		if ( array() === $order->coupon_codes || null === $order->date_created ) {
			return array();
		}

		$creators = array_flip( null === $state ? array() : self::creators_of( $state ) );

		foreach ( $order->lines as $line ) {
			if ( null !== $line->creator_id ) {
				$creators[ $line->creator_id ] = true;
			}
		}

		$result = array();

		foreach ( $this->codes->matching( $order->coupon_codes ) as $registration ) {
			if ( isset( $creators[ $registration->creator_id ] ) && ! isset( $result[ $registration->creator_id ] ) && $registration->covers( $order->date_created ) ) {
				$result[ $registration->creator_id ] = $registration;
			}
		}

		return $result;
	}

	/**
	 * The creators of the stored ledger rows, ascending.
	 *
	 * @param SalesLedgerState $state Stored ledger state.
	 * @return list<int>
	 */
	private static function creators_of( SalesLedgerState $state ): array {
		$ids = array_values( array_unique( array_map( static fn( $entry ): int => $entry->creator_id, $state->entries ) ) );
		sort( $ids );

		return $ids;
	}
}
