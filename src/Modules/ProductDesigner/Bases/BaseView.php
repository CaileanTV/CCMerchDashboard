<?php
/**
 * One side of a base product with its mockup template.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Bases;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A side (front, back, sleeve …) of a base product (ADR-080 pkt. 2): its
 * mockup template and print area.
 *
 * - The template is `width` × `height` px; its layers (template assets, all
 *   of that size) are the background, the colour mask (tinted in the
 *   product colour), the shading (multiplied) and the highlight (screened).
 *   At least the background or the colour mask is needed.
 * - The print area is the rectangle of the template (px) where the design
 *   is shown, and the physical print size in whole millimetres at `dpi`.
 *   The print file of the side is width_mm × height_mm at that dpi.
 */
final readonly class BaseView {

	/**
	 * The template layers, in the order they are drawn.
	 */
	public const LAYERS = array( 'background', 'color', 'shading', 'highlight' );

	/**
	 * Smallest and largest print resolution.
	 */
	public const MIN_DPI = 72;

	public const MAX_DPI = 600;

	/**
	 * Largest print size in millimetres.
	 */
	public const MAX_MM = 2000;

	/**
	 * Constructor.
	 *
	 * @param string                                         $key       Stable key (a-z, 0-9, -), unique per base product.
	 * @param string                                         $name      Name shown to creators.
	 * @param int                                            $width     Template width in px.
	 * @param int                                            $height    Template height in px.
	 * @param array<string, int>                             $layers    Asset id by layer (LAYERS); missing layers are left out.
	 * @param array{x: int, y: int, width: int, height: int} $area Print area in template px.
	 * @param int                                            $width_mm  Print width in mm.
	 * @param int                                            $height_mm Print height in mm.
	 * @param int                                            $dpi       Print resolution.
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	public function __construct(
		public string $key,
		public string $name,
		public int $width,
		public int $height,
		public array $layers,
		public array $area,
		public int $width_mm,
		public int $height_mm,
		public int $dpi
	) {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $key ) || '' === $name || mb_strlen( $name ) > 60 ) {
			throw new InvalidArgumentException( 'Invalid side.' );
		}

		if ( $width < 1 || $height < 1 || $width > 8000 || $height > 8000 ) {
			throw new InvalidArgumentException( 'Invalid template size.' );
		}

		foreach ( $layers as $layer => $asset ) {
			if ( ! in_array( $layer, self::LAYERS, true ) || $asset < 1 ) {
				throw new InvalidArgumentException( 'Invalid template layer.' );
			}
		}

		if ( ! isset( $layers['background'] ) && ! isset( $layers['color'] ) ) {
			throw new InvalidArgumentException( 'A side needs a background or a colour mask.' );
		}

		if ( $area['x'] < 0 || $area['y'] < 0 || $area['width'] < 1 || $area['height'] < 1 || $area['x'] + $area['width'] > $width || $area['y'] + $area['height'] > $height ) {
			throw new InvalidArgumentException( 'The print area must lie inside the template.' );
		}

		if ( $width_mm < 1 || $height_mm < 1 || $width_mm > self::MAX_MM || $height_mm > self::MAX_MM || $dpi < self::MIN_DPI || $dpi > self::MAX_DPI ) {
			throw new InvalidArgumentException( 'Invalid print size.' );
		}
	}

	/**
	 * The print file's size in px: millimetres at the side's dpi.
	 *
	 * @return array{0: int, 1: int}
	 */
	public function print_pixels(): array {
		return array( (int) round( $this->width_mm * $this->dpi / 25.4 ), (int) round( $this->height_mm * $this->dpi / 25.4 ) );
	}

	/**
	 * The side as stored (JSON).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'       => $this->key,
			'name'      => $this->name,
			'width'     => $this->width,
			'height'    => $this->height,
			'layers'    => $this->layers,
			'area'      => $this->area,
			'width_mm'  => $this->width_mm,
			'height_mm' => $this->height_mm,
			'dpi'       => $this->dpi,
		);
	}

	/**
	 * A side from its stored form.
	 *
	 * @param mixed $data Decoded JSON.
	 * @throws InvalidArgumentException When it is invalid.
	 */
	public static function from_array( mixed $data ): self {
		if ( ! is_array( $data ) || ! is_array( $data['layers'] ?? null ) || ! is_array( $data['area'] ?? null ) ) {
			throw new InvalidArgumentException( 'Invalid side.' );
		}

		$int    = static fn( mixed $value ): int => is_int( $value ) ? $value : throw new InvalidArgumentException( 'Invalid side.' );
		$layers = array();

		foreach ( $data['layers'] as $layer => $asset ) {
			$layers[ (string) $layer ] = $int( $asset );
		}

		return new self(
			is_string( $data['key'] ?? null ) ? $data['key'] : '',
			is_string( $data['name'] ?? null ) ? $data['name'] : '',
			$int( $data['width'] ?? null ),
			$int( $data['height'] ?? null ),
			$layers,
			array(
				'x'      => $int( $data['area']['x'] ?? null ),
				'y'      => $int( $data['area']['y'] ?? null ),
				'width'  => $int( $data['area']['width'] ?? null ),
				'height' => $int( $data['area']['height'] ?? null ),
			),
			$int( $data['width_mm'] ?? null ),
			$int( $data['height_mm'] ?? null ),
			$int( $data['dpi'] ?? null )
		);
	}
}
