<?php
/**
 * The variant a designer product shows first in the shop (phase 59).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;

defined( 'ABSPATH' ) || exit;

/**
 * The colour and size a designer product shows first (the owner's choice
 * 2026-11-25):
 *
 * - colour: the base product's fixed colour; else the colour of the
 *   creator's main image (`<key>:<colour>`, also before a provider's view
 *   is stored); else none;
 * - size: the only size; else the base product's fixed size; else none, so
 *   the customer chooses.
 *
 * A colour or size the creator did not choose is never shown first. Pure.
 */
final class DesignerDefaults {

	/**
	 * The colour key and size shown first; '' for none.
	 *
	 * @param BaseProduct $base    The base product.
	 * @param string[]    $colors  The chosen colour keys.
	 * @param string[]    $sizes   The chosen sizes.
	 * @param string|null $primary The creator's main image, `<key>:<colour>`, or null.
	 * @return array{color: string, size: string}
	 */
	public static function of( BaseProduct $base, array $colors, array $sizes, ?string $primary ): array {
		return array(
			'color' => self::color( $base, $colors, $primary ),
			'size'  => 1 === count( $sizes ) ? (string) reset( $sizes ) : ( in_array( $base->default_size, $sizes, true ) ? $base->default_size : '' ),
		);
	}

	/**
	 * The colour key shown first; '' for none.
	 *
	 * @param BaseProduct $base    The base product.
	 * @param string[]    $colors  The chosen colour keys.
	 * @param string|null $primary The creator's main image, or null.
	 */
	public static function color( BaseProduct $base, array $colors, ?string $primary ): string {
		if ( '' !== $base->default_color && in_array( $base->default_color, $colors, true ) ) {
			return $base->default_color;
		}

		$main = null === $primary || ! str_contains( $primary, ':' ) ? '' : substr( $primary, (int) strrpos( $primary, ':' ) + 1 );

		return in_array( $main, $colors, true ) ? $main : '';
	}
}
