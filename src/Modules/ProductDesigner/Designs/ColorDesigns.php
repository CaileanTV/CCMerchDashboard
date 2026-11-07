<?php
/**
 * The colour designs of a design.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the colour designs of a stored (validated) design (ADR-096): the top
 * level is the shared design, `designs` gives some colours a design of
 * their own. Each design reads as a document of its own, so the rules for
 * used sides, coverage and price apply to it unchanged.
 */
final class ColorDesigns {

	/**
	 * The id of the shared design.
	 */
	public const SHARED = '';

	/**
	 * The design a colour uses: a colour design's id, or SHARED.
	 *
	 * @param array<mixed> $design The design.
	 * @param string       $color  Colour key.
	 */
	public static function part_of( array $design, string $color ): string {
		foreach ( self::list( $design ) as $item ) {
			if ( in_array( $color, $item['colors'], true ) ) {
				return $item['id'];
			}
		}

		return self::SHARED;
	}

	/**
	 * One design as a document of its own: its sides, background colours and
	 * threads with the product's version and stitch colour. An unknown id has
	 * no sides.
	 *
	 * @param array<mixed> $design The design.
	 * @param string       $id     A colour design's id, or SHARED.
	 * @return array<string, mixed>
	 */
	public static function part( array $design, string $id ): array {
		$source = $design;

		if ( self::SHARED !== $id ) {
			$found  = array_values( array_filter( self::list( $design ), static fn( array $item ): bool => $id === $item['id'] ) );
			$source = $found[0] ?? array( 'sides' => array() );
		}

		$part = array(
			'version' => $design['version'] ?? DesignValidator::VERSION,
			'sides'   => is_array( $source['sides'] ?? null ) ? $source['sides'] : array(),
		);

		foreach ( array( 'background', 'threads' ) as $key ) {
			if ( is_array( $source[ $key ] ?? null ) ) {
				$part[ $key ] = $source[ $key ];
			}
		}

		if ( is_string( $design['stitch'] ?? null ) ) {
			$part['stitch'] = $design['stitch'];
		}

		return $part;
	}

	/**
	 * The designs the chosen colours use, with their colours in the chosen
	 * order: the shared design first, when a colour uses it.
	 *
	 * @param array<mixed>  $design The design.
	 * @param array<string> $colors Chosen colours.
	 * @return array<string, list<string>> Colours by design id.
	 */
	public static function parts( array $design, array $colors ): array {
		$parts = array();

		foreach ( $colors as $color ) {
			$parts[ self::part_of( $design, $color ) ][] = $color;
		}

		uksort( $parts, static fn( int|string $a, int|string $b ): int => self::order( $design, (string) $a ) <=> self::order( $design, (string) $b ) );

		return $parts;
	}

	/**
	 * Every design of the document: SHARED, then the colour designs.
	 *
	 * @param array<mixed> $design The design.
	 * @return list<string>
	 */
	public static function ids( array $design ): array {
		return array_merge( array( self::SHARED ), array_column( self::list( $design ), 'id' ) );
	}

	/**
	 * Whether the design has colour designs.
	 *
	 * @param array<mixed> $design The design.
	 */
	public static function has_any( array $design ): bool {
		return array() !== self::list( $design );
	}

	/**
	 * Where a design comes in the list: the shared one first.
	 *
	 * @param array<mixed> $design The design.
	 * @param string       $id     Design id.
	 */
	private static function order( array $design, string $id ): int {
		$index = array_search( $id, array_column( self::list( $design ), 'id' ), true );

		return self::SHARED === $id ? -1 : ( false === $index ? PHP_INT_MAX : (int) $index );
	}

	/**
	 * The colour designs, as stored.
	 *
	 * @param array<mixed> $design The design.
	 * @return list<array{id: string, colors: list<string>}&array<string, mixed>>
	 */
	private static function list( array $design ): array {
		$list = array();

		foreach ( is_array( $design['designs'] ?? null ) ? $design['designs'] : array() as $item ) {
			if ( is_array( $item ) && is_string( $item['id'] ?? null ) && is_array( $item['colors'] ?? null ) ) {
				$item['colors'] = array_values( array_filter( $item['colors'], 'is_string' ) );
				$list[]         = $item;
			}
		}

		return $list;
	}
}
