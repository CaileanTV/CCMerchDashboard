<?php
/**
 * Tests for PdfWriter.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Pdf;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use MerchBoard\Core\Pdf\PdfWriter;
use MerchBoard\Tests\Unit\TestCase;

final class PdfWriterTest extends TestCase {

	public function test_the_file_has_a_valid_structure_with_correct_offsets(): void {
		$pdf = $this->writer();
		$pdf->add_page();
		$pdf->text( 50, 60, 'Hello', 12 );
		$pdf->add_page();
		$pdf->line( 50, 70, 200, 70 );
		$pdf->rect( 50, 80, 100, 20, 0.9 );
		$file = $pdf->output();

		self::assertStringStartsWith( "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n", $file );
		self::assertStringEndsWith( "%%EOF\n", $file );
		self::assertStringContainsString( '/Type /Pages /Kids [6 0 R 8 0 R] /Count 2', $file );
		self::assertStringContainsString( '/BaseFont /Helvetica /Encoding /WinAnsiEncoding', $file );
		self::assertStringContainsString( '/BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding', $file );
		self::assertSame( 1, preg_match( '/startxref\n(\d+)\n%%EOF\n$/', $file, $start ) );
		self::assertSame( 'xref', substr( $file, (int) ( $start[1] ?? -1 ), 4 ), 'startxref points at the table.' );
		self::assertSame( 1, preg_match( '/xref\n0 (\d+)\n0000000000 65535 f \n((?:\d{10} 00000 n \n)+)trailer/', $file, $xref ) );
		self::assertSame( '10', $xref[1] ?? null, 'Catalog, pages, two fonts, info and two pages with their contents.' );

		foreach ( explode( "\n", trim( $xref[2] ) ) as $i => $entry ) {
			$offset = (int) substr( $entry, 0, 10 );
			self::assertSame( ( $i + 1 ) . ' 0 obj', substr( $file, $offset, strlen( ( $i + 1 ) . ' 0 obj' ) ), 'Offset of object ' . ( $i + 1 ) );
		}

		self::assertSame( 2, preg_match_all( '/<< \/Length (\d+) >>\nstream\n/', $file, $lengths, PREG_OFFSET_CAPTURE ) );

		foreach ( $lengths[0] as $i => $match ) {
			$begin = $match[1] + strlen( $match[0] );
			self::assertSame( "\nendstream", substr( $file, $begin + (int) $lengths[1][ $i ][0], 10 ), 'The stream length is exact.' );
		}
	}

	public function test_danish_and_western_european_letters_use_winansi_bytes(): void {
		self::assertSame( "Bl\xE5b\xE6rgr\xF8d \xC6\xD8\xC5 \xE9 \x80 \x96 \x93x\x94", PdfWriter::encode( 'Blåbærgrød ÆØÅ é € – “x”' ) );

		$pdf = $this->writer();
		$pdf->add_page();
		$pdf->text( 50, 60, 'Rødgrød med fløde', 10 );

		self::assertStringContainsString( "(R\xF8dgr\xF8d med fl\xF8de) Tj", $pdf->output() );
	}

	public function test_other_characters_controls_and_invalid_utf8_are_replaced(): void {
		self::assertSame( 'a?b', PdfWriter::encode( 'a→b' ) );
		self::assertSame( '?', PdfWriter::encode( '😀' ) );
		self::assertSame( 'a b c', PdfWriter::encode( "a\nb\tc" ) );
		self::assertSame( '1 234', PdfWriter::encode( "1\u{202F}234" ) );
		self::assertSame( '?', PdfWriter::encode( "\xC3\x28" ) );
	}

	public function test_parentheses_and_backslashes_are_escaped(): void {
		$pdf = $this->writer();
		$pdf->add_page();
		$pdf->text( 50, 60, 'a(b)c\\d', 10 );

		self::assertStringContainsString( '(a\\(b\\)c\\\\d) Tj', $pdf->output() );
		self::assertSame( 'x\\)y', PdfWriter::escape( 'x)y' ) );
	}

	public function test_widths_follow_the_standard_metrics_and_right_alignment_uses_them(): void {
		$pdf = $this->writer();

		self::assertEqualsWithDelta( 5.56, $pdf->width( 'a', 10 ), 0.0001 );
		self::assertEqualsWithDelta( 2.22, $pdf->width( 'i', 10 ), 0.0001 );
		self::assertEqualsWithDelta( 5.56, $pdf->width( 'å', 10 ), 0.0001 );
		self::assertGreaterThan( $pdf->width( 'Total', 10 ), $pdf->width( 'Total', 10, true ) );

		$pdf->add_page();
		$pdf->text( 200, 60, 'aa', 10, false, PdfWriter::RIGHT );

		self::assertStringContainsString( '1 0 0 1 188.88 781.89 Tm (aa) Tj', $pdf->output(), '200 − 2 × 5.56; y from the bottom.' );
	}

	public function test_wrapping_keeps_lines_within_the_width_and_breaks_long_words(): void {
		$pdf   = $this->writer();
		$text  = 'Du er selv ansvarlig for at undersøge og opfylde dine skatte- og indberetningsforpligtelser.';
		$lines = $pdf->wrap( $text, 120, 9 );

		self::assertGreaterThan( 1, count( $lines ) );
		self::assertSame( $text, implode( ' ', $lines ), 'Nothing is lost at the breaks.' );

		foreach ( $lines as $line ) {
			self::assertLessThanOrEqual( 120, $pdf->width( $line, 9 ) );
		}

		$long = $pdf->wrap( str_repeat( 'æ', 60 ), 50, 9 );
		self::assertGreaterThan( 1, count( $long ) );
		self::assertSame( str_repeat( 'æ', 60 ), implode( '', $long ), 'A long word is cut between whole characters.' );
		self::assertSame( array( 'one', 'two' ), $pdf->wrap( "one\ntwo", 500, 9 ) );
		self::assertSame( array( '' ), $pdf->wrap( '', 500, 9 ) );
	}

	public function test_pages_can_be_revisited_and_the_output_is_deterministic(): void {
		$build = function ( string $seed ): string {
			$pdf = $this->writer( $seed );
			$pdf->add_page();
			$pdf->add_page();
			$pdf->select_page( 0 );
			$pdf->text( 50, 800, 'Page 1 of 2', 7 );

			return $pdf->output();
		};

		self::assertSame( $build( 'AFR-000001' ), $build( 'AFR-000001' ), 'The same calls give the same bytes.' );
		self::assertNotSame( $build( 'AFR-000001' ), $build( 'AFR-000002' ), 'The file id follows the seed.' );
		self::assertStringContainsString( '/CreationDate (D:20261130120000Z)', $build( 'AFR-000001' ) );
		self::assertStringContainsString( '/ID [<' . md5( 'merchboard-pdf|AFR-000001' ) . '>', $build( 'AFR-000001' ) );
	}

	public function test_numbers_do_not_depend_on_the_locale(): void {
		$previous = setlocale( LC_NUMERIC, '0' );
		setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German_Germany.1252' );

		try {
			$pdf = $this->writer();
			$pdf->add_page();
			$pdf->text( 50.5, 60, 'x', 9.5 );
			$file = $pdf->output();
		} finally {
			setlocale( LC_NUMERIC, false === $previous ? 'C' : $previous );
		}

		self::assertStringContainsString( '/F1 9.50 Tf 1 0 0 1 50.50 781.89 Tm', $file );
	}

	public function test_misuse_is_refused(): void {
		$pdf = $this->writer();

		try {
			$pdf->output();
			self::fail( 'A PDF needs a page.' );
		} catch ( LogicException ) {
			self::assertSame( 0, $pdf->page_count() );
		}

		try {
			$pdf->text( 1, 1, 'x', 9 );
			self::fail( 'Drawing needs a page.' );
		} catch ( LogicException ) {
			$pdf->add_page();
		}

		$this->expectException( InvalidArgumentException::class );
		$pdf->select_page( 1 );
	}

	/**
	 * A writer with a fixed date.
	 *
	 * @param string $seed File id seed.
	 */
	private function writer( string $seed = 'AFR-000001' ): PdfWriter {
		return new PdfWriter( 'Test', new DateTimeImmutable( '2026-11-30 13:00:00', new DateTimeZone( 'Europe/Copenhagen' ) ), $seed );
	}
}
