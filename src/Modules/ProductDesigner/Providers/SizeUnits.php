<?php
/**
 * Sizes in inches written in centimetres.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * A catalog's sizes in inches, e.g. Printful's desk mat `16″×32″` or
 * Printify's poster `11.7" x 16.5"`, written in whole centimetres: "41 × 81
 * cm" (the owner's choice 2026-11-02). A size without inches stays as it is.
 * A size name may hold no comma, so there are no decimals. Applied to what
 * the administrator picks from (choice()) and, in the same way, to what is
 * imported, so the picked sizes still match.
 */
final class SizeUnits {

	/**
	 * Two lengths, the second in inches: `16″×32″`, `11.7" x 16.5"`, `16 x 32 in`.
	 */
	private const AREA = '/(\d+(?:\.\d+)?)\s*(?:″|"|”|in\b)?\s*[x×]\s*(\d+(?:\.\d+)?)\s*(?:″|"|”|in\b)/iu';

	/**
	 * One length in inches: `36″`.
	 */
	private const LENGTH = '/(\d+(?:\.\d+)?)\s*(?:″|"|”)/u';

	/**
	 * A size in centimetres, or the size itself when it has no inches.
	 *
	 * @param string $size Size.
	 */
	public static function to_cm( string $size ): string {
		$cm        = static fn( string $inches ): string => (string) (int) round( (float) $inches * 2.54 );
		$converted = preg_replace_callback( self::AREA, static fn( array $found ): string => $cm( $found[1] ) . ' × ' . $cm( $found[2] ) . ' cm', $size, 1, $count );

		if ( 0 === $count ) {
			$converted = preg_replace_callback( self::LENGTH, static fn( array $found ): string => $cm( $found[1] ) . ' cm', $size, -1, $count );
		}

		return 0 === $count || ! is_string( $converted ) ? $size : mb_substr( trim( $converted ), 0, 20 );
	}

	/**
	 * Whether any of a product's sizes is in inches.
	 *
	 * @phpstan-param list<string> $sizes
	 *
	 * @param string[] $sizes Sizes.
	 */
	public static function has_inches( array $sizes ): bool {
		foreach ( $sizes as $size ) {
			if ( self::to_cm( $size ) !== $size ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The new name of each size. Null when two sizes would get the same name:
	 * the product then keeps its inches.
	 *
	 * @phpstan-param list<string> $sizes
	 *
	 * @param string[] $sizes Sizes.
	 * @return array<string, string>|null
	 */
	public static function names( array $sizes ): ?array {
		$names = array();

		foreach ( $sizes as $size ) {
			$names[ $size ] = self::to_cm( $size );
		}

		return count( array_unique( $names ) ) === count( $names ) ? $names : null;
	}

	/**
	 * A product's sizes in centimetres everywhere they are named: the sizes,
	 * the variants by size, the sizes of a side and its name, and "(in)" in
	 * the product's name. Unchanged when two sizes would get the same name.
	 *
	 * @param array<string, mixed> $choice What the administrator picks from.
	 * @return array<string, mixed>
	 */
	public static function choice( array $choice ): array {
		$sizes = is_array( $choice['sizes'] ?? null ) ? array_values( array_filter( $choice['sizes'], 'is_string' ) ) : array();
		$names = self::names( $sizes );

		if ( null === $names ) {
			return $choice;
		}

		$rename             = static fn( mixed $size ): mixed => is_string( $size ) ? ( $names[ $size ] ?? $size ) : $size;
		$choice['sizes']    = array_values( $names );
		$choice['variants'] = array_map(
			static function ( mixed $by_size ) use ( $names ): mixed {
				if ( ! is_array( $by_size ) ) {
					return $by_size;
				}

				$renamed = array();

				foreach ( $by_size as $size => $variant ) {
					$renamed[ $names[ (string) $size ] ?? (string) $size ] = $variant;
				}

				return $renamed;
			},
			is_array( $choice['variants'] ?? null ) ? $choice['variants'] : array()
		);
		$choice['sides']    = array_map(
			static function ( mixed $side ) use ( $names, $rename ): mixed {
				if ( ! is_array( $side ) ) {
					return $side;
				}

				if ( is_array( $side['sizes'] ?? null ) ) {
					$side['sizes'] = array_values( array_map( $rename, $side['sizes'] ) );
				}

				if ( is_string( $side['name'] ?? null ) ) {
					$side['name'] = mb_substr( strtr( $side['name'], $names ), 0, 60 );
				}

				return $side;
			},
			is_array( $choice['sides'] ?? null ) ? $choice['sides'] : array()
		);

		if ( is_string( $choice['name'] ?? null ) ) {
			$choice['name'] = str_replace( '(in)', '(cm)', $choice['name'] );
		}

		return $choice;
	}
}
