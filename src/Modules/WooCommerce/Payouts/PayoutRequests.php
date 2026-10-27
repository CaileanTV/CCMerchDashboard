<?php
/**
 * A creator's payout requests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use Closure;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Pricing\Currency;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Request and cancellation of a payout by the creator (ADR-055). The
 * caller resolves the creator (CreatorContext, ADR-007); this service never
 * takes a creator id from input on its own.
 *
 * A request runs under the creator's payout lock and in one transaction:
 * the creator's row is locked first (CreatorRowLock), the request key is
 * looked up (a resent request returns the stored
 * payout), the limits are checked (3 pending, 10 new per 24 hours), the
 * balance is recalculated, the amount validated (> 0, ≤ available, at most
 * the store's decimals), the details encrypted (ADR-056), the payout and
 * its details inserted and the audit record written. An unknown balance
 * blocks; it is never treated as 0. A failed read or write is a
 * DatabaseException; the transaction is rolled back.
 *
 * A gift card request has no details and needs no key: it can be requested
 * only while a gift card provider is chosen in Settings and available
 * (ADR-057); the admin then creates and registers the card.
 */
final class PayoutRequests {

	/**
	 * Most pending payouts per creator (planning decision 9).
	 */
	public const MAX_PENDING = 3;

	/**
	 * Most new payouts per creator in 24 hours (planning decision 9).
	 */
	public const MAX_PER_DAY = 10;

	/**
	 * The amount value that requests the whole available amount.
	 */
	public const ALL = 'all';

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param PayoutRepository       $payouts     Storage.
	 * @param PayoutBalances         $balances    Balances.
	 * @param PayoutCipher           $cipher      Encryption.
	 * @param PricingProductStore    $store       Store currency precision.
	 * @param AuditTrail             $audit       Audit trail.
	 * @param Transaction            $transaction Transactions.
	 * @param Closure                $clock       Current time (`Closure(): DateTimeImmutable`).
	 * @param GiftCardProviders|null $gift_cards  Gift card providers; null = gift cards unavailable.
	 */
	public function __construct(
		private PayoutRepository $payouts,
		private PayoutBalances $balances,
		private PayoutCipher $cipher,
		private PricingProductStore $store,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Closure $clock,
		private ?GiftCardProviders $gift_cards = null
	) {
	}

	/**
	 * Whether bank and PayPal can be requested (a valid key is configured).
	 */
	public function methods_available(): bool {
		return $this->cipher->available();
	}

	/**
	 * Whether a gift card can be requested (a provider is chosen and available).
	 */
	public function gift_cards_available(): bool {
		return null !== $this->gift_cards?->usable();
	}

	/**
	 * Requests a payout, or returns the payout already stored for the key.
	 *
	 * @param int          $creator_id      The current creator.
	 * @param string       $currency        Currency.
	 * @param string       $amount          Decimal amount or ALL.
	 * @param string       $method          Method value.
	 * @param array<mixed> $details         Bank or PayPal details (PayoutDestination::from_input()).
	 * @param string       $idempotency_key The client's request key (UUID).
	 * @param int|null     $user_id         WordPress user who requests it.
	 * @throws PayoutException When the request is refused; nothing is stored.
	 */
	public function request( int $creator_id, string $currency, string $amount, string $method, array $details, string $idempotency_key, ?int $user_id ): Payout {
		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{8,64}$/', $idempotency_key ) ) {
			throw new PayoutException( PayoutException::INVALID_KEY );
		}

		try {
			Currency::assert_valid( $currency );
		} catch ( InvalidArgumentException ) {
			throw new PayoutException( PayoutException::INVALID_CURRENCY );
		}

		$type = PayoutMethod::tryFrom( $method );

		if ( null === $type ) {
			throw new PayoutException( PayoutException::METHOD_UNAVAILABLE );
		}

		if ( PayoutMethod::GiftCard === $type ) {
			$destination = null;

			if ( ! $this->gift_cards_available() ) {
				throw new PayoutException( PayoutException::METHOD_UNAVAILABLE );
			}
		} else {
			$destination = PayoutDestination::from_input( $type, $details );

			if ( ! $this->cipher->available() ) {
				throw new PayoutException( PayoutException::METHOD_UNAVAILABLE );
			}
		}

		$decimals = $this->store->decimals();

		$payout = $this->payouts->with_creator_lock(
			$creator_id,
			fn(): Payout => $this->transaction->run(
				function () use ( $creator_id, $currency, $amount, $type, $destination, $idempotency_key, $user_id, $decimals ): Payout {
					CreatorRowLock::acquire( $this->payouts, $creator_id );
					$existing = $this->payouts->find_by_key( $creator_id, $idempotency_key );

					if ( null !== $existing ) {
						return $existing;
					}

					$now = ( $this->clock )();

					if ( $this->payouts->count_pending( $creator_id ) >= self::MAX_PENDING ) {
						throw new PayoutException( PayoutException::TOO_MANY_PENDING );
					}

					if ( $this->payouts->count_requested_since( $creator_id, $now->sub( new DateInterval( 'PT24H' ) ) ) >= self::MAX_PER_DAY ) {
						throw new PayoutException( PayoutException::TOO_MANY_REQUESTS );
					}

					$available = $this->balances->in_currency( $creator_id, $currency )->available_units();

					if ( null === $available ) {
						throw new PayoutException( PayoutException::BALANCE_UNKNOWN );
					}

					$units = self::amount( $amount, $available, $decimals );

					try {
						$ciphertext = null === $destination ? null : $this->cipher->encrypt( $destination->to_json() );
					} catch ( JsonException ) {
						throw new PayoutException( PayoutException::INVALID_DETAILS );
					}

					$id = $this->payouts->insert_pending( $creator_id, $currency, $units, $decimals, $type, null === $destination ? '' : $destination->hint(), $idempotency_key, $user_id, $now, $ciphertext, null === $destination ? '' : $this->cipher->key_id() );

					$payout = $this->payouts->find( $id ) ?? throw new DatabaseException( 'The stored payout could not be read.' );

					$this->payouts->assert_same_connection( $creator_id );
					$this->audit->record( AuditAction::PayoutRequested, $creator_id, PayoutAudit::context( $payout ) );
					$this->payouts->assert_same_connection( $creator_id );

					return $payout;
				}
			)
		);

		PayoutNotice::send( 'payout_requested', $payout );
		PayoutNotice::admin( 'admin_payout_requested', $payout );

		return $payout;
	}

	/**
	 * Cancels the creator's own pending payout and releases its reservation.
	 * Cancelling a cancelled payout again changes nothing.
	 *
	 * @param int      $creator_id The current creator.
	 * @param int      $payout_id  Payout.
	 * @param int|null $user_id    WordPress user.
	 * @throws PayoutException When it is not the creator's (`not_found`) or no longer pending.
	 * @throws DatabaseException When a read or write fails (rolled back).
	 */
	public function cancel( int $creator_id, int $payout_id, ?int $user_id ): Payout {
		$payout = $this->payouts->with_creator_lock(
			$creator_id,
			fn(): Payout => $this->transaction->run(
				function () use ( $creator_id, $payout_id, $user_id ): Payout {
					CreatorRowLock::acquire( $this->payouts, $creator_id );
					$payout = $this->payouts->find( $payout_id, true );

					if ( null === $payout || $payout->creator_id !== $creator_id ) {
						throw new PayoutException( PayoutException::NOT_FOUND );
					}

					if ( PayoutStatus::Cancelled === $payout->status ) {
						return $payout;
					}

					if ( PayoutStatus::Pending !== $payout->status ) {
						throw new PayoutException( PayoutException::NOT_PENDING );
					}

					$this->payouts->finish( $payout, PayoutStatus::Cancelled, $user_id, ( $this->clock )(), null, null );
					$this->payouts->assert_same_connection( $creator_id );
					$this->audit->record( AuditAction::PayoutCancelled, $creator_id, PayoutAudit::context( $payout ) );
					$this->payouts->assert_same_connection( $creator_id );

					return $this->payouts->find( $payout_id ) ?? throw new DatabaseException( 'The stored payout could not be read.' );
				}
			)
		);

		PayoutNotice::send( 'payout_cancelled', $payout );
		PayoutNotice::admin( 'admin_payout_cancelled', $payout );

		return $payout;
	}

	/**
	 * The requested amount in units.
	 *
	 * @param string $amount    Decimal amount or ALL.
	 * @param int    $available Available units.
	 * @param int    $decimals  Store price decimals.
	 * @throws PayoutException When it is not a positive amount within the available amount and precision.
	 */
	private static function amount( string $amount, int $available, int $decimals ): int {
		try {
			$step = PricingMath::step( $decimals );
		} catch ( InvalidArgumentException ) {
			throw new PayoutException( PayoutException::INVALID_AMOUNT );
		}

		if ( self::ALL === $amount ) {
			// Never more than available: round down to the store's precision.
			$units = $available - ( $available % $step );
		} else {
			$pattern = 0 === $decimals ? '/^\d+$/' : '/^\d+(?:\.\d{1,' . $decimals . '})?$/';

			if ( 1 !== preg_match( $pattern, $amount ) ) {
				throw new PayoutException( PayoutException::INVALID_AMOUNT );
			}

			try {
				$units = SalesAmount::parse( $amount );
			} catch ( Throwable ) {
				throw new PayoutException( PayoutException::INVALID_AMOUNT );
			}
		}

		if ( $units <= 0 && self::ALL === $amount ) {
			throw new PayoutException( PayoutException::INSUFFICIENT_BALANCE );
		}

		if ( $units <= 0 ) {
			throw new PayoutException( PayoutException::INVALID_AMOUNT );
		}

		if ( $units > $available ) {
			throw new PayoutException( PayoutException::INSUFFICIENT_BALANCE );
		}

		return $units;
	}
}
