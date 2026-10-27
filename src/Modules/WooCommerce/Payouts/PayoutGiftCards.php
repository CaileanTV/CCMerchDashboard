<?php
/**
 * Registration of payout gift cards and the creator's view of theirs.
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
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProvider;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviderException;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardSnapshot;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Payout gift cards (ADR-057). The rules live here, not in an adapter, so
 * they are the same for every gift card plugin.
 *
 * **Registration** (admin, `merchboard_manage_payouts`): an admin created
 * the card by hand in the plugin and enters its code. Under the creator's
 * payout lock and in one transaction the creator's row is locked first
 * (CreatorRowLock), the payout is read again FOR UPDATE,
 * the chosen provider must be available, the card is read by its code and
 * must be exactly one card, active, not expired, in the payout's currency,
 * with amount = balance = the payout amount (integer units, no VAT), and on
 * no other payout. The creator's balance is checked as for a manual
 * completion. Only then is the payout completed with the provider id and
 * the card's external id (never the code) and `payout_gift_card_registered`
 * written. The same card on the same payout again is "already registered"
 * without a new audit record; anything else on a completed payout is
 * refused. A refusal changes nothing; a read failure is never a success
 * and never an amount of 0.
 *
 * **Creator's view:** only the creator of a completed gift card payout sees
 * the card, read live (find_by_id() and reveal_code()).
 *
 * Known limit: the plugin is not under Merch Dashboard's lock, so the card
 * can in theory be used in the shop between the read and COMMIT.
 */
final class PayoutGiftCards {

	/**
	 * Longest code accepted from the admin.
	 */
	public const MAX_CODE_LENGTH = 191;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param PayoutRepository    $payouts     Storage.
	 * @param PayoutBalances      $balances    Balances.
	 * @param GiftCardProviders   $providers   Gift card providers.
	 * @param AuditTrail          $audit       Audit trail.
	 * @param Transaction         $transaction Transactions.
	 * @param Logger              $logger      Technical log (ids and codes of failures, never a card code).
	 * @param Closure             $clock       Current time (`Closure(): DateTimeImmutable`).
	 * @param SettlementDocuments $documents   Settlement documents issued with a completion (ADR-060).
	 */
	public function __construct(
		private PayoutRepository $payouts,
		private PayoutBalances $balances,
		private GiftCardProviders $providers,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Logger $logger,
		private Closure $clock,
		private SettlementDocuments $documents
	) {
	}

	/**
	 * Registers the card with the code on a pending gift card payout and
	 * completes it.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $code      The card's code as the admin entered it (never stored).
	 * @throws PayoutException When not allowed or the card does not fit; nothing is changed.
	 */
	public function register( int $payout_id, string $code ): Payout {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			throw new PayoutException( PayoutException::NOT_AUTHORIZED );
		}

		$code = trim( $code );

		if ( '' === $code || mb_strlen( $code ) > self::MAX_CODE_LENGTH ) {
			throw new PayoutException( PayoutException::INVALID_GIFT_CARD_CODE );
		}

		$found = $this->payouts->find( $payout_id ) ?? throw new PayoutException( PayoutException::NOT_FOUND );

		if ( PayoutMethod::GiftCard !== $found->method ) {
			throw new PayoutException( PayoutException::WRONG_METHOD );
		}

		$creator_id = $found->creator_id;

		$payout = $this->payouts->with_creator_lock(
			$creator_id,
			fn(): Payout => $this->transaction->run(
				function () use ( $creator_id, $payout_id, $code ): Payout {
					CreatorRowLock::acquire( $this->payouts, $creator_id );
					$payout   = $this->payouts->find( $payout_id, true ) ?? throw new PayoutException( PayoutException::NOT_FOUND );
					$provider = $this->providers->usable() ?? throw new PayoutException( PayoutException::GIFT_CARDS_UNAVAILABLE );
					$card     = $this->read( $provider, $payout->id, static fn(): ?GiftCardSnapshot => $provider->find_by_code( $code ) )
						?? throw new PayoutException( PayoutException::GIFT_CARD_NOT_FOUND );

					if ( PayoutStatus::Completed === $payout->status && $provider->id() === $payout->gift_card_provider && $card->external_id === $payout->gift_card_external_id ) {
						return $payout;
					}

					if ( PayoutStatus::Pending !== $payout->status ) {
						throw new PayoutException( PayoutException::NOT_PENDING );
					}

					if ( null !== $this->payouts->find_by_gift_card( $provider->id(), $card->external_id ) ) {
						throw new PayoutException( PayoutException::GIFT_CARD_LINKED );
					}

					$this->validate( $payout, $card );

					$balance = $this->balances->in_currency( $payout->creator_id, $payout->currency )->balance_units();

					if ( null === $balance ) {
						throw new PayoutException( PayoutException::BALANCE_UNKNOWN );
					}

					// The balance includes this payout's own reservation.
					if ( $balance < 0 ) {
						throw new PayoutException( PayoutException::INSUFFICIENT_BALANCE );
					}

					$user = get_current_user_id();
					$now  = ( $this->clock )();
					$this->payouts->complete_gift_card( $payout, $provider->id(), $card->external_id, $user > 0 ? $user : null, $now );
					$this->payouts->assert_same_connection( $payout->creator_id );
					// ADR-060: the settlement document in the same transaction; a failure rolls the registration back.
					$this->documents->issue( $this->payouts->find( $payout->id ) ?? throw new DatabaseException( 'The completed payout could not be read.' ), $now, $user > 0 ? $user : null );
					$this->audit->record(
						AuditAction::PayoutGiftCardRegistered,
						$payout->creator_id,
						// Keys without "card": the log sanitizer redacts those (LogContext).
						PayoutAudit::context( $payout ) + array(
							'provider'    => $provider->id(),
							'external_id' => $card->external_id,
						)
					);
					$this->payouts->assert_same_connection( $payout->creator_id );

					return $this->payouts->find( $payout_id ) ?? throw new DatabaseException( 'The stored payout could not be read.' );
				}
			)
		);

		// Never the code: the creator reads it in the dashboard (ADR-057).
		PayoutNotice::send( 'payout_gift_card', $payout );

		return $payout;
	}

	/**
	 * The gift card of the creator's own completed gift card payout, read
	 * live from its provider, with its code.
	 *
	 * @param int $creator_id The current creator (CreatorContext).
	 * @param int $payout_id  Payout.
	 * @return array{code: string, card: GiftCardSnapshot}
	 * @throws PayoutException `not_found` for another creator's or a payout without a card; `gift_cards_unavailable` when it cannot be shown now.
	 */
	public function for_creator( int $creator_id, int $payout_id ): array {
		$payout = $this->payouts->find( $payout_id );

		if ( null === $payout || $payout->creator_id !== $creator_id || PayoutMethod::GiftCard !== $payout->method
			|| PayoutStatus::Completed !== $payout->status || null === $payout->gift_card_provider || null === $payout->gift_card_external_id ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		$provider = $this->providers->by_id( $payout->gift_card_provider );

		if ( null === $provider || ! $provider->availability()->available ) {
			throw new PayoutException( PayoutException::GIFT_CARDS_UNAVAILABLE );
		}

		$external_id = $payout->gift_card_external_id;

		try {
			$card = $this->read( $provider, $payout->id, static fn(): ?GiftCardSnapshot => $provider->find_by_id( $external_id ) );

			if ( null === $card || ! $card->can_reveal_code ) {
				throw new PayoutException( PayoutException::GIFT_CARDS_UNAVAILABLE );
			}

			$code = $this->read( $provider, $payout->id, static fn(): string => $provider->reveal_code( $external_id ) );
		} catch ( PayoutException ) {
			throw new PayoutException( PayoutException::GIFT_CARDS_UNAVAILABLE );
		}

		return array(
			'code' => $code,
			'card' => $card,
		);
	}

	/**
	 * The card must fit the payout exactly.
	 *
	 * @param Payout           $payout Pending payout.
	 * @param GiftCardSnapshot $card   The card.
	 * @throws PayoutException When it does not.
	 */
	private function validate( Payout $payout, GiftCardSnapshot $card ): void {
		if ( ! $card->can_reveal_code ) {
			// Its code could not be shown to the creator; no encrypted copy is kept (ADR-057).
			throw new PayoutException( PayoutException::GIFT_CARDS_UNAVAILABLE );
		}

		if ( GiftCardStatus::Expired === $card->status || ( null !== $card->expires_at && $card->expires_at <= ( $this->clock )() ) ) {
			throw new PayoutException( PayoutException::GIFT_CARD_EXPIRED );
		}

		if ( GiftCardStatus::Active !== $card->status ) {
			throw new PayoutException( PayoutException::GIFT_CARD_NOT_ACTIVE );
		}

		if ( $card->currency !== $payout->currency ) {
			throw new PayoutException( PayoutException::GIFT_CARD_CURRENCY );
		}

		if ( $card->amount_units !== $payout->amount_units ) {
			throw new PayoutException( PayoutException::GIFT_CARD_AMOUNT );
		}

		if ( $card->balance_units !== $card->amount_units ) {
			throw new PayoutException( PayoutException::GIFT_CARD_USED );
		}
	}

	/**
	 * Runs a read of the provider and maps its failures; a failure that is
	 * not the card's data is logged with ids and its code only.
	 *
	 * @template T
	 * @param GiftCardProvider $provider  Provider.
	 * @param int              $payout_id Payout, for the log.
	 * @param callable(): T    $read      The read.
	 * @return T
	 * @throws PayoutException When the read fails.
	 */
	private function read( GiftCardProvider $provider, int $payout_id, callable $read ): mixed {
		try {
			return $read();
		} catch ( GiftCardProviderException $e ) {
			$reason = $e->reason();
		} catch ( Throwable ) {
			$reason = GiftCardProviderException::READ_FAILED;
		}

		if ( GiftCardProviderException::READ_FAILED === $reason || GiftCardProviderException::UNAVAILABLE === $reason ) {
			$this->logger->error(
				'A gift card could not be read.',
				array(
					'provider'  => $provider->id(),
					'reason'    => $reason,
					'payout_id' => $payout_id,
				)
			);
		}

		throw new PayoutException(
			match ( $reason ) {
				GiftCardProviderException::AMBIGUOUS    => PayoutException::GIFT_CARD_AMBIGUOUS,
				GiftCardProviderException::INVALID_DATA => PayoutException::GIFT_CARD_INVALID_DATA,
				GiftCardProviderException::UNAVAILABLE  => PayoutException::GIFT_CARDS_UNAVAILABLE,
				default                                 => PayoutException::GIFT_CARD_READ_FAILED,
			}
		);
	}
}
