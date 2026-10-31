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
 *
 * The files are stored with the draft (`merchboard_designer_versions.files`):
 * `{ stamp, print: { side: file }, mockups: { side: { colour: file } } }`,
 * where a file is `{ file, mime, width, height, bytes }`. The stamp is the
 * hash of what the files show (design, colours, base version); files with
 * another stamp belong to an earlier state of the draft and are replaced.
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
	 * A slot: `print:<side>` or `mockup:<side>:<colour>` (keys as BaseView
	 * and the colours' sanitize_title() keys make them, or a chosen photo).
	 */
	private const SLOT = '/^(?:print:([a-z0-9][a-z0-9-]{0,31})|mockup:([a-z0-9][a-z0-9-]{0,31}|_photo-[0-9]{1,18}):([a-z0-9%_-]{1,32}))$/';

	/**
	 * The stamp of a version's content (its stored columns).
	 *
	 * @param array<string, mixed> $version Version row.
	 */
	public static function stamp( array $version ): string {
		return hash( 'sha256', (string) ( $version['design'] ?? '' ) . '|' . (string) ( $version['colors'] ?? '' ) . '|' . (string) ( $version['base_version'] ?? '' ) );
	}

	/**
	 * A slot's parts, or null when it is not a slot.
	 *
	 * @param string $slot Slot.
	 * @return array{0: string, 1: string, 2: string|null}|null Kind, side and colour (mockups).
	 */
	public static function slot( string $slot ): ?array {
		if ( 1 !== preg_match( self::SLOT, $slot, $match ) ) {
			return null;
		}

		return '' !== $match[1] ? array( self::PRINT, $match[1], null ) : array( self::MOCKUP, $match[2], $match[3] );
	}

	/**
	 * The used sides of a design document, in its order.
	 *
	 * @param array<string, mixed> $design Design document (stored JSON, decoded).
	 * @return list<string>
	 */
	public static function used_sides( array $design ): array {
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
	 * @return array{stamp: string, print: array<string, array<string, mixed>>, mockups: array<string, array<string, array<string, mixed>>>}
	 */
	public static function decode( ?string $json ): array {
		$data  = null === $json ? null : json_decode( $json, true );
		$data  = is_array( $data ) ? $data : array();
		$files = array(
			'stamp'   => is_string( $data['stamp'] ?? null ) ? $data['stamp'] : '',
			'print'   => array(),
			'mockups' => array(),
		);

		foreach ( is_array( $data['print'] ?? null ) ? $data['print'] : array() as $side => $file ) {
			if ( self::is_file( $file ) ) {
				$files['print'][ (string) $side ] = $file;
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

		foreach ( $files['mockups'] as $colors ) {
			$names = array_merge( $names, array_column( $colors, 'file' ) );
		}

		return array_values( array_filter( $names, 'is_string' ) );
	}

	/**
	 * Whether the files are exactly the ones a version needs: its stamp, one
	 * print file per used side and one mockup per used side and colour.
	 *
	 * @param array<string, mixed> $version Version row.
	 */
	public static function complete( array $version ): bool {
		$files  = self::decode( is_string( $version['files'] ?? null ) ? $version['files'] : null );
		$design = json_decode( (string) ( $version['design'] ?? '' ), true );
		$colors = json_decode( (string) ( $version['colors'] ?? '' ), true );
		$sides  = self::used_sides( is_array( $design ) ? $design : array() );
		$colors = is_array( $colors ) ? array_values( array_map( 'strval', $colors ) ) : array();
		// A chosen photo left from a withdrawn version is no file the browser sends.
		$mockups = array_filter( $files['mockups'], static fn( int|string $side ): bool => ! str_starts_with( (string) $side, self::PHOTO ), ARRAY_FILTER_USE_KEY );

		if ( self::stamp( $version ) !== $files['stamp'] || array() === $sides || array() === $colors ) {
			return false;
		}

		if ( self::sorted( array_keys( $files['print'] ) ) !== self::sorted( $sides ) || self::sorted( array_keys( $mockups ) ) !== self::sorted( $sides ) ) {
			return false;
		}

		foreach ( $mockups as $by_color ) {
			if ( self::sorted( array_keys( $by_color ) ) !== self::sorted( $colors ) ) {
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
