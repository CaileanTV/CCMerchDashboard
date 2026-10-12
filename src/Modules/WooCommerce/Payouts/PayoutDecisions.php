<?php
/**
 * An admin's handling of payouts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use Closure;
use DateTimeImmutable;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;

defined( 'ABSPATH' ) || exit;

/**
 * Rejection and completion of a pending payout, and the audited view of
 * its full details (ADR-055, ADR-056, ADR-058). Every method checks the
 * capability itself; the admin screens are PayoutsPage and PayoutActions.
 *
 * A decision runs under the payout creator's lock and in one transaction:
 * the creator's row is locked first (CreatorRowLock), the payout row is read
 * FOR UPDATE, the status checked, the conditional UPDATE
 * run, the details emptied and the audit record written. Repeating a
 * decision that already happened returns the payout unchanged ("already
 * handled"); a different decision on a decided payout is refused.
 *
 * A completion issues its settlement document in the same transaction
 * (ADR-060). It recalculates the balance first: when it is unknown, or the
 * balance without this payout's own reservation no longer covers it (for
 * example after a refund), it is refused for manual handling (ADR-054,
 * 14.3 scenario 2). Pending details that cannot be decrypted can only be
 * rejected (planning decision 6). A failed read or write is a
 * DatabaseException; the transaction is rolled back.
 *
 * The completion reference and the rejection reason are kept with the
 * payout (ADR-059) and the reason is shown to the creator, so neither may
 * contain payment details (PayoutFreeText: an e-mail address, an IBAN, or
 * the payout's own account number when its details can be read). Such a
 * text is refused before anything is written; it is never logged, audited
 * or echoed.
 */
final class PayoutDecisions {

	/**
	 * Longest rejection reason.
	 */
	public const MAX_REASON_LENGTH = 500;

	/**
	 * Longest completion reference.
	 */
	public const MAX_REFERENCE_LENGTH = 191;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param PayoutRepository    $payouts     Storage.
	 * @param PayoutBalances      $balances    Balances.
	 * @param PayoutCipher        $cipher      Encryption.
	 * @param AuditTrail          $audit       Audit trail.
	 * @param Transaction         $transaction Transactions.
	 * @param Closure             $clock       Current time (`Closure(): DateTimeImmutable`).
	 * @param SettlementDocuments $documents   Settlement documents issued with a completion (ADR-060).
	 */
	public function __construct(
		private PayoutRepository $payouts,
		private PayoutBalances $balances,
		private PayoutCipher $cipher,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Closure $clock,
		private SettlementDocuments $documents
	) {
	}

	/**
	 * Rejects a pending payout with a reason; the reservation is released.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $reason    Reason shown to the creator (not audited).
	 * @throws PayoutException When not allowed, the reason is missing, or the payout is not pending.
	 */
	public function reject( int $payout_id, string $reason ): Payout {
		self::authorize( Capabilities::MANAGE_PAYOUTS );
		$reason = trim( $reason );

		if ( '' === $reason || mb_strlen( $reason ) > self::MAX_REASON_LENGTH ) {
			throw new PayoutException( PayoutException::INVALID_REASON );
		}

		return $this->decide(
			$payout_id,
			PayoutStatus::Rejected,
			function ( Payout $payout ) use ( $reason ): void {
				// The creator sees the reason and it is kept (ADR-059): never payment details.
				if ( PayoutFreeText::contains_payment_details( $reason, $this->details_if_readable( $payout ) ) ) {
					throw new PayoutException( PayoutException::PAYMENT_DETAILS_IN_TEXT );
				}

				$this->payouts->finish( $payout, PayoutStatus::Rejected, self::user(), ( $this->clock )(), $reason, null );
				$this->payouts->assert_same_connection( $payout->creator_id );
				$this->audit->record( AuditAction::PayoutRejected, $payout->creator_id, PayoutAudit::context( $payout, array( 'has_reason' => true ) ) );
			}
		);
	}

	/**
	 * Registers a pending bank or PayPal payout as transferred outside
	 * Merch Dashboard.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $reference Optional bank or PayPal reference (not audited).
	 * @throws PayoutException When not allowed, not pending, the details are unreadable, or the balance no longer covers it.
	 */
	public function complete( int $payout_id, string $reference = '' ): Payout {
		self::authorize( Capabilities::MANAGE_PAYOUTS );
		$reference = trim( $reference );

		if ( mb_strlen( $reference ) > self::MAX_REFERENCE_LENGTH ) {
			throw new PayoutException( PayoutException::INVALID_REFERENCE );
		}

		return $this->decide(
			$payout_id,
			PayoutStatus::Completed,
			function ( Payout $payout ) use ( $reference ): void {
				// A gift card payout is completed only by registering a validated card (PayoutGiftCards).
				if ( PayoutMethod::GiftCard === $payout->method ) {
					throw new PayoutException( PayoutException::WRONG_METHOD );
				}

				// The reference is kept (ADR-059): never the payout's own or other payment details.
				if ( PayoutFreeText::contains_payment_details( $reference, $this->readable_details( $payout ) ) ) {
					throw new PayoutException( PayoutException::PAYMENT_DETAILS_IN_TEXT );
				}

				$balance = $this->balances->in_currency( $payout->creator_id, $payout->currency )->balance_units();

				if ( null === $balance ) {
					throw new PayoutException( PayoutException::BALANCE_UNKNOWN );
				}

				// The balance includes this payout's own reservation.
				if ( $balance < 0 ) {
					throw new PayoutException( PayoutException::INSUFFICIENT_BALANCE );
				}

				$now = ( $this->clock )();
				$this->payouts->finish( $payout, PayoutStatus::Completed, self::user(), $now, null, $reference );
				$this->payouts->assert_same_connection( $payout->creator_id );
				// ADR-060: the settlement document in the same transaction; a failure rolls the completion back.
				$this->documents->issue( $this->payouts->find( $payout->id ) ?? throw new DatabaseException( 'The completed payout could not be read.' ), $now, self::user() );
				$this->audit->record( AuditAction::PayoutCompleted, $payout->creator_id, PayoutAudit::context( $payout, array( 'has_reference' => '' !== $reference ) ) );
			}
		);
	}

	/**
	 * The full details of a pending payout, for an admin with both payout
	 * capabilities. Every view is audited before the details are returned.
	 *
	 * @param int $payout_id Payout.
	 * @throws PayoutException When not allowed, not pending, or the details cannot be read.
	 */
	public function details( int $payout_id ): PayoutDestination {
		self::authorize( Capabilities::MANAGE_PAYOUTS );
		self::authorize( Capabilities::VIEW_PAYOUT_DETAILS );

		$payout = $this->payouts->find( $payout_id );

		if ( null === $payout ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		if ( PayoutStatus::Pending !== $payout->status ) {
			throw new PayoutException( PayoutException::NOT_PENDING );
		}

		if ( PayoutMethod::GiftCard === $payout->method ) {
			throw new PayoutException( PayoutException::WRONG_METHOD );
		}

		$destination = $this->readable_details( $payout );

		$this->transaction->run(
			function () use ( $payout ): void {
				$this->audit->record(
					AuditAction::PayoutDetailsViewed,
					$payout->creator_id,
					array(
						'payout_id' => $payout->id,
						'method'    => $payout->method->value,
					)
				);
			}
		);

		return $destination;
	}

	/**
	 * Runs a decision on a pending payout under its creator's lock.
	 *
	 * @param int                    $payout_id Payout.
	 * @param PayoutStatus           $target    The decision's status.
	 * @param callable(Payout): void $decide    Writes the decision (inside the transaction).
	 * @throws PayoutException When the payout is missing or decided differently.
	 */
	private function decide( int $payout_id, PayoutStatus $target, callable $decide ): Payout {
		$found = $this->payouts->find( $payout_id );

		if ( null === $found ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		$creator_id = $found->creator_id;

		return $this->payouts->with_creator_lock(
			$creator_id,
			fn(): Payout => $this->transaction->run(
				function () use ( $creator_id, $payout_id, $target, $decide ): Payout {
					CreatorRowLock::acquire( $this->payouts, $creator_id );
					$payout = $this->payouts->find( $payout_id, true ) ?? throw new PayoutException( PayoutException::NOT_FOUND );

					if ( $target === $payout->status ) {
						return $payout;
					}

					if ( PayoutStatus::Pending !== $payout->status ) {
						throw new PayoutException( PayoutException::NOT_PENDING );
					}

					$decide( $payout );
					$this->payouts->assert_same_connection( $payout->creator_id );

					return $this->payouts->find( $payout_id ) ?? throw new DatabaseException( 'The stored payout could not be read.' );
				}
			)
		);
	}

	/**
	 * The decrypted details of a pending payout.
	 *
	 * @param Payout $payout Payout.
	 * @throws PayoutException When they are missing or cannot be decrypted.
	 */
	private function readable_details( Payout $payout ): PayoutDestination {
		$secret = $this->payouts->secret( $payout->id );

		if ( null === $secret ) {
			throw new PayoutException( PayoutException::DETAILS_UNREADABLE );
		}

		try {
			$destination = PayoutDestination::from_json( $this->cipher->decrypt( $secret['ciphertext'], $secret['key_id'] ) );
		} catch ( PayoutException ) {
			throw new PayoutException( PayoutException::DETAILS_UNREADABLE );
		}

		if ( $destination->method !== $payout->method ) {
			throw new PayoutException( PayoutException::DETAILS_UNREADABLE );
		}

		return $destination;
	}

	/**
	 * The decrypted details of a pending bank or PayPal payout, or null when
	 * there are none (gift card) or they cannot be read: a rejection must stay
	 * possible then (planning decision 6).
	 *
	 * @param Payout $payout Payout.
	 */
	private function details_if_readable( Payout $payout ): ?PayoutDestination {
		if ( PayoutMethod::GiftCard === $payout->method ) {
			return null;
		}

		try {
			return $this->readable_details( $payout );
		} catch ( PayoutException ) {
			return null;
		}
	}

	/**
	 * Requires a capability of the current user.
	 *
	 * @param string $capability Capability.
	 * @throws PayoutException When the user lacks it.
	 */
	private static function authorize( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
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
