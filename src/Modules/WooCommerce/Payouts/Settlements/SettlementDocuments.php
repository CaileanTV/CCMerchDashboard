<?php
/**
 * Settlement documents for self-billing.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use Closure;
use DateTimeImmutable;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutMethod;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * Afregningsbilag on the creator's behalf (ADR-060). Not approved by an
 * auditor or the Danish Tax Agency (R1–R4 open). Documents of template
 * version 1 are marked preliminary (060-9); from version 2 they carry a
 * neutral note on tax and reporting instead (SettlementPdf::tax_note()).
 * Issued documents keep the version they were issued with.
 *
 * **Issue** runs inside the transaction and under the creator's payout lock
 * that completes the payout (PayoutDecisions, PayoutGiftCards), so a
 * completed payout always has exactly one document and a failure rolls back
 * both (060-1). It settles every released ledger row of the payout's
 * currency that no document has settled yet, with LedgerCreatorPayouts'
 * D1/D2 rules (060-2); an unknown row refuses the completion (never 0). The
 * document number comes from the series counter in the same transaction
 * (D9). Amounts: commission = Σ payout of the settled rows; opening =
 * closing of the previous document in the currency; closing = opening +
 * commission − payout. Rows and parties are stored as a snapshot and never
 * recalculated (060-4).
 *
 * **Correct** (D11) issues a correction document that refers to the latest
 * document of a chain, with a new snapshot of the parties and a reason. It
 * carries no amounts: changes of amounts reach the next settlement document
 * through the ledger.
 */
final class SettlementDocuments {

	/**
	 * The number series (D9): one continuous series for settlement and
	 * correction documents.
	 */
	public const SERIES = 'AFR';

	/**
	 * Digits of the running number.
	 */
	public const DIGITS = 6;

	/**
	 * The calculation the documents are made with (ADR-049, ADR-054 D1/D2).
	 */
	public const CALCULATION_VERSION = 'adr049-adr054-v1';

	/**
	 * Version of the document template (texts and layout) of new documents:
	 * 1 = marked preliminary (060-9), 2 = note on tax and reporting.
	 */
	public const TEMPLATE_VERSION = 2;

	/**
	 * Longest reason of a correction.
	 */
	public const MAX_REASON_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param SettlementRepository $documents   Documents.
	 * @param PayoutRepository     $payouts     Lock, its connection, settlement start.
	 * @param LedgerCreatorPayouts $ledger      Unsettled released rows.
	 * @param CreatorRepository    $creators    Creator names.
	 * @param CreatorVatRepository $vat         The creator's VAT status.
	 * @param AuditTrail           $audit       Audit trail.
	 * @param Transaction          $transaction Transactions (corrections).
	 * @param Closure              $clock       Current time (`Closure(): DateTimeImmutable`).
	 */
	public function __construct(
		private SettlementRepository $documents,
		private PayoutRepository $payouts,
		private LedgerCreatorPayouts $ledger,
		private CreatorRepository $creators,
		private CreatorVatRepository $vat,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Closure $clock
	) {
	}

	/**
	 * Issues the settlement document of a payout that the caller is
	 * completing, inside its transaction and under the creator's lock. A
	 * payout that already has one gets it back unchanged.
	 *
	 * @param Payout            $payout The payout being completed (read under the lock).
	 * @param DateTimeImmutable $now    Time of the completion.
	 * @param int|null          $user   WordPress user.
	 * @throws PayoutException `balance_unknown` when a row in the window is unknown; a failed read or write is a DatabaseException (the caller rolls back).
	 */
	public function issue( Payout $payout, DateTimeImmutable $now, ?int $user ): SettlementDocument {
		$connection = $this->payouts->lock_connection( $payout->creator_id );
		$existing   = $this->documents->find_by_payout( $payout->id );

		if ( null !== $existing ) {
			return $existing;
		}

		$start = $this->payouts->settlement_start( $payout->creator_id );
		$rows  = $this->ledger->unsettled_rows( $payout->creator_id, $payout->currency, $start, $now, Settings::payout_holding_days(), true );

		if ( null === $rows ) {
			throw new PayoutException( PayoutException::BALANCE_UNKNOWN );
		}

		$previous   = $this->documents->latest_settlement( $payout->creator_id, $payout->currency );
		$opening    = null === $previous ? 0 : $previous->closing_units;
		$commission = 0;
		$profit     = 0;

		foreach ( $rows as $row ) {
			$commission = SalesAmount::add( $commission, $row['payout_units'] );
			$profit     = SalesAmount::add( $profit, $row['profit_units'] );
		}

		$vat      = $this->vat->find( $payout->creator_id );
		$document = $this->insert(
			array(
				'type'                 => SettlementDocument::TYPE_SETTLEMENT,
				'creator_id'           => $payout->creator_id,
				'payout_id'            => $payout->id,
				'settlement_payout_id' => $payout->id,
				'corrects_document_id' => 0,
				'currency'             => $payout->currency,
				'period_from'          => null === $previous ? $start : $previous->period_to,
				'period_to'            => $now,
				'profit_units'         => $profit,
				'commission_units'     => $commission,
				'opening_units'        => $opening,
				'payout_units'         => $payout->amount_units,
				'closing_units'        => SalesAmount::add( SalesAmount::add( $opening, $commission ), SalesAmount::negate( $payout->amount_units ) ),
				'vat_registered'       => $vat,
				'snapshot'             => $this->snapshot( $payout, $vat, $start ),
				'issued_by'            => (int) $user,
				'issued_at'            => $now,
			),
			$connection
		);

		foreach ( $rows as $row ) {
			$this->documents->insert_row( $document->id, $row, $connection );
		}

		$this->payouts->assert_same_connection( $payout->creator_id );
		$this->audit->record(
			AuditAction::SettlementDocumentIssued,
			$payout->creator_id,
			array(
				'document_id'     => $document->id,
				'document_number' => $document->number,
				'payout_id'       => $payout->id,
				'rows'            => count( $rows ),
				'currency'        => $payout->currency,
			)
		);

		return $document;
	}

	/**
	 * Issues a correction document for the latest document of a chain (D11).
	 *
	 * @param int    $document_id The document to correct (the latest of its chain).
	 * @param string $reason      Why (shown on the document, never audited).
	 * @throws PayoutException When not allowed, the reason is invalid, or the document cannot be corrected.
	 */
	public function correct( int $document_id, string $reason ): SettlementDocument {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			throw new PayoutException( PayoutException::NOT_AUTHORIZED );
		}

		$reason = trim( $reason );

		if ( '' === $reason || mb_strlen( $reason ) > self::MAX_REASON_LENGTH ) {
			throw new PayoutException( PayoutException::INVALID_REASON );
		}

		$found = $this->documents->find( $document_id ) ?? throw new PayoutException( PayoutException::DOCUMENT_NOT_CORRECTABLE );

		return $this->payouts->with_creator_lock(
			$found->creator_id,
			fn(): SettlementDocument => $this->transaction->run(
				function () use ( $document_id, $reason ): SettlementDocument {
					$original = $this->documents->find( $document_id, true );

					if ( null === $original || null !== $this->documents->correction_of( $document_id ) ) {
						throw new PayoutException( PayoutException::DOCUMENT_NOT_CORRECTABLE );
					}

					$connection = $this->payouts->lock_connection( $original->creator_id );
					$payout     = null === $original->payout_id ? null : $this->payouts->find( $original->payout_id );
					$vat        = $this->vat->find( $original->creator_id );
					$now        = ( $this->clock )();
					$user       = get_current_user_id();
					$snapshot   = $this->snapshot( $payout, $vat, $this->payouts->settlement_start( $original->creator_id ) )
						+ array(
							'corrects'          => $original->number,
							'correction_reason' => $reason,
						);
					$document   = $this->insert(
						array(
							'type'                 => SettlementDocument::TYPE_CORRECTION,
							'creator_id'           => $original->creator_id,
							'payout_id'            => (int) $original->payout_id,
							'settlement_payout_id' => 0,
							'corrects_document_id' => $original->id,
							'currency'             => $original->currency,
							'period_from'          => $original->period_from,
							'period_to'            => $original->period_to,
							'profit_units'         => 0,
							'commission_units'     => 0,
							'opening_units'        => 0,
							'payout_units'         => 0,
							'closing_units'        => 0,
							'vat_registered'       => $vat,
							'snapshot'             => $snapshot,
							'issued_by'            => $user,
							'issued_at'            => $now,
						),
						$connection
					);

					$this->payouts->assert_same_connection( $original->creator_id );
					$this->audit->record(
						AuditAction::SettlementDocumentCorrected,
						$original->creator_id,
						array(
							'document_id'     => $document->id,
							'document_number' => $document->number,
							'corrects'        => $original->number,
							'payout_id'       => $original->payout_id,
							'has_reason'      => true,
						)
					);
					$this->payouts->assert_same_connection( $original->creator_id );

					return $document;
				}
			)
		);
	}

	/**
	 * The settlement document of the creator's own payout with its rows and
	 * the numbers of its corrections.
	 *
	 * @param int $creator_id The current creator (CreatorContext).
	 * @param int $payout_id  Payout.
	 * @return array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>}
	 * @throws PayoutException `not_found` for another creator's payout or a payout without a document.
	 */
	public function for_creator( int $creator_id, int $payout_id ): array {
		$payout = $this->payouts->find( $payout_id );

		if ( null === $payout || $payout->creator_id !== $creator_id ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		return $this->for_payout( $payout_id ) ?? throw new PayoutException( PayoutException::NOT_FOUND );
	}

	/**
	 * Document numbers of the settlement documents of payouts.
	 *
	 * @phpstan-param list<int> $payout_ids
	 *
	 * @param int[] $payout_ids Payouts.
	 * @return array<int, string> Number by payout id.
	 * @throws DatabaseException When the read fails.
	 */
	public function numbers_for_payouts( array $payout_ids ): array {
		return $this->documents->numbers_for_payouts( $payout_ids );
	}

	/**
	 * The settlement document of a payout with its rows and corrections, or
	 * null when it has none.
	 *
	 * @param int $payout_id Payout.
	 * @return array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>}|null
	 * @throws DatabaseException When a read fails.
	 * @phpstan-impure Reads the database.
	 */
	public function for_payout( int $payout_id ): ?array {
		$document = $this->documents->find_by_payout( $payout_id );

		if ( null === $document ) {
			return null;
		}

		$corrections = array();
		$next        = $this->documents->correction_of( $document->id );

		// The chain of corrections; each corrects the previous one (D11).
		for ( $i = 0; null !== $next && $i < 100; $i++ ) {
			$corrections[] = $next;
			$next          = $this->documents->correction_of( $next->id );
		}

		return array(
			'document'    => $document,
			'rows'        => $this->documents->rows( $document->id ),
			'corrections' => $corrections,
		);
	}

	/**
	 * Inserts a document with the next number of the series.
	 *
	 * @param array<string, mixed> $values     Values without number and series.
	 * @param int                  $connection The lock's connection.
	 * @throws DatabaseException When a write fails.
	 */
	private function insert( array $values, int $connection ): SettlementDocument {
		$number = self::SERIES . '-' . str_pad( (string) $this->documents->next_number( self::SERIES, $connection ), self::DIGITS, '0', STR_PAD_LEFT );
		$id     = $this->documents->insert_document(
			$values + array(
				'number'              => $number,
				'series'              => self::SERIES,
				'calculation_version' => self::CALCULATION_VERSION,
				'template_version'    => self::TEMPLATE_VERSION,
			),
			$connection
		);

		return $this->documents->find( $id ) ?? throw new DatabaseException( 'The stored settlement document could not be read.' );
	}

	/**
	 * The parties and the payout as they are now (D10: existing data only).
	 *
	 * @param Payout|null            $payout Payout.
	 * @param bool|null              $vat    The creator's VAT status.
	 * @param DateTimeImmutable|null $start  Settlement start.
	 * @return array<string, mixed>
	 * @throws DatabaseException When the creator cannot be read.
	 */
	private function snapshot( ?Payout $payout, ?bool $vat, ?DateTimeImmutable $start ): array {
		$creator_id = null === $payout ? 0 : $payout->creator_id;
		$creator    = $creator_id > 0 ? $this->creators->find( $creator_id ) : null;
		$method     = $payout?->method;

		return array(
			// Template version 2: the note on tax and reporting replaces the preliminary mark.
			'preliminary'      => false,
			'creator'          => array(
				'id'             => $creator_id,
				'name'           => null === $creator ? '' : $creator->display_name,
				'vat_registered' => $vat,
			),
			'company'          => array(
				'name'    => (string) get_bloginfo( 'name' ),
				'address' => array_values(
					array_filter(
						array(
							(string) get_option( 'woocommerce_store_address', '' ),
							(string) get_option( 'woocommerce_store_address_2', '' ),
							trim( (string) get_option( 'woocommerce_store_postcode', '' ) . ' ' . (string) get_option( 'woocommerce_store_city', '' ) ),
							(string) get_option( 'woocommerce_default_country', '' ),
						),
						static fn( string $line ): bool => '' !== trim( $line )
					)
				),
			),
			'payout'           => null === $payout ? null : array(
				'id'                 => $payout->id,
				'method'             => $method?->value,
				'destination_hint'   => $payout->destination_hint,
				'gift_card_provider' => PayoutMethod::GiftCard === $method ? $payout->gift_card_provider : null,
				'gift_card_id'       => PayoutMethod::GiftCard === $method ? $payout->gift_card_external_id : null,
				'completed'          => PayoutStatus::Completed === $payout->status,
			),
			'holding_days'     => Settings::payout_holding_days(),
			'settlement_start' => $start?->format( 'Y-m-d\TH:i:s\Z' ),
		);
	}
}
