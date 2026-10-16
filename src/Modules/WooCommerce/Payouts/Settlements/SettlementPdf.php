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
 * settled rows' commission and profit must equal the document's, and the
 * closing balance must equal opening + commission − paid out. Otherwise, or
 * for an unknown template version, no PDF is made.
 *
 * The template version decides the texts: version 1 keeps its original mark
 * "Preliminary, not approved by an auditor or SKAT." (060-9); version 2
 * carries the neutral note on tax and reporting instead. Fees, withholdings
 * and VAT on the commission are never shown: the data model has none.
 *
 * Texts are in the site's language, so a document reads the same for the
 * creator and for an administrator; dates are in the site's time zone. The
 * same document, template and site settings always give the same bytes.
 */
final class SettlementPdf {

	/**
	 * Template versions this renderer knows.
	 */
	public const TEMPLATES = array( 1, 2 );

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
			return $this->layout( $document, $verified, $shown['corrections'] );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * HTTP headers of a download: a PDF attachment named after the document
	 * number, never cached or sniffed.
	 *
	 * @param SettlementDocument $document Document.
	 * @return array<string, string>
	 */
	public static function headers( SettlementDocument $document ): array {
		$name = 1 === preg_match( '/^[A-Z]{1,10}-\d{1,12}$/', $document->number ) ? $document->number : 'settlement-document';

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
	 * @return array{rows: list<array{order_id: int, item_name: string, type: string, quantity: int, net: int, tax: int, payout: int}>, net: int, tax: int}
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

			$closing = SalesAmount::add( SalesAmount::add( $document->opening_units, $document->commission_units ), SalesAmount::negate( $document->payout_units ) );
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new UnexpectedValueException( 'A settled row of the settlement document is invalid.' );
		}

		if ( $commission !== $document->commission_units || $profit !== $document->profit_units || $closing !== $document->closing_units ) {
			throw new UnexpectedValueException( 'The settlement document does not add up.' );
		}

		return array(
			'rows' => $parsed,
			'net'  => $net,
			'tax'  => $tax,
		);
	}

	/**
	 * Lays the document out.
	 *
	 * @phpstan-param list<SettlementDocument> $corrections
	 *
	 * @param SettlementDocument                                                                                                                           $document    Document.
	 * @param array{rows: list<array{order_id: int, item_name: string, type: string, quantity: int, net: int, tax: int, payout: int}>, net: int, tax: int} $verified    Verified rows and totals.
	 * @param SettlementDocument[]                                                                                                                         $corrections Corrections, oldest first.
	 */
	private function layout( SettlementDocument $document, array $verified, array $corrections ): string {
		global $wp_locale;

		$format              = is_object( $wp_locale ) && is_array( $wp_locale->number_format ?? null ) ? $wp_locale->number_format : array();
		$this->decimal_point = self::separator( $format['decimal_point'] ?? '.', '.' );
		$this->thousands     = self::separator( $format['thousands_sep'] ?? ',', '' );
		$this->decimals      = self::decimals( $document, $verified );
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
		$this->rows( $document, $verified );
		$this->corrections( $corrections );

		if ( ! self::preliminary( $document ) ) {
			$this->note();
		}

		$this->footers( $document );

		return $this->pdf->output();
	}

	/**
	 * Title, number, date, the preliminary mark of version 1 and the self-billing line.
	 *
	 * @param SettlementDocument $document Document.
	 */
	private function header( SettlementDocument $document ): void {
		$company = is_array( $document->snapshot['company'] ?? null ) ? $document->snapshot['company'] : array();
		$creator = is_array( $document->snapshot['creator'] ?? null ) ? $document->snapshot['creator'] : array();
		$pdf     = $this->pdf;

		$pdf->text( self::MARGIN, $this->y + 18, __( 'Settlement document', 'merchboard' ), 20, true );
		$pdf->text( self::RIGHT, $this->y + 10, $document->number, 12, true, PdfWriter::RIGHT );
		$pdf->text( self::RIGHT, $this->y + 24, __( 'Issued', 'merchboard' ) . ': ' . self::date( $document->issued_at ), self::BODY, false, PdfWriter::RIGHT );
		$this->y += 40;

		if ( self::preliminary( $document ) ) {
			$mark = __( 'Preliminary, not approved by an auditor or SKAT.', 'merchboard' );
			$pdf->rect( self::MARGIN, $this->y, self::RIGHT - self::MARGIN, 20, 0.9 );
			$pdf->text( self::MARGIN + 8, $this->y + 13.5, $mark, self::BODY, true );
			$this->y += 30;
		}

		/* translators: 1: company name, 2: creator name. */
		$line = sprintf( __( 'Self-billing: issued by %1$s on behalf of %2$s.', 'merchboard' ), self::text( $company['name'] ?? '' ), self::text( $creator['name'] ?? '' ) );
		$this->paragraph( $line, self::BODY );
		$this->y += 4;
		$pdf->line( self::MARGIN, $this->y, self::RIGHT, $this->y, 0.75, 0.6 );
		$this->y += 16;
	}

	/**
	 * The issuer and the recipient side by side.
	 *
	 * @param SettlementDocument $document Document.
	 */
	private function parties( SettlementDocument $document ): void {
		$snapshot = $document->snapshot;
		$company  = is_array( $snapshot['company'] ?? null ) ? $snapshot['company'] : array();
		$creator  = is_array( $snapshot['creator'] ?? null ) ? $snapshot['creator'] : array();
		$address  = is_array( $company['address'] ?? null ) ? array_map( static fn( mixed $line ): string => self::text( $line ), $company['address'] ) : array();
		$vat      = match ( $document->vat_registered ) {
			true    => __( 'Yes', 'merchboard' ),
			false   => __( 'No', 'merchboard' ),
			default => __( 'Unknown', 'merchboard' ),
		};
		$id = is_numeric( $creator['id'] ?? null ) ? (int) $creator['id'] : $document->creator_id;
		/* translators: %d: creator id. */
		$creator_id = sprintf( __( 'Creator ID: %d', 'merchboard' ), $id );

		$left  = $this->block( self::MARGIN, __( 'Issued by', 'merchboard' ), array_merge( array( self::text( $company['name'] ?? '' ) ), $address ) );
		$right = $this->block( 310.0, __( 'Recipient', 'merchboard' ), array( self::text( $creator['name'] ?? '' ), $creator_id, __( 'VAT registered at issue', 'merchboard' ) . ': ' . $vat ) );

		$this->y += max( $left, $right ) + 26;
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
	 * The settlement: carried in + commission − paid out = carried forward.
	 *
	 * @param SettlementDocument $document Document.
	 */
	private function summary( SettlementDocument $document ): void {
		$lines  = array(
			array( '', __( 'Carried from earlier', 'merchboard' ), $document->opening_units ),
			array( '+', __( 'Commission in the period', 'merchboard' ), $document->commission_units ),
			array( '-', __( 'Paid out', 'merchboard' ), $document->payout_units ),
		);
		$height = 4 * 15 + 18;
		$left   = 300.0;

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
	 * The settled rows with totals; the header repeats on every page.
	 *
	 * @param SettlementDocument                                                                                                                           $document Document.
	 * @param array{rows: list<array{order_id: int, item_name: string, type: string, quantity: int, net: int, tax: int, payout: int}>, net: int, tax: int} $verified Verified rows and totals.
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
	 * @phpstan-param list<SettlementDocument> $corrections
	 *
	 * @param SettlementDocument[] $corrections Corrections, oldest first.
	 */
	private function corrections( array $corrections ): void {
		if ( array() === $corrections ) {
			return;
		}

		$this->ensure( 40 );
		$this->pdf->text( self::MARGIN, $this->y, __( 'Corrections', 'merchboard' ), 11, true );
		$this->y += 16;

		foreach ( $corrections as $correction ) {
			$reason = self::text( $correction->snapshot['correction_reason'] ?? '' );
			/* translators: 1: number of the correction document, 2: date, 3: reason. */
			$this->paragraph( sprintf( __( 'Corrected by %1$s (%2$s): %3$s', 'merchboard' ), $correction->number, self::date( $correction->issued_at ), $reason ), self::BODY );
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
	 * @param SettlementDocument                                                                                                                           $document Document.
	 * @param array{rows: list<array{order_id: int, item_name: string, type: string, quantity: int, net: int, tax: int, payout: int}>, net: int, tax: int} $verified Verified rows and totals.
	 */
	private static function decimals( SettlementDocument $document, array $verified ): int {
		$amounts = array( $document->opening_units, $document->commission_units, $document->payout_units, $document->closing_units, $verified['net'], $verified['tax'] );

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
