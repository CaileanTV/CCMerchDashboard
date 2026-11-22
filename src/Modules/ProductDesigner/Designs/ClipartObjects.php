<?php
/**
 * A clipart as an object in a design.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use Closure;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\Files\Clipart;
use MerchBoard\Modules\ProductDesigner\Files\ClipartDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-121 pkt. 6 and 10–14: the size is the clipart's own, a clipart with
 * parts has a paint per part, keeps its proportions and is never faded, and
 * a locked one is placed by the server: only its place sideways is the
 * creator's. The same rules check a saved design when it is submitted.
 *
 * A missing paint is refused and never filled in, so an older open page
 * cannot reset a design's colours.
 */
final class ClipartObjects {

	/**
	 * How far the two scales may differ by rounding.
	 */
	private const SAME_SCALE = 0.0002;

	/**
	 * The object as it is stored, or null when it is refused.
	 *
	 * @param array<string, mixed> $item        The object, with its common fields checked.
	 * @param mixed                $paints      The paints as given.
	 * @param Clipart              $clipart     The object's clipart.
	 * @param int                  $width       Print width of the side or master.
	 * @param int                  $height      Print height of the side or master.
	 * @param bool                 $embroidered Whether the side is embroidered.
	 * @return array<string, mixed>|null
	 */
	public static function normalized( array $item, mixed $paints, Clipart $clipart, int $width, int $height, bool $embroidered ): ?array {
		if ( ! $clipart->readable() ) {
			return null;
		}

		unset( $item['paints'] );
		$definition = $clipart->definition;

		// A clipart from before is an image like any other.
		if ( null === $definition ) {
			return $item;
		}

		$item['width']  = $definition->width;
		$item['height'] = $definition->height;

		if ( $definition->locked ) {
			$item = array_merge( $item, self::locked( $definition->width, $definition->height, $width, $height, (float) ( $item['x'] ?? 0 ) ) );
		}

		if ( array() === $definition->parts ) {
			return $item;
		}

		if ( abs( (float) ( $item['scaleX'] ?? 0 ) - (float) ( $item['scaleY'] ?? 0 ) ) > self::SAME_SCALE ) {
			return null;
		}

		$chosen = self::paints( $paints, $definition, $embroidered );

		if ( null === $chosen ) {
			return null;
		}

		$item['scaleY']  = $item['scaleX'];
		$item['opacity'] = 1;
		$item['paints']  = $chosen;

		return $item;
	}

	/**
	 * Whether every clipart of a stored design is as a save would store it.
	 *
	 * @phpstan-param Closure(int): ?Clipart $clipart
	 *
	 * @param array<mixed> $design  The stored design.
	 * @param BaseProduct  $base    Its base product as it is now.
	 * @param Closure      $clipart A clipart by asset; null for any other asset.
	 */
	public static function as_saved( array $design, BaseProduct $base, Closure $clipart ): bool {
		$parts = array( $design );
		$views = array();

		foreach ( is_array( $design['designs'] ?? null ) ? $design['designs'] : array() as $own ) {
			$parts[] = is_array( $own ) ? $own : array();
		}

		foreach ( $base->views as $view ) {
			$views[ $view->key ] = $view;
		}

		foreach ( $parts as $part ) {
			// Each place objects are: the objects, its size and whether it is embroidered.
			$places = array();

			foreach ( is_array( $part['sides'] ?? null ) ? $part['sides'] : array() as $key => $objects ) {
				$view     = $views[ $key ] ?? null;
				$size     = null === $view ? array( 0, 0 ) : $view->print_pixels();
				$places[] = array( $objects, $size[0], $size[1], null !== $view && self::embroidered( $view ) );
			}

			$formats = is_array( $part['formats'] ?? null ) ? $part['formats'] : array();

			foreach ( is_array( $formats['shared'] ?? null ) ? $formats['shared'] : array() as $master ) {
				if ( is_array( $master ) ) {
					$places[] = array( $master['objects'] ?? null, (int) ( $master['width'] ?? 0 ), (int) ( $master['height'] ?? 0 ), false );
				}
			}

			foreach ( $places as list( $objects, $width, $height, $embroidered ) ) {
				foreach ( is_array( $objects ) ? $objects : array() as $item ) {
					if ( ! is_array( $item ) || 'image' !== ( $item['type'] ?? null ) || ! is_int( $item['asset'] ?? null ) ) {
						continue;
					}

					$found = $clipart( $item['asset'] );

					if ( null === $found ) {
						continue;
					}

					// Without a size only a clipart from before can be checked.
					$saved = ( $width < 1 || $height < 1 ) && null !== $found->definition ? null : self::normalized( $item, $item['paints'] ?? null, $found, $width, $height, $embroidered );

					// By their JSON, as 1 and 1.0 are stored alike.
					if ( null === $saved || wp_json_encode( $saved ) !== wp_json_encode( $item ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Whether a side is embroidered.
	 *
	 * @param BaseView $view The side.
	 */
	public static function embroidered( BaseView $view ): bool {
		return '' !== $view->thread_pack;
	}

	/**
	 * A locked clipart's fields, as lockedPlacement() in the browser.
	 *
	 * @param int   $width        The clipart's width.
	 * @param int   $height       The clipart's height.
	 * @param int   $print_width  Print width.
	 * @param int   $print_height Print height.
	 * @param float $x            The middle the creator asks for.
	 * @return array{x: float, y: float, scaleX: float, scaleY: float, angle: int, flipX: bool, flipY: bool}
	 */
	private static function locked( int $width, int $height, int $print_width, int $print_height, float $x ): array {
		$fit    = min( (float) $print_width / $width, (float) $print_height / $height );
		$scale  = min( 100.0, max( 0.01, floor( $fit * 10000 ) / 10000 ) );
		$centre = $print_width / 2.0;
		$room   = max( 0.0, ( $print_width - $width * $scale ) / 2.0 );

		return array(
			'x'      => min( $centre + $room, max( $centre - $room, $x ) ),
			'y'      => $print_height / 2.0,
			'scaleX' => $scale,
			'scaleY' => $scale,
			'angle'  => 0,
			'flipX'  => false,
			'flipY'  => false,
		);
	}

	/**
	 * A paint per part, or null. Embroidery has no gradient, so one becomes
	 * its first colour as on a side embroidered later, and no palette, as the
	 * threads are the colours there.
	 *
	 * @param mixed             $value       The paints as given.
	 * @param ClipartDefinition $definition  The clipart's definition.
	 * @param bool              $embroidered Whether the side is embroidered.
	 * @return list<array<string, int|string>>|null
	 */
	private static function paints( mixed $value, ClipartDefinition $definition, bool $embroidered ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) !== count( $definition->parts ) ) {
			return null;
		}

		$palette = $embroidered ? null : $definition->palette;
		$paints  = array();

		foreach ( $value as $given ) {
			if ( ! is_array( $given ) ) {
				return null;
			}

			if ( 'solid' === ( $given['type'] ?? null ) ) {
				$color = self::color( $given['color'] ?? null, $palette );

				if ( null === $color ) {
					return null;
				}

				$paints[] = array(
					'type'  => 'solid',
					'color' => $color,
				);
				continue;
			}

			$from  = self::color( $given['from'] ?? null, $palette );
			$to    = self::color( $given['to'] ?? null, $palette );
			$angle = $given['angle'] ?? null;

			if ( 'gradient' !== ( $given['type'] ?? null ) || null === $from || null === $to || ! is_int( $angle ) || $angle < 0 || $angle > 359 ) {
				return null;
			}

			$paints[] = $embroidered
				? array(
					'type'  => 'solid',
					'color' => $from,
				)
				: array(
					'type'  => 'gradient',
					'from'  => $from,
					'to'    => $to,
					'angle' => $angle,
				);
		}

		return $paints;
	}

	/**
	 * A colour that may be chosen, in lower case.
	 *
	 * @phpstan-param list<string>|null $palette
	 *
	 * @param mixed         $value   The colour as given.
	 * @param string[]|null $palette The colours to choose between; null for any.
	 */
	private static function color( mixed $value, ?array $palette ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^#[0-9a-fA-F]{6}\z/', $value ) ) {
			return null;
		}

		$color = strtolower( $value );

		return null === $palette || in_array( $color, $palette, true ) ? $color : null;
	}
}
