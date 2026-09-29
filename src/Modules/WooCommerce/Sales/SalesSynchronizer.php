<?php
/**
 * Brings one order's sales ledger in line with WooCommerce.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * The synchronization of ADR-038 (pkt. 13, 18, 20-21 and the status notes):
 *
 * 1. Guard: a synchronization of an order already running in this request is
 *    skipped (it is its own trigger, e.g. the failure marker's meta save).
 * 2. Cheap preflight: a non-counted order without a cycle, and a counted one
 *    without a cycle and without a line with a creator, need no lock.
 * 3. Under the order's lock (SalesRepository::with_order_lock()): read the
 *    snapshot outside the transaction, then plan and append in one transaction.
 * 4. Failure marker and technical log: set on failure, removed on success.
 *
 * Never throws: every error becomes a failure code (lock_timeout,
 * connection_changed, db_error, a planner code, or unexpected), is logged
 * with ids only and marked on the order, so WooCommerce' order and refund
 * flows are never interrupted. No retry and no queue.
 *
 * Deletion (ADR-038, Q5): once an order's deletion started, every later call
 * for it in this request works from the ledger alone and cannot open a cycle;
 * no failure marker is written for it, errors are only logged.
 *
 * Another order (Q7, with the precision of 2026-09-29) is synchronized at once
 * under its own lock when the lock and transaction context allow it; when the
 * repository or Transaction refuses the nested call, that order gets its
 * failure marker and log entry like any other failure.
 */
final class SalesSynchronizer implements SalesSync {

	/**
	 * Orders being synchronized in this request.
	 *
	 * @var array<int, true>
	 */
	private array $running = array();

	/**
	 * Orders whose deletion started in this request.
	 *
	 * @var array<int, true>
	 */
	private array $deleting = array();

	/**
	 * Processing clock.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 *
	 * @param SalesRepository     $repository Ledger storage, lock and transaction.
	 * @param SalesPlanner        $planner    State transition.
	 * @param OrderSnapshotReader $reader     WooCommerce data.
	 * @param SalesFailureMarker  $marker     Failure marker.
	 * @param Logger              $logger     Technical log.
	 * @param Closure|null        $clock      Processing time; UTC now by default.
	 */
	public function __construct(
		private SalesRepository $repository,
		private SalesPlanner $planner,
		private OrderSnapshotReader $reader,
		private SalesFailureMarker $marker,
		private Logger $logger,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Synchronizes an order.
	 *
	 * @param int      $order_id          Order id.
	 * @param int|null $deleted_refund_id Refund a delete hook reported as deleted.
	 */
	public function sync( int $order_id, ?int $deleted_refund_id = null ): void {
		$this->run( $order_id, null !== $deleted_refund_id && $deleted_refund_id > 0 ? $deleted_refund_id : null );
	}

	/**
	 * Marks an order as being deleted and closes its cycle from the ledger alone.
	 *
	 * @param int $order_id Order id.
	 */
	public function order_deleting( int $order_id ): void {
		if ( $order_id > 0 ) {
			$this->deleting[ $order_id ] = true;
			$this->run( $order_id, null );
		}
	}

	/**
	 * One guarded synchronization with its marker and log.
	 *
	 * @param int      $order_id          Order id.
	 * @param int|null $deleted_refund_id Refund known to be deleted.
	 */
	private function run( int $order_id, ?int $deleted_refund_id ): void {
		if ( $order_id < 1 || isset( $this->running[ $order_id ] ) ) {
			return;
		}

		$this->running[ $order_id ] = true;
		$deleting                   = isset( $this->deleting[ $order_id ] );

		try {
			$this->report( $order_id, $this->synchronize( $order_id, $deleted_refund_id, $deleting ), $deleting );
		} catch ( Throwable ) {
			// The marker itself could not be written; the ledger result stands.
			$this->logger->error( 'Could not update the sales ledger failure marker.', array( 'order_id' => $order_id ) );
		} finally {
			unset( $this->running[ $order_id ] );
		}
	}

	/**
	 * Synchronizes and classifies the outcome.
	 *
	 * @param int      $order_id          Order id.
	 * @param int|null $deleted_refund_id Refund known to be deleted.
	 * @param bool     $deleting          Whether the order is being deleted.
	 * @return SalesPlanFailure|bool True on success, false when there was nothing to synchronize, or the failure.
	 */
	private function synchronize( int $order_id, ?int $deleted_refund_id, bool $deleting ): SalesPlanFailure|bool {
		try {
			if ( $deleting ) {
				return 0 === $this->repository->cycle_state( $order_id )->latest_cycle()
					? false
					: $this->locked( $order_id, static fn(): OrderSnapshot => OrderSnapshot::deleted( $order_id ) );
			}

			$counted = $this->reader->counts( $order_id );

			// Missing, not a shop_order, or in the trash (ADR-038, Q8).
			if ( null === $counted ) {
				return false;
			}

			if ( 0 === $this->repository->cycle_state( $order_id )->latest_cycle() ) {
				if ( ! $counted ) {
					return true;
				}

				$preview = $this->reader->read( $order_id, $deleted_refund_id );

				if ( null === $preview ) {
					return false;
				}

				if ( ! self::has_creator_line( $preview ) ) {
					return true;
				}
			}

			return $this->locked( $order_id, fn(): ?OrderSnapshot => $this->reader->read( $order_id, $deleted_refund_id ) );
		} catch ( SalesLockTimeoutException ) {
			return new SalesPlanFailure( SalesFailureCode::LockTimeout, $order_id );
		} catch ( SalesConnectionChangedException ) {
			return new SalesPlanFailure( SalesFailureCode::ConnectionChanged, $order_id );
		} catch ( DatabaseException ) {
			return new SalesPlanFailure( SalesFailureCode::DbError, $order_id );
		} catch ( Throwable ) {
			return new SalesPlanFailure( SalesFailureCode::Unexpected, $order_id );
		}
	}

	/**
	 * Reads (outside the transaction), plans and appends under the order's lock.
	 * Lock, connection and database errors propagate to synchronize().
	 *
	 * @phpstan-param Closure(): ?OrderSnapshot $snapshot
	 *
	 * @param int     $order_id Order id.
	 * @param Closure $snapshot Reads the order under the lock.
	 * @return SalesPlanFailure|bool
	 */
	private function locked( int $order_id, Closure $snapshot ): SalesPlanFailure|bool {
		return $this->repository->with_order_lock(
			$order_id,
			function () use ( $order_id, $snapshot ): SalesPlanFailure|bool {
				$order = $snapshot();

				if ( null === $order ) {
					return false;
				}

				$now     = ( $this->clock )();
				$failure = null;

				$this->repository->write(
					$order_id,
					function ( SalesLedgerState $state ) use ( $order, $now, &$failure ): SalesLedgerWrite {
						$result  = $this->planner->plan( $order, $state, $now );
						$failure = $result->failure;

						// A planning failure writes nothing (ADR-038 pkt. 23).
						return $result->write ?? new SalesLedgerWrite();
					}
				);

				return $failure ?? true;
			}
		);
	}

	/**
	 * Updates the failure marker and logs a failure.
	 *
	 * @param int                   $order_id Order id.
	 * @param SalesPlanFailure|bool $result   Outcome.
	 * @param bool                  $deleting Whether the order is being deleted (no marker).
	 */
	private function report( int $order_id, SalesPlanFailure|bool $result, bool $deleting ): void {
		if ( false === $result ) {
			return;
		}

		if ( true === $result ) {
			if ( ! $deleting ) {
				$this->marker->clear( $order_id );
			}

			return;
		}

		$context = array(
			'order_id' => $order_id,
			'code'     => $result->code->value,
		);

		if ( null !== $result->item_id ) {
			$context['item_id'] = $result->item_id;
		}

		if ( null !== $result->refund_id ) {
			$context['refund_id'] = $result->refund_id;
		}

		$this->logger->error( 'Sales ledger synchronization failed.', $context );

		if ( ! $deleting ) {
			$this->marker->record( $order_id, $result->code, ( $this->clock )() );
		}
	}

	/**
	 * Whether any line of the order has a creator.
	 *
	 * @param OrderSnapshot $order Order.
	 */
	private static function has_creator_line( OrderSnapshot $order ): bool {
		foreach ( $order->lines as $line ) {
			if ( null !== $line->creator_id ) {
				return true;
			}
		}

		return false;
	}
}
