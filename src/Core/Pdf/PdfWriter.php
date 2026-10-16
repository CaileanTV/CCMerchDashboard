<?php
/**
 * A minimal PDF writer.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Pdf;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a PDF 1.4 file with A4 pages: text in the standard fonts Helvetica
 * and Helvetica-Bold, lines and filled rectangles. No images, no embedded
 * fonts, no compression and no third-party code (ADR-004).
 *
 * Text is encoded as WinAnsiEncoding (Windows-1252), which covers Danish
 * æ, ø and å and the other Western European letters; any other character is
 * written as "?". Text widths come from the fonts' standard metrics, so
 * alignment and wrapping work without the font files.
 *
 * The output is deterministic: the same calls give the same bytes. The
 * creation date and the seed of the file id are passed in, and numbers are
 * written with a fixed, locale-independent format.
 *
 * Coordinates are points (1/72 inch) from the top left corner of the page;
 * a text's y is its baseline.
 */
final class PdfWriter {

	/**
	 * A4 width in points.
	 */
	public const PAGE_WIDTH = 595.28;

	/**
	 * A4 height in points.
	 */
	public const PAGE_HEIGHT = 841.89;

	/**
	 * Text alignments.
	 */
	public const LEFT = 'left';

	public const RIGHT = 'right';

	/**
	 * Characters outside Latin-1 that Windows-1252 has, by code point.
	 */
	private const CP1252 = array(
		0x20AC => 0x80,
		0x201A => 0x82,
		0x0192 => 0x83,
		0x201E => 0x84,
		0x2026 => 0x85,
		0x2020 => 0x86,
		0x2021 => 0x87,
		0x02C6 => 0x88,
		0x2030 => 0x89,
		0x0160 => 0x8A,
		0x2039 => 0x8B,
		0x0152 => 0x8C,
		0x017D => 0x8E,
		0x2018 => 0x91,
		0x2019 => 0x92,
		0x201C => 0x93,
		0x201D => 0x94,
		0x2022 => 0x95,
		0x2013 => 0x96,
		0x2014 => 0x97,
		0x02DC => 0x98,
		0x2122 => 0x99,
		0x0161 => 0x9A,
		0x203A => 0x9B,
		0x0153 => 0x9C,
		0x017E => 0x9E,
		0x0178 => 0x9F,
	);

	/**
	 * Helvetica widths (1/1000 em) of the WinAnsi bytes 32–255.
	 */
	private const REGULAR = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500';

	/**
	 * Helvetica-Bold widths (1/1000 em) of the WinAnsi bytes 32–255.
	 */
	private const BOLD = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556';

	/**
	 * Content operators per page.
	 *
	 * @var list<list<string>>
	 */
	private array $pages = array();

	/**
	 * Index of the page that receives drawing operations.
	 *
	 * @var int
	 */
	private int $page = -1;

	/**
	 * Widths by font and byte, built on first use.
	 *
	 * @var array{0: list<int>, 1: list<int>}|null
	 */
	private ?array $widths = null;

	/**
	 * Constructor.
	 *
	 * @param string            $title   Document title (metadata).
	 * @param DateTimeImmutable $created Creation date written into the file.
	 * @param string            $id_seed Seed of the file id (e.g. the document number).
	 */
	public function __construct( private string $title, private DateTimeImmutable $created, private string $id_seed ) {
	}

	/**
	 * Adds a page and makes it the current one.
	 */
	public function add_page(): void {
		$this->pages[] = array();
		$this->page    = count( $this->pages ) - 1;
	}

	/**
	 * Number of pages.
	 */
	public function page_count(): int {
		return count( $this->pages );
	}

	/**
	 * Makes an existing page the current one (e.g. to add footers later).
	 *
	 * @param int $index Page index, from 0.
	 * @throws InvalidArgumentException When the page does not exist.
	 */
	public function select_page( int $index ): void {
		if ( $index < 0 || $index >= count( $this->pages ) ) {
			throw new InvalidArgumentException( 'No such page.' );
		}

		$this->page = $index;
	}

	/**
	 * Writes one line of text.
	 *
	 * @param float  $x     Left edge (LEFT) or right edge (RIGHT).
	 * @param float  $y     Baseline from the top of the page.
	 * @param string $text  UTF-8 text; line breaks and tabs become spaces.
	 * @param float  $size  Font size in points.
	 * @param bool   $bold  Helvetica-Bold instead of Helvetica.
	 * @param string $align LEFT or RIGHT.
	 */
	public function text( float $x, float $y, string $text, float $size, bool $bold = false, string $align = self::LEFT ): void {
		$bytes = self::encode( $text );

		if ( '' === $bytes ) {
			return;
		}

		if ( self::RIGHT === $align ) {
			$x -= $this->bytes_width( $bytes, $size, $bold );
		}

		$this->operator( sprintf( 'BT /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET', $bold ? 'F2' : 'F1', self::number( $size ), self::number( $x ), self::number( self::PAGE_HEIGHT - $y ), self::escape( $bytes ) ) );
	}

	/**
	 * Draws a straight line.
	 *
	 * @param float $x1    Start x.
	 * @param float $y1    Start y from the top.
	 * @param float $x2    End x.
	 * @param float $y2    End y from the top.
	 * @param float $width Line width.
	 * @param float $gray  0 (black) to 1 (white).
	 */
	public function line( float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0.0 ): void {
		$this->operator( sprintf( 'q %s G %s w %s %s m %s %s l S Q', self::number( self::gray( $gray ) ), self::number( $width ), self::number( $x1 ), self::number( self::PAGE_HEIGHT - $y1 ), self::number( $x2 ), self::number( self::PAGE_HEIGHT - $y2 ) ) );
	}

	/**
	 * Fills a rectangle.
	 *
	 * @param float $x      Left edge.
	 * @param float $y      Top edge from the top of the page.
	 * @param float $width  Width.
	 * @param float $height Height.
	 * @param float $gray   0 (black) to 1 (white).
	 */
	public function rect( float $x, float $y, float $width, float $height, float $gray ): void {
		$this->operator( sprintf( 'q %s g %s %s %s %s re f Q', self::number( self::gray( $gray ) ), self::number( $x ), self::number( self::PAGE_HEIGHT - $y - $height ), self::number( $width ), self::number( $height ) ) );
	}

	/**
	 * Width of a text in points.
	 *
	 * @param string $text UTF-8 text.
	 * @param float  $size Font size.
	 * @param bool   $bold Helvetica-Bold.
	 */
	public function width( string $text, float $size, bool $bold = false ): float {
		return $this->bytes_width( self::encode( $text ), $size, $bold );
	}

	/**
	 * Breaks a text into lines no wider than $max: at spaces, inside a word
	 * only when the word alone is too wide; "\n" always breaks.
	 *
	 * @param string $text UTF-8 text.
	 * @param float  $max  Largest line width in points.
	 * @param float  $size Font size.
	 * @param bool   $bold Helvetica-Bold.
	 * @return list<string> Lines (at least one).
	 */
	public function wrap( string $text, float $max, float $size, bool $bold = false ): array {
		$lines = array();

		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $text ) ) as $paragraph ) {
			$line = '';

			$words = preg_split( '/[ \t]+/', trim( $paragraph ) );

			foreach ( false === $words ? array() : $words as $word ) {
				$candidate = '' === $line ? $word : $line . ' ' . $word;

				if ( $this->width( $candidate, $size, $bold ) <= $max ) {
					$line = $candidate;
					continue;
				}

				if ( '' !== $line ) {
					$lines[] = $line;
				}

				$line = $word;

				// A word wider than the line is cut into pieces that fit.
				while ( $this->width( $line, $size, $bold ) > $max ) {
					$piece   = self::fitting_prefix( $line, $max, $size, $bold, $this );
					$lines[] = $piece;
					$line    = substr( $line, strlen( $piece ) );
				}
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * The PDF file.
	 *
	 * @throws LogicException When there is no page.
	 */
	public function output(): string {
		if ( array() === $this->pages ) {
			throw new LogicException( 'A PDF needs at least one page.' );
		}

		$count   = count( $this->pages );
		$objects = array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [' . implode( ' ', array_map( static fn( int $i ): string => ( 6 + 2 * $i ) . ' 0 R', range( 0, $count - 1 ) ) ) . '] /Count ' . $count . ' >>',
			3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
			5 => '<< /Title (' . self::escape( self::encode( $this->title ) ) . ') /Producer (Merch Dashboard) /CreationDate (D:' . $this->created->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'YmdHis' ) . 'Z) >>',
		);

		foreach ( $this->pages as $i => $operators ) {
			$stream                = implode( "\n", $operators );
			$objects[ 6 + 2 * $i ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::number( self::PAGE_WIDTH ) . ' ' . self::number( self::PAGE_HEIGHT ) . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ( 7 + 2 * $i ) . ' 0 R >>';
			$objects[ 7 + 2 * $i ] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
		}

		// The binary comment marks the file as containing 8-bit text.
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();

		foreach ( $objects as $number => $object ) {
			$offsets[ $number ] = strlen( $pdf );
			$pdf               .= $number . " 0 obj\n" . $object . "\nendobj\n";
		}

		$xref = strlen( $pdf );
		$id   = md5( 'merchboard-pdf|' . $this->id_seed );
		$pdf .= 'xref' . "\n" . '0 ' . ( count( $objects ) + 1 ) . "\n" . '0000000000 65535 f ' . "\n";

		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( '%010d 00000 n ', $offset ) . "\n";
		}

		return $pdf . 'trailer' . "\n" . '<< /Size ' . ( count( $objects ) + 1 ) . ' /Root 1 0 R /Info 5 0 R /ID [<' . $id . '> <' . $id . '>] >>' . "\n" . 'startxref' . "\n" . $xref . "\n" . '%%EOF' . "\n";
	}

	/**
	 * UTF-8 text as WinAnsiEncoding bytes; characters it has no byte for
	 * become "?", control characters a space. Invalid UTF-8 becomes "?".
	 *
	 * @param string $text UTF-8 text.
	 */
	public static function encode( string $text ): string {
		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $characters ) {
			return '?';
		}

		$bytes = '';

		foreach ( $characters as $character ) {
			$code = self::code_point( $character );

			if ( $code < 0x20 || 0x7F === $code ) {
				$bytes .= ' ';
			} elseif ( $code < 0x80 || ( $code >= 0xA0 && $code <= 0xFF ) ) {
				$bytes .= chr( $code );
			} elseif ( isset( self::CP1252[ $code ] ) ) {
				$bytes .= chr( self::CP1252[ $code ] );
			} elseif ( 0x202F === $code || 0x2009 === $code ) {
				// Narrow and thin spaces (some number formats).
				$bytes .= ' ';
			} else {
				$bytes .= '?';
			}
		}

		return $bytes;
	}

	/**
	 * Escapes encoded bytes for a PDF literal string.
	 *
	 * @param string $bytes WinAnsi bytes.
	 */
	public static function escape( string $bytes ): string {
		return strtr(
			$bytes,
			array(
				'\\' => '\\\\',
				'('  => '\\(',
				')'  => '\\)',
			)
		);
	}

	/**
	 * Appends an operator to the current page.
	 *
	 * @param string $operator Content stream operator.
	 * @throws LogicException When there is no page.
	 */
	private function operator( string $operator ): void {
		if ( $this->page < 0 ) {
			throw new LogicException( 'Add a page before drawing.' );
		}

		$this->pages[ $this->page ][] = $operator;
	}

	/**
	 * Width of encoded bytes in points.
	 *
	 * @param string $bytes WinAnsi bytes.
	 * @param float  $size  Font size.
	 * @param bool   $bold  Helvetica-Bold.
	 */
	private function bytes_width( string $bytes, float $size, bool $bold ): float {
		$this->widths ??= array(
			array_map( 'intval', explode( ',', self::REGULAR ) ),
			array_map( 'intval', explode( ',', self::BOLD ) ),
		);
		$widths         = $this->widths[ $bold ? 1 : 0 ];
		$total          = 0;
		$length         = strlen( $bytes );

		for ( $i = 0; $i < $length; $i++ ) {
			$byte   = ord( $bytes[ $i ] );
			$total += $byte >= 32 ? ( $widths[ $byte - 32 ] ?? 556 ) : 278;
		}

		return $total * $size / 1000;
	}

	/**
	 * The longest prefix of a word (whole UTF-8 characters, at least one)
	 * that fits $max.
	 *
	 * @param string    $word   UTF-8 word.
	 * @param float     $max    Width in points.
	 * @param float     $size   Font size.
	 * @param bool      $bold   Helvetica-Bold.
	 * @param PdfWriter $writer Writer (for the metrics).
	 */
	private static function fitting_prefix( string $word, float $max, float $size, bool $bold, PdfWriter $writer ): string {
		$characters = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $characters || array() === $characters ) {
			return $word;
		}

		$prefix = $characters[0];

		foreach ( array_slice( $characters, 1 ) as $character ) {
			if ( $writer->width( $prefix . $character, $size, $bold ) > $max ) {
				break;
			}

			$prefix .= $character;
		}

		return $prefix;
	}

	/**
	 * Code point of one UTF-8 character.
	 *
	 * @param string $character One valid UTF-8 character.
	 */
	private static function code_point( string $character ): int {
		$bytes = array_values( (array) unpack( 'C*', $character ) );
		$first = (int) ( $bytes[0] ?? 0 );

		return match ( count( $bytes ) ) {
			2       => ( ( $first & 0x1F ) << 6 ) | ( (int) $bytes[1] & 0x3F ),
			3       => ( ( $first & 0x0F ) << 12 ) | ( ( (int) $bytes[1] & 0x3F ) << 6 ) | ( (int) $bytes[2] & 0x3F ),
			4       => ( ( $first & 0x07 ) << 18 ) | ( ( (int) $bytes[1] & 0x3F ) << 12 ) | ( ( (int) $bytes[2] & 0x3F ) << 6 ) | ( (int) $bytes[3] & 0x3F ),
			default => $first,
		};
	}

	/**
	 * A gray level clamped to 0–1.
	 *
	 * @param float $gray Gray.
	 */
	private static function gray( float $gray ): float {
		return max( 0.0, min( 1.0, $gray ) );
	}

	/**
	 * A number with two decimals, independent of the locale.
	 *
	 * @param float $value Number.
	 */
	private static function number( float $value ): string {
		return sprintf( '%.2F', $value );
	}
}
