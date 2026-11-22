<?php
/**
 * What a clipart for chosen base products is made of.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Files;

defined( 'ABSPATH' ) || exit;

/**
 * The column `definition` (schema 55, ADR-121 pkt. 9): the base products the
 * clipart is offered on and, with coloured parts, the parts, the drawing
 * order, the atlas, the palette and the lock. The shape is the browser's
 * `ClipartArt` (clipart-model.ts). Anything unreadable reads as no
 * definition, and an artwork without one is offered nowhere.
 *
 * Only the base products can change: submitted designs do not hash the rest.
 *
 * @phpstan-type Rect array{x: int, y: int, width: int, height: int}
 * @phpstan-type Place array{x: int, y: int}
 * @phpstan-type Solid array{type: 'solid', color: string}
 * @phpstan-type Gradient array{type: 'gradient', from: string, to: string, angle: int}
 * @phpstan-type Part array{label: string, box: Rect, stroke: string|null, scale: float, paint: Solid|Gradient, gradient: Gradient}
 * @phpstan-type Step array{type: 'image', rect: Rect, image: Place}|array{type: 'part', part: int, rect: Rect, fill: Place, grown: Place|null}
 * @phpstan-type Atlas array{file: string, width: int, height: int}
 */
final readonly class ClipartDefinition {

	/**
	 * The most parts.
	 */
	public const MAX_PARTS = 8;

	/**
	 * The largest area of a clipart with parts.
	 */
	public const MAX_AREA = 16000000;

	/**
	 * The most colours of a palette.
	 */
	public const MAX_PALETTE = 64;

	/**
	 * The most base products chosen.
	 */
	public const MAX_BASES = 1000;

	/**
	 * The longest name of a part.
	 */
	public const MAX_LABEL = 100;

	/**
	 * How far a part's box may go past the clipart: the stroke of a shape
	 * outside the document reaches into it.
	 */
	private const FAR = 100000;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int>|null $bases
	 * @phpstan-param list<Part> $parts
	 * @phpstan-param list<Step> $steps
	 * @phpstan-param Atlas|null $atlas
	 * @phpstan-param list<string>|null $palette
	 *
	 * @param int        $width   The clipart's width.
	 * @param int        $height  The clipart's height.
	 * @param int[]|null $bases   The base products it is offered on; null for all.
	 * @param array[]    $parts   The parts the creator colours; none for a flat clipart.
	 * @param array[]    $steps   The drawing order, bottom first.
	 * @param array|null $atlas   The atlas' file and size; null without parts.
	 * @param array|null $palette The colours to choose between; null for free colours.
	 * @param bool       $locked  Whether the placement is locked.
	 */
	private function __construct( public int $width, public int $height, public ?array $bases, public array $parts, public array $steps, public ?array $atlas, public ?array $palette, public bool $locked ) {
	}

	/**
	 * A valid definition, or null.
	 *
	 * @param mixed $data   The definition's data.
	 * @param int   $width  The clipart's width.
	 * @param int   $height The clipart's height.
	 */
	public static function from_data( mixed $data, int $width, int $height ): ?self {
		if ( ! is_array( $data ) || $width < 1 || $height < 1 || $width > DesignerFiles::MAX_PIXELS || $height > DesignerFiles::MAX_PIXELS || ! is_bool( $data['locked'] ?? null ) || ! array_key_exists( 'bases', $data ) ) {
			return null;
		}

		$bases = null === $data['bases'] ? null : self::base_ids( $data['bases'] );

		if ( null !== $data['bases'] && null === $bases ) {
			return null;
		}

		$listed = $data['parts'] ?? null;
		$order  = $data['steps'] ?? null;

		if ( ! is_array( $listed ) || ! array_is_list( $listed ) || ! is_array( $order ) || ! array_is_list( $order ) || count( $listed ) > self::MAX_PARTS ) {
			return null;
		}

		// A flat clipart for chosen base products.
		if ( array() === $listed ) {
			return array() === $order && null === ( $data['atlas'] ?? null ) && null === ( $data['palette'] ?? null )
				? new self( $width, $height, $bases, array(), array(), null, null, $data['locked'] )
				: null;
		}

		if ( $width * $height > self::MAX_AREA ) {
			return null;
		}

		$atlas = self::atlas( $data['atlas'] ?? null );
		$parts = array();

		foreach ( $listed as $item ) {
			$part = self::part( $item );

			if ( null === $part ) {
				return null;
			}

			$parts[] = $part;
		}

		$steps = null === $atlas ? null : self::steps( $order, $parts, $atlas, $width, $height );

		if ( null === $atlas || null === $steps ) {
			return null;
		}

		$palette = null;

		if ( null !== ( $data['palette'] ?? null ) ) {
			$palette = self::palette( $data['palette'], $parts );

			if ( null === $palette ) {
				return null;
			}
		}

		return new self( $width, $height, $bases, $parts, $steps, $atlas, $palette, $data['locked'] );
	}

	/**
	 * A stored definition, or null for none and for anything unreadable.
	 *
	 * @param mixed $stored The column's value.
	 * @param int   $width  The clipart's width.
	 * @param int   $height The clipart's height.
	 */
	public static function from_stored( mixed $stored, int $width, int $height ): ?self {
		return is_string( $stored ) && '' !== $stored ? self::from_data( json_decode( $stored, true ), $width, $height ) : null;
	}

	/**
	 * As it is stored.
	 */
	public function to_json(): string {
		return (string) wp_json_encode(
			array(
				'bases'   => $this->bases,
				'parts'   => $this->parts,
				'steps'   => $this->steps,
				'atlas'   => $this->atlas,
				'palette' => $this->palette,
				'locked'  => $this->locked,
			)
		);
	}

	/**
	 * Whether it is offered on a base product.
	 *
	 * @param int $base_id Base product.
	 */
	public function offered_on( int $base_id ): bool {
		return null === $this->bases || in_array( $base_id, $this->bases, true );
	}

	/**
	 * The same with other base products, or null when they are not valid.
	 *
	 * @param int[]|null $bases Base products; null for all.
	 */
	public function with_bases( ?array $bases ): ?self {
		$ids = null === $bases ? null : self::base_ids( $bases );

		return null !== $bases && null === $ids ? null : new self( $this->width, $this->height, $ids, $this->parts, $this->steps, $this->atlas, $this->palette, $this->locked );
	}

	/**
	 * Base product ids, each once.
	 *
	 * @param mixed $value Data.
	 * @return list<int>|null
	 */
	private static function base_ids( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_BASES ) {
			return null;
		}

		$ids = array();

		foreach ( $value as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return null;
			}

			$ids[ $id ] = $id;
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * A part.
	 *
	 * @param mixed $value Data.
	 * @return array|null
	 * @phpstan-return Part|null
	 */
	private static function part( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! is_string( $value['label'] ?? null ) || ! array_key_exists( 'stroke', $value ) ) {
			return null;
		}

		$label    = $value['label'];
		$box      = self::rect( $value['box'] ?? null );
		$stroke   = null === $value['stroke'] ? null : self::color( $value['stroke'] );
		$scale    = $value['scale'] ?? null;
		$gradient = self::gradient( $value['gradient'] ?? null );
		$paint    = self::solid( $value['paint'] ?? null ) ?? self::gradient( $value['paint'] ?? null );

		if (
			mb_strlen( $label ) > self::MAX_LABEL
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $label )
			|| null === $box
			|| ( null !== $value['stroke'] && null === $stroke )
			|| ( ! is_int( $scale ) && ! is_float( $scale ) )
			|| $scale < 0.1
			|| $scale > 1.5
			|| null === $gradient
			|| null === $paint
		) {
			return null;
		}

		return array(
			'label'    => $label,
			'box'      => $box,
			'stroke'   => $stroke,
			'scale'    => (float) $scale,
			'paint'    => $paint,
			'gradient' => $gradient,
		);
	}

	/**
	 * The drawing order: every part once, and everything inside the clipart
	 * and the atlas.
	 *
	 * @phpstan-param list<mixed> $value
	 * @phpstan-param list<Part> $parts
	 * @phpstan-param Atlas $atlas
	 *
	 * @param array   $value  Data.
	 * @param array[] $parts  The parts.
	 * @param array   $atlas  The atlas.
	 * @param int     $width  The clipart's width.
	 * @param int     $height The clipart's height.
	 * @return array|null
	 * @phpstan-return list<Step>|null
	 */
	private static function steps( array $value, array $parts, array $atlas, int $width, int $height ): ?array {
		if ( count( $value ) > 2 * count( $parts ) + 1 ) {
			return null;
		}

		$steps = array();
		$drawn = array();

		foreach ( $value as $step ) {
			$rect = is_array( $step ) ? self::rect( $step['rect'] ?? null ) : null;

			if ( ! is_array( $step ) || null === $rect || $rect['x'] < 0 || $rect['y'] < 0 || $rect['x'] + $rect['width'] > $width || $rect['y'] + $rect['height'] > $height ) {
				return null;
			}

			$type = $step['type'] ?? null;

			if ( 'image' === $type ) {
				$image = self::place( $step['image'] ?? null, $rect, $atlas );

				if ( null === $image ) {
					return null;
				}

				$steps[] = array(
					'type'  => 'image',
					'rect'  => $rect,
					'image' => $image,
				);
				continue;
			}

			$index = $step['part'] ?? null;

			if ( 'part' !== $type || ! is_int( $index ) || ! isset( $parts[ $index ] ) || isset( $drawn[ $index ] ) ) {
				return null;
			}

			$fill  = self::place( $step['fill'] ?? null, $rect, $atlas );
			$grown = null;

			// A stroke has its mask, and only a stroke.
			if ( null !== $parts[ $index ]['stroke'] ) {
				$grown = self::place( $step['grown'] ?? null, $rect, $atlas );

				if ( null === $grown ) {
					return null;
				}
			} elseif ( null !== ( $step['grown'] ?? null ) ) {
				return null;
			}

			if ( null === $fill ) {
				return null;
			}

			$drawn[ $index ] = true;

			$steps[] = array(
				'type'  => 'part',
				'part'  => $index,
				'rect'  => $rect,
				'fill'  => $fill,
				'grown' => $grown,
			);
		}

		return count( $drawn ) === count( $parts ) ? $steps : null;
	}

	/**
	 * A palette, which holds every colour a part starts with.
	 *
	 * @phpstan-param list<Part> $parts
	 *
	 * @param mixed   $value Data.
	 * @param array[] $parts The parts.
	 * @return list<string>|null
	 */
	private static function palette( mixed $value, array $parts ): ?array {
		if ( ! is_array( $value ) || array() === $value || ! array_is_list( $value ) || count( $value ) > self::MAX_PALETTE ) {
			return null;
		}

		$colors = array();

		foreach ( $value as $item ) {
			$color = self::color( $item );

			if ( null === $color ) {
				return null;
			}

			$colors[ $color ] = $color;
		}

		foreach ( $parts as $part ) {
			$starts = array( $part['gradient']['from'], $part['gradient']['to'] );
			$paint  = $part['paint'];

			if ( 'solid' === $paint['type'] ) {
				$starts[] = $paint['color'];
			} else {
				array_push( $starts, $paint['from'], $paint['to'] );
			}

			foreach ( $starts as $start ) {
				if ( ! isset( $colors[ $start ] ) ) {
					return null;
				}
			}
		}

		return array_values( $colors );
	}

	/**
	 * The atlas.
	 *
	 * @param mixed $value Data.
	 * @return array|null
	 * @phpstan-return Atlas|null
	 */
	private static function atlas( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$file   = $value['file'] ?? null;
		$width  = $value['width'] ?? null;
		$height = $value['height'] ?? null;

		if ( ! is_string( $file ) || 1 !== preg_match( '/^[a-f0-9]{32}\.png\z/', $file ) || ! is_int( $width ) || ! is_int( $height ) || $width < 1 || $height < 1 || $width > DesignerFiles::MAX_PIXELS || $height > DesignerFiles::MAX_PIXELS ) {
			return null;
		}

		return array(
			'file'   => $file,
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * A rectangle.
	 *
	 * @param mixed $value Data.
	 * @return array|null
	 * @phpstan-return Rect|null
	 */
	private static function rect( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$x      = $value['x'] ?? null;
		$y      = $value['y'] ?? null;
		$width  = $value['width'] ?? null;
		$height = $value['height'] ?? null;

		if ( ! is_int( $x ) || ! is_int( $y ) || ! is_int( $width ) || ! is_int( $height ) || $width < 1 || $height < 1 || max( abs( $x ), abs( $y ), $width, $height ) > self::FAR ) {
			return null;
		}

		return array(
			'x'      => $x,
			'y'      => $y,
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * A place in the atlas with room for its rectangle.
	 *
	 * @phpstan-param Rect $rect
	 * @phpstan-param Atlas $atlas
	 *
	 * @param mixed $value Data.
	 * @param array $rect  The rectangle.
	 * @param array $atlas The atlas.
	 * @return array|null
	 * @phpstan-return Place|null
	 */
	private static function place( mixed $value, array $rect, array $atlas ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$x = $value['x'] ?? null;
		$y = $value['y'] ?? null;

		if ( ! is_int( $x ) || ! is_int( $y ) || $x < 0 || $y < 0 || $x > $atlas['width'] - $rect['width'] || $y > $atlas['height'] - $rect['height'] ) {
			return null;
		}

		return array(
			'x' => $x,
			'y' => $y,
		);
	}

	/**
	 * One colour as a paint.
	 *
	 * @param mixed $value Data.
	 * @return array|null
	 * @phpstan-return Solid|null
	 */
	private static function solid( mixed $value ): ?array {
		$color = is_array( $value ) && 'solid' === ( $value['type'] ?? null ) ? self::color( $value['color'] ?? null ) : null;

		return null === $color ? null : array(
			'type'  => 'solid',
			'color' => $color,
		);
	}

	/**
	 * A gradient of two colours and an angle.
	 *
	 * @param mixed $value Data.
	 * @return array|null
	 * @phpstan-return Gradient|null
	 */
	private static function gradient( mixed $value ): ?array {
		if ( ! is_array( $value ) || 'gradient' !== ( $value['type'] ?? null ) ) {
			return null;
		}

		$from  = self::color( $value['from'] ?? null );
		$to    = self::color( $value['to'] ?? null );
		$angle = $value['angle'] ?? null;

		if ( null === $from || null === $to || ! is_int( $angle ) || $angle < 0 || $angle > 359 ) {
			return null;
		}

		return array(
			'type'  => 'gradient',
			'from'  => $from,
			'to'    => $to,
			'angle' => $angle,
		);
	}

	/**
	 * A colour as `#rrggbb` in lower case.
	 *
	 * @param mixed $value Data.
	 */
	private static function color( mixed $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}\z/', $value ) ? strtolower( $value ) : null;
	}
}
