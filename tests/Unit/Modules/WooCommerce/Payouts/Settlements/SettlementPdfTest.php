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
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CommissionVat;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementPdf;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\VatDeduction;
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

	public function test_a_template_3_document_shows_the_vat_on_the_commission_apart_from_the_vat_on_the_sale(): void {
		$pdf = ( new SettlementPdf() )->render( $this->vat_document( array( '1', '1' ) ) );

		foreach ( array( 'VAT on the commission', 'Commission excl. VAT', '32,00 DKK', 'VAT (25 %)', '8,00 DKK', 'Commission incl. VAT', '40,00 DKK', 'The commission in the period includes this VAT. It is separate from the VAT on the sale.', 'VAT on the sale', 'Tax and reporting', 'Template version 3.' ) as $text ) {
			$this->assert_pdf_contains( $text, $pdf );
		}

		$this->assert_pdf_lacks( 'Commission without VAT (not VAT registered when sold)', $pdf );
	}

	public function test_no_vat_on_the_commission_is_shown_for_sales_while_not_vat_registered(): void {
		$pdf = ( new SettlementPdf() )->render( $this->vat_document( array( '0', '0' ) ) );

		$this->assert_pdf_contains( 'Template version 3.', $pdf );
		$this->assert_pdf_contains( 'VAT on the sale', $pdf );

		foreach ( array( 'VAT on the commission', 'Commission excl. VAT', 'Commission incl. VAT', 'VAT (25 %)' ) as $text ) {
			$this->assert_pdf_lacks( $text, $pdf );
		}
	}

	public function test_a_mixed_document_shows_the_commission_without_vat_apart(): void {
		$pdf = ( new SettlementPdf() )->render( $this->vat_document( array( '1', '0' ) ) );

		foreach ( array( 'Commission excl. VAT', '16,00 DKK', '4,00 DKK', 'Commission incl. VAT', '20,00 DKK', 'Commission without VAT (not VAT registered when sold)' ) as $text ) {
			$this->assert_pdf_contains( $text, $pdf );
		}
	}

	public function test_earlier_template_versions_never_show_vat_on_the_commission(): void {
		foreach ( array( 1, 2 ) as $version ) {
			$shown = $this->vat_document( array( '1', '1' ) );
			$pdf   = ( new SettlementPdf() )->render( $this->shown( array( 'template_version' => $version ), null, $shown['rows'] ) );

			$this->assert_pdf_contains( 'Template version ' . $version . '.', $pdf );
			$this->assert_pdf_lacks( 'VAT on the commission', $pdf );
			$this->assert_pdf_lacks( 'Commission excl. VAT', $pdf );
		}
	}

	public function test_a_template_6_document_shows_the_vat_deducted_while_not_vat_registered(): void {
		$pdf = ( new SettlementPdf() )->render( $this->deduction_document( array( '0', '0' ) ) );

		foreach ( array( 'VAT deducted from the payout', 'Commission of sales while not VAT registered, incl. VAT', '40,00 DKK', 'VAT deducted (not VAT registered when sold)', '-8,00 DKK', 'Commission of sales while not VAT registered, paid out', '32,00 DKK', 'Commission in the period', '16,00', 'Template version 6.' ) as $text ) {
			$this->assert_pdf_contains( $text, $pdf );
		}

		$this->assert_pdf_contains( 'The creator was not VAT registered when these sales were made.', $pdf );
		$this->assert_pdf_lacks( 'VAT on the commission', $pdf );
	}

	public function test_a_template_6_document_without_sales_while_not_vat_registered_shows_no_deduction(): void {
		$pdf = ( new SettlementPdf() )->render( $this->deduction_document( array( '1', '1' ) ) );

		$this->assert_pdf_contains( 'Commission incl. VAT', $pdf );
		$this->assert_pdf_contains( 'Template version 6.', $pdf );
		$this->assert_pdf_lacks( 'VAT deducted', $pdf );
	}

	public function test_a_mixed_template_6_document_shows_the_vat_on_the_commission_and_the_deduction(): void {
		$pdf = ( new SettlementPdf() )->render( $this->deduction_document( array( '1', '0' ) ) );

		// VAT registered: 20.00 incl. VAT = 16.00 + 4.00; not registered: 20.00 − 4.00 = 16.00 paid out.
		foreach ( array( 'Commission incl. VAT', '20,00 DKK', 'Commission without VAT (not VAT registered when sold)', '16,00 DKK', 'VAT deducted (not VAT registered when sold)', '-4,00 DKK', '36,00 DKK' ) as $text ) {
			$this->assert_pdf_contains( $text, $pdf );
		}
	}

	public function test_earlier_template_versions_never_show_a_vat_deduction(): void {
		foreach ( array( 2, 3, 4 ) as $version ) {
			$pdf = ( new SettlementPdf() )->render( $this->deduction_document( array( '0', '0' ), $version ) );

			$this->assert_pdf_contains( 'Template version ' . $version . '.', $pdf );
			$this->assert_pdf_lacks( 'VAT deducted', $pdf );
		}
	}

	public function test_a_template_6_document_whose_deduction_does_not_match_its_rows_gives_no_pdf(): void {
		$cases = array(
			'missing'         => $this->deduction_document( array( '0', '0' ), 6, array( 'vat_deduction' => null ) ),
			'other amounts'   => $this->deduction_document(
				array( '0', '0' ),
				6,
				array(
					'vat_deduction' => array(
						'version'   => VatDeduction::VERSION,
						'gross'     => '40.0000',
						'deduction' => '7.0000',
						'net'       => '33.0000',
					),
				)
			),
			'does not add up' => $this->deduction_document(
				array( '0', '0' ),
				6,
				array(
					'vat_deduction' => array(
						'version'   => VatDeduction::VERSION,
						'gross'     => '40.0000',
						'deduction' => '8.0000',
						'net'       => '33.0000',
					),
				)
			),
		);

		foreach ( $cases as $case => $shown ) {
			try {
				( new SettlementPdf() )->render( $shown );
				self::fail( 'Expected no PDF: ' . $case );
			} catch ( UnexpectedValueException $e ) {
				self::assertStringNotContainsString( '40', $e->getMessage(), 'No amounts in the message: ' . $case );
			}
		}

		// Like the VAT on the commission, a snapshot that is not valid in itself is shown nowhere.
		self::assertNull( VatDeduction::shown( $cases['missing']['document'] ) );
		self::assertNull( VatDeduction::shown( $cases['does not add up']['document'] ) );
	}

	public function test_a_template_4_document_identifies_both_parties_with_their_master_data(): void {
		$shown = $this->master_document(
			array(
				'legal_name' => 'Søren Ærø ApS',
				'address'    => array( 'Nørregade 2', '1. th.', '8000 Aarhus C', 'Danmark' ),
				'cvr'        => '87654321',
			),
			'12345678'
		);
		$pdf   = ( new SettlementPdf() )->render( $shown );

		foreach ( array( 'Self-billing: issued by CCMerch ApS on behalf of Søren Ærø ApS.', 'CVR no.: 12345678', 'Søren Ærø ApS', 'Display name: Søren Ærø', 'Nørregade 2', '1. th.', '8000 Aarhus C', 'Danmark', 'CVR/SE no.: 87654321', 'Creator ID: 3', 'VAT registered at issue: Yes', 'Commission incl. VAT', 'Template version 4.' ) as $text ) {
			$this->assert_pdf_contains( $text, $pdf );
		}

		$this->assert_pdf_lacks( 'Not registered', $pdf );
		self::assertSame(
			array(
				'creator_name'         => 'Søren Ærø ApS',
				'creator_display_name' => 'Søren Ærø',
				'creator_legal_name'   => 'Søren Ærø ApS',
				'creator_address'      => array( 'Nørregade 2', '1. th.', '8000 Aarhus C', 'Danmark' ),
				'creator_cvr'          => '87654321',
				'company_cvr'          => '12345678',
			),
			SettlementPdf::master_data( $shown['document'] )
		);
	}

	public function test_missing_master_data_of_a_template_4_document_is_shown_as_not_registered(): void {
		$shown = $this->master_document(
			array(
				'legal_name' => '',
				'address'    => array(),
				'cvr'        => '',
			),
			''
		);
		$pdf   = ( new SettlementPdf() )->render( $shown );

		foreach ( array( 'Self-billing: issued by CCMerch ApS on behalf of Søren Ærø.', 'CVR no.: Not registered', 'Address: Not registered', 'CVR/SE no.: Not registered', 'Creator ID: 3', 'Template version 4.' ) as $text ) {
			$this->assert_pdf_contains( $text, $pdf );
		}

		$this->assert_pdf_lacks( 'Display name:', $pdf );
		self::assertSame( 'Søren Ærø', SettlementPdf::master_data( $shown['document'] )['creator_name'] ?? null, 'The display name when no legal name was registered.' );

		$keys = $this->master_document( null, null );
		$pdf  = ( new SettlementPdf() )->render( $keys );

		$this->assert_pdf_contains( 'CVR no.: Not registered', $pdf );
		$this->assert_pdf_contains( 'Address: Not registered', $pdf );
		$this->assert_pdf_contains( 'CVR/SE no.: Not registered', $pdf, 'Also when the keys are missing altogether.' );
	}

	public function test_earlier_template_versions_never_show_master_data(): void {
		foreach ( array( 1, 2, 3 ) as $version ) {
			$shown = $this->master_document(
				array(
					'legal_name' => 'Søren Ærø ApS',
					'address'    => array( 'Nørregade 2' ),
					'cvr'        => '87654321',
				),
				'12345678',
				$version
			);
			$pdf   = ( new SettlementPdf() )->render( $shown );

			self::assertNull( SettlementPdf::master_data( $shown['document'] ) );
			$this->assert_pdf_contains( 'Template version ' . $version . '.', $pdf );
			$this->assert_pdf_contains( 'Self-billing: issued by CCMerch ApS on behalf of Søren Ærø.', $pdf );

			foreach ( array( 'CVR no.:', 'CVR/SE no.:', 'Display name:', 'Nørregade 2', 'Not registered', 'Søren Ærø ApS' ) as $text ) {
				$this->assert_pdf_lacks( $text, $pdf );
			}
		}
	}

	public function test_a_template_3_document_whose_vat_on_the_commission_does_not_add_up_gives_no_pdf(): void {
		$tampered = CommissionVat::snapshot( CommissionVat::split( array( self::split_row( '20', true ), self::split_row( '20', true ) ) ) );
		$cases    = array(
			'no split'           => $this->shown( array( 'template_version' => 3 ), null, $this->vat_document( array( '1', '1' ) )['rows'] ),
			'other vat'          => $this->shown(
				array(
					'template_version' => 3,
					'commission_vat'   => array(
						'vat'  => '9.0000',
						'excl' => '31.0000',
					) + $tampered,
				),
				null,
				$this->vat_document( array( '1', '1' ) )['rows']
			),
			'row status changed' => $this->shown(
				array(
					'template_version' => 3,
					'commission_vat'   => $tampered,
				),
				null,
				$this->vat_document( array( '1', '0' ) )['rows']
			),
			'other rate'         => $this->shown(
				array(
					'template_version' => 3,
					'commission_vat'   => array( 'rate_bps' => 2000 ) + $tampered,
				),
				null,
				$this->vat_document( array( '1', '1' ) )['rows']
			),
			'row without status' => $this->shown(
				array(
					'template_version' => 3,
					'commission_vat'   => $tampered,
				),
				array( 'vat_registered' => null )
			),
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
			'template 5'   => $this->shown( array( 'template_version' => 5 ) ),
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

		if ( array_key_exists( 'commission_vat', $o ) ) {
			$snapshot[ CommissionVat::SNAPSHOT_KEY ] = $o['commission_vat'];
		}

		if ( array_key_exists( 'vat_deduction', $o ) ) {
			$snapshot[ VatDeduction::SNAPSHOT_KEY ] = $o['vat_deduction'];
		}

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
	 * A template version 3 document of the two default rows (20.00 commission
	 * each) with the VAT statuses at the sale given and the split they give.
	 *
	 * @param array{0: string, 1: string} $statuses VAT status at the sale of the two rows ('1' or '0').
	 * @return array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>}
	 */
	private function vat_document( array $statuses ): array {
		$rows = array(
			self::row(
				array(
					'item_name'      => 'Hættetrøje (sort)',
					'vat_registered' => $statuses[0],
				)
			),
			self::row(
				array(
					'type'           => 'refund',
					'order_id'       => '9102',
					'vat_registered' => $statuses[1],
				)
			),
		);

		return $this->shown(
			array(
				'template_version' => 3,
				'commission_vat'   => CommissionVat::snapshot( CommissionVat::of_stored_rows( $rows, CommissionVat::RATE_BPS ) ),
			),
			null,
			$rows
		);
	}

	/**
	 * A document of two rows of a sale of 100.00 incl. 20.00 VAT at 20 %
	 * (profit 20.00): payout 20.00 while VAT registered, 16.00 while not
	 * (ADR-064), with the split and the deduction the rows give, of template
	 * version 6 unless another is given. Opening 1005, paid out 25.
	 *
	 * @param array{0: string, 1: string} $statuses VAT status at the sale of the two rows ('1' or '0').
	 * @param int                         $version  Template version.
	 * @param array<string, mixed>        $o        Overrides of the document.
	 * @return array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>}
	 */
	private function deduction_document( array $statuses, int $version = 6, array $o = array() ): array {
		$rows       = array(
			self::row(
				array(
					'item_name'      => 'Hættetrøje (sort)',
					'vat_registered' => $statuses[0],
					'payout_amount'  => '1' === $statuses[0] ? '20.0000' : '16.0000',
				)
			),
			self::row(
				array(
					'order_id'       => '9102',
					'vat_registered' => $statuses[1],
					'payout_amount'  => '1' === $statuses[1] ? '20.0000' : '16.0000',
				)
			),
		);
		$commission = SalesAmount::add( SalesAmount::parse( (string) $rows[0]['payout_amount'] ), SalesAmount::parse( (string) $rows[1]['payout_amount'] ) );

		return $this->shown(
			$o + array(
				'template_version' => $version,
				'commission'       => SalesAmount::format( $commission ),
				'closing'          => SalesAmount::format( SalesAmount::add( SalesAmount::parse( '980' ), $commission ) ),
				'commission_vat'   => CommissionVat::snapshot( CommissionVat::of_stored_rows( $rows, CommissionVat::RATE_BPS ) ),
				'vat_deduction'    => VatDeduction::snapshot( VatDeduction::of_stored_rows( $rows ) ),
			),
			null,
			$rows
		);
	}

	/**
	 * A document of the two default rows (VAT registered at both sales) with
	 * the master data given, of template version 4 unless another is given.
	 *
	 * @param array<string, mixed>|null $creator     Master data of the creator, null for none of the keys.
	 * @param string|null               $company_cvr The issuer's CVR number, null for no key.
	 * @param int                       $version     Template version.
	 * @return array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>}
	 */
	private function master_document( ?array $creator, ?string $company_cvr, int $version = 4 ): array {
		$rows    = $this->vat_document( array( '1', '1' ) )['rows'];
		$company = array(
			'name'    => 'CCMerch ApS',
			'address' => array( 'Strandvejen 1', '2900 Hellerup', 'DK' ),
		);

		if ( null !== $company_cvr ) {
			$company['cvr'] = $company_cvr;
		}

		return $this->shown(
			array(
				'template_version' => $version,
				'commission_vat'   => CommissionVat::snapshot( CommissionVat::of_stored_rows( $rows, CommissionVat::RATE_BPS ) ),
				'snapshot'         => array(
					'preliminary' => false,
					'creator'     => array(
						'id'             => 3,
						'name'           => 'Søren Ærø',
						'vat_registered' => true,
					) + ( $creator ?? array() ),
					'company'     => $company,
					'payout'      => array(
						'id'               => 41,
						'method'           => 'bank',
						'destination_hint' => '•••• 5678',
					),
				),
			),
			null,
			$rows
		);
	}

	/**
	 * A row for CommissionVat::split().
	 *
	 * @param string $payout Commission.
	 * @param bool   $vat    VAT status at the sale.
	 * @return array{payout_units: int, vat_registered: bool, price_decimals: int}
	 */
	private static function split_row( string $payout, bool $vat ): array {
		return array(
			'payout_units'   => SalesAmount::parse( $payout ),
			'vat_registered' => $vat,
			'price_decimals' => 2,
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
				'event_key'      => 's:1:1',
				'order_id'       => '9101',
				'order_item_id'  => '1',
				'item_name'      => 'Tee',
				'type'           => 'sale',
				'quantity'       => '1',
				'net_amount'     => '80.0000',
				'tax_amount'     => '20.0000',
				'profit_amount'  => '20.0000',
				'payout_amount'  => '20.0000',
				'vat_registered' => '1',
				'price_decimals' => '2',
			),
			$o
		);

		return array_filter( $row, static fn( mixed $value ): bool => null !== $value );
	}
}
