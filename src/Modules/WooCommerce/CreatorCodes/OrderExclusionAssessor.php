<?php
/**
 * Decides whether an order is excluded by a creator's own discount code.
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
 * 1. An order with a stored decision gets no new ledger rows (the exclusion
 *    is permanent: later changes, refunds or a removed coupon do not bring
 *    it back; events after the exclusion are not written to the ledger).
 * 2. Otherwise its coupon codes (normalized, read through WooCommerce CRUD)
 *    are matched against every registration of those codes; the order is
 *    covered when a registration's [valid_from, valid_to) contains the
 *    order's WooCommerce creation time (K1, design decision 5). The trigger
 *    is the use of the code, not the amount, whatever the number of
 *    creators on the order. Unknown coupon data is never read as "no
 *    creator code": the synchronization fails and writes nothing.
 * 3. A covered order is decided now: when it already has ledger rows, the
 *    rows of its creators are locked FOR UPDATE in ascending id order (the
 *    approved lock protocol: order, then creator rows), so a settlement
 *    document of one of them cannot run at the same time; then a locking
 *    read decides `open` (rows in an issued settlement document, K2) or
 *    `excluded`. The decision and its audit record are written on the
 *    lock's connection in the same transaction; no ledger row is written.
 *
 * Existing ledger rows are never changed or reversed (M2): the read models
 * leave them out through OrderExclusionRepository's filter.
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
	 * The order's exclusion, decided now when needed, or null when the order
	 * counts. Must run inside the synchronization's transaction under the
	 * order's lock held by $sales.
	 *
	 * @param OrderSnapshot     $order Order.
	 * @param SalesLedgerState  $state The order's stored ledger state.
	 * @param SalesRepository   $sales Storage holding the order's lock.
	 * @param DateTimeImmutable $now   Decision time.
	 * @throws DatabaseException When a read or write fails, or a creator row cannot be locked.
	 */
	public function assess( OrderSnapshot $order, SalesLedgerState $state, SalesRepository $sales, DateTimeImmutable $now ): ?OrderExclusionAssessment {
		$stored = $this->exclusions->find( $order->order_id );

		if ( null !== $stored ) {
			return new OrderExclusionAssessment( $stored, false );
		}

		$registration = $this->covering( $order );

		if ( null === $registration || null === $order->date_created ) {
			return null;
		}

		$creators = array_values( array_unique( array_map( static fn( $entry ): int => $entry->creator_id, $state->entries ) ) );
		$settled  = false;

		if ( array() !== $creators ) {
			$creators = $this->locks->acquire_for_order( $sales, $order->order_id, $creators );
			$settled  = $this->exclusions->has_settled_rows( $order->order_id );
		}

		$exclusion  = new OrderExclusion(
			$order->order_id,
			$settled ? OrderExclusionStatus::Open : OrderExclusionStatus::Excluded,
			$registration->id,
			$registration->creator_id,
			$registration->code,
			array() !== $this->deviations->of( $registration ),
			$order->date_created,
			$now
		);
		$connection = $sales->lock_connection( $order->order_id );

		if ( ! $this->exclusions->insert_on_connection( $exclusion, $connection ) ) {
			throw new DatabaseException( 'The order already has an exclusion decision.', array( 'order_id' => $order->order_id ) );
		}

		$this->audit->record_system_on_connection(
			AuditAction::OrderExcludedByCreatorCode,
			$registration->creator_id,
			array(
				'order_id'         => $order->order_id,
				'creator_code_id'  => $registration->id,
				'decision'         => $exclusion->status->value,
				'settled_rows'     => $settled,
				'ledger_rows'      => count( $state->entries ),
				'coupon_deviation' => $exclusion->coupon_deviation,
			),
			$connection
		);

		return new OrderExclusionAssessment( $exclusion, true, $creators );
	}

	/**
	 * Whether a backfill would find the order excluded, read-only (its dry run).
	 *
	 * @param OrderSnapshot $order Order.
	 * @throws InvalidArgumentException When the order's coupon data is unknown.
	 * @throws DatabaseException When a read fails.
	 */
	public function would_exclude( OrderSnapshot $order ): bool {
		return null !== $this->exclusions->find( $order->order_id ) || null !== $this->covering( $order );
	}

	/**
	 * After COMMIT of a decision made now: the creators whose existing rows
	 * it took out get a new cache generation of their statistics.
	 *
	 * @param OrderExclusionAssessment $assessment Assessment.
	 */
	public function committed( OrderExclusionAssessment $assessment ): void {
		if ( $assessment->decided && array() !== $assessment->invalidate ) {
			$this->cache->invalidate( $assessment->invalidate );
		}
	}

	/**
	 * The oldest registration whose code the order used within its period,
	 * or null.
	 *
	 * @param OrderSnapshot $order Order.
	 * @throws InvalidArgumentException When the coupon data of an existing order is unknown.
	 */
	private function covering( OrderSnapshot $order ): ?CreatorCode {
		if ( ! $order->exists ) {
			return null;
		}

		if ( null === $order->coupon_codes ) {
			throw new InvalidArgumentException( 'The order\'s coupon data is unknown.' );
		}

		if ( array() === $order->coupon_codes || null === $order->date_created ) {
			return null;
		}

		foreach ( $this->codes->matching( $order->coupon_codes ) as $registration ) {
			if ( $registration->covers( $order->date_created ) ) {
				return $registration;
			}
		}

		return null;
	}
}
