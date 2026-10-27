<?php
/**
 * Recalculation of settled payouts without the 20 % deduction (ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Recalculation;

use Closure;
use DateTimeImmutable;
use JsonException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-063 pkt. 2–5: settled payouts of creators who were not VAT registered
 * are recalculated by removing only the 20 % deduction, and the differences
 * are regulated in separate, audited steps by an administrator with
 * `merchboard_manage_payouts`:
 *
 * 1. **Preview** (preview()): computes the items from the stored settlement
 *    rows and stores them with a fingerprint. Nothing else is written: no
 *    document, payout or balance changes. Only one recalculation is open at
 *    a time; earlier ones are kept with their decisions.
 * 2. **Approval** (approve()) or **rejection** (reject()). Approval requires
 *    the basis to be unchanged since the preview (same fingerprint). A
 *    rejected recalculation is never reopened automatically; reopen() makes
 *    a new preview that refers to it.
 * 3. **Execution** (execute(), separately confirmed): first every pending
 *    active item must still have its approved fingerprint (amounts, rows,
 *    documents, group and method), otherwise nothing is executed and a new
 *    preview and approval are needed. Each item then runs under the
 *    creator's payout lock in its own transaction: the item is marked
 *    executed, one adjustment is written (UNIQUE per item) and its rows are
 *    recorded as regulated (UNIQUE per row and rule), with audit. A failure
 *    rolls the item back, marks it failed and stops the execution; a new
 *    execution is an explicit action and re-validates everything, so no item
 *    or row is regulated twice.
 * 4. **Closing** (close()) after the execution, explicitly.
 *
 * Since ADR-064 (the VAT is deducted again from the payout of sales while not
 * VAT registered) no new recalculation can be started: preview() and
 * reopen() refuse with `recalculation_disabled`. Existing recalculations, their
 * adjustments, exports and history are kept, and their remaining steps
 * (approval, rejection, execution, closing, export) still work.
 *
 * An executed adjustment counts in the balance (PayoutBalances): a negative
 * one is offset against what is available and the rest is carried forward
 * as outstanding (ADR-054). The legal right to offset is not validated
 * (ADR-063). It is settled by the creator's next settlement document. Items
 * of former creators are reported only; items whose data does not reproduce,
 * and completed payouts without a settlement document, need a manual
 * assessment. Settlement documents and payouts are never changed.
 */
final class PayoutRecalculations {

	/**
	 * The calculation of the recalculated amounts: ADR-063's rule (the whole
	 * profit), fixed when ADR-064 changed the calculation of new documents.
	 */
	public const CALCULATION_VERSION = 'adr063-adr054-v1';

	/**
	 * How an approved difference is regulated (part of the fingerprint).
	 */
	public const METHOD = 'balance_adjustment';

	/**
	 * Longest note of a decision or closing.
	 */
	public const MAX_NOTE = 500;

	/**
	 * Named lock that serialises the steps of recalculations.
	 */
	private const LOCK = 'merchboard_payout_recalculation';

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param PayoutRecalculationRepository $recalculations Recalculations and their items.
	 * @param PayoutAdjustmentRepository    $adjustments    Adjustments.
	 * @param PayoutRepository              $payouts        The creator's payout lock.
	 * @param CreatorRepository             $creators       Creators (group).
	 * @param AuditTrail                    $audit          Audit.
	 * @param Transaction                   $transaction    Transactions.
	 * @param wpdb                          $wpdb           Database (the named lock).
	 * @param Closure                       $clock          Current time (`Closure(): DateTimeImmutable`).
	 * @param bool                          $new_runs       Whether new recalculations may be started; false since ADR-064 (only tests that stand for recalculations made before it pass true).
	 */
	public function __construct(
		private PayoutRecalculationRepository $recalculations,
		private PayoutAdjustmentRepository $adjustments,
		private PayoutRepository $payouts,
		private CreatorRepository $creators,
		private AuditTrail $audit,
		private Transaction $transaction,
		private wpdb $wpdb,
		private Closure $clock,
		private bool $new_runs = false
	) {
	}

	/**
	 * The items as the stored data gives them now, keyed by item key. Reads only.
	 *
	 * @return array<string, array{creator_id: int, currency: string, category: string, reason: string, original_units: int|null, recalculated_units: int|null, difference_units: int|null, row_count: int, detail: array<string, mixed>, fingerprint: string}>
	 * @throws DatabaseException When a read fails.
	 */
	public function compute(): array {
		$groups = array();

		foreach ( $this->recalculations->candidate_rows() as $row ) {
			$groups[ (int) $row['creator_id'] . ':' . (string) $row['currency'] ][] = $row;
		}

		$items = array();

		foreach ( $groups as $rows ) {
			$creator_id = (int) $rows[0]['creator_id'];
			$currency   = (string) $rows[0]['currency'];
			$detail     = array();
			$documents  = array();
			$consistent = true;
			$original   = 0;
			$new        = 0;

			foreach ( $rows as $row ) {
				$result                                        = RecalculationRule::row( $row );
				$documents[ (string) $row['document_number'] ] = true;
				$consistent                                    = $consistent && null !== $result;
				$detail[]                                      = array(
					'event_key'       => (string) $row['event_key'],
					'document_id'     => (int) $row['document_id'],
					'document_number' => (string) $row['document_number'],
					'order_id'        => (int) $row['order_id'],
					'type'            => (string) $row['type'],
					'occurred_at'     => (string) $row['occurred_at'],
					'share_bps'       => (int) $row['share_bps'],
					'price_decimals'  => (int) $row['price_decimals'],
					'net'             => (string) $row['net_amount'],
					'tax'             => (string) $row['tax_amount'],
					'profit'          => (string) $row['profit_amount'],
					'original'        => null === $result ? (string) $row['payout_amount'] : SalesAmount::format( $result['original'] ),
					'recalculated'    => null === $result ? null : SalesAmount::format( $result['recalculated'] ),
					'difference'      => null === $result ? null : SalesAmount::format( $result['difference'] ),
				);

				if ( null !== $result ) {
					$original = SalesAmount::add( $original, $result['original'] );
					$new      = SalesAmount::add( $new, $result['recalculated'] );
				}
			}

			$creator  = $this->creators->find( $creator_id );
			$category = ! $consistent ? RecalculationItem::MANUAL : ( null !== $creator && $creator->is_active() ? RecalculationItem::ACTIVE : RecalculationItem::FORMER );
			$item     = array(
				'creator_id'         => $creator_id,
				'currency'           => $currency,
				'category'           => $category,
				'reason'             => $consistent ? '' : RecalculationItem::REASON_INCONSISTENT_ROW,
				'original_units'     => $consistent ? $original : null,
				'recalculated_units' => $consistent ? $new : null,
				'difference_units'   => $consistent ? SalesAmount::add( $new, SalesAmount::negate( $original ) ) : null,
				'row_count'          => count( $rows ),
				'detail'             => array(
					'documents' => array_keys( $documents ),
					'rows'      => $detail,
				),
				'fingerprint'        => '',
			);

			$items[ RecalculationItem::key_of( $creator_id, $currency, $category ) ] = $item;
		}

		foreach ( $this->recalculations->undocumented_payouts() as $payouts ) {
			$key = RecalculationItem::key_of( $payouts['creator_id'], $payouts['currency'], RecalculationItem::MANUAL );

			if ( isset( $items[ $key ] ) ) {
				$items[ $key ]['detail']['undocumented_payouts'] = $payouts['payouts'];
				continue;
			}

			$items[ $key ] = array(
				'creator_id'         => $payouts['creator_id'],
				'currency'           => $payouts['currency'],
				'category'           => RecalculationItem::MANUAL,
				'reason'             => RecalculationItem::REASON_UNDOCUMENTED_PAYOUTS,
				'original_units'     => null,
				'recalculated_units' => null,
				'difference_units'   => null,
				'row_count'          => 0,
				'detail'             => array( 'undocumented_payouts' => $payouts['payouts'] ),
				'fingerprint'        => '',
			);
		}

		foreach ( $items as $key => $item ) {
			$items[ $key ]['fingerprint'] = self::fingerprint( $item );
		}

		ksort( $items );

		return $items;
	}

	/**
	 * Makes a preview: a new recalculation with the items as computed now.
	 * Disabled since ADR-064 (`recalculation_disabled`): R2 pays out the VAT
	 * that ADR-064 deducts again while not VAT registered.
	 *
	 * @return int Recalculation id.
	 * @throws PayoutException When not authorized, disabled (ADR-064) or another recalculation is open; a failed read or write is a DatabaseException.
	 */
	public function preview(): int {
		self::authorize();

		if ( ! $this->new_runs ) {
			throw new PayoutException( PayoutException::RECALCULATION_DISABLED );
		}

		return $this->locked( fn(): int => $this->create( null ) );
	}

	/**
	 * Approves a previewed recalculation whose basis is unchanged.
	 *
	 * @param int    $id   Recalculation.
	 * @param string $note Optional note (at most 500 characters).
	 * @throws PayoutException When not authorized, not a preview, stale or the note is too long.
	 * @throws DatabaseException When a read or write fails.
	 */
	public function approve( int $id, string $note = '' ): void {
		self::authorize();
		$note = self::note( $note, false );

		$this->locked(
			function () use ( $id, $note ): void {
				$run = $this->require_status( $id, array( Recalculation::PREVIEW ) );

				if ( ! hash_equals( $run->fingerprint, self::run_fingerprint( $this->compute() ) ) ) {
					throw new PayoutException( PayoutException::RECALCULATION_STALE );
				}

				$this->transaction->run(
					function () use ( $run, $note ): void {
						$moved = $this->recalculations->transition(
							$run->id,
							array( Recalculation::PREVIEW ),
							Recalculation::APPROVED,
							array(
								'decided_by'    => (string) ( self::user() ?? 0 ),
								'decided_at'    => PayoutRecalculationRepository::utc( ( $this->clock )() ),
								'decision_note' => $note,
							)
						);

						if ( ! $moved ) {
							throw new PayoutException( PayoutException::RECALCULATION_STATE );
						}

						$active = array_filter( $this->recalculations->items( $run->id ), static fn( RecalculationItem $item ): bool => RecalculationItem::ACTIVE === $item->category );
						$this->audit->record(
							AuditAction::PayoutRecalculationApproved,
							null,
							array(
								'reference'    => $run->reference(),
								'active_items' => count( $active ),
								'difference'   => self::totals( $active ),
								'has_note'     => '' !== $note,
							)
						);
					}
				);
			}
		);
	}

	/**
	 * Rejects a previewed or approved recalculation.
	 *
	 * @param int    $id   Recalculation.
	 * @param string $note Required reason (1–500 characters).
	 * @throws PayoutException When not authorized, in another status or the note is missing.
	 * @throws DatabaseException When a write fails.
	 */
	public function reject( int $id, string $note ): void {
		self::authorize();
		$note = self::note( $note, true );

		$this->locked(
			function () use ( $id, $note ): void {
				$run = $this->require_status( $id, array( Recalculation::PREVIEW, Recalculation::APPROVED ) );

				$this->transaction->run(
					function () use ( $run, $note ): void {
						$moved = $this->recalculations->transition(
							$run->id,
							array( Recalculation::PREVIEW, Recalculation::APPROVED ),
							Recalculation::REJECTED,
							array(
								'decided_by'    => (string) ( self::user() ?? 0 ),
								'decided_at'    => PayoutRecalculationRepository::utc( ( $this->clock )() ),
								'decision_note' => $note,
							)
						);

						if ( ! $moved ) {
							throw new PayoutException( PayoutException::RECALCULATION_STATE );
						}

						$this->audit->record(
							AuditAction::PayoutRecalculationRejected,
							null,
							array(
								'reference' => $run->reference(),
								'previous'  => $run->status,
								'has_note'  => true,
							)
						);
					}
				);
			}
		);
	}

	/**
	 * Reopens a rejected recalculation as a new preview of the current data.
	 * Disabled since ADR-064, like preview(); the rejected one stays rejected.
	 *
	 * @param int $id Rejected recalculation.
	 * @return int The new recalculation's id.
	 * @throws PayoutException When not authorized, disabled (ADR-064), not rejected or another recalculation is open; a failed read or write is a DatabaseException.
	 */
	public function reopen( int $id ): int {
		self::authorize();

		if ( ! $this->new_runs ) {
			throw new PayoutException( PayoutException::RECALCULATION_DISABLED );
		}

		return $this->locked(
			function () use ( $id ): int {
				$this->require_status( $id, array( Recalculation::REJECTED ) );

				return $this->create( $id );
			}
		);
	}

	/**
	 * Executes the pending active items of an approved (or partly executed)
	 * recalculation, item by item; stops at the first failure.
	 *
	 * @param int $id Recalculation.
	 * @return int Items executed now.
	 * @throws PayoutException When not authorized, in another status, stale, or an item failed (RECALCULATION_ITEM_FAILED).
	 * @throws DatabaseException When a read or write fails.
	 */
	public function execute( int $id ): int {
		self::authorize();

		return $this->locked(
			function () use ( $id ): int {
				$run     = $this->require_status( $id, array( Recalculation::APPROVED, Recalculation::PARTIALLY_EXECUTED ) );
				$pending = array_values(
					array_filter(
						$this->recalculations->items( $run->id ),
						static fn( RecalculationItem $item ): bool => RecalculationItem::ACTIVE === $item->category && in_array( $item->status, array( RecalculationItem::PROPOSED, RecalculationItem::FAILED ), true )
					)
				);
				$current = $this->compute();

				// Validate everything first: a changed basis executes nothing.
				foreach ( $pending as $item ) {
					if ( ! isset( $current[ $item->key() ] ) || ! hash_equals( $item->fingerprint, $current[ $item->key() ]['fingerprint'] ) ) {
						throw new PayoutException( PayoutException::RECALCULATION_STALE );
					}
				}

				$executed = 0;

				foreach ( $pending as $item ) {
					try {
						$this->execute_item( $run, $item );
						++$executed;
					} catch ( Throwable $e ) {
						$failure = $e instanceof PayoutException ? $e->reason() : ( $e instanceof DatabaseException ? 'database' : 'error' );
						$this->recalculations->transition_item( $item->id, array( RecalculationItem::PROPOSED, RecalculationItem::FAILED ), RecalculationItem::FAILED, $failure, null, null );
						$this->recalculations->transition( $run->id, array( Recalculation::APPROVED, Recalculation::PARTIALLY_EXECUTED ), Recalculation::PARTIALLY_EXECUTED );
						$this->audit->record(
							AuditAction::PayoutRecalculationItemFailed,
							$item->creator_id,
							array(
								'reference' => $run->reference(),
								'item_id'   => $item->id,
								'failure'   => $failure,
							)
						);
						do_action( 'merchboard_notify', 'admin_adjustment_failed', $item->creator_id, array( 'reference' => $run->reference() ), 'item:' . $item->id . ':' . $failure );

						throw new PayoutException( PayoutException::RECALCULATION_ITEM_FAILED );
					}
				}

				$this->transaction->run(
					function () use ( $run, $executed ): void {
						if ( ! $this->recalculations->transition( $run->id, array( Recalculation::APPROVED, Recalculation::PARTIALLY_EXECUTED ), Recalculation::EXECUTED ) ) {
							throw new PayoutException( PayoutException::RECALCULATION_STATE );
						}

						$this->audit->record(
							AuditAction::PayoutRecalculationExecuted,
							null,
							array(
								'reference' => $run->reference(),
								'executed'  => $executed,
							)
						);
					}
				);

				return $executed;
			}
		);
	}

	/**
	 * Closes an executed or partly executed recalculation.
	 *
	 * @param int    $id   Recalculation.
	 * @param string $note Note; required when items are not executed (1–500 characters).
	 * @throws PayoutException When not authorized, in another status or the note is missing.
	 * @throws DatabaseException When a write fails.
	 */
	public function close( int $id, string $note = '' ): void {
		self::authorize();

		$this->locked(
			function () use ( $id, $note ): void {
				$run  = $this->require_status( $id, array( Recalculation::EXECUTED, Recalculation::PARTIALLY_EXECUTED ) );
				$note = self::note( $note, Recalculation::PARTIALLY_EXECUTED === $run->status );

				$this->transaction->run(
					function () use ( $run, $note ): void {
						$moved = $this->recalculations->transition(
							$run->id,
							array( Recalculation::EXECUTED, Recalculation::PARTIALLY_EXECUTED ),
							Recalculation::CLOSED,
							array(
								'closed_by'  => (string) ( self::user() ?? 0 ),
								'closed_at'  => PayoutRecalculationRepository::utc( ( $this->clock )() ),
								'close_note' => $note,
							)
						);

						if ( ! $moved ) {
							throw new PayoutException( PayoutException::RECALCULATION_STATE );
						}

						$this->audit->record(
							AuditAction::PayoutRecalculationClosed,
							null,
							array(
								'reference' => $run->reference(),
								'previous'  => $run->status,
								'has_note'  => '' !== $note,
							)
						);
					}
				);
			}
		);
	}

	/**
	 * Stores a new preview (inside the named lock).
	 *
	 * @param int|null $reopens The rejected recalculation it reopens.
	 * @throws PayoutException When another recalculation is open; a failed read or write is a DatabaseException.
	 */
	private function create( ?int $reopens ): int {
		if ( null !== $this->recalculations->open() ) {
			throw new PayoutException( PayoutException::RECALCULATION_OPEN );
		}

		$items = $this->compute();

		return $this->transaction->run(
			function () use ( $items, $reopens ): int {
				$id = $this->recalculations->insert( self::run_fingerprint( $items ), $reopens, self::user(), ( $this->clock )() );
				$by = array(
					RecalculationItem::ACTIVE => 0,
					RecalculationItem::FORMER => 0,
					RecalculationItem::MANUAL => 0,
				);

				foreach ( $items as $item ) {
					$status = match ( $item['category'] ) {
						RecalculationItem::ACTIVE => RecalculationItem::PROPOSED,
						RecalculationItem::FORMER => RecalculationItem::REPORTED,
						default                   => RecalculationItem::NEEDS_REVIEW,
					};
					$this->recalculations->insert_item( $id, $item, $status );
					++$by[ $item['category'] ];
				}

				$this->audit->record(
					AuditAction::PayoutRecalculationPreviewed,
					null,
					array(
						'reference'    => Recalculation::reference_of( $id ),
						'reopens'      => null === $reopens ? null : Recalculation::reference_of( $reopens ),
						'active_items' => $by[ RecalculationItem::ACTIVE ],
						'former_items' => $by[ RecalculationItem::FORMER ],
						'manual_items' => $by[ RecalculationItem::MANUAL ],
						'rows'         => array_sum( array_column( $items, 'row_count' ) ),
					)
				);

				if ( null !== $reopens ) {
					$this->audit->record(
						AuditAction::PayoutRecalculationReopened,
						null,
						array(
							'reference' => Recalculation::reference_of( $reopens ),
							'new'       => Recalculation::reference_of( $id ),
						)
					);
				}

				return $id;
			}
		);
	}

	/**
	 * Executes one item under the creator's lock, in one transaction.
	 *
	 * @param Recalculation     $run  Recalculation.
	 * @param RecalculationItem $item Pending active item.
	 * @throws PayoutException When the item moved meanwhile or the lock is busy; a failed write, also for a row or item already regulated, is a DatabaseException.
	 */
	private function execute_item( Recalculation $run, RecalculationItem $item ): void {
		$rows = is_array( $item->detail['rows'] ?? null ) ? $item->detail['rows'] : array();

		if ( null === $item->difference_units || count( $rows ) !== $item->row_count ) {
			throw new PayoutException( PayoutException::RECALCULATION_STALE );
		}

		$this->payouts->with_creator_lock(
			$item->creator_id,
			fn() => $this->transaction->run(
				function () use ( $run, $item, $rows ): void {
					$connection = $this->payouts->lock_connection( $item->creator_id );
					$now        = ( $this->clock )();

					if ( ! $this->recalculations->transition_item( $item->id, array( RecalculationItem::PROPOSED, RecalculationItem::FAILED ), RecalculationItem::EXECUTED, '', self::user(), $now ) ) {
						throw new PayoutException( PayoutException::RECALCULATION_STATE );
					}

					$adjustment = $this->adjustments->insert( $item->creator_id, $item->currency, (int) $item->difference_units, $run->id, $item->id, self::user(), $now, $connection );

					foreach ( $rows as $row ) {
						if ( ! is_array( $row ) || ! is_string( $row['original'] ?? null ) || ! is_string( $row['recalculated'] ?? null ) ) {
							throw new PayoutException( PayoutException::RECALCULATION_STALE );
						}

						$this->adjustments->insert_row( (string) $row['event_key'], $adjustment, SalesAmount::parse( $row['original'] ), SalesAmount::parse( $row['recalculated'] ), $connection );
					}

					$this->audit->record(
						AuditAction::PayoutAdjustmentExecuted,
						$item->creator_id,
						array(
							'reference'     => $run->reference(),
							'item_id'       => $item->id,
							'adjustment_id' => $adjustment,
							'currency'      => $item->currency,
							'amount'        => SalesAmount::format( (int) $item->difference_units ),
							'rows'          => count( $rows ),
						)
					);
					$this->payouts->assert_same_connection( $item->creator_id );
				}
			)
		);

		do_action(
			'merchboard_notify',
			'payout_adjusted',
			$item->creator_id,
			array(
				'amount'    => SalesAmount::format( (int) $item->difference_units ),
				'currency'  => $item->currency,
				'reference' => $run->reference(),
			),
			'adjustment:' . $item->id
		);
	}

	/**
	 * A recalculation in one of the expected statuses.
	 *
	 * @param int      $id       Recalculation.
	 * @phpstan-param list<string> $statuses
	 *
	 * @param string[] $statuses Expected statuses.
	 * @throws PayoutException When it does not exist or is in another status; a failed read is a DatabaseException.
	 */
	private function require_status( int $id, array $statuses ): Recalculation {
		$run = $this->recalculations->find( $id );

		if ( null === $run ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		if ( ! in_array( $run->status, $statuses, true ) ) {
			throw new PayoutException( PayoutException::RECALCULATION_STATE );
		}

		return $run;
	}

	/**
	 * Runs $work while holding the recalculations' named lock.
	 *
	 * @template T
	 * @param callable(): T $work Work.
	 * @return T
	 * @throws PayoutException When another step is running (`busy`).
	 * @throws DatabaseException When the lock cannot be taken.
	 */
	private function locked( callable $work ): mixed {
		$wpdb     = $this->wpdb;
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK, PayoutRepository::LOCK_TIMEOUT ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock; never cached.

		if ( ! is_numeric( $acquired ) ) {
			throw new DatabaseException( 'Could not take the recalculation lock.' );
		}

		if ( 1 !== (int) $acquired ) {
			throw new PayoutException( PayoutException::BUSY );
		}

		try {
			return $work();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the named lock.
		}
	}

	/**
	 * Fingerprint of an item: method, group, creator, currency, reason,
	 * amounts and every row with its documents and amounts.
	 *
	 * @param array<string, mixed> $item Item as computed.
	 * @throws DatabaseException When it cannot be encoded.
	 */
	private static function fingerprint( array $item ): string {
		try {
			return hash(
				'sha256',
				json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON_THROW_ON_ERROR.
					array(
						self::METHOD,
						RecalculationRule::RULE,
						$item['category'],
						$item['creator_id'],
						$item['currency'],
						$item['reason'],
						$item['original_units'],
						$item['recalculated_units'],
						$item['difference_units'],
						$item['row_count'],
						$item['detail'],
					),
					JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			);
		} catch ( JsonException ) {
			throw new DatabaseException( 'Could not fingerprint the recalculation item.' );
		}
	}

	/**
	 * Fingerprint of all items.
	 *
	 * @param array<string, array<string, mixed>> $items Items keyed by item key.
	 */
	private static function run_fingerprint( array $items ): string {
		ksort( $items );

		return hash( 'sha256', implode( "\n", array_map( static fn( string $key, array $item ): string => $key . '=' . (string) $item['fingerprint'], array_keys( $items ), $items ) ) );
	}

	/**
	 * Difference per currency of items, e.g. "DKK 12.0000".
	 *
	 * @param array<RecalculationItem> $items Items.
	 */
	private static function totals( array $items ): string {
		$totals = array();

		foreach ( $items as $item ) {
			$totals[ $item->currency ] = SalesAmount::add( $totals[ $item->currency ] ?? 0, (int) $item->difference_units );
		}

		ksort( $totals );

		return implode( ', ', array_map( static fn( string $currency, int $units ): string => $currency . ' ' . SalesAmount::format( $units ), array_keys( $totals ), $totals ) );
	}

	/**
	 * A validated note.
	 *
	 * @param string $note     Note.
	 * @param bool   $required Whether it must not be empty.
	 * @throws PayoutException When it is missing or longer than 500 characters.
	 */
	private static function note( string $note, bool $required ): string {
		$note = trim( $note );

		if ( ( $required && '' === $note ) || mb_strlen( $note ) > self::MAX_NOTE ) {
			throw new PayoutException( PayoutException::INVALID_NOTE );
		}

		return $note;
	}

	/**
	 * Requires `merchboard_manage_payouts`.
	 *
	 * @throws PayoutException When the user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			throw new PayoutException( PayoutException::NOT_AUTHORIZED );
		}
	}

	/**
	 * The current WordPress user id, null when none.
	 */
	private static function user(): ?int {
		$id = get_current_user_id();

		return $id > 0 ? $id : null;
	}
}
