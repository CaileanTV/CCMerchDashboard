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
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutFreeText;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutMethod;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutAdjustmentRepository;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\Recalculation;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Afregningsbilag on the creator's behalf (ADR-060). Not approved by an
 * auditor or the Danish Tax Agency (R1–R4 open). Documents of template
 * version 1 are marked preliminary (060-9); from version 2 they carry a
 * neutral note on tax and reporting instead (SettlementPdf::tax_note()); from
 * version 3 they also carry the VAT on the commission (CommissionVat), and
 * from version 4 the master data of both parties (ADR-062 K09): the
 * creator's legal name, address and CVR/SE number and the issuing business'
 * CVR number, copied at issue and '' or [] when not registered; from version
 * 6 also the VAT deducted from the payout of sales while the creator was not
 * VAT registered (VatDeduction, ADR-064). Issued documents keep the version
 * they were issued with.
 *
 * **Issue** runs inside the transaction and under the creator's payout lock
 * that completes the payout (PayoutDecisions, PayoutGiftCards), so a
 * completed payout always has exactly one document and a failure rolls back
 * both (060-1). It settles every released ledger row of the payout's
 * currency that no document has settled yet, with LedgerCreatorPayouts'
 * D1/D2 rules (060-2); an unknown row refuses the completion (never 0). The
 * document number comes from the series counter in the same transaction
 * (D9). Amounts: commission = Σ payout of the settled rows; opening =
 * closing of the previous document in the currency; adjustment = Σ executed
 * payout adjustments not settled yet (ADR-063, settled by this document with
 * their references in the snapshot); closing = opening + commission +
 * adjustment − payout. Rows and parties are stored as a snapshot and never
 * recalculated (060-4), and so is the split of the commission into VAT and
 * commission excl. VAT (CommissionVat, ADR-062 K05).
 *
 * **Correct** (ADR-062 K10 and K15) issues a correction document
 * (rettelsesbilag) for a material error, as assessed by the administrator:
 * it corrects the latest document of a chain, is numbered after the original
 * (`AFR-000007-K1`) and states the amounts in force, the corrected amounts,
 * the difference and the reason, with a new snapshot of the parties
 * (SettlementCorrection, template version 5). It changes no issued document,
 * payout, ledger row or balance, and the difference is not settled.
 * Correction documents issued before (D11 a) carry no amounts and a number
 * of the series; they are kept and shown as they were.
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
	 * The calculation new documents are made with: ADR-049's share, with the
	 * VAT deducted from the payout of sales while not VAT registered
	 * (ADR-064), ADR-054 D1/D2. Documents issued before keep
	 * `adr049-adr054-v1` (20 % deduction) or `adr063-adr054-v1` (no deduction).
	 */
	public const CALCULATION_VERSION = 'adr064-adr054-v1';

	/**
	 * Version of the document template (texts and layout) of new documents:
	 * 1 = marked preliminary (060-9), 2 = note on tax and reporting, 3 = also
	 * VAT on the commission (CommissionVat, ADR-062 K05 and K12), 4 = also the
	 * master data of both parties (ADR-062 K09), 6 = also the VAT deducted
	 * from the payout of sales while not VAT registered (VatDeduction,
	 * ADR-064). Version 5 is the correction document (SettlementCorrection).
	 */
	public const TEMPLATE_VERSION = 6;

	/**
	 * Longest reason of a correction.
	 */
	public const MAX_REASON_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(): DateTimeImmutable $clock
	 *
	 * @param SettlementRepository            $documents   Documents.
	 * @param PayoutRepository                $payouts     Lock, its connection, settlement start.
	 * @param LedgerCreatorPayouts            $ledger      Unsettled released rows.
	 * @param CreatorRepository               $creators    Creator names.
	 * @param CreatorVatRepository            $vat         The creator's VAT status.
	 * @param AuditTrail                      $audit       Audit trail.
	 * @param Transaction                     $transaction Transactions (corrections).
	 * @param Closure                         $clock       Current time (`Closure(): DateTimeImmutable`).
	 * @param PayoutAdjustmentRepository|null $adjustments Payout adjustments to settle (ADR-063); null = none.
	 * @param CreatorBillingDetailsStore|null $billing     Creators' master data (ADR-062 K09); null = none registered.
	 */
	public function __construct(
		private SettlementRepository $documents,
		private PayoutRepository $payouts,
		private LedgerCreatorPayouts $ledger,
		private CreatorRepository $creators,
		private CreatorVatRepository $vat,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Closure $clock,
		private ?PayoutAdjustmentRepository $adjustments = null,
		private ?CreatorBillingDetailsStore $billing = null
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
	 * @throws DatabaseException When a settled row's precision does not match the schema.
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

		// Executed payout adjustments not settled yet (ADR-063), locked until the commit.
		$adjustments = null === $this->adjustments ? array() : $this->adjustments->unsettled_for_update( $payout->creator_id, $payout->currency );
		$adjustment  = 0;

		foreach ( $adjustments as $entry ) {
			$adjustment = SalesAmount::add( $adjustment, $entry['amount_units'] );
		}

		try {
			// ADR-062 K05: VAT on the commission by each row's VAT status at the sale.
			$commission_vat = CommissionVat::snapshot( CommissionVat::split( $rows ) );
			// ADR-064: the VAT deducted from the payout of the sales while not VAT registered.
			$vat_deduction = VatDeduction::snapshot( VatDeduction::of_rows( $rows ) );
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new DatabaseException( 'A ledger row does not match the schema.' );
		}

		$vat                     = $this->vat->find( $payout->creator_id );
		$snapshot                = $this->snapshot( $payout, $vat, $start ) + array(
			CommissionVat::SNAPSHOT_KEY => $commission_vat,
			VatDeduction::SNAPSHOT_KEY  => $vat_deduction,
		);
		$snapshot['adjustments'] = array_map(
			static fn( array $entry ): array => array(
				'reference' => Recalculation::reference_of( $entry['recalculation_id'] ),
				'amount'    => SalesAmount::format( $entry['amount_units'] ),
			),
			$adjustments
		);
		$document                = $this->insert(
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
				'adjustment_units'     => $adjustment,
				'opening_units'        => $opening,
				'payout_units'         => $payout->amount_units,
				'closing_units'        => SalesAmount::add( SalesAmount::add( SalesAmount::add( $opening, $commission ), $adjustment ), SalesAmount::negate( $payout->amount_units ) ),
				'vat_registered'       => $vat,
				'snapshot'             => $snapshot,
				'issued_by'            => (int) $user,
				'issued_at'            => $now,
			),
			$connection
		);

		foreach ( $rows as $row ) {
			$this->documents->insert_row( $document->id, $row, $connection );
		}

		$this->adjustments?->mark_settled( array_column( $adjustments, 'id' ), $document->id, $connection );
		$this->payouts->assert_same_connection( $payout->creator_id );
		$this->audit->record(
			AuditAction::SettlementDocumentIssued,
			$payout->creator_id,
			array(
				'document_id'     => $document->id,
				'document_number' => $document->number,
				'payout_id'       => $payout->id,
				'rows'            => count( $rows ),
				'adjustments'     => count( $adjustments ),
				'currency'        => $payout->currency,
			)
		);

		return $document;
	}

	/**
	 * Issues a correction document (rettelsesbilag, ADR-062 K10 and K15) for
	 * a material error, as assessed by the administrator, in the settlement
	 * document of a payout. It corrects the latest document of the chain
	 * (the original or its latest correction), which the caller names, so a
	 * repeated or stale request is refused. It is numbered after the
	 * original (`AFR-000007-K1`, `-K2`, …; no number of the series is used)
	 * and states the amounts in force, the corrected amounts, the difference
	 * and the reason (SettlementCorrection), with the parties as they are
	 * now. Nothing else changes: not the original or earlier corrections,
	 * the payout, the ledger, the balance or later documents; the difference
	 * is not settled. One transaction under the creator's payout lock with
	 * audit; any failure writes nothing.
	 *
	 * @param int    $payout_id   The payout whose settlement document is corrected.
	 * @param int    $document_id The document to correct: the latest of the chain.
	 * @param string $reason      Why (shown on the document, never audited).
	 * @param string $incl        Corrected commission of sales with VAT, incl. VAT, as entered.
	 * @param string $untaxed     Corrected commission of sales without VAT, as entered.
	 * @throws PayoutException When not allowed, an input is invalid, nothing would change, or the document cannot be corrected; a failed read or write is a DatabaseException (nothing is written).
	 */
	public function correct( int $payout_id, int $document_id, string $reason, string $incl, string $untaxed ): SettlementDocument {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			throw new PayoutException( PayoutException::NOT_AUTHORIZED );
		}

		$reason = trim( $reason );

		if ( '' === $reason || mb_strlen( $reason ) > self::MAX_REASON_LENGTH || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason ) || 1 !== preg_match( '//u', $reason ) ) {
			throw new PayoutException( PayoutException::INVALID_CORRECTION_REASON );
		}

		// Shown to the creator and on the document: never bank or payment details (as rejection reasons).
		if ( PayoutFreeText::contains_payment_details( $reason, null ) ) {
			throw new PayoutException( PayoutException::PAYMENT_DETAILS_IN_TEXT );
		}

		// Format only; the store precision is checked under the lock.
		SettlementCorrection::amount( $incl, SalesAmount::DECIMALS );
		SettlementCorrection::amount( $untaxed, SalesAmount::DECIMALS );

		$found = $this->documents->find( $document_id );

		if ( null === $found || $found->payout_id !== $payout_id || $payout_id < 1 ) {
			throw new PayoutException( PayoutException::DOCUMENT_NOT_CORRECTABLE );
		}

		$document = $this->payouts->with_creator_lock(
			$found->creator_id,
			fn(): SettlementDocument => $this->transaction->run(
				function () use ( $payout_id, $document_id, $reason, $incl, $untaxed ): SettlementDocument {
					$corrected = $this->documents->find( $document_id, true );

					// The latest of its chain, still: a repeated or stale request corrects nothing.
					if ( null === $corrected || $corrected->payout_id !== $payout_id || null !== $this->documents->correction_of( $document_id ) ) {
						throw new PayoutException( PayoutException::DOCUMENT_NOT_CORRECTABLE );
					}

					$chain = $this->chain_to( $corrected );

					// At most MAX_CHAIN corrections, so every issued one can be read and shown again.
					if ( count( $chain ) > SettlementCorrection::MAX_CHAIN ) {
						throw new PayoutException( PayoutException::CORRECTION_LIMIT );
					}

					$original = $chain[0];
					$rows     = $this->documents->rows( $original->id );

					try {
						$before = SettlementCorrection::in_force( $chain, $rows );
						// The VAT status at the sale of the original's rows, never the creator's current status.
						$categories = SettlementCorrection::categories( $rows );
					} catch ( UnexpectedValueException ) {
						throw new PayoutException( PayoutException::DOCUMENT_NOT_CORRECTABLE );
					}

					$decimals = SettlementCorrection::decimals( $rows );

					try {
						$after = SettlementCorrection::corrected( $before['rate_bps'], SettlementCorrection::amount( $incl, $decimals ), SettlementCorrection::amount( $untaxed, $decimals ), $decimals );
					} catch ( UnexpectedValueException ) {
						throw new PayoutException( PayoutException::INVALID_CORRECTION_AMOUNT );
					}

					// No VAT on the commission of sales while not VAT registered, and none removed from sales while registered.
					if ( ! SettlementCorrection::within( $categories, $after ) ) {
						throw new PayoutException( PayoutException::CORRECTION_VAT_STATUS );
					}

					$connection = $this->payouts->lock_connection( $original->creator_id );
					$payout     = $this->payouts->find( $payout_id );
					$vat        = $this->vat->find( $original->creator_id );
					$snapshot   = $this->snapshot( $payout, $vat, $this->payouts->settlement_start( $original->creator_id ) );

					// Something must change: an amount, or the master data of the parties (K09); never only the current VAT status or display name.
					if ( SettlementCorrection::unchanged( $before, $after ) && SettlementCorrection::same_parties( $corrected->snapshot, $snapshot ) ) {
						throw new PayoutException( PayoutException::CORRECTION_UNCHANGED );
					}

					$sequence   = count( $chain );
					$correction = SettlementCorrection::snapshot( $original->number, $corrected->number, $sequence, $decimals, $before, $after, $original->payout_units );
					$document   = $this->insert(
						array(
							'number'               => $original->number . '-K' . $sequence,
							'template_version'     => SettlementCorrection::TEMPLATE,
							'type'                 => SettlementDocument::TYPE_CORRECTION,
							'creator_id'           => $original->creator_id,
							'payout_id'            => $payout_id,
							'settlement_payout_id' => 0,
							'corrects_document_id' => $corrected->id,
							'currency'             => $original->currency,
							'period_from'          => $original->period_from,
							'period_to'            => $original->period_to,
							// The amounts are in the snapshot only: no balance or sum ever counts a correction.
							'profit_units'         => 0,
							'commission_units'     => 0,
							'opening_units'        => 0,
							'payout_units'         => 0,
							'closing_units'        => 0,
							'vat_registered'       => $vat,
							'snapshot'             => $snapshot + array(
								'corrects'          => $corrected->number,
								'correction_reason' => $reason,
								SettlementCorrection::SNAPSHOT_KEY => $correction,
							),
							'issued_by'            => get_current_user_id(),
							'issued_at'            => ( $this->clock )(),
						),
						$connection
					);

					$this->payouts->assert_same_connection( $original->creator_id );
					$this->audit->record(
						AuditAction::SettlementDocumentCorrected,
						$original->creator_id,
						array(
							'document_id'           => $document->id,
							'document_number'       => $document->number,
							'original'              => $original->number,
							'corrects'              => $corrected->number,
							'payout_id'             => $payout_id,
							'currency'              => $original->currency,
							'commission_before'     => $correction['before']['commission'],
							'commission_after'      => $correction['after']['commission'],
							'commission_difference' => $correction['difference']['commission'],
							'has_reason'            => true,
						)
					);
					$this->payouts->assert_same_connection( $original->creator_id );

					return $document;
				}
			)
		);

		$correction = $document->snapshot[ SettlementCorrection::SNAPSHOT_KEY ] ?? null;
		do_action(
			'merchboard_notify',
			'settlement_corrected',
			$document->creator_id,
			array(
				'document_number'   => (string) ( $document->snapshot['corrects'] ?? '' ),
				'correction_number' => $document->number,
				'difference'        => is_array( $correction ) && is_array( $correction['difference'] ?? null ) ? (string) ( $correction['difference']['commission'] ?? '' ) : '',
				'currency'          => $document->currency,
				'reason'            => $reason,
			),
			'document:' . $document->id
		);

		return $document;
	}

	/**
	 * The chain of a document back to its original settlement document: the
	 * original first, the document last.
	 *
	 * @param SettlementDocument $document Document.
	 * @return non-empty-list<SettlementDocument>
	 * @throws PayoutException `document_not_correctable` for a broken or too long chain; a failed read is a DatabaseException.
	 */
	private function chain_to( SettlementDocument $document ): array {
		$chain = array( $document );

		while ( SettlementDocument::TYPE_CORRECTION === $chain[0]->type ) {
			$previous = null === $chain[0]->corrects_document_id ? null : $this->documents->find( $chain[0]->corrects_document_id );

			if ( null === $previous || $previous->payout_id !== $document->payout_id || count( $chain ) > SettlementCorrection::MAX_CHAIN ) {
				throw new PayoutException( PayoutException::DOCUMENT_NOT_CORRECTABLE );
			}

			array_unshift( $chain, $previous );
		}

		return $chain;
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
		for ( $i = 0; null !== $next && $i < SettlementCorrection::MAX_CHAIN; $i++ ) {
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
		// A correction document is numbered after its original (K10); only settlement documents take the next number.
		$number = isset( $values['number'] ) ? (string) $values['number'] : self::SERIES . '-' . str_pad( (string) $this->documents->next_number( self::SERIES, $connection ), self::DIGITS, '0', STR_PAD_LEFT );
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
		// Template version 4 (ADR-062 K09): the master data as registered now; '' and [] when not registered.
		$billing = $creator_id > 0 && null !== $this->billing ? $this->billing->find( $creator_id ) : new CreatorBillingDetails();

		return array(
			// Template version 2: the note on tax and reporting replaces the preliminary mark.
			'preliminary'      => false,
			'creator'          => array(
				'id'             => $creator_id,
				'name'           => null === $creator ? '' : $creator->display_name,
				'vat_registered' => $vat,
				'legal_name'     => $billing->legal_name,
				'address'        => $billing->address_lines(),
				'cvr'            => $billing->cvr,
			),
			'company'          => array(
				'name'    => (string) get_bloginfo( 'name' ),
				'cvr'     => Settings::company_cvr(),
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
