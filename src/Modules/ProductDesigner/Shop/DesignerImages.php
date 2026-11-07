<?php
/**
 * The order of a designer product's images in the shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Which image is the product's and in which order the gallery follows: the
 * colours in the creator's order, each with its sides in the base product's
 * order (a chosen photo first, a close-up after its side). The image the
 * creator chose (ADR-096), `<side>:<colour>`, comes first; when a provider
 * replaced it with photos, that colour's first image does. Pure.
 */
final class DesignerImages {

	/**
	 * The product's images and each colour's first image.
	 *
	 * @param BaseProduct        $base    The base product.
	 * @param array<string>      $chosen  The chosen colour keys, in the creator's order.
	 * @param array<int|string>  $sides   The keys among the version's mockups.
	 * @param array<string, int> $media   Attachment by slot (`mockup:<key>:<colour>`).
	 * @param string|null        $primary The image the creator chose, `<key>:<colour>`, or null.
	 * @return array{images: list<int>, first: array<string, int>}
	 */
	public static function order( BaseProduct $base, array $chosen, array $sides, array $media, ?string $primary ): array {
		$chosen = array_values( $chosen );
		$colors = array_values( array_intersect( $chosen, array_column( $base->colors, 'key' ) ) );
		$order  = array_flip( array_map( static fn( BaseView $view ): string => $view->key, $base->views ) );
		$sides  = array_map( 'strval', $sides );

		usort( $sides, static fn( string $a, string $b ): int => ( $order[ SubmissionFiles::side_of( $a ) ] ?? -1 ) <=> ( $order[ SubmissionFiles::side_of( $b ) ] ?? -1 ) );

		$images = array();
		$first  = array();

		foreach ( $colors as $color ) {
			foreach ( $sides as $side ) {
				$attachment = $media[ SubmissionFiles::MOCKUP . ':' . $side . ':' . $color ] ?? null;

				if ( null !== $attachment ) {
					$images[]          = $attachment;
					$first[ $color ] ??= $attachment;
				}
			}
		}

		$main = self::main( $primary, $media, $first );

		if ( null !== $main ) {
			$images = array_merge( array( $main ), array_values( array_diff( $images, array( $main ) ) ) );
		}

		return array(
			'images' => $images,
			'first'  => $first,
		);
	}

	/**
	 * Whether a choice names a mockup of the version: `<key>:<colour>` with a
	 * chosen colour, the key a used side of that colour's design or a photo.
	 *
	 * @param mixed         $primary The choice.
	 * @param array<mixed>  $design  The version's design.
	 * @param array<string> $colors  The chosen colours.
	 */
	public static function valid( mixed $primary, array $design, array $colors ): bool {
		if ( ! is_string( $primary ) || 1 !== preg_match( '/^([a-z0-9][a-z0-9-]{0,31}|_photo-[0-9]{1,18}):([a-z0-9%_-]{1,32})$/', $primary, $match ) ) {
			return false;
		}

		return in_array( $match[2], $colors, true ) && ( str_starts_with( $match[1], SubmissionFiles::PHOTO ) || in_array( $match[1], SubmissionFiles::used_sides_of( $design, $match[2] ), true ) );
	}

	/**
	 * The attachment of the chosen image, or of its colour's first image.
	 *
	 * @param string|null        $primary The choice.
	 * @param array<string, int> $media   Attachment by slot.
	 * @param array<string, int> $first   Each colour's first image.
	 */
	private static function main( ?string $primary, array $media, array $first ): ?int {
		if ( null === $primary || ! str_contains( $primary, ':' ) ) {
			return null;
		}

		$color = substr( $primary, (int) strrpos( $primary, ':' ) + 1 );

		return $media[ SubmissionFiles::MOCKUP . ':' . $primary ] ?? $first[ $color ] ?? null;
	}
}
