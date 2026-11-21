<?php
/**
 * The order of a catalog product's sizes.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-115 pkt. 6: clothing sizes from small to large (XS < S < M < L < XL <
 * 2XL … 8XL, XXL as 2XL), all others by their print area from smallest to
 * largest. Sizes alike in rank or area keep the catalog's order. Written for
 * Gelato (phase 52); the other catalogs can use it later.
 */
final class SizeOrder {

	/**
	 * Clothing sizes with their rank; `nXL` and `XX…L` are worked out.
	 */
	private const APPAREL = array(
		'XXS' => 1,
		'2XS' => 1,
		'XS'  => 2,
		'S'   => 3,
		'M'   => 4,
		'L'   => 5,
		'XL'  => 6,
	);

	/**
	 * The sizes in order.
	 *
	 * @phpstan-param list<string> $sizes
	 *
	 * @param string[]             $sizes Sizes in the catalog's order.
	 * @param array<string, float> $areas Print area of a size (any unit); a size without one goes last.
	 * @return list<string>
	 */
	public static function sort( array $sizes, array $areas = array() ): array {
		$ranks = array_map( self::rank( ... ), $sizes );

		if ( array() !== $sizes && ! in_array( null, $ranks, true ) ) {
			$keys = $ranks;
		} else {
			$keys = array_map( static fn( string $size ): float => $areas[ $size ] ?? PHP_FLOAT_MAX, $sizes );
		}

		$order = array_keys( $sizes );

		// Stable: equal keys keep the catalog's order.
		usort( $order, static fn( int $a, int $b ): int => 0 !== ( $keys[ $a ] <=> $keys[ $b ] ) ? $keys[ $a ] <=> $keys[ $b ] : $a <=> $b );

		return array_map( static fn( int $index ): string => $sizes[ $index ], $order );
	}

	/**
	 * A clothing size's rank, or null when the size is none.
	 *
	 * @param string $size Size.
	 */
	public static function rank( string $size ): ?int {
		$name = strtoupper( str_replace( ' ', '', $size ) );

		if ( isset( self::APPAREL[ $name ] ) ) {
			return self::APPAREL[ $name ];
		}

		if ( 1 === preg_match( '/^([2-8])XL$/', $name, $match ) ) {
			return 5 + (int) $match[1];
		}

		if ( 1 === preg_match( '/^(X{2,8})L$/', $name, $match ) ) {
			return 5 + strlen( $match[1] );
		}

		return null;
	}
}
