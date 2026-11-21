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
 *   A colour can have its own background instead (`background:<key>`, a
 *   photo of the product in that colour, e.g. rendered by Printful or
 *   Gelato; review 30.15). At least one background or the colour mask is
 *   needed.
 * - The print area is the rectangle of the template (px) where the design
 *   is shown, and the physical print size in whole millimetres at `dpi`.
 *   The print file of the side is width_mm × height_mm at that dpi.
 * - A side with a `thread_pack` is embroidered: its design keeps to that
 *   pack's threads (ThreadPacks). Without one it is printed.
 * - A `flat` side is a flat part of an all-over product (ADR-081): the
 *   template is the sewing pattern, drawn normally over the design (its veil
 *   outside the part must cover it), and the print file is a JPEG on white.
 *   Only the provider's mockups show the product sewn.
 * - A side with a `scene` has its mockup from a PSD with smart objects: the
 *   layer `scene` is one image of the layers around the design, and the scene
 *   says how the creator's browser draws them and warps the print file. The
 *   mockup then has the scene's size, not the template's. With `preview` in
 *   the scene the creator can also see it while designing.
 * - A side with `sizes` belongs to those sizes only: each has its own template
 *   and print area, and the creator designs each for itself (the owner's
 *   choice 2026-10-31, cups of two sizes). Without any it is on every size.
 * - A side with `colors` belongs to those values of the first axis only, e.g.
 *   "Vertical" of a poster (ADR-115); without any it is on every colour.
 * - A side's `formats` are the print size of each of its sizes in mm (with
 *   a decimal) and the bleed, half of what the print area exceeds the
 *   finished size by per axis (ADR-115): a format group of a poster, whose
 *   print file has the largest size and is cut to each size's shape.
 */
final readonly class BaseView {

	/**
	 * The template layers: the background and the colour mask under the
	 * design, the shading and the highlight over it, and the cutout (ADR-099):
	 * where it is opaque, the product lies in front of the design (e.g. the
	 * strings of a hoodie). The cutout alone is no template. The silhouette
	 * (ADR-114) is the product's outline: the images without background are
	 * cut to it (without it, to the colour mask).
	 */
	public const LAYERS = array( 'background', 'color', 'shading', 'highlight', 'cutout', 'silhouette' );

	/**
	 * The silhouette layer (ADR-114): only the images without background.
	 */
	public const SILHOUETTE = 'silhouette';

	/**
	 * The cutout layer (ADR-099): not part of what a design was made on.
	 */
	public const CUTOUT = 'cutout';

	/**
	 * The prefix of a colour's own background (`background:<colour key>`):
	 * a photo of the product in that colour, drawn instead of the background
	 * and the tinted colour mask (review 30.15, the owner's choice).
	 */
	public const COLOR_BACKGROUND = 'background:';

	/**
	 * The layer of a PSD scene's image.
	 */
	public const SCENE = 'scene';

	/**
	 * The blends of a scene's images.
	 */
	public const SCENE_BLENDS = array( 'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'hard-light', 'darker-color' );

	/**
	 * The most steps of a scene, and the longest side of its mockup.
	 */
	private const MAX_SCENE_STEPS = 64;

	private const MAX_SCENE_SIDE = 2000;

	/**
	 * A side's key from its name: lowercase letters, digits and hyphens, at
	 * most 32 characters, as a key must be (30.15 review: a name with inch
	 * marks gave a key the side was refused for).
	 *
	 * @param string $name Side name.
	 */
	public static function key_of( string $name ): string {
		// Inch marks and times signs become letters: "16″×16″" gives "16inx16in", not "%e2%80%b3".
		$slug = sanitize_title( str_replace( array( '″', '"', '×' ), array( 'in', 'in', 'x' ), $name ) );
		$slug = preg_replace( '/[^a-z0-9-]+/', '', $slug ) ?? '';

		return trim( substr( $slug, 0, 32 ), '-' );
	}

	/**
	 * The layer key of a colour's own background.
	 *
	 * @param string $color Colour key.
	 */
	public static function color_layer( string $color ): string {
		return self::COLOR_BACKGROUND . $color;
	}

	/**
	 * Whether a layer key is a known layer or a colour's background.
	 *
	 * @param string $layer Layer key.
	 */
	public static function valid_layer( string $layer ): bool {
		return self::SCENE === $layer || in_array( $layer, self::LAYERS, true ) || 1 === preg_match( '/^' . preg_quote( self::COLOR_BACKGROUND, '/' ) . '[a-z0-9][a-z0-9_-]{0,31}$/', $layer );
	}

	/**
	 * The colours' own backgrounds: asset id by colour key.
	 *
	 * @return array<string, int>
	 */
	public function color_backgrounds(): array {
		$backgrounds = array();

		foreach ( $this->layers as $layer => $asset ) {
			if ( str_starts_with( $layer, self::COLOR_BACKGROUND ) ) {
				$backgrounds[ substr( $layer, strlen( self::COLOR_BACKGROUND ) ) ] = $asset;
			}
		}

		return $backgrounds;
	}

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
	 * @param string                                                                                     $key         Stable key (a-z, 0-9, -), unique per base product.
	 * @param string                                                                                     $name        Name shown to creators.
	 * @param int                                                                                        $width       Template width in px.
	 * @param int                                                                                        $height      Template height in px.
	 * @param array<string, int>                                                                         $layers      Asset id by layer (LAYERS and colour backgrounds); missing layers are left out.
	 * @param array{x: int, y: int, width: int, height: int}                                             $area        Print area in template px.
	 * @param int                                                                                        $width_mm    Print width in mm.
	 * @param int                                                                                        $height_mm   Print height in mm.
	 * @param int                                                                                        $dpi         Print resolution.
	 * @param string                                                                                     $thread_pack The embroidery pack; empty when the side is printed.
	 * @param bool                                                                                       $flat        Whether the side is a flat part of an all-over product.
	 * @param array<string, mixed>|null                                                                  $scene       The side's PSD scene; it comes with the layer `scene`.
	 * @param string[]                                                                                   $sizes       The sizes the side belongs to; empty for every size.
	 * @param bool                                                                                       $small       Whether the side is a small design area, with the price of small extra areas.
	 * @param string[]                                                                                   $colors      The colour keys the side belongs to; empty for every colour.
	 * @param array<string, array{width_mm: float, height_mm: float, bleed?: array{0: float, 1: float}}> $formats Print size and bleed by size.
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
		public int $dpi,
		public string $thread_pack = '',
		public bool $flat = false,
		public ?array $scene = null,
		public array $sizes = array(),
		public bool $small = false,
		public array $colors = array(),
		public array $formats = array()
	) {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $key ) || '' === $name || mb_strlen( $name ) > 60 ) {
			throw new InvalidArgumentException( 'Invalid side.' );
		}

		if ( $width < 1 || $height < 1 || $width > 8000 || $height > 8000 ) {
			throw new InvalidArgumentException( 'Invalid template size.' );
		}

		foreach ( $layers as $layer => $asset ) {
			if ( ! self::valid_layer( (string) $layer ) || $asset < 1 ) {
				throw new InvalidArgumentException( 'Invalid template layer.' );
			}
		}

		if ( ! isset( $layers['background'] ) && ! isset( $layers['color'] ) && array() === $this->color_backgrounds() ) {
			throw new InvalidArgumentException( 'A side needs a background or a colour mask.' );
		}

		if ( $area['x'] < 0 || $area['y'] < 0 || $area['width'] < 1 || $area['height'] < 1 || $area['x'] + $area['width'] > $width || $area['y'] + $area['height'] > $height ) {
			throw new InvalidArgumentException( 'The print area must lie inside the template.' );
		}

		if ( $width_mm < 1 || $height_mm < 1 || $width_mm > self::MAX_MM || $height_mm > self::MAX_MM || $dpi < self::MIN_DPI || $dpi > self::MAX_DPI ) {
			throw new InvalidArgumentException( 'Invalid print size.' );
		}

		if ( null === $scene ? isset( $layers[ self::SCENE ] ) : ! isset( $layers[ self::SCENE ] ) || ! self::valid_scene( $scene ) ) {
			throw new InvalidArgumentException( 'Invalid scene.' );
		}

		if ( '' !== $thread_pack && 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $thread_pack ) ) {
			throw new InvalidArgumentException( 'Invalid embroidery pack.' );
		}

		if ( in_array( '', $sizes, true ) ) {
			throw new InvalidArgumentException( 'Invalid sizes of a side.' );
		}

		foreach ( $colors as $color ) {
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $color ) ) {
				throw new InvalidArgumentException( 'Invalid colours of a side.' );
			}
		}

		foreach ( $formats as $size => $format ) {
			$bleed = $format['bleed'] ?? array( 0.0, 0.0 );

			if ( '' === (string) $size || $format['width_mm'] <= 0 || $format['height_mm'] <= 0 || $format['width_mm'] > self::MAX_MM || $format['height_mm'] > self::MAX_MM || $bleed[0] < 0 || $bleed[1] < 0 || 2 * $bleed[0] >= $format['width_mm'] || 2 * $bleed[1] >= $format['height_mm'] ) {
				throw new InvalidArgumentException( 'Invalid format of a side.' );
			}
		}
	}

	/**
	 * Whether the side belongs to a colour (a value of the first axis).
	 *
	 * @param string $color Colour key.
	 */
	public function on_color( string $color ): bool {
		return array() === $this->colors || in_array( $color, $this->colors, true );
	}

	/**
	 * The side as it is with other formats (a fetched bleed).
	 *
	 * @param array<string, array{width_mm: float, height_mm: float, bleed?: array{0: float, 1: float}}> $formats Print size and bleed by size.
	 * @throws InvalidArgumentException When a format is invalid.
	 */
	public function with_formats( array $formats ): self {
		return new self( $this->key, $this->name, $this->width, $this->height, $this->layers, $this->area, $this->width_mm, $this->height_mm, $this->dpi, $this->thread_pack, $this->flat, $this->scene, $this->sizes, $this->small, $this->colors, $formats );
	}

	/**
	 * The side as it is with other layers.
	 *
	 * @param array<string, int> $layers Asset id by layer.
	 * @throws InvalidArgumentException When a layer is invalid.
	 */
	public function with_layers( array $layers ): self {
		return new self( $this->key, $this->name, $this->width, $this->height, $layers, $this->area, $this->width_mm, $this->height_mm, $this->dpi, $this->thread_pack, $this->flat, $this->scene, $this->sizes, $this->small, $this->colors, $this->formats );
	}

	/**
	 * Whether the side belongs to a size.
	 *
	 * @param string $size The size.
	 */
	public function on_size( string $size ): bool {
		return array() === $this->sizes || in_array( $size, $this->sizes, true );
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
	 * The size of the side's mockups in px.
	 *
	 * @return array{0: int, 1: int}
	 */
	public function mockup_size(): array {
		return null === $this->scene ? array( $this->width, $this->height ) : array( (int) $this->scene['width'], (int) $this->scene['height'] );
	}

	/**
	 * Whether a scene is sound. The creator's browser draws from it, so only
	 * its shape matters: the image is checked as any upload.
	 *
	 * @param array<mixed> $scene Scene.
	 */
	private static function valid_scene( array $scene ): bool {
		$size  = static fn( mixed $value ): bool => is_int( $value ) && $value >= 1 && $value <= self::MAX_SCENE_SIDE;
		$place = static fn( mixed $value ): bool => is_int( $value ) && abs( $value ) <= 8000;
		$rect  = static fn( mixed $rect ): bool => is_array( $rect ) && is_int( $rect['x'] ?? null ) && is_int( $rect['y'] ?? null ) && $rect['x'] >= 0 && $rect['y'] >= 0 && is_int( $rect['width'] ?? null ) && is_int( $rect['height'] ?? null ) && $rect['width'] >= 1 && $rect['height'] >= 1;
		$steps = $scene['steps'] ?? null;

		if ( ! is_bool( $scene['preview'] ?? false ) || ! $size( $scene['width'] ?? null ) || ! $size( $scene['height'] ?? null ) || ! is_array( $steps ) || ! array_is_list( $steps ) || array() === $steps || count( $steps ) > self::MAX_SCENE_STEPS ) {
			return false;
		}

		$designs = 0;

		foreach ( $steps as $step ) {
			$type = is_array( $step ) ? ( $step['type'] ?? null ) : null;

			if ( 'image' === $type ) {
				$opacity = $step['opacity'] ?? null;

				// `background`: the PSD's background layer, left out of the images without background (ADR-114).
				if ( ! $rect( $step['rect'] ?? null ) || ! $place( $step['left'] ?? null ) || ! $place( $step['top'] ?? null ) || ! in_array( $step['blend'] ?? null, self::SCENE_BLENDS, true ) || ! is_numeric( $opacity ) || $opacity < 0 || $opacity > 1 || ! is_bool( $step['background'] ?? false ) ) {
					return false;
				}
			} elseif ( 'design' === $type ) {
				++$designs;

				$mask = $step['mask'] ?? null;

				if ( ! in_array( $step['end'] ?? null, array( 'start', 'middle', 'end' ), true ) || ! is_int( $step['width'] ?? null ) || ! is_int( $step['height'] ?? null ) || $step['width'] < 1 || $step['height'] < 1 || ! self::numbers( $step['quad'] ?? null, 8 ) || ! self::numbers( $step['mesh'] ?? null, 32 ) ) {
					return false;
				}

				if ( null !== $mask && ( ! $rect( $mask ) || ! $place( $mask['left'] ?? null ) || ! $place( $mask['top'] ?? null ) ) ) {
					return false;
				}
			} else {
				return false;
			}
		}

		return $designs > 0;
	}

	/**
	 * Whether a value is a list of a number of numbers.
	 *
	 * @param mixed $value Value.
	 * @param int   $count How many.
	 */
	private static function numbers( mixed $value, int $count ): bool {
		return is_array( $value ) && array_is_list( $value ) && count( $value ) === $count && array() === array_filter( $value, static fn( mixed $number ): bool => ! is_int( $number ) && ! is_float( $number ) );
	}

	/**
	 * The side as stored (JSON).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$side = array(
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

		if ( '' !== $this->thread_pack ) {
			$side['thread_pack'] = $this->thread_pack;
		}

		if ( $this->flat ) {
			$side['flat'] = true;
		}

		if ( null !== $this->scene ) {
			$side['scene'] = $this->scene;
		}

		if ( array() !== $this->sizes ) {
			$side['sizes'] = $this->sizes;
		}

		if ( $this->small ) {
			$side['small'] = true;
		}

		if ( array() !== $this->colors ) {
			$side['colors'] = $this->colors;
		}

		if ( array() !== $this->formats ) {
			$side['formats'] = $this->formats;
		}

		return $side;
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
			$int( $data['dpi'] ?? null ),
			is_string( $data['thread_pack'] ?? null ) ? $data['thread_pack'] : '',
			true === ( $data['flat'] ?? false ),
			is_array( $data['scene'] ?? null ) ? $data['scene'] : null,
			is_array( $data['sizes'] ?? null ) ? array_values( array_filter( $data['sizes'], 'is_string' ) ) : array(),
			true === ( $data['small'] ?? false ),
			is_array( $data['colors'] ?? null ) ? array_values( array_filter( $data['colors'], 'is_string' ) ) : array(),
			self::formats_of( $data['formats'] ?? null )
		);
	}

	/**
	 * Formats from their stored or entered form; an invalid entry is left out.
	 *
	 * @param mixed $data Decoded formats.
	 * @return array<string, array{width_mm: float, height_mm: float, bleed?: array{0: float, 1: float}}>
	 */
	public static function formats_of( mixed $data ): array {
		$formats = array();

		foreach ( is_array( $data ) ? $data : array() as $size => $format ) {
			if ( ! is_array( $format ) || ! is_numeric( $format['width_mm'] ?? null ) || ! is_numeric( $format['height_mm'] ?? null ) ) {
				continue;
			}

			$formats[ (string) $size ] = array(
				'width_mm'  => (float) $format['width_mm'],
				'height_mm' => (float) $format['height_mm'],
			);

			$bleed = $format['bleed'] ?? null;

			if ( is_array( $bleed ) && is_numeric( $bleed[0] ?? null ) && is_numeric( $bleed[1] ?? null ) ) {
				$formats[ (string) $size ]['bleed'] = array( (float) $bleed[0], (float) $bleed[1] );
			}
		}

		return $formats;
	}
}
