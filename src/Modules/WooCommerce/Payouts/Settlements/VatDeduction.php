<?php
/**
 * VAT deducted from the payout of sales while not VAT registered (ADR-064).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Only a creator who is VAT registered may be paid amounts including VAT
 * (ADR-064). A settled row of a sale while the creator was not VAT registered
 * (the row's status at the sale, ADR-062 pkt. 3) carries its profit (the share
 * of the sale price including VAT) and its payout (the same share of the sale
 * price excluding the sale's actual VAT, CreatorShare::payout()). The
 * document shows, for those rows together, the commission including VAT
 * (gross = Σ profit), the VAT deducted (gross − net) and what is paid out
 * (net = Σ payout). Rows of sales while VAT registered are not part of it.
 *
 * Issued from template version 6 with the amounts in the snapshot
 * (`vat_deduction`), so a later change never changes an issued document; the
 * PDF checks them against the settled rows (fail closed). The commission of
 * the document (Σ payout) is unchanged by this: it only explains it. Not
 * validated by an auditor or the Danish Tax Agency.
 */
final class VatDeduction {

	/**
	 * Version of this calculation in the snapshot.
	 */
	public const VERSION = 1;

	/**
	 * Snapshot key of the amounts.
	 */
	public const SNAPSHOT_KEY = 'vat_deduction';

	/**
	 * The first template version whose documents carry the deduction.
	 */
	public const TEMPLATE = 6;

	/**
	 * The deduction of settled rows.
	 *
	 * @phpstan-param list<array{profit_units: int, payout_units: int, vat_registered: bool}> $rows
	 *
	 * @param array[] $rows Settled rows: profit, payout, VAT status at the sale.
	 * @return array{gross_units: int, deduction_units: int, net_units: int}
	 * @throws OverflowException When an amount does not fit into an integer.
	 */
	public static function of_rows( array $rows ): array {
		$gross = 0;
		$net   = 0;

		foreach ( $rows as $row ) {
			if ( ! $row['vat_registered'] ) {
				$gross = SalesAmount::add( $gross, $row['profit_units'] );
				$net   = SalesAmount::add( $net, $row['payout_units'] );
			}
		}

		return array(
			'gross_units'     => $gross,
			'deduction_units' => SalesAmount::add( $gross, SalesAmount::negate( $net ) ),
			'net_units'       => $net,
		);
	}

	/**
	 * The deduction of settled rows as stored (`merchboard_settlement_document_rows`).
	 *
	 * @param list<array<string, mixed>> $rows Stored rows.
	 * @return array{gross_units: int, deduction_units: int, net_units: int}
	 * @throws UnexpectedValueException When a row is invalid.
	 */
	public static function of_stored_rows( array $rows ): array {
		$parsed = array();

		try {
			foreach ( $rows as $row ) {
				$vat    = $row['vat_registered'] ?? null;
				$profit = $row['profit_amount'] ?? null;
				$payout = $row['payout_amount'] ?? null;

				if ( ! in_array( $vat, array( true, false, 1, 0, '1', '0' ), true ) || ! is_string( $profit ) || ! is_string( $payout ) ) {
					throw new UnexpectedValueException( 'A settled row has no valid VAT status, profit or payout.' );
				}

				$parsed[] = array(
					'profit_units'   => SalesAmount::parse( $profit ),
					'payout_units'   => SalesAmount::parse( $payout ),
					'vat_registered' => in_array( $vat, array( true, 1, '1' ), true ),
				);
			}

			return self::of_rows( $parsed );
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new UnexpectedValueException( 'The VAT deducted cannot be calculated from the settled rows.' );
		}
	}

	/**
	 * The deduction as stored in a document's snapshot.
	 *
	 * @param array{gross_units: int, deduction_units: int, net_units: int} $deduction Deduction.
	 * @return array{version: int, gross: string, deduction: string, net: string}
	 */
	public static function snapshot( array $deduction ): array {
		return array(
			'version'   => self::VERSION,
			'gross'     => SalesAmount::format( $deduction['gross_units'] ),
			'deduction' => SalesAmount::format( $deduction['deduction_units'] ),
			'net'       => SalesAmount::format( $deduction['net_units'] ),
		);
	}

	/**
	 * The deduction stored with a document, or null when it has none
	 * (template version below 6, or not a settlement document).
	 *
	 * @param SettlementDocument $document Document.
	 * @return array{gross_units: int, deduction_units: int, net_units: int}|null
	 * @throws UnexpectedValueException When a document of template version 6 or later has no valid deduction.
	 */
	public static function of_document( SettlementDocument $document ): ?array {
		if ( SettlementDocument::TYPE_SETTLEMENT !== $document->type || $document->template_version < self::TEMPLATE ) {
			return null;
		}

		$stored = $document->snapshot[ self::SNAPSHOT_KEY ] ?? null;

		if ( ! is_array( $stored ) || self::VERSION !== ( $stored['version'] ?? null ) ) {
			throw new UnexpectedValueException( 'The settlement document has no valid VAT deduction.' );
		}

		try {
			$deduction = array(
				'gross_units'     => SalesAmount::parse( self::decimal( $stored['gross'] ?? null ) ),
				'deduction_units' => SalesAmount::parse( self::decimal( $stored['deduction'] ?? null ) ),
				'net_units'       => SalesAmount::parse( self::decimal( $stored['net'] ?? null ) ),
			);
		} catch ( InvalidArgumentException ) {
			throw new UnexpectedValueException( 'The settlement document has no valid VAT deduction.' );
		}

		if ( SalesAmount::add( $deduction['gross_units'], SalesAmount::negate( $deduction['deduction_units'] ) ) !== $deduction['net_units'] ) {
			throw new UnexpectedValueException( 'The VAT deduction of the settlement document does not add up.' );
		}

		return $deduction;
	}

	/**
	 * The deduction to show on a document: only when VAT was deducted. Null
	 * otherwise, also for a document whose stored deduction is invalid (the
	 * PDF refuses those).
	 *
	 * @param SettlementDocument $document Document.
	 * @return array{gross_units: int, deduction_units: int, net_units: int}|null
	 */
	public static function shown( SettlementDocument $document ): ?array {
		try {
			$deduction = self::of_document( $document );
		} catch ( UnexpectedValueException ) {
			return null;
		}

		return null === $deduction || 0 === $deduction['deduction_units'] ? null : $deduction;
	}

	/**
	 * A stored decimal amount.
	 *
	 * @param mixed $value Value.
	 * @throws InvalidArgumentException When it is not a string.
	 */
	private static function decimal( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Invalid amount.' );
		}

		return $value;
	}
}
