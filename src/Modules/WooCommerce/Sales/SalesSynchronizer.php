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
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionAssessment;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionView;
use MerchBoard\Modules\WooCommerce\CreatorCodes\OrderExclusionAssessor;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerCampaignSource;
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
 * Creator codes (ADR-068): inside the transaction, before the planner, the
 * OrderExclusionAssessor decides which creators' lines the order excludes
 * because it used their own discount code; those lines get no ledger rows
 * (their existing rows stay, and the read models leave them out), and the
 * other creators' lines are planned as usual (OrderExclusionView). The
 * backfill reports an order whose lines are all excluded as not counted.
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
 *
 * Backfill (ROADMAP trin 7): backfill() registers an order without ledger
 * history through the same steps, and preview() is its read-only dry run.
 * Both are outside the SalesSync contract the live hooks use.
 */
final class SalesSynchronizer implements SalesSync, SalesBackfillSync {

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
	 * The planning failure of the running backfill() (with its item or refund),
	 * for the log and the marker.
	 *
	 * @var SalesPlanFailure|null
	 */
	private ?SalesPlanFailure $failed_plan = null;

	/**
	 * Whether the last locked() wrote nothing because all of the order's
	 * lines are excluded (ADR-068).
	 *
	 * @var bool
	 */
	private bool $excluded = false;

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
	 * @param SalesRepository             $repository Ledger storage, lock and transaction.
	 * @param SalesPlanner                $planner    State transition.
	 * @param OrderSnapshotReader         $reader     WooCommerce data.
	 * @param SalesFailureMarker          $marker     Failure marker.
	 * @param Logger                      $logger     Technical log.
	 * @param Closure|null                $clock      Processing time; UTC now by default.
	 * @param OrderExclusionAssessor|null $exclusions Creator code exclusion (ADR-068); null assesses nothing.
	 * @param DesignerCampaignSource|null $campaigns  The company's campaigns of designer lines (ADR-080 pkt. 9); null: none.
	 */
	public function __construct(
		private SalesRepository $repository,
		private SalesPlanner $planner,
		private OrderSnapshotReader $reader,
		private SalesFailureMarker $marker,
		private Logger $logger,
		?Closure $clock = null,
		private ?OrderExclusionAssessor $exclusions = null,
		private ?DesignerCampaignSource $campaigns = null
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
		} catch ( Throwable $e ) {
			return self::failure( $e, $order_id );
		}
	}

	/**
	 * Registers one order that has no ledger history yet: the backfill's entry
	 * (ADR-038, status note "ROADMAP trin 7", D1 and D4). It is the same
	 * synchronization as sync() (same reader, lock, transaction, planner,
	 * failure marker and log), restricted to orders whose ledger is empty: an
	 * order with history is never touched, also when a live hook registered it
	 * between the check and the lock. Never throws; the live contract
	 * (SalesSync) is unchanged.
	 *
	 * @param int $order_id Order id.
	 */
	public function backfill( int $order_id ): SalesBackfillOutcome {
		if ( $order_id < 1 ) {
			return SalesBackfillOutcome::of( SalesBackfillStatus::Missing );
		}

		if ( isset( $this->running[ $order_id ] ) || isset( $this->deleting[ $order_id ] ) ) {
			return SalesBackfillOutcome::of( SalesBackfillStatus::Busy );
		}

		$this->running[ $order_id ] = true;

		try {
			$outcome = $this->register( $order_id );

			if ( SalesBackfillStatus::Failed === $outcome->status && null !== $outcome->failure ) {
				$this->report( $order_id, $this->failed_plan ?? new SalesPlanFailure( $outcome->failure, $order_id ), false );
			} elseif ( in_array( $outcome->status, array( SalesBackfillStatus::Registered, SalesBackfillStatus::NotCounted, SalesBackfillStatus::NoCreator ), true ) ) {
				// Like sync(): a synchronization that found nothing wrong clears a marker.
				$this->report( $order_id, true, false );
			}

			return $outcome;
		} catch ( Throwable ) {
			// The marker itself could not be written; the ledger result stands.
			$this->logger->error( 'Could not update the sales ledger failure marker.', array( 'order_id' => $order_id ) );

			return SalesBackfillOutcome::failed( SalesFailureCode::Unexpected );
		} finally {
			$this->failed_plan = null;
			unset( $this->running[ $order_id ] );
		}
	}

	/**
	 * What backfill() would do, read-only (the dry run, D3): the same checks
	 * and the same planner on the stored ledger state, without the lock,
	 * without writing rows, without a failure marker and without a log entry.
	 * The result is advisory: the order may change before a real run.
	 *
	 * @param int $order_id Order id.
	 */
	public function preview( int $order_id ): SalesBackfillOutcome {
		if ( $order_id < 1 ) {
			return SalesBackfillOutcome::of( SalesBackfillStatus::Missing );
		}

		try {
			$order = $this->eligible( $order_id );

			if ( $order instanceof SalesBackfillOutcome ) {
				return $order;
			}

			$state = $this->repository->state( $order_id );

			// Creator codes (ADR-068): the excluded creators' lines are left out.
			if ( null !== $this->exclusions ) {
				$excluded = $this->exclusions->excluded_creators( $order );

				if ( array() !== $excluded ) {
					$view = OrderExclusionView::without( $order, $state, $excluded );

					if ( ! $view->has_lines() ) {
						return SalesBackfillOutcome::of( SalesBackfillStatus::NotCounted );
					}

					$order = $view->order;
					$state = $view->state;
				}
			}

			$result = $this->planner->plan( $order, $state, ( $this->clock )() );

			return null !== $result->failure
				? SalesBackfillOutcome::failed( $result->failure->code )
				: SalesBackfillOutcome::registered( $result->write ?? new SalesLedgerWrite() );
		} catch ( Throwable $e ) {
			return SalesBackfillOutcome::failed( self::failure( $e, $order_id )->code );
		}
	}

	/**
	 * The registration of one order without history, classified.
	 *
	 * @param int $order_id Order id.
	 */
	private function register( int $order_id ): SalesBackfillOutcome {
		try {
			$order = $this->eligible( $order_id );

			if ( $order instanceof SalesBackfillOutcome ) {
				return $order;
			}

			$history = false;
			$planned = null;
			$result  = $this->locked(
				$order_id,
				fn(): ?OrderSnapshot => $this->reader->read( $order_id ),
				static function ( SalesLedgerState $state ) use ( &$history ): bool {
					// Registered meanwhile (e.g. by a live hook): never touched (D1).
					$history = 0 !== $state->cycles->latest_cycle();

					return ! $history;
				},
				static function ( SalesLedgerWrite $write ) use ( &$planned ): void {
					$planned = $write;
				}
			);
		} catch ( Throwable $e ) {
			$this->failed_plan = self::failure( $e, $order_id );

			return SalesBackfillOutcome::failed( $this->failed_plan->code );
		}

		if ( $result instanceof SalesPlanFailure ) {
			$this->failed_plan = $result;

			return SalesBackfillOutcome::failed( $result->code );
		}

		if ( $history ) {
			return SalesBackfillOutcome::of( SalesBackfillStatus::HasHistory );
		}

		// All lines excluded by creator codes (ADR-068): it does not count.
		if ( $this->excluded ) {
			return SalesBackfillOutcome::of( SalesBackfillStatus::NotCounted );
		}

		return true === $result && $planned instanceof SalesLedgerWrite
			? SalesBackfillOutcome::registered( $planned )
			: SalesBackfillOutcome::of( SalesBackfillStatus::Missing );
	}

	/**
	 * The order's snapshot when the backfill may register it, else the reason
	 * it is skipped. Same preflight as synchronize(), plus: an order with
	 * ledger history is skipped.
	 *
	 * @param int $order_id Order id.
	 * @throws Throwable Reader or repository errors (classified by the caller).
	 */
	private function eligible( int $order_id ): OrderSnapshot|SalesBackfillOutcome {
		$counted = $this->reader->counts( $order_id );

		// Missing, not a shop_order, or in the trash (ADR-038, Q8).
		if ( null === $counted ) {
			return SalesBackfillOutcome::of( SalesBackfillStatus::Missing );
		}

		if ( 0 !== $this->repository->cycle_state( $order_id )->latest_cycle() ) {
			return SalesBackfillOutcome::of( SalesBackfillStatus::HasHistory );
		}

		if ( ! $counted ) {
			return SalesBackfillOutcome::of( SalesBackfillStatus::NotCounted );
		}

		$order = $this->reader->read( $order_id );

		if ( null === $order ) {
			return SalesBackfillOutcome::of( SalesBackfillStatus::Missing );
		}

		return self::has_creator_line( $order ) ? $order : SalesBackfillOutcome::of( SalesBackfillStatus::NoCreator );
	}

	/**
	 * The failure code of an exception (lock, connection, database, other).
	 *
	 * @param Throwable $e        Exception.
	 * @param int       $order_id Order id.
	 */
	private static function failure( Throwable $e, int $order_id ): SalesPlanFailure {
		return new SalesPlanFailure(
			match ( true ) {
				$e instanceof SalesLockTimeoutException      => SalesFailureCode::LockTimeout,
				$e instanceof SalesConnectionChangedException => SalesFailureCode::ConnectionChanged,
				$e instanceof DatabaseException              => SalesFailureCode::DbError,
				default                                      => SalesFailureCode::Unexpected,
			},
			$order_id
		);
	}

	/**
	 * Reads (outside the transaction), plans and appends under the order's lock.
	 * Lock, connection and database errors propagate to the caller.
	 *
	 * The backfill passes $admit (checked on the locked ledger state before
	 * planning; false writes nothing and returns false) and $observe (receives
	 * the planned rows). The live synchronization passes neither.
	 *
	 * @phpstan-param Closure(): ?OrderSnapshot $snapshot
	 * @phpstan-param (Closure(SalesLedgerState): bool)|null $admit
	 * @phpstan-param (Closure(SalesLedgerWrite): void)|null $observe
	 *
	 * @param int          $order_id Order id.
	 * @param Closure      $snapshot Reads the order under the lock.
	 * @param Closure|null $admit    Whether to plan for this ledger state.
	 * @param Closure|null $observe  Receives the planned rows.
	 * @return SalesPlanFailure|bool
	 */
	private function locked( int $order_id, Closure $snapshot, ?Closure $admit = null, ?Closure $observe = null ): SalesPlanFailure|bool {
		return $this->repository->with_order_lock(
			$order_id,
			function () use ( $order_id, $snapshot, $admit, $observe ): SalesPlanFailure|bool {
				$order = $snapshot();

				if ( null === $order ) {
					return false;
				}

				$now        = ( $this->clock )();
				$failure    = null;
				$admitted   = true;
				$assessment = null;
				$nothing    = false;
				$written    = null;

				$this->excluded = false;

				$this->repository->write(
					$order_id,
					function ( SalesLedgerState $state ) use ( $order, $now, $admit, $observe, &$failure, &$admitted, &$assessment, &$nothing, &$written ): ?SalesLedgerWrite {
						// Null: the repository writes nothing, not even a G1 `pending` (D1, T5b).
						if ( null !== $admit && ! $admit( $state ) ) {
							$admitted = false;

							return null;
						}

						// ADR-068: the excluded creators' lines get no ledger rows; the other
						// creators' lines of the order are planned as usual.
						$assessment = $this->exclusions?->assess( $order, $state, $this->repository, $now );

						if ( null !== $assessment ) {
							$view = OrderExclusionView::without( $order, $state, $assessment->creator_ids() );

							// Only excluded lines: nothing is written, not even `pending`.
							if ( ! $view->has_lines() ) {
								$nothing = true;

								return null;
							}

							$order = $view->order;
							$state = $view->state;
						}

						$result  = $this->planner->plan( $order, $state, $now );
						$failure = $result->failure;

						// A planning failure writes nothing (ADR-038 pkt. 23), not even `pending`.
						if ( null !== $failure || null === $result->write ) {
							return null;
						}

						// ADR-080 pkt. 9: decided under the lock, stored with a line's first designer row.
						$planned = null === $this->campaigns ? $result->write : $result->write->with_campaigns( $this->campaigns->campaigns( $order ) );
						// Review 30.15: a designer line sold without VAT compares without VAT.
						$planned = $planned->with_vat_exempt( array_filter( array_map( static fn( OrderLineSnapshot $line ): ?int => $line->exempt_vat_bps, $order->lines_by_item() ), static fn( ?int $bps ): bool => null !== $bps ) );

						if ( null !== $observe ) {
							$observe( $planned );
						}

						$written = $planned;

						return $planned;
					}
				);

				if ( ! $admitted ) {
					return false;
				}

				// Committed: write() returning means COMMIT.
				if ( $assessment instanceof OrderExclusionAssessment ) {
					$this->excluded = $nothing;
					$this->exclusions?->committed( $assessment );
				}

				// The live synchronization tells the creators (ADR-079); a backfill ($admit) never does.
				if ( null === $admit && $written instanceof SalesLedgerWrite ) {
					try {
						SalesNotices::send( $written );
					} catch ( Throwable ) {
						// Committed: a notification never turns the synchronization into a failure.
						$this->logger->error( 'Could not notify the creators of new sales rows.', array( 'order_id' => $order_id ) );
					}
				}

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
			// Once per order and failure, however often it is retried.
			do_action(
				'merchboard_notify',
				'admin_sync_failed',
				null,
				array(
					'order_number' => SalesNotices::order_number( $order_id ),
					'reason'       => $result->code->value,
				),
				'order:' . $order_id . ':' . $result->code->value
			);
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
