<?php
/**
 * The PDF of a settlement document.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use DateTimeImmutable;
use InvalidArgumentException;
use MerchBoard\Core\Pdf\PdfWriter;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an issued settlement document (ADR-060) as an A4 PDF in an
 * invoice-like layout. The PDF is a view of the stored, immutable document:
 * it is made on every download and never stored, and every value comes from
 * the document, its settled rows and its snapshot; nothing is recalculated
 * from the ledger, the settings or the creator.
 *
 * Before anything is drawn the document must add up (fail closed): the
 * settled rows' commission and profit must equal the document's, the
 * closing balance must equal opening + commission − paid out, and from
 * template version 3 the stored VAT on the commission must equal the one the
 * settled rows give at the stored rate. Otherwise, or for an unknown template
 * version, no PDF is made.
 *
 * The template version decides the texts: version 1 keeps its original mark
 * "Preliminary, not approved by an auditor or SKAT." (060-9); version 2
 * carries the neutral note on tax and reporting instead; version 3 also shows
 * the VAT on the commission (CommissionVat, ADR-062 K05 and K12) when VAT was
 * calculated on part of it, separate from the VAT on the sale. Documents of
 * versions 1 and 2 never show VAT on the commission. Version 4 also shows the
 * master data of both parties (ADR-062 K09): the issuer's CVR number and the
 * creator's legal name, address and CVR/SE number, each "Not registered"
 * when missing at issue; earlier versions never show them. Fees and
 * withholdings are never shown: the data model has none.
 *
 * A correction document with amounts (template version 5, ADR-062 K10 and
 * K15) has its own PDF (render_correction()): the reference to the original
 * and to the document it corrects (it must match its chain and stay within
 * the VAT categories of the original's rows), the parties as at the correction, the
 * amounts before and after and the difference, the unchanged amount paid
 * out, the reason and the note that nothing else changes and the difference
 * is not settled automatically. The original's PDF lists its corrections
 * with the corrected commission. Correction documents without amounts (D11 a)
 * have no PDF.
 *
 * Texts are in the site's language, so a document reads the same for the
 * creator and for an administrator; dates are in the site's time zone. The
 * same document, template and site settings always give the same bytes.
 */
final class SettlementPdf {

	/**
	 * Template versions this renderer knows.
	 */
	public const TEMPLATES = array( 1, 2, 3, 4 );

	/**
	 * The first template version with the master data of both parties
	 * (ADR-062 K09).
	 */
	public const MASTER_DATA_TEMPLATE = 4;

	private const MARGIN = 48.0;

	private const RIGHT = PdfWriter::PAGE_WIDTH - self::MARGIN;

	/**
	 * Lowest baseline of the content; the footer is below it.
	 */
	private const BOTTOM = PdfWriter::PAGE_HEIGHT - 70.0;

	private const BODY = 9.0;

	private const SMALL = 8.0;

	/**
	 * Columns of the rows table: key => [width, right aligned].
	 */
	private const COLUMNS = array(
		'order'      => array( 52.0, false ),
		'product'    => array( 150.0, false ),
		'type'       => array( 62.0, false ),
		'quantity'   => array( 36.0, true ),
		'net'        => array( 66.0, true ),
		'tax'        => array( 66.0, true ),
		'commission' => array( 67.28, true ),
	);

	/**
	 * The document being written.
	 *
	 * @var PdfWriter
	 */
	private PdfWriter $pdf;

	/**
	 * Current height on the page, from the top.
	 *
	 * @var float
	 */
	private float $y = 0.0;

	/**
	 * Decimals shown for all amounts of the document (2, or 4 when any amount needs them).
	 *
	 * @var int
	 */
	private int $decimals = 2;

	/**
	 * Decimal point of the site's locale.
	 *
	 * @var string
	 */
	private string $decimal_point = '.';

	/**
	 * Thousands separator of the site's locale.
	 *
	 * @var string
	 */
	private string $thousands = ',';

	/**
	 * Running header of continuation pages.
	 *
	 * @var string
	 */
	private string $continued = '';

	/**
	 * The PDF of a settlement document.
	 *
	 * @param array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>} $shown Document, settled rows and corrections (SettlementDocuments::for_payout()).
	 * @throws UnexpectedValueException When the document does not add up, is not a settlement document or has an unknown template version.
	 */
	public function render( array $shown ): string {
		$document = $shown['document'];
		$verified = self::verify( $document, $shown['rows'] );
		$switched = switch_to_locale( get_locale() );

		try {
			// Corrections with amounts are listed with them only when they match the chain.
			$corrections = array_map(
				static fn( SettlementDocument $correction ): array => array(
					'document' => $correction,
					'amounts'  => self::verified_correction( $shown, $correction ),
				),
				$shown['corrections']
			);

			return $this->layout( $document, $verified, $corrections );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * The PDF of a correction document with amounts (rettelsesbilag, ADR-062
	 * K10 and K15, template version 5) of a settlement document's chain.
	 * Before anything is drawn its stored correction must add up, the amounts
	 * before must be the ones in force at the document it corrects, and its
	 * references must match the chain (fail closed).
	 *
	 * @param array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>} $shown      The original with its rows and corrections (SettlementDocuments::for_payout()).
	 * @param SettlementDocument                                                                                           $correction One of its corrections.
	 * @throws UnexpectedValueException When the correction does not add up or does not match the chain.
	 */
	public function render_correction( array $shown, SettlementDocument $correction ): string {
		$verified = self::verify_correction( $shown, $correction );
		$switched = switch_to_locale( get_locale() );

		try {
			return $this->correction_layout( $shown['document'], $correction, $verified );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * The correction document with amounts of a chain by its suffix (`K1`),
	 * or null when there is none (also for a correction without amounts).
	 *
	 * @param array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>} $shown  The original with its corrections.
	 * @param string                                                                                                       $suffix Suffix, e.g. K1.
	 */
	public static function correction( array $shown, string $suffix ): ?SettlementDocument {
		if ( 1 !== preg_match( '/^K[1-9]\d{0,2}$/', $suffix ) ) {
			return null;
		}

		foreach ( $shown['corrections'] as $correction ) {
			if ( $correction->number === $shown['document']->number . '-' . $suffix && $correction->template_version >= SettlementCorrection::TEMPLATE ) {
				return $correction;
			}
		}

		return null;
	}

	/**
	 * HTTP headers of a download: a PDF attachment named after the document
	 * number, never cached or sniffed.
	 *
	 * @param SettlementDocument $document Document.
	 * @return array<string, string>
	 */
	public static function headers( SettlementDocument $document ): array {
		$name = 1 === preg_match( '/^[A-Z]{1,10}-\d{1,12}(?:-K[1-9]\d{0,2})?$/', $document->number ) ? $document->number : 'settlement-document';

		return array(
			'Content-Type'           => 'application/pdf',
			'Content-Disposition'    => 'attachment; filename="' . $name . '.pdf"',
			'Cache-Control'          => 'no-store, private',
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * Whether a document carries the original preliminary mark (060-9):
	 * template version 1. Later templates carry the note on tax and
	 * reporting.
	 *
	 * @param SettlementDocument $document Document.
	 */
	public static function preliminary( SettlementDocument $document ): bool {
		return $document->template_version < 2;
	}

	/**
	 * The note on tax and reporting of template version 2 (also shown in the
	 * admin and the dashboard). General information, not legal advice.
	 *
	 * @return array{0: string, 1: string} Heading and text.
	 */
	public static function tax_note(): array {
		return array(
			__( 'Tax and reporting', 'merchboard' ),
			__( 'You are responsible for finding out about and meeting your own tax and reporting obligations to the Danish Tax Agency. Depending on your situation, this may include whether the amount must be included in your taxable income and whether you have VAT obligations. This document shows the payout and its basis according to the information registered in the system. It is not tax advice and has not been approved by an auditor or the Danish Tax Agency.', 'merchboard' ),
		);
	}

	/**
	 * Checks the document against its rows and parses them (fail closed).
	 *
	 * @param SettlementDocument         $document Document.
	 * @param list<array<string, mixed>> $rows     Settled rows as stored.
	 * @return array{rows: list<array{order_id: int, item_name: string, type: string, quantity: int, net: int, tax: int, payout: int}>, net: int, tax: int, commission_vat: array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int}|null}
	 * @throws UnexpectedValueException When anything does not add up.
	 */
	private static function verify( SettlementDocument $document, array $rows ): array {
		if ( SettlementDocument::TYPE_SETTLEMENT !== $document->type ) {
			throw new UnexpectedValueException( 'Only a settlement document has a PDF.' );
		}

		if ( ! in_array( $document->template_version, self::TEMPLATES, true ) ) {
			throw new UnexpectedValueException( 'Unknown template version of the settlement document.' );
		}

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $document->currency ) || '' === trim( $document->number ) ) {
			throw new UnexpectedValueException( 'Invalid settlement document.' );
		}

		$parsed     = array();
		$commission = 0;
		$profit     = 0;
		$net        = 0;
		$tax        = 0;

		try {
			foreach ( $rows as $row ) {
				$item = array(
					'order_id'  => self::integer( $row['order_id'] ?? null ),
					'item_name' => is_scalar( $row['item_name'] ?? null ) ? (string) $row['item_name'] : '',
					'type'      => is_scalar( $row['type'] ?? null ) ? (string) $row['type'] : '',
					'quantity'  => self::integer( $row['quantity'] ?? null ),
					'net'       => SalesAmount::parse( self::decimal( $row['net_amount'] ?? null ) ),
					'tax'       => SalesAmount::parse( self::decimal( $row['tax_amount'] ?? null ) ),
					'payout'    => SalesAmount::parse( self::decimal( $row['payout_amount'] ?? null ) ),
				);

				$commission = SalesAmount::add( $commission, $item['payout'] );
				$profit     = SalesAmount::add( $profit, SalesAmount::parse( self::decimal( $row['profit_amount'] ?? null ) ) );
				$net        = SalesAmount::add( $net, $item['net'] );
				$tax        = SalesAmount::add( $tax, $item['tax'] );
				$parsed[]   = $item;
			}

			$closing = SalesAmount::add( SalesAmount::add( SalesAmount::add( $document->opening_units, $document->commission_units ), $document->adjustment_units ), SalesAmount::negate( $document->payout_units ) );
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new UnexpectedValueException( 'A settled row of the settlement document is invalid.' );
		}

		if ( $commission !== $document->commission_units || $profit !== $document->profit_units || $closing !== $document->closing_units ) {
			throw new UnexpectedValueException( 'The settlement document does not add up.' );
		}

		// Template version 3: the stored VAT on the commission must be the one the rows give.
		$commission_vat = CommissionVat::of_document( $document );

		if ( null !== $commission_vat && CommissionVat::of_stored_rows( $rows, $commission_vat['rate_bps'] ) !== $commission_vat ) {
			throw new UnexpectedValueException( 'The VAT on the commission of the settlement document does not add up.' );
		}

		return array(
			'rows'           => $parsed,
			'net'            => $net,
			'tax'            => $tax,
			'commission_vat' => $commission_vat,
		);
	}

	/**
	 * Lays the document out.
	 *
	 * @phpstan-param list<array{document: SettlementDocument, amounts: array{before: array{commission: int}, after: array{commission: int}, difference: array{commission: int}}|null}> $corrections
	 *
	 * @phpstan-param array{rows: list<array{order_id: int, item_name: string, type: string, quantity: int, net: int, tax: int, payout: int}>, net: int, tax: int, commission_vat: array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int}|null} $verified
	 *
	 * @param SettlementDocument   $document    Document.
	 * @param array<string, mixed> $verified    Verified rows, totals and VAT on the commission (verify()).
	 * @param array<mixed>         $corrections Corrections, oldest first, with their verified amounts.
	 */
	private function layout( SettlementDocument $document, array $verified, array $corrections ): string {
		global $wp_locale;

		$format              = is_object( $wp_locale ) && is_array( $wp_locale->number_format ?? null ) ? $wp_locale->number_format : array();
		$this->decimal_point = self::separator( $format['decimal_point'] ?? '.', '.' );
		$this->thousands     = self::separator( $format['thousands_sep'] ?? ',', '' );
		$this->decimals      = self::decimals( $document, $verified, $corrections );
		/* translators: %s: document number. */
		$title = sprintf( __( 'Settlement document %s', 'merchboard' ), $document->number );
		/* translators: %s: title of the document. */
		$this->continued = sprintf( __( '%s (continued)', 'merchboard' ), $title );
		$this->pdf       = new PdfWriter( $title, $document->issued_at, $document->number );
		$this->pdf->add_page();
		$this->y = self::MARGIN;

		$this->header( $document );
		$this->parties( $document );
		$this->details( $document );
		$this->summary( $document );

		if ( null !== $verified['commission_vat'] && 0 !== $verified['commission_vat']['incl_units'] ) {
			$this->commission_vat( $document, $verified['commission_vat'] );
		}

		$this->rows( $document, $verified );
		$this->corrections( $corrections );

		if ( ! self::preliminary( $document ) ) {
			$this->note();
		}

		$this->footers( $document );

		return $this->pdf->output();
	}

	/**
	 * The verified correction of a correction document with amounts, or null
	 * when it has none or does not match its chain (then it is shown nowhere
	 * with amounts and has no PDF).
	 *
	 * @param array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>} $shown      The original with its rows and corrections.
	 * @param SettlementDocument                                                                                           $correction One of its corrections.
	 * @return array{original: string, corrects: string, sequence: int, rate_bps: int, decimals: int, before: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, after: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, difference: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, payout: int}|null
	 */
	public static function verified_correction( array $shown, SettlementDocument $correction ): ?array {
		try {
			return self::verify_correction( $shown, $correction );
		} catch ( UnexpectedValueException ) {
			return null;
		}
	}

	/**
	 * The master data of both parties on a correction document with amounts
	 * (ADR-062 K09 and K15), from its own snapshot as issued, never the
	 * creator's current data: the creator's legal name, address and CVR/SE
	 * number and the issuer's name, address and CVR number ('' and [] = not
	 * registered), and whether they differ from the document it corrects
	 * (`changed`, SettlementCorrection::same_parties()). Null for a
	 * correction document without amounts (D11 a) and for one that does not
	 * match its chain (fail closed, as its amounts).
	 *
	 * @param array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>} $shown      The original with its rows and corrections.
	 * @param SettlementDocument                                                                                           $correction One of its corrections.
	 * @return array{creator_legal_name: string, creator_address: list<string>, creator_cvr: string, company_name: string, company_address: list<string>, company_cvr: string, changed: bool}|null
	 */
	public static function correction_master_data( array $shown, SettlementDocument $correction ): ?array {
		$master = self::master_data( $correction );

		if ( null === $master || null === self::verified_correction( $shown, $correction ) ) {
			return null;
		}

		$previous = $shown['document'];

		foreach ( $shown['corrections'] as $item ) {
			if ( $item->id === $correction->id ) {
				break;
			}

			$previous = $item;
		}

		$company = is_array( $correction->snapshot['company'] ?? null ) ? $correction->snapshot['company'] : array();
		$address = is_array( $company['address'] ?? null ) ? array_values( array_filter( array_map( static fn( mixed $line ): string => self::text( $line ), $company['address'] ), static fn( string $line ): bool => '' !== $line ) ) : array();

		return array(
			'creator_legal_name' => $master['creator_legal_name'],
			'creator_address'    => $master['creator_address'],
			'creator_cvr'        => $master['creator_cvr'],
			'company_name'       => self::text( $company['name'] ?? '' ),
			'company_address'    => $address,
			'company_cvr'        => $master['company_cvr'],
			'changed'            => ! SettlementCorrection::same_parties( $previous->snapshot, $correction->snapshot ),
		);
	}

	/**
	 * Checks a correction document against its chain (fail closed).
	 *
	 * @param array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>} $shown      The original with its rows and corrections.
	 * @param SettlementDocument                                                                                           $correction One of its corrections.
	 * @return array{original: string, corrects: string, sequence: int, rate_bps: int, decimals: int, before: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, after: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, difference: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, payout: int}
	 * @throws UnexpectedValueException When anything does not match.
	 */
	private static function verify_correction( array $shown, SettlementDocument $correction ): array {
		$original = $shown['document'];

		if ( SettlementDocument::TYPE_SETTLEMENT !== $original->type || SettlementDocument::TYPE_CORRECTION !== $correction->type
			|| SettlementCorrection::TEMPLATE !== $correction->template_version || $correction->currency !== $original->currency
			|| 1 !== preg_match( '/^[A-Z]{3}$/', $correction->currency ) ) {
			throw new UnexpectedValueException( 'Not a correction document of this template version.' );
		}

		$chain = array( $original );
		$found = false;

		foreach ( $shown['corrections'] as $item ) {
			if ( $item->id === $correction->id ) {
				$found = true;
				break;
			}

			$chain[] = $item;
		}

		$stored      = SettlementCorrection::of_document( $correction );
		$predecessor = $chain[ count( $chain ) - 1 ];

		if ( ! $found || null === $stored ) {
			throw new UnexpectedValueException( 'The correction document does not belong to the chain.' );
		}

		$before = SettlementCorrection::in_force( $chain, $shown['rows'] );
		$rate   = $before['rate_bps'];
		unset( $before['rate_bps'] );

		if ( $stored['original'] !== $original->number || $stored['corrects'] !== $predecessor->number || $correction->corrects_document_id !== $predecessor->id
			|| count( $chain ) !== $stored['sequence'] || $original->payout_units !== $stored['payout'] || $rate !== $stored['rate_bps'] || $before !== $stored['before'] ) {
			throw new UnexpectedValueException( 'The correction document does not match its chain.' );
		}

		// Only the VAT categories of the original's rows (their VAT status at the sale).
		$categories = SettlementCorrection::categories( $shown['rows'] );

		if ( ! SettlementCorrection::within( $categories, $stored['before'] ) || ! SettlementCorrection::within( $categories, $stored['after'] ) ) {
			throw new UnexpectedValueException( 'The correction document has VAT categories its original does not have.' );
		}

		return $stored;
	}

	/**
	 * Lays a correction document out.
	 *
	 * @phpstan-param array{original: string, corrects: string, sequence: int, rate_bps: int, decimals: int, before: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, after: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, difference: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, payout: int} $stored
	 *
	 * @param SettlementDocument   $original   The original settlement document.
	 * @param SettlementDocument   $correction The correction document.
	 * @param array<string, mixed> $stored     Its verified correction.
	 */
	private function correction_layout( SettlementDocument $original, SettlementDocument $correction, array $stored ): string {
		global $wp_locale;

		$format              = is_object( $wp_locale ) && is_array( $wp_locale->number_format ?? null ) ? $wp_locale->number_format : array();
		$this->decimal_point = self::separator( $format['decimal_point'] ?? '.', '.' );
		$this->thousands     = self::separator( $format['thousands_sep'] ?? ',', '' );
		$this->decimals      = 2;

		foreach ( array_merge( array_values( $stored['before'] ), array_values( $stored['after'] ), array_values( $stored['difference'] ), array( $stored['payout'] ) ) as $units ) {
			if ( 0 !== $units % 100 ) {
				$this->decimals = 4;
			}
		}

		/* translators: %s: document number. */
		$title = sprintf( __( 'Correction document %s', 'merchboard' ), $correction->number );
		/* translators: %s: title of the document. */
		$this->continued = sprintf( __( '%s (continued)', 'merchboard' ), $title );
		$this->pdf       = new PdfWriter( $title, $correction->issued_at, $correction->number );
		$this->pdf->add_page();
		$this->y = self::MARGIN;

		$this->header( $correction, __( 'Correction document', 'merchboard' ) );
		/* translators: 1: number of the original settlement document, 2: its date of issue. */
		$this->paragraph( sprintf( __( 'Corrects settlement document %1$s of %2$s.', 'merchboard' ), $original->number, self::date( $original->issued_at ) ), self::BODY );

		if ( $stored['corrects'] !== $stored['original'] ) {
			/* translators: %s: number of the previous correction document. */
			$this->paragraph( sprintf( __( 'Replaces the corrected amounts of correction document %s.', 'merchboard' ), $stored['corrects'] ), self::BODY );
		}

		$this->y += 16;
		$this->parties( $correction );
		$this->details( $correction );
		$this->correction_amounts( $correction, $stored );

		$this->ensure( 40 );
		$this->pdf->text( self::MARGIN, $this->y, __( 'Reason', 'merchboard' ), 11, true );
		$this->y += 2;
		$this->paragraph( self::text( $correction->snapshot['correction_reason'] ?? '' ), self::BODY );
		$this->y += 16;

		$this->note();
		$this->footers( $correction );

		return $this->pdf->output();
	}

	/**
	 * The amounts before and after the correction and the difference.
	 *
	 * @phpstan-param array{original: string, corrects: string, sequence: int, rate_bps: int, decimals: int, before: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, after: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, difference: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, payout: int} $stored
	 *
	 * @param SettlementDocument   $correction The correction document.
	 * @param array<string, mixed> $stored     Its verified correction.
	 */
	private function correction_amounts( SettlementDocument $correction, array $stored ): void {
		$lines = array( array( 'commission', __( 'Commission in the period', 'merchboard' ), true ) );

		if ( 0 !== $stored['before']['incl'] || 0 !== $stored['after']['incl'] ) {
			$lines[] = array( 'excl', __( 'Commission excl. VAT', 'merchboard' ), false );
			/* translators: %s: VAT rate in percent, e.g. 25. */
			$lines[] = array( 'vat', sprintf( __( 'VAT (%s %%)', 'merchboard' ), CommissionVat::percent( $stored['rate_bps'], $this->decimal_point ) ), false );
			$lines[] = array( 'incl', __( 'Commission incl. VAT', 'merchboard' ), false );
		}

		if ( 0 !== $stored['before']['untaxed'] || 0 !== $stored['after']['untaxed'] ) {
			$lines[] = array( 'untaxed', __( 'Commission without VAT (not VAT registered when sold)', 'merchboard' ), false );
		}

		/* translators: 1: amount paid out, 2: currency. */
		$paid    = sprintf( __( 'Paid out with the original document (unchanged): %1$s %2$s', 'merchboard' ), $this->amount( $stored['payout'] ), $correction->currency );
		$note    = $this->pdf->wrap( __( 'This correction document does not change the original document, earlier correction documents or the completed payout. The difference is not settled automatically.', 'merchboard' ) . ' ' . __( 'The VAT follows the creator\'s VAT status at each sale the original settled, not the current status.', 'merchboard' ), self::RIGHT - self::MARGIN - 20, self::SMALL );
		$height  = ( count( $lines ) + 1 ) * 15 + count( $note ) * 10 + 30;
		$columns = array( 330.0, 420.0, self::RIGHT - 10 );

		$this->ensure( $height + 30 );
		$this->pdf->text( self::MARGIN, $this->y, __( 'Correction of the amounts', 'merchboard' ), 11, true );
		$this->y += 8;
		$this->pdf->rect( self::MARGIN, $this->y, self::RIGHT - self::MARGIN, $height, 0.94 );
		$y = $this->y + 16;

		foreach ( array( __( 'Before', 'merchboard' ), __( 'Corrected', 'merchboard' ), __( 'Difference', 'merchboard' ) ) as $i => $heading ) {
			$this->pdf->text( $columns[ $i ], $y, $heading . ' (' . $correction->currency . ')', self::SMALL, true, PdfWriter::RIGHT );
		}

		$y += 15;

		foreach ( $lines as list( $key, $label, $bold ) ) {
			$this->pdf->text( self::MARGIN + 10, $y, $label, self::BODY, $bold );

			foreach ( array( 'before', 'after', 'difference' ) as $i => $column ) {
				$this->pdf->text( $columns[ $i ], $y, $this->amount( $stored[ $column ][ $key ] ), self::BODY, $bold, PdfWriter::RIGHT );
			}

			$y += 15;
		}

		$this->pdf->text( self::MARGIN + 10, $y + 4, $paid, self::BODY );
		$y += 19;

		foreach ( $note as $line ) {
			$this->pdf->text( self::MARGIN + 10, $y, $line, self::SMALL );
			$y += 10;
		}

		$this->y += $height + 22;
	}

	/**
	 * Title, number, date, the preliminary mark of version 1 and the self-billing line.
	 *
	 * @param SettlementDocument $document Document.
	 * @param string|null        $heading  Title instead of "Settlement document".
	 */
	private function header( SettlementDocument $document, ?string $heading = null ): void {
		$company = is_array( $document->snapshot['company'] ?? null ) ? $document->snapshot['company'] : array();
		$creator = is_array( $document->snapshot['creator'] ?? null ) ? $document->snapshot['creator'] : array();
		$pdf     = $this->pdf;

		$pdf->text( self::MARGIN, $this->y + 18, $heading ?? __( 'Settlement document', 'merchboard' ), 20, true );
		$pdf->text( self::RIGHT, $this->y + 10, $document->number, 12, true, PdfWriter::RIGHT );
		$pdf->text( self::RIGHT, $this->y + 24, __( 'Issued', 'merchboard' ) . ': ' . self::date( $document->issued_at ), self::BODY, false, PdfWriter::RIGHT );
		$this->y += 40;

		if ( self::preliminary( $document ) ) {
			$mark = __( 'Preliminary, not approved by an auditor or SKAT.', 'merchboard' );
			$pdf->rect( self::MARGIN, $this->y, self::RIGHT - self::MARGIN, 20, 0.9 );
			$pdf->text( self::MARGIN + 8, $this->y + 13.5, $mark, self::BODY, true );
			$this->y += 30;
		}

		$master = self::master_data( $document );
		$name   = null === $master ? self::text( $creator['name'] ?? '' ) : $master['creator_name'];
		/* translators: 1: company name, 2: creator name. */
		$line = sprintf( __( 'Self-billing: issued by %1$s on behalf of %2$s.', 'merchboard' ), self::text( $company['name'] ?? '' ), $name );
		$this->paragraph( $line, self::BODY );
		$this->y += 4;
		$pdf->line( self::MARGIN, $this->y, self::RIGHT, $this->y, 0.75, 0.6 );
		$this->y += 16;
	}

	/**
	 * The issuer and the recipient side by side. A correction document's VAT
	 * status is the creator's when it was issued, not at the sales: it is
	 * labelled so (the VAT on its amounts follows the original's rows).
	 *
	 * @param SettlementDocument $document Document.
	 */
	private function parties( SettlementDocument $document ): void {
		$vat_label = SettlementDocument::TYPE_CORRECTION === $document->type
			? __( 'VAT registered when this correction was issued', 'merchboard' )
			: __( 'VAT registered at issue', 'merchboard' );
		$snapshot  = $document->snapshot;
		$company   = is_array( $snapshot['company'] ?? null ) ? $snapshot['company'] : array();
		$creator   = is_array( $snapshot['creator'] ?? null ) ? $snapshot['creator'] : array();
		$address   = is_array( $company['address'] ?? null ) ? array_map( static fn( mixed $line ): string => self::text( $line ), $company['address'] ) : array();
		$vat       = match ( $document->vat_registered ) {
			true    => __( 'Yes', 'merchboard' ),
			false   => __( 'No', 'merchboard' ),
			default => __( 'Unknown', 'merchboard' ),
		};
		$id = is_numeric( $creator['id'] ?? null ) ? (int) $creator['id'] : $document->creator_id;
		/* translators: %d: creator id. */
		$creator_id = sprintf( __( 'Creator ID: %d', 'merchboard' ), $id );

		$issuer    = array_merge( array( self::text( $company['name'] ?? '' ) ), $address );
		$recipient = array( self::text( $creator['name'] ?? '' ), $creator_id, $vat_label . ': ' . $vat );
		$master    = self::master_data( $document );

		// Template version 4 (ADR-062 K09): the master data of both parties; missing data is said to be missing.
		if ( null !== $master ) {
			$missing  = __( 'Not registered', 'merchboard' );
			$issuer[] = sprintf(
				/* translators: %s: CVR number or "Not registered". */
				__( 'CVR no.: %s', 'merchboard' ),
				'' === $master['company_cvr'] ? $missing : $master['company_cvr']
			);
			$recipient = array( $master['creator_name'] );

			if ( '' !== $master['creator_legal_name'] && $master['creator_legal_name'] !== $master['creator_display_name'] ) {
				/* translators: %s: the creator's display name. */
				$recipient[] = sprintf( __( 'Display name: %s', 'merchboard' ), $master['creator_display_name'] );
			}

			/* translators: %s: "Not registered". */
			$recipient   = array_merge( $recipient, array() === $master['creator_address'] ? array( sprintf( __( 'Address: %s', 'merchboard' ), $missing ) ) : $master['creator_address'] );
			$recipient[] = sprintf(
				/* translators: %s: CVR or SE number or "Not registered". */
				__( 'CVR/SE no.: %s', 'merchboard' ),
				'' === $master['creator_cvr'] ? $missing : $master['creator_cvr']
			);
			$recipient[] = $creator_id;
			$recipient[] = $vat_label . ': ' . $vat;
		}

		$left  = $this->block( self::MARGIN, __( 'Issued by', 'merchboard' ), $issuer );
		$right = $this->block( 310.0, __( 'Recipient', 'merchboard' ), $recipient );

		$this->y += max( $left, $right ) + 26;
	}

	/**
	 * The master data of both parties as issued (template version 4,
	 * ADR-062 K09), or null for documents of earlier template versions, which
	 * never show it. The creator's name is the legal name when one was
	 * registered, else the display name. '' and [] mean not registered at
	 * issue; nothing is filled in from current data.
	 *
	 * @param SettlementDocument $document Document.
	 * @return array{creator_name: string, creator_display_name: string, creator_legal_name: string, creator_address: list<string>, creator_cvr: string, company_cvr: string}|null
	 */
	public static function master_data( SettlementDocument $document ): ?array {
		if ( $document->template_version < self::MASTER_DATA_TEMPLATE ) {
			return null;
		}

		$creator = is_array( $document->snapshot['creator'] ?? null ) ? $document->snapshot['creator'] : array();
		$company = is_array( $document->snapshot['company'] ?? null ) ? $document->snapshot['company'] : array();
		$display = self::text( $creator['name'] ?? '' );
		$legal   = self::text( $creator['legal_name'] ?? '' );
		$address = is_array( $creator['address'] ?? null ) ? array_values( array_filter( array_map( static fn( mixed $line ): string => self::text( $line ), $creator['address'] ), static fn( string $line ): bool => '' !== $line ) ) : array();

		return array(
			'creator_name'         => '' === $legal ? $display : $legal,
			'creator_display_name' => $display,
			'creator_legal_name'   => $legal,
			'creator_address'      => $address,
			'creator_cvr'          => self::text( $creator['cvr'] ?? '' ),
			'company_cvr'          => self::text( $company['cvr'] ?? '' ),
		);
	}

	/**
	 * A labelled block of lines at the current height; the first line is bold.
	 *
	 * @param float         $x     Left edge.
	 * @param string        $label Label.
	 * @param array<string> $lines Lines.
	 * @return float Height used.
	 */
	private function block( float $x, string $label, array $lines ): float {
		$this->pdf->text( $x, $this->y + 8, $label, 7.5, true );
		$height = 12.0;

		foreach ( array_values( $lines ) as $i => $line ) {
			foreach ( $this->pdf->wrap( $line, 230, self::BODY, 0 === $i ) as $part ) {
				$height += 12;
				$this->pdf->text( $x, $this->y + $height, $part, self::BODY, 0 === $i );
			}
		}

		return $height;
	}

	/**
	 * Period, currency, payment method, destination and payout.
	 *
	 * @param SettlementDocument $document Document.
	 */
	private function details( SettlementDocument $document ): void {
		$payout = is_array( $document->snapshot['payout'] ?? null ) ? $document->snapshot['payout'] : array();
		$from   = null === $document->period_from ? __( 'Whole history', 'merchboard' ) : self::date( $document->period_from );
		$fields = array(
			__( 'Period', 'merchboard' )         => $from . ' – ' . self::date( $document->period_to ),
			__( 'Currency', 'merchboard' )       => $document->currency,
			__( 'Payment method', 'merchboard' ) => self::method( self::text( $payout['method'] ?? '' ) ),
		);
		$hint   = self::text( $payout['destination_hint'] ?? '' );

		if ( '' !== $hint ) {
			$fields[ __( 'Paid to', 'merchboard' ) ] = $hint;
		}

		if ( null !== $document->payout_id ) {
			$fields[ __( 'Payout ID', 'merchboard' ) ] = '#' . $document->payout_id;
		}

		foreach ( $fields as $label => $value ) {
			$this->pdf->text( self::MARGIN, $this->y, $label, self::BODY, true );
			$this->pdf->text( self::MARGIN + 110, $this->y, $value, self::BODY );
			$this->y += 13;
		}

		$this->y += 10;
	}

	/**
	 * The settlement: carried in + commission (+ adjustments) − paid out =
	 * carried forward. The adjustments line (ADR-063) is shown only when the
	 * document settled any, with the references of their recalculations.
	 *
	 * @param SettlementDocument $document Document.
	 */
	private function summary( SettlementDocument $document ): void {
		$lines = array(
			array( '', __( 'Carried from earlier', 'merchboard' ), $document->opening_units ),
			array( '+', __( 'Commission in the period', 'merchboard' ), $document->commission_units ),
		);

		if ( 0 !== $document->adjustment_units ) {
			/* translators: %s: references of the recalculations, e.g. R2-000001. */
			$lines[] = array( '+', sprintf( __( 'Adjustments (%s)', 'merchboard' ), implode( ', ', self::adjustment_references( $document ) ) ), $document->adjustment_units );
		}

		$lines[] = array( '-', __( 'Paid out', 'merchboard' ), $document->payout_units );
		$height  = ( count( $lines ) + 1 ) * 15 + 18;
		$left    = 300.0;

		$this->ensure( $height + 10 );
		$this->pdf->rect( $left, $this->y, self::RIGHT - $left, $height, 0.94 );
		$y = $this->y + 16;

		foreach ( $lines as $line ) {
			$this->pdf->text( $left + 10, $y, $line[0], self::BODY );
			$this->pdf->text( $left + 22, $y, $line[1], self::BODY );
			$this->pdf->text( self::RIGHT - 10, $y, $this->money( $line[2], $document->currency ), self::BODY, false, PdfWriter::RIGHT );
			$y += 15;
		}

		$this->pdf->line( $left + 10, $y - 9, self::RIGHT - 10, $y - 9, 0.75, 0.3 );
		$y += 3;
		$this->pdf->text( $left + 10, $y, '=', self::BODY, true );
		$this->pdf->text( $left + 22, $y, __( 'Carried forward', 'merchboard' ), self::BODY, true );
		$this->pdf->text( self::RIGHT - 10, $y, $this->money( $document->closing_units, $document->currency ), self::BODY, true, PdfWriter::RIGHT );

		$this->y += $height + 22;
	}

	/**
	 * The distinct references of the adjustments a document settled.
	 *
	 * @param SettlementDocument $document Document.
	 * @return list<string>
	 */
	public static function adjustment_references( SettlementDocument $document ): array {
		$entries    = is_array( $document->snapshot['adjustments'] ?? null ) ? $document->snapshot['adjustments'] : array();
		$references = array();

		foreach ( $entries as $entry ) {
			$reference = is_array( $entry ) && is_string( $entry['reference'] ?? null ) ? self::text( $entry['reference'] ) : '';

			if ( '' !== $reference ) {
				$references[ $reference ] = true;
			}
		}

		return array_keys( $references );
	}

	/**
	 * VAT on the commission (template version 3, ADR-062 K05 and K12), shown
	 * only when VAT was calculated on part of the commission: commission excl.
	 * VAT, the rate, the VAT and the commission incl. VAT, and the commission
	 * without VAT from sales while the creator was not VAT registered. Separate
	 * from the VAT on the sale in the rows table.
	 *
	 * @param SettlementDocument                                                                         $document Document.
	 * @param array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int} $split    Verified split.
	 */
	private function commission_vat( SettlementDocument $document, array $split ): void {
		$lines = array(
			array( __( 'Commission excl. VAT', 'merchboard' ), $split['excl_units'], false ),
			/* translators: %s: VAT rate in percent, e.g. 25. */
			array( sprintf( __( 'VAT (%s %%)', 'merchboard' ), CommissionVat::percent( $split['rate_bps'], $this->decimal_point ) ), $split['vat_units'], false ),
			array( __( 'Commission incl. VAT', 'merchboard' ), $split['incl_units'], true ),
		);

		if ( 0 !== $split['untaxed_units'] ) {
			$lines[] = array( __( 'Commission without VAT (not VAT registered when sold)', 'merchboard' ), $split['untaxed_units'], false );
		}

		$note = $this->pdf->wrap( __( 'The commission in the period includes this VAT. It is separate from the VAT on the sale.', 'merchboard' ), self::RIGHT - self::MARGIN - 20, self::SMALL );

		$this->ensure( count( $lines ) * 15 + count( $note ) * 10 + 46 );
		$this->pdf->text( self::MARGIN, $this->y, __( 'VAT on the commission', 'merchboard' ), 11, true );
		$this->y += 8;
		$height   = count( $lines ) * 15 + count( $note ) * 10 + 14;
		$this->pdf->rect( self::MARGIN, $this->y, self::RIGHT - self::MARGIN, $height, 0.94 );
		$y = $this->y + 16;

		foreach ( $lines as list( $label, $units, $bold ) ) {
			$this->pdf->text( self::MARGIN + 10, $y, $label, self::BODY, $bold );
			$this->pdf->text( self::RIGHT - 10, $y, $this->money( $units, $document->currency ), self::BODY, $bold, PdfWriter::RIGHT );
			$y += 15;
		}

		foreach ( $note as $line ) {
			$this->pdf->text( self::MARGIN + 10, $y, $line, self::SMALL );
			$y += 10;
		}

		$this->y += $height + 22;
	}

	/**
	 * The settled rows with totals; the header repeats on every page.
	 *
	 * @phpstan-param array{rows: list<array{order_id: int, item_name: string, type: string, quantity: int, net: int, tax: int, payout: int}>, net: int, tax: int, commission_vat: array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int}|null} $verified
	 *
	 * @param SettlementDocument   $document Document.
	 * @param array<string, mixed> $verified Verified rows and totals.
	 */
	private function rows( SettlementDocument $document, array $verified ): void {
		$this->ensure( 60 );
		$this->pdf->text( self::MARGIN, $this->y, __( 'Settled sales', 'merchboard' ), 11, true );
		$this->y += 10;
		$this->table_header( $document->currency );

		if ( array() === $verified['rows'] ) {
			$this->pdf->text( self::MARGIN + 3, $this->y + 14, __( 'No sales rows were settled with this document.', 'merchboard' ), self::BODY );
			$this->y += 30;
			return;
		}

		foreach ( $verified['rows'] as $row ) {
			$cells  = array(
				'order'      => array( '#' . $row['order_id'] ),
				'product'    => $this->pdf->wrap( $row['item_name'], self::COLUMNS['product'][0] - 6, self::SMALL ),
				'type'       => array( self::row_type( $row['type'] ) ),
				'quantity'   => array( (string) $row['quantity'] ),
				'net'        => array( $this->amount( $row['net'] ) ),
				'tax'        => array( $this->amount( $row['tax'] ) ),
				'commission' => array( $this->amount( $row['payout'] ) ),
			);
			$height = count( $cells['product'] ) * 10 + 8;

			if ( $this->y + $height > self::BOTTOM ) {
				$this->new_page();
				$this->table_header( $document->currency );
			}

			$this->cells( $cells, false );
			$this->y += $height;
			$this->pdf->line( self::MARGIN, $this->y, self::RIGHT, $this->y, 0.25, 0.8 );
		}

		$this->ensure( 24 );
		$this->cells(
			array(
				'order'      => array( __( 'Total', 'merchboard' ) ),
				'product'    => array( '' ),
				'type'       => array( '' ),
				'quantity'   => array( '' ),
				'net'        => array( $this->amount( $verified['net'] ) ),
				'tax'        => array( $this->amount( $verified['tax'] ) ),
				'commission' => array( $this->amount( $document->commission_units ) ),
			),
			true
		);
		$this->y += 18;
		$this->pdf->line( self::MARGIN, $this->y, self::RIGHT, $this->y, 0.75, 0.3 );
		$this->y += 18;
	}

	/**
	 * The shaded header row of the rows table.
	 *
	 * @param string $currency Currency of the amounts.
	 */
	private function table_header( string $currency ): void {
		$this->pdf->rect( self::MARGIN, $this->y, self::RIGHT - self::MARGIN, 18, 0.88 );
		$this->cells(
			array(
				'order'      => array( __( 'Order', 'merchboard' ) ),
				'product'    => array( __( 'Product', 'merchboard' ) ),
				'type'       => array( __( 'Type', 'merchboard' ) ),
				'quantity'   => array( __( 'Quantity', 'merchboard' ) ),
				'net'        => array( __( 'Net', 'merchboard' ) . ' (' . $currency . ')' ),
				'tax'        => array( __( 'VAT on the sale', 'merchboard' ) ),
				'commission' => array( __( 'Commission', 'merchboard' ) ),
			),
			true
		);
		$this->y += 18;
	}

	/**
	 * One table row of lines per column; the current height is the row's top.
	 *
	 * @param array<string, list<string>> $cells Lines by column key.
	 * @param bool                        $bold  Bold text.
	 */
	private function cells( array $cells, bool $bold ): void {
		$x = self::MARGIN;

		foreach ( self::COLUMNS as $key => list( $width, $right ) ) {
			foreach ( $cells[ $key ] ?? array() as $i => $line ) {
				$this->pdf->text( $right ? $x + $width - 3 : $x + 3, $this->y + 12 + $i * 10, $line, self::SMALL, $bold, $right ? PdfWriter::RIGHT : PdfWriter::LEFT );
			}

			$x += $width;
		}
	}

	/**
	 * The correction documents of the chain, if any.
	 *
	 * @phpstan-param list<array{document: SettlementDocument, amounts: array{before: array{commission: int}, after: array{commission: int}, difference: array{commission: int}}|null}> $corrections
	 *
	 * @param array<mixed> $corrections Corrections, oldest first, with their verified amounts (null = listed without).
	 */
	private function corrections( array $corrections ): void {
		if ( array() === $corrections ) {
			return;
		}

		$this->ensure( 40 );
		$this->pdf->text( self::MARGIN, $this->y, __( 'Corrections', 'merchboard' ), 11, true );
		$this->y += 16;

		foreach ( $corrections as $entry ) {
			$correction = $entry['document'];
			$stored     = $entry['amounts'];
			$reason     = self::text( $correction->snapshot['correction_reason'] ?? '' );
			/* translators: 1: number of the correction document, 2: date, 3: reason. */
			$this->paragraph( sprintf( __( 'Corrected by %1$s (%2$s): %3$s', 'merchboard' ), $correction->number, self::date( $correction->issued_at ), $reason ), self::BODY );

			if ( null !== $stored ) {
				/* translators: 1: commission before the correction, 2: corrected commission, 3: difference, 4: currency. */
				$this->paragraph( sprintf( __( 'Commission in the period corrected from %1$s to %2$s %4$s (difference %3$s %4$s); see the correction document.', 'merchboard' ), $this->amount( $stored['before']['commission'] ), $this->amount( $stored['after']['commission'] ), $this->amount( $stored['difference']['commission'] ), $correction->currency ), self::SMALL );
			}

			$this->y += 4;
		}

		$this->y += 10;
	}

	/**
	 * The note on tax and reporting (template version 2).
	 */
	private function note(): void {
		list( $heading, $text ) = self::tax_note();
		$lines                  = $this->pdf->wrap( $text, self::RIGHT - self::MARGIN, self::BODY );

		$this->ensure( count( $lines ) * 12 + 30 );
		$this->pdf->line( self::MARGIN, $this->y, self::RIGHT, $this->y, 0.5, 0.6 );
		$this->y += 16;
		$this->pdf->text( self::MARGIN, $this->y, $heading, 10, true );
		$this->y += 14;

		foreach ( $lines as $line ) {
			$this->pdf->text( self::MARGIN, $this->y, $line, self::BODY );
			$this->y += 12;
		}
	}

	/**
	 * Footers with the page numbers, once every page is known.
	 *
	 * @param SettlementDocument $document Document.
	 */
	private function footers( SettlementDocument $document ): void {
		$count = $this->pdf->page_count();
		/* translators: %d: template version of the document. */
		$text = sprintf( __( 'Created automatically from the data registered in the system. Template version %d.', 'merchboard' ), $document->template_version );

		for ( $i = 0; $i < $count; $i++ ) {
			$this->pdf->select_page( $i );
			$this->pdf->line( self::MARGIN, self::BOTTOM + 22, self::RIGHT, self::BOTTOM + 22, 0.5, 0.6 );
			$this->pdf->text( self::MARGIN, self::BOTTOM + 34, $text, 7 );
			/* translators: 1: page number, 2: number of pages. */
			$this->pdf->text( self::RIGHT, self::BOTTOM + 34, sprintf( __( 'Page %1$d of %2$d', 'merchboard' ), $i + 1, $count ), 7, false, PdfWriter::RIGHT );
		}
	}

	/**
	 * A wrapped paragraph over the content width.
	 *
	 * @param string $text Text.
	 * @param float  $size Font size.
	 */
	private function paragraph( string $text, float $size ): void {
		foreach ( $this->pdf->wrap( $text, self::RIGHT - self::MARGIN, $size ) as $line ) {
			$this->ensure( 14 );
			$this->y += 12;
			$this->pdf->text( self::MARGIN, $this->y, $line, $size );
		}
	}

	/**
	 * Starts a new page when $height does not fit any more.
	 *
	 * @param float $height Height needed.
	 */
	private function ensure( float $height ): void {
		if ( $this->y + $height > self::BOTTOM ) {
			$this->new_page();
		}
	}

	/**
	 * A new page with a short running header.
	 */
	private function new_page(): void {
		$this->pdf->add_page();
		$this->pdf->text( self::MARGIN, self::MARGIN, $this->continued, self::BODY, true );
		$this->pdf->line( self::MARGIN, self::MARGIN + 8, self::RIGHT, self::MARGIN + 8, 0.5, 0.6 );
		$this->y = self::MARGIN + 28;
	}

	/**
	 * An amount with its currency.
	 *
	 * @param int    $units    Amount in units.
	 * @param string $currency Currency.
	 */
	private function money( int $units, string $currency ): string {
		return $this->amount( $units ) . ' ' . $currency;
	}

	/**
	 * An amount with the document's decimals and the site's separators,
	 * never rounded: two decimals only when every amount of the document has
	 * at most two.
	 *
	 * @param int $units Amount in units.
	 */
	private function amount( int $units ): string {
		$negative = $units < 0;
		$digits   = (string) ( $negative ? SalesAmount::negate( $units ) : $units );
		$digits   = str_pad( $digits, 5, '0', STR_PAD_LEFT );
		$whole    = substr( $digits, 0, -4 );
		$fraction = substr( substr( $digits, -4 ), 0, $this->decimals );
		// Groups of three from the right; reversing twice keeps a multibyte separator intact.
		$grouped = strrev( implode( strrev( $this->thousands ), str_split( strrev( $whole ), 3 ) ) );

		return ( $negative ? '-' : '' ) . $grouped . $this->decimal_point . $fraction;
	}

	/**
	 * 2 when every amount of the document is whole cents, else 4.
	 *
	 * @phpstan-param array{rows: list<array{order_id: int, item_name: string, type: string, quantity: int, net: int, tax: int, payout: int}>, net: int, tax: int, commission_vat: array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int}|null} $verified
	 *
	 * @phpstan-param list<array{document: SettlementDocument, amounts: array{before: array{commission: int}, after: array{commission: int}, difference: array{commission: int}}|null}> $corrections
	 *
	 * @param SettlementDocument   $document    Document.
	 * @param array<string, mixed> $verified    Verified rows, totals and VAT on the commission.
	 * @param array<mixed>         $corrections Its corrections with their verified amounts (their commission is listed).
	 */
	private static function decimals( SettlementDocument $document, array $verified, array $corrections = array() ): int {
		$amounts = array( $document->opening_units, $document->commission_units, $document->payout_units, $document->closing_units, $verified['net'], $verified['tax'] );

		if ( null !== $verified['commission_vat'] ) {
			array_push( $amounts, $verified['commission_vat']['incl_units'], $verified['commission_vat']['vat_units'], $verified['commission_vat']['excl_units'], $verified['commission_vat']['untaxed_units'] );
		}

		foreach ( $corrections as $entry ) {
			$stored = $entry['amounts'];

			if ( null !== $stored ) {
				array_push( $amounts, $stored['before']['commission'], $stored['after']['commission'], $stored['difference']['commission'] );
			}
		}

		foreach ( $verified['rows'] as $row ) {
			array_push( $amounts, $row['net'], $row['tax'], $row['payout'] );
		}

		foreach ( $amounts as $amount ) {
			if ( 0 !== $amount % 100 ) {
				return 4;
			}
		}

		return 2;
	}

	/**
	 * A date in the site's time zone (ISO, unambiguous in every language).
	 *
	 * @param DateTimeImmutable $time Time (UTC).
	 */
	private static function date( DateTimeImmutable $time ): string {
		return (string) wp_date( 'Y-m-d', $time->getTimestamp() );
	}

	/**
	 * Label of a payout method.
	 *
	 * @param string $method Method value.
	 */
	private static function method( string $method ): string {
		return match ( $method ) {
			'bank'      => __( 'Bank transfer', 'merchboard' ),
			'paypal'    => __( 'PayPal', 'merchboard' ),
			'gift_card' => __( 'Gift card', 'merchboard' ),
			default     => __( 'Unknown', 'merchboard' ),
		};
	}

	/**
	 * Label of a settled row's type.
	 *
	 * @param string $type Ledger row type.
	 */
	private static function row_type( string $type ): string {
		return match ( $type ) {
			'sale'     => __( 'Sale', 'merchboard' ),
			'refund'   => __( 'Refund', 'merchboard' ),
			'reversal' => __( 'Reversal', 'merchboard' ),
			default    => __( 'Correction', 'merchboard' ),
		};
	}

	/**
	 * A number separator of the locale as plain text.
	 *
	 * @param mixed  $value    Separator from WP_Locale (may be an HTML entity).
	 * @param string $fallback Fallback.
	 */
	private static function separator( mixed $value, string $fallback ): string {
		return is_string( $value ) ? html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $fallback;
	}

	/**
	 * A snapshot value as text ('' when it is not scalar).
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A stored integer.
	 *
	 * @param mixed $value Value.
	 * @throws InvalidArgumentException When it is not an integer.
	 */
	private static function integer( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,18}$/', $value ) ) {
			return (int) $value;
		}

		throw new InvalidArgumentException( 'Invalid integer.' );
	}

	/**
	 * A stored decimal amount.
	 *
	 * @param mixed $value Value.
	 * @throws InvalidArgumentException When it is not a decimal string.
	 */
	private static function decimal( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Invalid amount.' );
		}

		return $value;
	}
}
