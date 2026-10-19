<?php
/**
 * Settings of an alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

defined( 'ABSPATH' ) || exit;

/**
 * The appearance and timing of an overlay, with MerchAlert's keys, ranges
 * and defaults (ADR-065 pkt. 3), so imported settings keep their meaning.
 * Every value is normalized by from_array(); anything invalid falls back to
 * the default. CSS values are validated strictly, because they end up in a
 * style sheet: colors are hex only, the font family is a plain name, and the
 * text shadow is one shadow of pixel lengths and a hex or rgb(a) color.
 * MerchAlert put these values into CSS unchecked.
 *
 * The layout places the image, the main text and the secondary text on a
 * 1920 × 1080 canvas (OBS' default browser source size).
 */
final class OverlaySettings {

	/**
	 * Ways text meets its box (text_fit()).
	 */
	public const TEXT_FITS = array( 'fixed', 'shrink', 'grow' );

	/**
	 * Canvas height in pixels (OBS' default browser source).
	 */
	public const CANVAS_HEIGHT = 1080;

	/**
	 * Default main template (MerchAlert's global default).
	 */
	public const DEFAULT_TEMPLATE = '$Product was purchased by $UserName!';

	/**
	 * Longest template in characters.
	 */
	public const TEMPLATE_MAX_LENGTH = 500;

	/**
	 * Layout elements, in drawing order.
	 */
	public const ELEMENTS = array( 'product_image', 'main_text', 'secondary_text' );

	/**
	 * Normalized values.
	 *
	 * @var array{template: string, secondary_template: string, font_family: string, font_size: int, font_weight: string, text_color: string, text_shadow: string, text_align: string, text_fit: string, image_size: int, bg_color: string, bg_opacity: int, border_radius: int, padding: int, anim_duration: int, display_duration: int, audio_enabled: bool, audio_default_sound: int, audio_category_map: array<int, int>, layout: array{elements: array<string, array{x: int, y: int, w: int, h: int, visible: bool, lock_aspect: bool, border_radius: int}>, canvas: array{snap_grid: bool, grid_size: int}}}
	 */
	private array $values;

	/**
	 * Constructor; use from_array() or defaults().
	 *
	 * @param array<mixed> $input Raw values.
	 */
	private function __construct( array $input ) {
		$template  = self::clean_template( $input['template'] ?? null );
		$secondary = self::clean_template( $input['secondary_template'] ?? null );

		$this->values = array(
			'template'            => '' === $template ? self::DEFAULT_TEMPLATE : $template,
			'secondary_template'  => $secondary,
			'font_family'         => self::font_family( $input['font_family'] ?? null ),
			'font_size'           => self::int( $input['font_size'] ?? null, 8, 200, 32 ),
			'font_weight'         => self::font_weight( $input['font_weight'] ?? null ),
			'text_color'          => self::hex( $input['text_color'] ?? null, '#ffffff' ),
			'text_shadow'         => self::text_shadow( $input['text_shadow'] ?? null ),
			'text_align'          => self::choice( $input['text_align'] ?? null, array( 'left', 'center', 'right' ), 'center' ),
			'text_fit'            => self::choice( $input['text_fit'] ?? null, self::TEXT_FITS, 'fixed' ),
			'image_size'          => self::int( $input['image_size'] ?? null, 32, 512, 120 ),
			'bg_color'            => self::hex( $input['bg_color'] ?? null, '#000000' ),
			'bg_opacity'          => self::int( $input['bg_opacity'] ?? null, 0, 100, 80 ),
			'border_radius'       => self::int( $input['border_radius'] ?? null, 0, 100, 8 ),
			'padding'             => self::int( $input['padding'] ?? null, 0, 200, 20 ),
			'anim_duration'       => self::int( $input['anim_duration'] ?? null, 0, 5000, 500 ),
			'display_duration'    => self::int( $input['display_duration'] ?? null, 1000, 30000, 5000 ),
			'audio_enabled'       => self::flag( $input['audio_enabled'] ?? null ),
			'audio_default_sound' => self::int( $input['audio_default_sound'] ?? null, 0, PHP_INT_MAX, 0 ),
			'audio_category_map'  => self::category_map( $input['audio_category_map'] ?? null ),
			'layout'              => self::layout( $input['layout'] ?? null ),
		);
	}

	/**
	 * Settings from stored or submitted values.
	 *
	 * @param array<mixed> $input Raw values (MerchAlert's string values are accepted).
	 */
	public static function from_array( array $input ): self {
		return new self( $input );
	}

	/**
	 * Settings from stored JSON; invalid JSON gives the defaults.
	 *
	 * @param string $json JSON object.
	 */
	public static function from_json( string $json ): self {
		$data = json_decode( $json, true );

		return new self( is_array( $data ) ? $data : array() );
	}

	/**
	 * The defaults.
	 */
	public static function defaults(): self {
		return new self( array() );
	}

	/**
	 * The normalized values.
	 *
	 * @return array{template: string, secondary_template: string, font_family: string, font_size: int, font_weight: string, text_color: string, text_shadow: string, text_align: string, text_fit: string, image_size: int, bg_color: string, bg_opacity: int, border_radius: int, padding: int, anim_duration: int, display_duration: int, audio_enabled: bool, audio_default_sound: int, audio_category_map: array<int, int>, layout: array{elements: array<string, array{x: int, y: int, w: int, h: int, visible: bool, lock_aspect: bool, border_radius: int}>, canvas: array{snap_grid: bool, grid_size: int}}}
	 */
	public function to_array(): array {
		return $this->values;
	}

	/**
	 * The values as JSON for storage.
	 */
	public function to_json(): string {
		return (string) wp_json_encode( $this->values );
	}

	/**
	 * The main template.
	 */
	public function template(): string {
		return $this->values['template'];
	}

	/**
	 * The secondary template ('' for none).
	 */
	public function secondary_template(): string {
		return $this->values['secondary_template'];
	}

	/**
	 * Whether the secondary text is shown: visible in the layout and a template set.
	 */
	public function shows_secondary(): bool {
		return '' !== $this->values['secondary_template'] && $this->values['layout']['elements']['secondary_text']['visible'];
	}

	/**
	 * The requested image size in pixels (the rendition fetched, not the box).
	 */
	public function image_size(): int {
		return $this->values['image_size'];
	}

	/**
	 * How text meets its box (phase 25, step 6.5): `fixed` (the font size;
	 * longer text is cut off), `shrink` (the font size is the largest; the
	 * overlay shrinks the text until it fits) or `grow` (the box keeps its
	 * width and grows with the text; its height is the least; see grows_up()).
	 */
	public function text_fit(): string {
		return $this->values['text_fit'];
	}

	/**
	 * Whether a text box grows upward: with `grow`, a box whose middle is in
	 * the lower half of the canvas keeps its bottom edge and grows up; others
	 * keep their top and grow down. When the main text grows up, the overlay
	 * moves the image up as much, so their distance stays (step 6.5).
	 *
	 * @param string $element One of ELEMENTS.
	 */
	public function grows_up( string $element ): bool {
		$box = $this->values['layout']['elements'][ $element ] ?? null;

		return 'product_image' !== $element && 'grow' === $this->values['text_fit'] && null !== $box && 2 * $box['y'] + $box['h'] > self::CANVAS_HEIGHT;
	}

	/**
	 * Display time in milliseconds.
	 */
	public function display_duration(): int {
		return $this->values['display_duration'];
	}

	/**
	 * Animation time in milliseconds.
	 */
	public function anim_duration(): int {
		return $this->values['anim_duration'];
	}

	/**
	 * The CSS custom properties of the overlay page, every value validated.
	 *
	 * @return array<string, string> Property name (without `--merchboard-`) → value.
	 */
	public function css_variables(): array {
		$v      = $this->values;
		$family = 'inherit' === $v['font_family'] ? 'inherit' : '"' . $v['font_family'] . '", sans-serif';

		return array(
			'anim'              => ( $v['anim_duration'] / 1000 ) . 's',
			'font-family'       => $family,
			'font-size'         => $v['font_size'] . 'px',
			'font-weight'       => $v['font_weight'],
			'text-color'        => $v['text_color'],
			'text-shadow'       => '' === $v['text_shadow'] ? 'none' : $v['text_shadow'],
			'text-align'        => $v['text_align'],
			'img-border-radius' => $v['layout']['elements']['product_image']['border_radius'] . 'px',
		);
	}

	/**
	 * The box of a layout element as CSS declarations (validated integers only).
	 *
	 * @param string $element One of ELEMENTS.
	 */
	public function element_style( string $element ): string {
		$box = $this->values['layout']['elements'][ $element ] ?? self::default_layout()['elements']['main_text'];
		if ( $this->grows_up( $element ) ) {
			$style = sprintf( 'left:%dpx;bottom:%dpx;width:%dpx;height:auto;min-height:%dpx;', $box['x'], self::CANVAS_HEIGHT - $box['y'] - $box['h'], $box['w'], $box['h'] );
		} elseif ( 'product_image' !== $element && 'grow' === $this->values['text_fit'] ) {
			$style = sprintf( 'left:%dpx;top:%dpx;width:%dpx;height:auto;min-height:%dpx;', $box['x'], $box['y'], $box['w'], $box['h'] );
		} else {
			$style = sprintf( 'left:%dpx;top:%dpx;width:%dpx;height:%dpx;', $box['x'], $box['y'], $box['w'], $box['h'] );
		}

		if ( 'product_image' !== $element ) {
			$style .= sprintf( 'background:%s;border-radius:%dpx;padding:%dpx;', $this->background(), $this->values['border_radius'], $this->values['padding'] );
		}

		$visible = 'secondary_text' === $element ? $this->shows_secondary() : $box['visible'];

		return $style . ( $visible ? '' : 'display:none;' );
	}

	/**
	 * The text box background as rgba().
	 */
	private function background(): string {
		$hex = ltrim( $this->values['bg_color'], '#' );

		return sprintf( 'rgba(%d,%d,%d,%s)', hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), rtrim( rtrim( number_format( $this->values['bg_opacity'] / 100, 2, '.', '' ), '0' ), '.' ) );
	}

	/**
	 * MerchAlert's default layout.
	 *
	 * @return array{elements: array<string, array{x: int, y: int, w: int, h: int, visible: bool, lock_aspect: bool, border_radius: int}>, canvas: array{snap_grid: bool, grid_size: int}}
	 */
	public static function default_layout(): array {
		return array(
			'elements' => array(
				'product_image'  => array(
					'x'             => 40,
					'y'             => 880,
					'w'             => 120,
					'h'             => 120,
					'visible'       => true,
					'lock_aspect'   => true,
					'border_radius' => 4,
				),
				'main_text'      => array(
					'x'             => 176,
					'y'             => 895,
					'w'             => 700,
					'h'             => 80,
					'visible'       => true,
					'lock_aspect'   => false,
					'border_radius' => 0,
				),
				'secondary_text' => array(
					'x'             => 176,
					'y'             => 985,
					'w'             => 700,
					'h'             => 50,
					'visible'       => false,
					'lock_aspect'   => false,
					'border_radius' => 0,
				),
			),
			'canvas'   => array(
				'snap_grid' => false,
				'grid_size' => 10,
			),
		);
	}

	/**
	 * A template: plain text without tags, line breaks kept, at most
	 * TEMPLATE_MAX_LENGTH characters.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function clean_template( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return mb_substr( trim( sanitize_textarea_field( $value ) ), 0, self::TEMPLATE_MAX_LENGTH );
	}

	/**
	 * A font family: "inherit" or a plain name of letters, digits, spaces
	 * and hyphens (every Google Fonts family fits).
	 *
	 * @param mixed $value Raw value.
	 */
	private static function font_family( mixed $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9 -]{0,63}$/', $value ) && 'inherit' !== strtolower( $value ) ? $value : 'inherit';
	}

	/**
	 * A font weight: 100…900 in hundreds, "normal" or "bold".
	 *
	 * @param mixed $value Raw value.
	 */
	private static function font_weight( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return in_array( $value, array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ), true ) ? $value : '700';
	}

	/**
	 * A six-digit lowercase hex color; three digits are expanded.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Default.
	 */
	private static function hex( mixed $value, string $fallback ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		if ( 1 === preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m ) ) {
			return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
		}

		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : $fallback;
	}

	/**
	 * One text shadow: two or three pixel lengths and a hex or rgb(a) color;
	 * '' (no shadow) for anything else.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text_shadow( mixed $value ): string {
		if ( null === $value ) {
			return '2px 2px 4px rgba(0,0,0,0.8)';
		}

		$value  = is_string( $value ) ? strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ?? '' ) ) : '';
		$length = '-?\d{1,3}(?:px)?';
		$color  = '(?:#[0-9a-f]{3}|#[0-9a-f]{6}|#[0-9a-f]{8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d{1,3}|1\.0{1,3})\s*)?\))';

		return 1 === preg_match( '/^' . $length . ' ' . $length . '(?: ' . $length . ')? ' . $color . '$/', $value ) ? $value : '';
	}

	/**
	 * One of a fixed list of values.
	 *
	 * @param mixed         $value    Raw value.
	 * @param array<string> $choices  Allowed values.
	 * @param string        $fallback Default.
	 */
	private static function choice( mixed $value, array $choices, string $fallback ): string {
		return is_string( $value ) && in_array( $value, $choices, true ) ? $value : $fallback;
	}

	/**
	 * An integer within bounds; numeric strings are accepted, anything else
	 * gives the default.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Minimum.
	 * @param int   $max      Maximum.
	 * @param int   $fallback Default.
	 */
	private static function int( mixed $value, int $min, int $max, int $fallback ): int {
		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,9}$/', trim( $value ) ) ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			return max( $min, min( $max, (int) $value ) );
		}

		return $fallback;
	}

	/**
	 * A boolean flag: true, 1, "1", "true", "yes" and "on" are true.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function flag( mixed $value ): bool {
		return true === $value || 1 === $value || ( is_string( $value ) && in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true ) );
	}

	/**
	 * Sounds per product category: positive term id → positive attachment id.
	 * Accepts an array or MerchAlert's JSON string.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, int>
	 */
	private static function category_map( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}

		$map = array();

		foreach ( is_array( $value ) ? $value : array() as $term => $attachment ) {
			$term       = self::int( $term, 0, PHP_INT_MAX, 0 );
			$attachment = self::int( $attachment, 0, PHP_INT_MAX, 0 );

			if ( $term > 0 && $attachment > 0 && count( $map ) < 100 ) {
				$map[ $term ] = $attachment;
			}
		}

		return $map;
	}

	/**
	 * The layout: only the known elements, positions within a generous range
	 * around the canvas, sizes of at least 10 pixels.
	 *
	 * @param mixed $value Raw value.
	 * @return array{elements: array<string, array{x: int, y: int, w: int, h: int, visible: bool, lock_aspect: bool, border_radius: int}>, canvas: array{snap_grid: bool, grid_size: int}}
	 */
	private static function layout( mixed $value ): array {
		$defaults = self::default_layout();
		$value    = is_array( $value ) ? $value : array();
		$elements = is_array( $value['elements'] ?? null ) ? $value['elements'] : array();
		$canvas   = is_array( $value['canvas'] ?? null ) ? $value['canvas'] : array();
		$layout   = $defaults;

		foreach ( self::ELEMENTS as $id ) {
			$raw  = is_array( $elements[ $id ] ?? null ) ? $elements[ $id ] : array();
			$base = $defaults['elements'][ $id ];

			$layout['elements'][ $id ] = array(
				'x'             => self::int( $raw['x'] ?? null, -3840, 3840, $base['x'] ),
				'y'             => self::int( $raw['y'] ?? null, -2160, 2160, $base['y'] ),
				'w'             => self::int( $raw['w'] ?? null, 10, 3840, $base['w'] ),
				'h'             => self::int( $raw['h'] ?? null, 10, 2160, $base['h'] ),
				'visible'       => array_key_exists( 'visible', $raw ) ? self::flag( $raw['visible'] ) : $base['visible'],
				'lock_aspect'   => array_key_exists( 'lock_aspect', $raw ) ? self::flag( $raw['lock_aspect'] ) : $base['lock_aspect'],
				'border_radius' => 'product_image' === $id ? self::int( $raw['border_radius'] ?? null, 0, 500, $base['border_radius'] ) : 0,
			);
		}

		$layout['canvas'] = array(
			'snap_grid' => array_key_exists( 'snap_grid', $canvas ) ? self::flag( $canvas['snap_grid'] ) : false,
			'grid_size' => self::int( $canvas['grid_size'] ?? null, 1, 100, 10 ),
		);

		return $layout;
	}
}
