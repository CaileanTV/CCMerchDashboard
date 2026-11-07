<?php
/**
 * The files of a design's submission.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * What a submission needs (ADR-080 pkt. 5, step 30.9): the creator's
 * browser renders one transparent print PNG per used side (a JPEG on white on
 * a flat side), in the side's print size, and one mockup per used side and
 * chosen colour, in the template's size. A side is used when it has at least one visible object.
 * An embroidered side the browser stitches also has a close-up per colour
 * (ADR-085), which is not required.
 *
 * The files are stored with the draft (`merchboard_designer_versions.files`):
 * `{ stamp, print: { side: file }, mockups: { side: { colour: file } } }`,
 * where a file is `{ file, mime, width, height, bytes }`. The stamp is the
 * hash of what the files show (design, colours, base version); files with
 * another stamp belong to an earlier state of the draft and are replaced.
 *
 * Colour designs (ADR-096): `print` holds the shared design's files and
 * `designs: { id: { side: file } }` those of colour designs (slot
 * `print:<side>:<id>`). A colour's mockups show its own design, so their
 * keys stay `mockup:<side>:<colour>`.
 * Pure functions.
 */
final class SubmissionFiles {

	public const PRINT = 'print';

	public const MOCKUP = 'mockup';

	/**
	 * What starts the key of a photo the administrator chose (a mockup style
	 * of the base product), stored among the mockups in place of a side. No
	 * side key can start like this.
	 */
	public const PHOTO = '_photo-';

	/**
	 * What starts the key of a side's close-ups, stored among the mockups.
	 * No side key can start like this.
	 */
	public const CLOSE = '_close-';

	/**
	 * Width and height of a close-up.
	 */
	public const CLOSE_SIZE = 1200;

	/**
	 * A slot: `print:<side>` or `mockup:<side>:<colour>` (keys as BaseView
	 * and the colours' sanitize_title() keys make them, a chosen photo or a
	 * side's close-up).
	 */
	private const SLOT = '/^(?:print:([a-z0-9][a-z0-9-]{0,31})(?::(d[1-9][0-9]?))?|mockup:([a-z0-9][a-z0-9-]{0,31}|_photo-[0-9]{1,18}|_close-[a-z0-9][a-z0-9-]{0,31}):([a-z0-9%_-]{1,32}))$/';

	/**
	 * The stamp of a version's content (its stored columns).
	 *
	 * @param array<string, mixed> $version Version row.
	 */
	public static function stamp( array $version ): string {
		return hash( 'sha256', (string) ( $version['design'] ?? '' ) . '|' . (string) ( $version['colors'] ?? '' ) . '|' . (string) ( $version['base_version'] ?? '' ) );
	}

	/**
	 * The stitch colour the creator chose, or ''.
	 *
	 * @param array<string, mixed> $version Version row.
	 */
	public static function stitch( array $version ): string {
		$design = json_decode( (string) ( $version['design'] ?? '' ), true );

		return is_array( $design ) && is_string( $design['stitch'] ?? null ) ? $design['stitch'] : '';
	}

	/**
	 * A slot's parts, or null when it is not a slot.
	 *
	 * @param string $slot Slot.
	 * @return array{0: string, 1: string, 2: string|null}|null Kind, side and colour (mockups) or colour design (print files; null for the shared design).
	 */
	public static function slot( string $slot ): ?array {
		if ( 1 !== preg_match( self::SLOT, $slot, $match, PREG_UNMATCHED_AS_NULL ) ) {
			return null;
		}

		if ( null !== $match[1] ) {
			return array( self::PRINT, $match[1], $match[2] );
		}

		return null === $match[3] || null === $match[4] ? null : array( self::MOCKUP, $match[3], $match[4] );
	}

	/**
	 * The print files by design: SHARED ('') for the shared design, then the
	 * colour designs (ADR-096).
	 *
	 * @param array{print: array<string, array<string, mixed>>, designs?: array<string, array<string, array<string, mixed>>>} $files decode().
	 * @return array<string, array<string, array<string, mixed>>> Files by design and side.
	 */
	public static function prints( array $files ): array {
		$prints = array() === $files['print'] ? array() : array( ColorDesigns::SHARED => $files['print'] );

		foreach ( $files['designs'] ?? array() as $id => $sides ) {
			$prints[ (string) $id ] = $sides;
		}

		return $prints;
	}

	/**
	 * The used sides of the design a colour uses (ADR-096).
	 *
	 * @param array<mixed> $design Design document (stored JSON, decoded).
	 * @param string       $color  Colour key.
	 * @return list<string>
	 */
	public static function used_sides_of( array $design, string $color ): array {
		return self::used_sides( ColorDesigns::part( $design, ColorDesigns::part_of( $design, $color ) ) );
	}

	/**
	 * The used sides of every design the chosen colours use, in the order
	 * first met (ADR-096).
	 *
	 * @param array<mixed>  $design Design document (stored JSON, decoded).
	 * @param array<string> $colors Chosen colours.
	 * @return list<string>
	 */
	public static function all_used_sides( array $design, array $colors ): array {
		$sides = array();

		foreach ( array_keys( ColorDesigns::parts( $design, $colors ) ) as $id ) {
			$sides = array_merge( $sides, self::used_sides( ColorDesigns::part( $design, (string) $id ) ) );
		}

		return array_values( array_unique( $sides ) );
	}

	/**
	 * Whether a key among the mockups is a side's close-ups.
	 *
	 * @param string $key Key.
	 */
	public static function is_close( string $key ): bool {
		return str_starts_with( $key, self::CLOSE );
	}

	/**
	 * The side a key among the mockups belongs to.
	 *
	 * @param string $key Key.
	 */
	public static function side_of( string $key ): string {
		return self::is_close( $key ) ? substr( $key, strlen( self::CLOSE ) ) : $key;
	}

	/**
	 * The used sides of a design document, in its order: a side with a
	 * visible object or a background colour (ADR-095). They get print files
	 * and mockups.
	 *
	 * @param array<string, mixed> $design Design document (stored JSON, decoded).
	 * @return list<string>
	 */
	public static function used_sides( array $design ): array {
		$background = is_array( $design['background'] ?? null ) ? $design['background'] : array();
		$designed   = self::designed_sides( $design );

		return array_values( array_filter( array_map( 'strval', array_keys( is_array( $design['sides'] ?? null ) ? $design['sides'] : array() ) ), static fn( string $side ): bool => in_array( $side, $designed, true ) || is_string( $background[ $side ] ?? null ) ) );
	}

	/**
	 * The sides with a visible object, in the document's order: the sides that
	 * count for the price of extra design areas. A side with only a background
	 * colour is free (the owner's choice 2026-11-07, ADR-095).
	 *
	 * @param array<string, mixed> $design Design document (stored JSON, decoded).
	 * @return list<string>
	 */
	public static function designed_sides( array $design ): array {
		$used = array();

		foreach ( is_array( $design['sides'] ?? null ) ? $design['sides'] : array() as $side => $objects ) {
			foreach ( is_array( $objects ) ? $objects : array() as $object ) {
				if ( is_array( $object ) && true !== ( $object['hidden'] ?? false ) ) {
					$used[] = (string) $side;
					break;
				}
			}
		}

		return $used;
	}

	/**
	 * The stored files, normalised.
	 *
	 * @param string|null $json Stored JSON.
	 * @return array{stamp: string, print: array<string, array<string, mixed>>, designs: array<string, array<string, array<string, mixed>>>, mockups: array<string, array<string, array<string, mixed>>>}
	 */
	public static function decode( ?string $json ): array {
		$data  = null === $json ? null : json_decode( $json, true );
		$data  = is_array( $data ) ? $data : array();
		$files = array(
			'stamp'   => is_string( $data['stamp'] ?? null ) ? $data['stamp'] : '',
			'print'   => array(),
			'designs' => array(),
			'mockups' => array(),
		);

		foreach ( is_array( $data['print'] ?? null ) ? $data['print'] : array() as $side => $file ) {
			if ( self::is_file( $file ) ) {
				$files['print'][ (string) $side ] = $file;
			}
		}

		foreach ( is_array( $data['designs'] ?? null ) ? $data['designs'] : array() as $id => $sides ) {
			foreach ( is_array( $sides ) ? $sides : array() as $side => $file ) {
				if ( self::is_file( $file ) ) {
					$files['designs'][ (string) $id ][ (string) $side ] = $file;
				}
			}
		}

		foreach ( is_array( $data['mockups'] ?? null ) ? $data['mockups'] : array() as $side => $colors ) {
			foreach ( is_array( $colors ) ? $colors : array() as $color => $file ) {
				if ( self::is_file( $file ) ) {
					$files['mockups'][ (string) $side ][ (string) $color ] = $file;
				}
			}
		}

		return $files;
	}

	/**
	 * The file names of stored files.
	 *
	 * @param string|null $json Stored JSON.
	 * @return list<string>
	 */
	public static function names( ?string $json ): array {
		$files = self::decode( $json );
		$names = array_column( $files['print'], 'file' );

		foreach ( $files['designs'] as $sides ) {
			$names = array_merge( $names, array_column( $sides, 'file' ) );
		}

		foreach ( $files['mockups'] as $colors ) {
			$names = array_merge( $names, array_column( $colors, 'file' ) );
		}

		return array_values( array_filter( $names, 'is_string' ) );
	}

	/**
	 * Whether the files are exactly the ones a version needs: its stamp, one
	 * print file per used side of every design the colours use (ADR-096),
	 * and one mockup per colour and used side of its design.
	 *
	 * @param array<string, mixed> $version Version row.
	 */
	public static function complete( array $version ): bool {
		$files  = self::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
		$design = json_decode( (string) ( $version['design'] ?? '' ), true );
		$design = is_array( $design ) ? $design : array();
		$colors = json_decode( (string) ( $version['colors'] ?? '' ), true );
		$colors = is_array( $colors ) ? array_values( array_map( 'strval', $colors ) ) : array();
		// A chosen photo left from a withdrawn version is no file the browser
		// sends, and a design without a close-up is complete.
		$mockups = array_filter( $files['mockups'], static fn( int|string $side ): bool => ! str_starts_with( (string) $side, self::PHOTO ) && ! self::is_close( (string) $side ), ARRAY_FILTER_USE_KEY );

		if ( self::stamp( $version ) !== $files['stamp'] || array() === $colors ) {
			return false;
		}

		$parts  = ColorDesigns::parts( $design, $colors );
		$prints = self::prints( $files );

		if ( self::sorted( array_keys( $prints ) ) !== self::sorted( array_keys( $parts ) ) ) {
			return false;
		}

		// The colours each side needs a mockup of.
		$needed = array();

		foreach ( $parts as $id => $own ) {
			$sides = self::used_sides( ColorDesigns::part( $design, (string) $id ) );

			if ( array() === $sides || self::sorted( array_keys( $prints[ (string) $id ] ) ) !== self::sorted( $sides ) ) {
				return false;
			}

			foreach ( $sides as $side ) {
				$needed[ $side ] = array_merge( $needed[ $side ] ?? array(), $own );
			}
		}

		if ( self::sorted( array_keys( $mockups ) ) !== self::sorted( array_keys( $needed ) ) ) {
			return false;
		}

		foreach ( $mockups as $side => $by_color ) {
			if ( self::sorted( array_keys( $by_color ) ) !== self::sorted( $needed[ (string) $side ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value is a stored file.
	 *
	 * @param mixed $file Value.
	 * @phpstan-assert-if-true array<string, mixed> $file
	 */
	private static function is_file( mixed $file ): bool {
		return is_array( $file ) && is_string( $file['file'] ?? null ) && is_string( $file['mime'] ?? null ) && is_int( $file['width'] ?? null ) && is_int( $file['height'] ?? null ) && is_int( $file['bytes'] ?? null );
	}

	/**
	 * Strings sorted, for comparing sets.
	 *
	 * @param array<int|string> $values Values.
	 * @return list<string>
	 */
	private static function sorted( array $values ): array {
		$values = array_map( 'strval', $values );
		sort( $values );

		return $values;
	}
}
