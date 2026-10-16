<?php
/**
 * Tests for SettlementPdf.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Payouts\Settlements;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Pdf\PdfWriter;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementPdf;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use UnexpectedValueException;

/**
 * Synthetic documents only; the site's number format is Danish ("1.234,56").
 */
final class SettlementPdfTest extends TestCase {

	/**
	 * Locale object before the test.
	 *
	 * @var mixed
	 */
	private mixed $locale = null;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'get_locale' )->justReturn( 'da_DK' );
		Functions\when( 'switch_to_locale' )->justReturn( false );
		Functions\when( 'restore_previous_locale' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		$this->locale         = $GLOBALS['wp_locale'] ?? null;
		$GLOBALS['wp_locale'] = (object) array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A stand-in with the Danish number format.
			'number_format' => array(
				'decimal_point' => ',',
				'thousands_sep' => '.',
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wp_locale'] = $this->locale; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the test's stand-in.
		parent::tearDown();
	}

	public function test_a_new_document_has_the_invoice_layout_and_the_note_on_tax_and_reporting(): void {
		$pdf = ( new SettlementPdf() )->render( $this->shown() );

		self::assertStringStartsWith( '%PDF-1.4', $pdf );
		foreach ( array( 'Settlement document', 'AFR-000007', 'Issued: 2026-11-30', 'Self-billing: issued by CCMerch ApS on behalf of Søren Ærø.', 'Issued by', 'Strandvejen 1', 'Recipient', 'Creator ID: 3', 'VAT registered at issue: Yes', 'Period', '2026-10-01 – 2026-11-30', 'Payment method', 'Bank transfer', 'Paid to', '•••• 5678', 'Payout ID', '#41', 'Carried from earlier', 'Commission in the period', 'Paid out', 'Carried forward', '1.005,00 DKK', '40,00 DKK', '25,00 DKK', '1.020,00 DKK', 'Settled sales', 'VAT on the sale', '#9101', 'Hættetrøje (sort)', 'Sale', 'Refund', 'Total', 'Tax and reporting', 'Page 1 of 1', 'Template version 2.' ) as $text ) {
			$this->assert_pdf_contains( $text, $pdf );
		}

		$this->assert_pdf_lacks( 'Preliminary, not approved by an auditor or SKAT.', $pdf );
		$this->assert_pdf_lacks( 'Fee', $pdf );
		self::assertStringContainsString( "S\xF8ren \xC6r\xF8", $pdf, 'Danish letters as WinAnsi bytes.' );
	}

	public function test_a_template_1_document_keeps_its_preliminary_mark(): void {
		$pdf = ( new SettlementPdf() )->render( $this->shown( array( 'template_version' => 1 ) ) );

		$this->assert_pdf_contains( 'Preliminary, not approved by an auditor or SKAT.', $pdf );
		$this->assert_pdf_contains( 'Template version 1.', $pdf );
		$this->assert_pdf_lacks( 'Tax and reporting', $pdf );
		self::assertTrue( SettlementPdf::preliminary( $this->shown( array( 'template_version' => 1 ) )['document'] ) );
		self::assertFalse( SettlementPdf::preliminary( $this->shown()['document'] ) );
	}

	public function test_the_same_document_always_gives_the_same_bytes(): void {
		$renderer = new SettlementPdf();

		self::assertSame( $renderer->render( $this->shown() ), ( new SettlementPdf() )->render( $this->shown() ) );
		self::assertSame( $renderer->render( $this->shown() ), $renderer->render( $this->shown() ), 'Also from the same renderer.' );
	}

	public function test_a_document_that_does_not_add_up_gives_no_pdf(): void {
		$cases = array(
			'commission'   => $this->shown( array( 'commission' => '41' ) ),
			'profit'       => $this->shown( array( 'profit' => '41' ) ),
			'closing'      => $this->shown( array( 'closing' => '1021' ) ),
			'invalid row'  => $this->shown( array(), array( 'payout_amount' => '20,00' ) ),
			'missing row'  => $this->shown( array(), array( 'payout_amount' => null ) ),
			'template 3'   => $this->shown( array( 'template_version' => 3 ) ),
			'correction'   => $this->shown( array( 'type' => SettlementDocument::TYPE_CORRECTION ) ),
			'bad currency' => $this->shown( array( 'currency' => 'dkk' ) ),
		);

		foreach ( $cases as $case => $shown ) {
			try {
				( new SettlementPdf() )->render( $shown );
				self::fail( 'Expected no PDF: ' . $case );
			} catch ( UnexpectedValueException $e ) {
				self::assertStringNotContainsString( '40', $e->getMessage(), 'No amounts in the message: ' . $case );
			}
		}
	}

	public function test_amounts_are_never_rounded(): void {
		$shown = $this->shown(
			array(
				'commission' => '40.0001',
				'closing'    => '1020.0001',
			),
			array( 'payout_amount' => '20.0001' )
		);
		$pdf   = ( new SettlementPdf() )->render( $shown );

		$this->assert_pdf_contains( '40,0001 DKK', $pdf );
		$this->assert_pdf_contains( '1.005,0000 DKK', $pdf, 'All amounts with four decimals when one needs them.' );
	}

	public function test_many_rows_continue_on_more_pages_with_the_table_header(): void {
		$rows = array();

		for ( $i = 0; $i < 90; $i++ ) {
			$rows[] = self::row( array( 'order_id' => (string) ( 10000 + $i ) ) );
		}

		$pdf = ( new SettlementPdf() )->render(
			$this->shown(
				array(
					'commission' => '1800',
					'profit'     => '1800',
					'closing'    => '2780',
				),
				null,
				$rows
			)
		);

		self::assertGreaterThan( 1, preg_match_all( '/\/Type \/Page \//', $pdf ) );
		$this->assert_pdf_contains( 'Settlement document AFR-000007 (continued)', $pdf );
		$this->assert_pdf_contains( 'Page 2 of ', $pdf );
		self::assertGreaterThan( 1, substr_count( $pdf, '(VAT on the sale) Tj' ), 'The table header repeats.' );
		$this->assert_pdf_contains( '#10089', $pdf );
	}

	public function test_missing_data_is_shown_as_such_and_never_invented(): void {
		$shown             = $this->shown(
			array(
				'vat'      => null,
				'snapshot' => array( 'payout' => array( 'method' => 'gift_card' ) ),
				'from'     => null,
			),
			null,
			array()
		);
		$shown['document'] = new SettlementDocument( 7, 'AFR-000007', SettlementDocument::TYPE_SETTLEMENT, 3, 41, null, 'DKK', null, new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) ), 0, 0, 1005 * 10000, 0, 1005 * 10000, null, 'adr049-adr054-v1', 2, array( 'payout' => array( 'method' => 'gift_card' ) ), 1, new DateTimeImmutable( '2026-11-30 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$pdf               = ( new SettlementPdf() )->render( $shown );

		$this->assert_pdf_contains( 'VAT registered at issue: Unknown', $pdf );
		$this->assert_pdf_contains( 'Whole history', $pdf );
		$this->assert_pdf_contains( 'Gift card', $pdf );
		$this->assert_pdf_contains( 'No sales rows were settled with this document.', $pdf );
		$this->assert_pdf_lacks( 'Paid to', $pdf );
	}

	public function test_the_download_headers_name_the_file_after_the_document(): void {
		$document = $this->shown()['document'];
		$headers  = SettlementPdf::headers( $document );

		self::assertSame(
			array(
				'Content-Type'           => 'application/pdf',
				'Content-Disposition'    => 'attachment; filename="AFR-000007.pdf"',
				'Cache-Control'          => 'no-store, private',
				'X-Content-Type-Options' => 'nosniff',
			),
			$headers
		);

		$odd = new SettlementDocument( 1, "AFR-1\"; x=\r\n", SettlementDocument::TYPE_SETTLEMENT, 3, 41, null, 'DKK', null, $document->period_to, 0, 0, 0, 0, 0, null, 'v', 2, array(), null, $document->issued_at );
		self::assertSame( 'attachment; filename="settlement-document.pdf"', SettlementPdf::headers( $odd )['Content-Disposition'], 'Never an unchecked number in a header.' );
	}

	/**
	 * A text must be in the PDF (as one encoded string of a text operator).
	 *
	 * @param string $text    UTF-8 text.
	 * @param string $pdf     PDF.
	 * @param string $message Message.
	 */
	private function assert_pdf_contains( string $text, string $pdf, string $message = '' ): void {
		self::assertStringContainsString( PdfWriter::escape( PdfWriter::encode( $text ) ), $pdf, '' === $message ? $text : $message );
	}

	/**
	 * A text must not be in the PDF.
	 *
	 * @param string $text UTF-8 text.
	 * @param string $pdf  PDF.
	 */
	private function assert_pdf_lacks( string $text, string $pdf ): void {
		self::assertStringNotContainsString( PdfWriter::escape( PdfWriter::encode( $text ) ), $pdf, $text );
	}

	/**
	 * A document with two rows (a sale of 100 incl. VAT with 20.00 commission
	 * twice, one of them refunded later in the ledger is not modelled here):
	 * opening 1005, commission 40, paid out 25, closing 1020.
	 *
	 * @param array<string, mixed>            $o     Overrides of the document.
	 * @param array<string, mixed>|null       $row   Overrides of the second row.
	 * @param list<array<string, mixed>>|null $rows  Rows instead of the default ones.
	 * @return array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>}
	 */
	private function shown( array $o = array(), ?array $row = null, ?array $rows = null ): array {
		$utc      = new DateTimeZone( 'UTC' );
		$snapshot = $o['snapshot'] ?? array(
			'preliminary' => false,
			'creator'     => array(
				'id'             => 3,
				'name'           => 'Søren Ærø',
				'vat_registered' => true,
			),
			'company'     => array(
				'name'    => 'CCMerch ApS',
				'address' => array( 'Strandvejen 1', '2900 Hellerup', 'DK' ),
			),
			'payout'      => array(
				'id'               => 41,
				'method'           => 'bank',
				'destination_hint' => '•••• 5678',
			),
		);

		$document = new SettlementDocument(
			7,
			'AFR-000007',
			(string) ( $o['type'] ?? SettlementDocument::TYPE_SETTLEMENT ),
			3,
			41,
			null,
			(string) ( $o['currency'] ?? 'DKK' ),
			array_key_exists( 'from', $o ) ? null : new DateTimeImmutable( '2026-10-01 00:00:00', $utc ),
			new DateTimeImmutable( '2026-11-30 12:00:00', $utc ),
			SalesAmount::parse( (string) ( $o['profit'] ?? '40' ) ),
			SalesAmount::parse( (string) ( $o['commission'] ?? '40' ) ),
			SalesAmount::parse( '1005' ),
			SalesAmount::parse( '25' ),
			SalesAmount::parse( (string) ( $o['closing'] ?? '1020' ) ),
			array_key_exists( 'vat', $o ) ? null : true,
			'adr049-adr054-v1',
			(int) ( $o['template_version'] ?? 2 ),
			$snapshot,
			1,
			new DateTimeImmutable( '2026-11-30 12:00:00', $utc )
		);

		return array(
			'document'    => $document,
			'rows'        => $rows ?? array(
				self::row( array( 'item_name' => 'Hættetrøje (sort)' ) ),
				self::row( array_merge( array( 'type' => 'refund', 'order_id' => '9102' ), $row ?? array() ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
			'corrections' => array(),
		);
	}

	/**
	 * A settled row as stored (strings), 20.00 commission and profit.
	 *
	 * @param array<string, mixed> $o Overrides.
	 * @return array<string, mixed>
	 */
	private static function row( array $o = array() ): array {
		$row = array_merge(
			array(
				'event_key'     => 's:1:1',
				'order_id'      => '9101',
				'order_item_id' => '1',
				'item_name'     => 'Tee',
				'type'          => 'sale',
				'quantity'      => '1',
				'net_amount'    => '80.0000',
				'tax_amount'    => '20.0000',
				'profit_amount' => '20.0000',
				'payout_amount' => '20.0000',
			),
			$o
		);

		return array_filter( $row, static fn( mixed $value ): bool => null !== $value );
	}
}
