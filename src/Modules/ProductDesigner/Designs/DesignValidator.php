<?php
/**
 * Validation of a design from the designer.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use Closure;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\ThreadPacks;

defined( 'ABSPATH' ) || exit;

/**
 * The server's check of a design (ADR-080 pkt. 4–5). The designer sends its
 * own small document, never Fabric's JSON, and only this whitelist is
 * stored:
 *
 * `{ "version": 1, "sides": { "<side key>": [ object, … ] } }`, where an
 * object is a `text`, an `image` (an asset: the creator's own upload or a
 * clipart) or a shape (`rect`, `ellipse`, `triangle`). Positions are the
 * object's centre in the side's print file pixels; every number has a
 * range, colours are `#rrggbb`, fonts are approved Google Fonts. Unknown
 * keys are dropped, anything else invalid refuses the whole design. The
 * normalized document is what is stored, so a stored design is always
 * valid.
 *
 * ADR-095: on a base product that offers it, `background` gives a side a
 * colour (`#rrggbb`) under its objects. A side with a background is kept
 * in `sides`, also without objects. On another base product the key is
 * dropped (a draft from before the tick was removed), and so is the colour
 * of an embroidered or unknown side (a side changed later, review 33.5).
 *
 * ADR-096: `designs` lists colour designs, `[{ "id": "d1", "colors": [ … ],
 * "sides": …, "background": …, "threads": … }]`, checked like the top level
 * (the shared design). A colour is in at most one design; colours not
 * chosen are dropped and so is a design left without colours. Without any,
 * the key is left out, so a design from before keeps its stamp.
 */
final class DesignValidator {

	/**
	 * Document version.
	 */
	public const VERSION = 1;

	/**
	 * Most objects per side.
	 */
	public const MAX_OBJECTS = 100;

	/**
	 * Largest stored design (bytes of JSON).
	 */
	public const MAX_BYTES = 262144;

	/**
	 * Object types.
	 */
	public const TYPES = array( 'text', 'image', 'rect', 'ellipse', 'triangle' );

	/**
	 * Most colour designs besides the shared one (ADR-096).
	 */
	public const MAX_DESIGNS = 8;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): bool $asset_allowed
	 * @phpstan-param list<string> $fonts
	 *
	 * @param Closure  $asset_allowed Whether the creator may use an asset (own upload or clipart).
	 * @param string[] $fonts         Approved font families.
	 */
	public function __construct( private Closure $asset_allowed, private array $fonts ) {
	}

	/**
	 * The normalized design, or a refusal.
	 *
	 * @phpstan-param list<string> $stitches
	 * @phpstan-param list<string>|null $colors
	 *
	 * @param mixed         $design   Decoded design.
	 * @param BaseProduct   $base     The base product it is designed on.
	 * @param string[]      $stitches The stitch colours the base product has; the first without a choice.
	 * @param string[]|null $colors   The chosen colours (null: every colour of the base product).
	 * @return array{version: int, sides: array<string, list<array<string, mixed>>>, background?: array<string, string>, threads?: array<string, array<string, string>>, stitch?: string, designs?: list<array{id: string, colors: list<string>, sides: array<string, list<array<string, mixed>>>, background?: array<string, string>, threads?: array<string, array<string, string>>}>}
	 * @throws DesignerException When the design is invalid (DESIGN_INVALID).
	 */
	public function validate( mixed $design, BaseProduct $base, array $stitches = array(), ?array $colors = null ): array {
		if ( ! is_array( $design ) || self::VERSION !== ( $design['version'] ?? null ) ) {
			self::refuse();
		}

		$normalized = array_merge( array( 'version' => self::VERSION ), $this->part( $design, $base ) );

		if ( array() !== $stitches ) {
			$normalized['stitch'] = $design['stitch'] ?? $stitches[0];

			if ( ! in_array( $normalized['stitch'], $stitches, true ) ) {
				self::refuse();
			}
		}

		$designs = $this->designs( $design['designs'] ?? null, $base, $colors );

		if ( array() !== $designs ) {
			$normalized['designs'] = $designs;
		}

		$json = wp_json_encode( $normalized );

		if ( false === $json || strlen( $json ) > self::MAX_BYTES ) {
			self::refuse();
		}

		return $normalized;
	}

	/**
	 * The colour designs (ADR-096): unique ids `d1`…, each with colours of
	 * its own among the chosen ones, in the order given.
	 *
	 * @phpstan-param list<string>|null $colors
	 *
	 * @param mixed         $designs Input.
	 * @param BaseProduct   $base    The base product.
	 * @param string[]|null $colors  The chosen colours (null: all of the base product).
	 * @return list<array{id: string, colors: list<string>, sides: array<string, list<array<string, mixed>>>, background?: array<string, string>, threads?: array<string, array<string, string>>}>
	 * @throws DesignerException When a design is refused.
	 */
	private function designs( mixed $designs, BaseProduct $base, ?array $colors ): array {
		if ( null === $designs ) {
			return array();
		}

		if ( ! is_array( $designs ) || ! array_is_list( $designs ) || count( $designs ) > self::MAX_DESIGNS ) {
			self::refuse();
		}

		$offered    = array_column( $base->colors, 'key' );
		$chosen     = null === $colors ? $offered : array_values( array_intersect( $colors, $offered ) );
		$ids        = array();
		$taken      = array();
		$normalized = array();

		foreach ( $designs as $item ) {
			$id = is_array( $item ) ? ( $item['id'] ?? null ) : null;

			if ( ! is_array( $item ) || ! is_string( $id ) || 1 !== preg_match( '/^d[1-9][0-9]?$/', $id ) || isset( $ids[ $id ] ) || ! is_array( $item['colors'] ?? null ) || ! array_is_list( $item['colors'] ) ) {
				self::refuse();
			}

			$ids[ $id ] = true;
			$own        = array();

			foreach ( $item['colors'] as $color ) {
				if ( ! is_string( $color ) || isset( $taken[ $color ] ) ) {
					self::refuse();
				}

				$taken[ $color ] = true;

				if ( in_array( $color, $chosen, true ) ) {
					$own[] = $color;
				}
			}

			$part = $this->part( $item, $base );

			if ( array() !== $own ) {
				$normalized[] = array_merge(
					array(
						'id'     => $id,
						'colors' => $own,
					),
					$part
				);
			}
		}

		return $normalized;
	}

	/**
	 * The sides, background colours and threads of one design: the shared
	 * one at the top level, or a colour design (ADR-096).
	 *
	 * @param array<mixed, mixed> $design Input.
	 * @param BaseProduct         $base   The base product.
	 * @return array{sides: array<string, list<array<string, mixed>>>, background?: array<string, string>, threads?: array<string, array<string, string>>}
	 * @throws DesignerException When something is refused.
	 */
	private function part( array $design, BaseProduct $base ): array {
		if ( ! is_array( $design['sides'] ?? null ) ) {
			self::refuse();
		}

		$sides = array();
		$keys  = array_map( static fn( $view ): string => $view->key, $base->views );

		foreach ( $design['sides'] as $key => $items ) {
			if ( ! is_string( $key ) || ! in_array( $key, $keys, true ) || ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > self::MAX_OBJECTS ) {
				self::refuse();
			}

			$sides[ $key ] = array_map( fn( mixed $item ): array => $this->object( $item ), $items );
		}

		$background = self::background( $design['background'] ?? array(), $base );

		// A side with only a background is a side of the design too.
		foreach ( array_keys( $background ) as $key ) {
			$sides[ $key ] ??= array();
		}

		$normalized = array( 'sides' => $sides );

		if ( array() !== $background ) {
			$normalized['background'] = $background;
		}

		$threads = $this->threads( $design['threads'] ?? array(), $base );

		if ( array() !== $threads ) {
			$normalized['threads'] = $threads;
		}

		return $normalized;
	}

	/**
	 * The background colour by side (ADR-095), in the base product's order of
	 * sides. Empty on a base product that offers none.
	 *
	 * @param mixed       $background Input.
	 * @param BaseProduct $base       The base product.
	 * @return array<string, string>
	 * @throws DesignerException When a colour is refused.
	 */
	private static function background( mixed $background, BaseProduct $base ): array {
		if ( ! $base->background_color ) {
			return array();
		}

		if ( ! is_array( $background ) ) {
			self::refuse();
		}

		$normalized = array();

		foreach ( $background as $color ) {
			if ( ! is_string( $color ) || 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
				self::refuse();
			}
		}

		foreach ( $base->views as $view ) {
			if ( '' === $view->thread_pack && is_string( $background[ $view->key ] ?? null ) ) {
				$normalized[ $view->key ] = strtolower( $background[ $view->key ] );
			}
		}

		return $normalized;
	}

	/**
	 * The creator's choice of threads: by embroidered side, the thread found
	 * for a colour and the thread to use instead.
	 *
	 * @param mixed       $threads Input.
	 * @param BaseProduct $base    The base product.
	 * @return array<string, array<string, string>>
	 * @throws DesignerException When a side or thread is refused.
	 */
	private function threads( mixed $threads, BaseProduct $base ): array {
		if ( ! is_array( $threads ) ) {
			self::refuse();
		}

		$normalized = array();

		foreach ( $threads as $key => $swaps ) {
			$found = array_values( array_filter( $base->views, static fn( $view ): bool => (string) $key === $view->key ) );
			$pack  = '' === ( $found[0]->thread_pack ?? '' ) ? null : ThreadPacks::find( $found[0]->thread_pack );

			if ( null === $pack || ! is_array( $swaps ) ) {
				self::refuse();
			}

			$codes = array_column( $pack['threads'], 'code' );

			foreach ( $swaps as $from => $to ) {
				if ( ! is_string( $to ) || ! in_array( (string) $from, $codes, true ) || ! in_array( $to, $codes, true ) ) {
					self::refuse();
				}

				$normalized[ (string) $key ][ (string) $from ] = $to;
			}
		}

		return $normalized;
	}

	/**
	 * One object.
	 *
	 * @param mixed $item Object.
	 * @return array<string, mixed>
	 * @throws DesignerException When it is invalid.
	 */
	private function object( mixed $item ): array {
		if ( ! is_array( $item ) || ! in_array( $item['type'] ?? null, self::TYPES, true ) ) {
			self::refuse();
		}

		$id = $item['id'] ?? null;

		if ( ! is_string( $id ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,36}$/', $id ) ) {
			self::refuse();
		}

		$common = array(
			'type'    => $item['type'],
			'id'      => $id,
			'x'       => self::number( $item, 'x', -20000, 20000 ),
			'y'       => self::number( $item, 'y', -20000, 20000 ),
			'width'   => self::number( $item, 'width', 1, 20000 ),
			'height'  => self::number( $item, 'height', 1, 20000 ),
			'angle'   => self::number( $item, 'angle', -360, 360 ),
			'scaleX'  => self::number( $item, 'scaleX', 0.01, 100 ),
			'scaleY'  => self::number( $item, 'scaleY', 0.01, 100 ),
			'flipX'   => self::flag( $item, 'flipX' ),
			'flipY'   => self::flag( $item, 'flipY' ),
			'opacity' => self::number( $item, 'opacity', 0, 1 ),
			'locked'  => self::flag( $item, 'locked' ),
			'hidden'  => self::flag( $item, 'hidden' ),
		);

		return array_merge(
			$common,
			match ( $item['type'] ) {
				'text'  => $this->text( $item ),
				'image' => $this->image( $item ),
				default => self::shape( $item ),
			}
		);
	}

	/**
	 * The fields of a text.
	 *
	 * @param array<mixed> $item Object.
	 * @return array<string, mixed>
	 * @throws DesignerException When they are invalid.
	 */
	private function text( array $item ): array {
		$text = $item['text'] ?? null;
		$font = $item['font'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) || mb_strlen( $text ) > 500 || ! is_string( $font ) || ! in_array( $font, $this->fonts, true ) ) {
			self::refuse();
		}

		$weight = self::number( $item, 'weight', 100, 900 );
		$align  = $item['align'] ?? 'left';

		if ( 0 !== ( (int) $weight ) % 100 || ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			self::refuse();
		}

		return array(
			'text'        => wp_check_invalid_utf8( $text ),
			'font'        => $font,
			'weight'      => (int) $weight,
			'italic'      => self::flag( $item, 'italic' ),
			'size'        => self::number( $item, 'size', 4, 2000 ),
			'fill'        => self::color( $item, 'fill', false ),
			'align'       => $align,
			'lineHeight'  => self::number( $item, 'lineHeight', 0.5, 3 ),
			'charSpacing' => self::number( $item, 'charSpacing', -500, 2000 ),
			'stroke'      => self::color( $item, 'stroke', true ),
			'strokeWidth' => self::number( $item, 'strokeWidth', 0, 100 ),
		);
	}

	/**
	 * The fields of an image.
	 *
	 * @param array<mixed> $item Object.
	 * @return array{asset: int}
	 * @throws DesignerException When the asset may not be used.
	 */
	private function image( array $item ): array {
		$asset = $item['asset'] ?? null;

		if ( ! is_int( $asset ) || $asset < 1 || ! ( $this->asset_allowed )( $asset ) ) {
			self::refuse();
		}

		return array( 'asset' => $asset );
	}

	/**
	 * The fields of a shape.
	 *
	 * @param array<mixed> $item Object.
	 * @return array<string, mixed>
	 * @throws DesignerException When they are invalid.
	 */
	private static function shape( array $item ): array {
		$fill   = self::color( $item, 'fill', true );
		$stroke = self::color( $item, 'stroke', true );

		if ( null === $fill && null === $stroke ) {
			self::refuse();
		}

		return array(
			'fill'        => $fill,
			'stroke'      => $stroke,
			'strokeWidth' => self::number( $item, 'strokeWidth', 0, 200 ),
			'radius'      => 'rect' === $item['type'] ? self::number( $item, 'radius', 0, 10000 ) : 0,
		);
	}

	/**
	 * A number in a range.
	 *
	 * @param array<mixed> $item Object.
	 * @param string       $key    Key.
	 * @param float        $min    Smallest.
	 * @param float        $max    Largest.
	 * @throws DesignerException When it is missing or out of range.
	 */
	private static function number( array $item, string $key, float $min, float $max ): float|int {
		$value = $item[ $key ] ?? null;

		if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) || $value < $min || $value > $max ) {
			self::refuse();
		}

		return is_float( $value ) ? round( $value, 4 ) : $value;
	}

	/**
	 * A flag (missing is false).
	 *
	 * @param array<mixed> $item Object.
	 * @param string       $key    Key.
	 * @throws DesignerException When it is not a boolean.
	 */
	private static function flag( array $item, string $key ): bool {
		$value = $item[ $key ] ?? false;

		if ( ! is_bool( $value ) ) {
			self::refuse();
		}

		return $value;
	}

	/**
	 * A colour `#rrggbb` (lower case), or null when allowed.
	 *
	 * @param array<mixed> $item   Object.
	 * @param string       $key      Key.
	 * @param bool         $nullable Whether null (no colour) is allowed.
	 * @throws DesignerException When it is invalid.
	 */
	private static function color( array $item, string $key, bool $nullable ): ?string {
		$value = $item[ $key ] ?? null;

		if ( null === $value && $nullable ) {
			return null;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
			self::refuse();
		}

		return strtolower( $value );
	}

	/**
	 * Refuses the design.
	 *
	 * @throws DesignerException Always.
	 */
	private static function refuse(): never {
		throw new DesignerException( DesignerException::DESIGN_INVALID );
	}
}
