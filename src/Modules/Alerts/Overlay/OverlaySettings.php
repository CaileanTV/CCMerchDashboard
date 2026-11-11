<?php
/**
 * Settings of an alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Tts\TtsVoices;

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
 *
 * Alert designer 2.0 (ADR-088): the alert is one box (`widget`) with a
 * layout (`layout_mode`); the elements keep their own boxes, which stay in
 * canvas coordinates. ADR-109: the layout places only the image. Settings stored before have no box: it
 * becomes the box around their visible elements and the layout `free`, so
 * they look as before. The top-level look is the default; `variations`
 * hold other looks (LOOK_KEYS), chosen by choose().
 *
 * @phpstan-type Widget array{x: int, y: int, w: int, h: int, opacity: int, rotation: int}
 * @phpstan-type Layout array{elements: array<string, array{x: int, y: int, w: int, h: int, visible: bool, lock_aspect: bool, border_radius: int}>, canvas: array{snap_grid: bool, grid_size: int}}
 * @phpstan-type TextAnimation array{in: string, effect: string, out: string, color: string, speed: string, in_ms: int, out_ms: int}
 * @phpstan-type Animations array{alert: array{in: string, out: string, in_ms: int, out_ms: int}, main_text: TextAnimation, secondary_text: TextAnimation}
 * @phpstan-type Fill array{type: string, preset: string, image: int, motion: string}
 * @phpstan-type Fills array{main_text: Fill, secondary_text: Fill}
 * @phpstan-type Frame array{enabled: bool, direction: string, speed: string, repeat: bool}
 * @phpstan-type Highlight array{enabled: bool, color: string, effect: string}
 * @phpstan-type Margin array{top: int, right: int, bottom: int, left: int}
 * @phpstan-type Tts array{enabled: bool, voice: string, volume: int, delay: int, min_amount: int}
 * @phpstan-type Conditions array{min_amount: int, min_quantity: int, categories: list<int>}
 * @phpstan-type Variation array{id: string, name: string, enabled: bool, conditions: Conditions, look: array<string, mixed>}
 * @phpstan-type Values array{template: string, secondary_template: string, font_family: string, font_size: int, font_weight: string, text_color: string, text_opacity: int, text_shadow: string, text_shadow_enabled: bool, text_align: string, text_fit: string, line_height: int, letter_spacing: int, word_spacing: int, text_direction: string, italic: bool, underline: bool, text_case: string, stroke_width: int, stroke_color: string, stroke_opacity: int, highlight: Highlight, margin: Margin, number_format: string, max_name_length: int, image_size: int, bg_color: string, bg_opacity: int, border_radius: int, padding: int, anim_duration: int, display_duration: int, audio_enabled: bool, display_from_sound: bool, audio_default_sound: int, audio_volume: int, audio_category_map: array<int, int>, widget: Widget, layout_mode: string, layout: Layout, animations: Animations, text_fills: Fills, text_frame: Frame, text_timing: array{show_after: int, hide_before: int}, tts: Tts, variations: list<Variation>}
 */
final class OverlaySettings {

	/**
	 * Ways text meets its box (text_fit()).
	 */
	public const TEXT_FITS = array( 'fixed', 'shrink', 'grow' );

	/**
	 * Text animations (step 6.9): in-animations, effects while shown,
	 * out-animations and speeds. The same lists as assets/src/overlay/
	 * animations.ts; anything else falls back to the default (fade, none, fade).
	 */
	public const ANIMATIONS_IN     = array( 'fade', 'slide-up', 'pop', 'typewriter', 'split', 'wave', 'drop', 'spin-3d', 'roll-3d', 'blur', 'wipe', 'shadow-paint' );
	public const ANIMATION_EFFECTS = array( 'none', 'neon', 'glow-pulse', 'heartbeat', 'glitch', 'dancing-shadow', 'melt', 'wave', 'float-3d', 'depth-pulse', 'breathe' );
	public const ANIMATIONS_OUT    = array( 'fade', 'slide-down', 'pop', 'typewriter', 'split', 'wave', 'spin-3d', 'roll-3d', 'blur', 'wipe' );
	public const ANIMATION_SPEEDS  = array( 'slow', 'normal', 'fast' );

	/**
	 * Text fills (step 6.10): what fills the letters (none, a preset gradient
	 * or pattern, an image), the presets and how the fill moves. The same
	 * lists as assets/src/overlay/textfill.ts.
	 */
	public const FILL_TYPES   = array( 'none', 'preset', 'image' );
	public const FILL_PRESETS = array( 'rainbow', 'aurora', 'sunset', 'ocean', 'fire', 'gold', 'candy', 'matrix' );
	public const FILL_MOTIONS = array( 'none', 'pan', 'fall' );

	/**
	 * The main text around the image (step 6.11): directions and speeds, as
	 * in assets/src/overlay/frame.ts, and the margin round the image that
	 * holds the text, in font sizes (FRAME_MARGIN there).
	 */
	public const FRAME_DIRECTIONS = array( 'clockwise', 'counterclockwise' );
	public const FRAME_SPEEDS     = array( 'still', 'slow', 'normal', 'fast' );
	public const FRAME_MARGIN     = 1.2;

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
	 * Layouts of the alert box. ADR-109: the image's place in the box (top,
	 * left, right, background, or free in its own box); the texts always keep
	 * their own boxes. ADR-088 before it, still drawn as before but no longer
	 * offered in the designer: image above the text, image left of the text,
	 * text on the image.
	 */
	public const LAYOUT_MODES = array( 'top', 'left', 'right', 'background', 'free', 'above', 'side', 'overlay' );

	/**
	 * Text styles of alert designer 2.0, as in assets/src/overlay/render.ts.
	 */
	public const TEXT_ALIGNS     = array( 'left', 'center', 'right', 'justify' );
	public const TEXT_DIRECTIONS = array( 'ltr', 'rtl' );
	public const TEXT_CASES      = array( 'none', 'upper', 'lower', 'capitalize' );

	/**
	 * Amounts in the text: as the shop shows them, without decimals, or the
	 * number alone (AlertMoney::format()).
	 */
	public const NUMBER_FORMATS = array( 'shop', 'whole', 'plain' );

	/**
	 * Effects of the inserted values (highlight).
	 */
	public const HIGHLIGHT_EFFECTS = array( 'none', 'pulse', 'glow', 'bounce', 'shake' );

	/**
	 * In- and out-animations of the whole alert box.
	 */
	public const ALERT_ANIMATIONS = array( 'none', 'rise', 'fade', 'slide-up', 'slide-down', 'slide-left', 'slide-right', 'zoom', 'bounce', 'flip' );

	/**
	 * Longest display time in milliseconds (90 s, as StreamElements).
	 */
	public const MAX_DISPLAY = 90000;

	/**
	 * Most variations of an overlay.
	 */
	public const MAX_VARIATIONS = 10;

	/**
	 * Longest name of a variation.
	 */
	public const VARIATION_NAME_LENGTH = 60;

	/**
	 * The keys a variation sets (its look); the others (the box, its style,
	 * whether sounds play, the sounds per category and the canvas grid) are
	 * the overlay's.
	 */
	public const LOOK_KEYS = array( 'template', 'secondary_template', 'font_family', 'font_size', 'font_weight', 'text_color', 'text_opacity', 'text_shadow', 'text_shadow_enabled', 'text_align', 'text_fit', 'line_height', 'letter_spacing', 'word_spacing', 'text_direction', 'italic', 'underline', 'text_case', 'stroke_width', 'stroke_color', 'stroke_opacity', 'highlight', 'margin', 'number_format', 'max_name_length', 'image_size', 'anim_duration', 'display_duration', 'display_from_sound', 'audio_default_sound', 'audio_volume', 'layout_mode', 'layout', 'animations', 'text_fills', 'text_frame', 'text_timing', 'tts' );

	/**
	 * Normalized values.
	 *
	 * @var Values
	 */
	private array $values;

	/**
	 * Constructor; use from_array() or defaults().
	 *
	 * @param array<mixed> $input      Raw values.
	 * @param bool         $variations Whether to read the variations (a variation has none).
	 */
	private function __construct( array $input, bool $variations = true ) {
		$template  = self::clean_template( $input['template'] ?? null );
		$secondary = self::clean_template( $input['secondary_template'] ?? null );
		$anim      = self::int( $input['anim_duration'] ?? null, 0, 5000, 500 );
		$shadow    = self::text_shadow( $input['text_shadow'] ?? null );
		$layout    = self::layout( $input['layout'] ?? null );
		$frame     = self::clean_frame( $input['text_frame'] ?? null );
		$font_size = self::int( $input['font_size'] ?? null, 8, 200, 32 );

		$this->values = array(
			'template'            => '' === $template ? self::DEFAULT_TEMPLATE : $template,
			'secondary_template'  => $secondary,
			'font_family'         => self::font_family( $input['font_family'] ?? null ),
			'font_size'           => $font_size,
			'font_weight'         => self::font_weight( $input['font_weight'] ?? null ),
			'text_color'          => self::hex( $input['text_color'] ?? null, '#ffffff' ),
			'text_opacity'        => self::int( $input['text_opacity'] ?? null, 0, 100, 100 ),
			'text_shadow'         => $shadow,
			// Settings from before the switch have it on while they have a shadow.
			'text_shadow_enabled' => array_key_exists( 'text_shadow_enabled', $input ) ? self::flag( $input['text_shadow_enabled'] ) : '' !== $shadow,
			'text_align'          => self::choice( $input['text_align'] ?? null, self::TEXT_ALIGNS, 'center' ),
			'text_fit'            => self::choice( $input['text_fit'] ?? null, self::TEXT_FITS, 'fixed' ),
			// Percent of the font size; 130 is the overlay's line height before 2.0.
			'line_height'         => self::int( $input['line_height'] ?? null, 50, 300, 130 ),
			'letter_spacing'      => self::int( $input['letter_spacing'] ?? null, -20, 100, 0 ),
			'word_spacing'        => self::int( $input['word_spacing'] ?? null, -20, 200, 0 ),
			'text_direction'      => self::choice( $input['text_direction'] ?? null, self::TEXT_DIRECTIONS, 'ltr' ),
			'italic'              => self::flag( $input['italic'] ?? null ),
			'underline'           => self::flag( $input['underline'] ?? null ),
			'text_case'           => self::choice( $input['text_case'] ?? null, self::TEXT_CASES, 'none' ),
			'stroke_width'        => self::int( $input['stroke_width'] ?? null, 0, 20, 0 ),
			'stroke_color'        => self::hex( $input['stroke_color'] ?? null, '#000000' ),
			'stroke_opacity'      => self::int( $input['stroke_opacity'] ?? null, 0, 100, 100 ),
			'highlight'           => self::clean_highlight( $input['highlight'] ?? null ),
			'margin'              => self::clean_margin( $input['margin'] ?? null ),
			'number_format'       => self::choice( $input['number_format'] ?? null, self::NUMBER_FORMATS, 'shop' ),
			'max_name_length'     => self::int( $input['max_name_length'] ?? null, 0, 100, 0 ),
			'image_size'          => self::int( $input['image_size'] ?? null, 32, 512, 120 ),
			'bg_color'            => self::hex( $input['bg_color'] ?? null, '#000000' ),
			'bg_opacity'          => self::int( $input['bg_opacity'] ?? null, 0, 100, 80 ),
			'border_radius'       => self::int( $input['border_radius'] ?? null, 0, 100, 8 ),
			'padding'             => self::int( $input['padding'] ?? null, 0, 200, 20 ),
			'anim_duration'       => $anim,
			'display_duration'    => self::int( $input['display_duration'] ?? null, 1000, self::MAX_DISPLAY, 5000 ),
			'audio_enabled'       => self::flag( $input['audio_enabled'] ?? null ),
			'display_from_sound'  => self::flag( $input['display_from_sound'] ?? null ),
			'audio_default_sound' => self::int( $input['audio_default_sound'] ?? null, 0, PHP_INT_MAX, 0 ),
			'audio_volume'        => self::int( $input['audio_volume'] ?? null, 0, 100, 100 ),
			'audio_category_map'  => self::category_map( $input['audio_category_map'] ?? null ),
			// Settings without a box (stored before 2.0) get the box around their elements.
			'widget'              => self::widget( $input['widget'] ?? null, self::around( $layout, $frame['enabled'], $font_size, '' !== $secondary ) ),
			'layout_mode'         => self::choice( $input['layout_mode'] ?? null, self::LAYOUT_MODES, 'free' ),
			'layout'              => $layout,
			'animations'          => self::clean_animations( $input['animations'] ?? null, $anim ),
			'text_fills'          => self::clean_fills( $input['text_fills'] ?? null ),
			'text_frame'          => $frame,
			'text_timing'         => array(
				'show_after'  => self::int( is_array( $input['text_timing'] ?? null ) ? ( $input['text_timing']['show_after'] ?? null ) : null, 0, self::MAX_DISPLAY, 0 ),
				'hide_before' => self::int( is_array( $input['text_timing'] ?? null ) ? ( $input['text_timing']['hide_before'] ?? null ) : null, 0, self::MAX_DISPLAY, 0 ),
			),
			'tts'                 => self::clean_tts( $input['tts'] ?? null ),
			'variations'          => array(),
		);

		if ( $variations ) {
			$this->values['variations'] = self::clean_variations( $input['variations'] ?? null, $this->values );
		}
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
	 * @return Values
	 */
	public function to_array(): array {
		return $this->values;
	}

	/**
	 * The variations (ADR-088).
	 *
	 * @return list<Variation>
	 */
	public function variations(): array {
		return $this->values['variations'];
	}

	/**
	 * The variations' own sounds (attachment ids); a variation without one
	 * plays the overlay's sounds.
	 *
	 * @return list<int>
	 */
	public function variation_sounds(): array {
		$sounds = array();

		foreach ( $this->values['variations'] as $variation ) {
			$sound = $variation['look']['audio_default_sound'] ?? 0;

			if ( is_int( $sound ) && $sound > 0 ) {
				$sounds[] = $sound;
			}
		}

		return $sounds;
	}

	/**
	 * The settings an alert is shown with when a variation is chosen: the
	 * overlay's with the variation's look. The default ('') or an unknown
	 * id gives these settings.
	 *
	 * @param string $id Variation id; '' for the default.
	 */
	public function look( string $id ): self {
		foreach ( '' === $id ? array() : $this->values['variations'] as $variation ) {
			if ( $variation['id'] === $id ) {
				$values = array_merge( $this->values, $variation['look'] );

				// The canvas grid is the overlay's.
				if ( is_array( $values['layout'] ) ) {
					$values['layout']['canvas'] = $this->values['layout']['canvas'];
				}

				return new self( $values, false );
			}
		}

		return $this;
	}

	/**
	 * The variation an alert is shown with ('' for the default), ADR-088:
	 * when enabled variations with conditions match, one of them; otherwise
	 * the default or an enabled variation without conditions. The choice is
	 * random but the same for the same alert, so two open overlays agree.
	 *
	 * @param AlertEvent $event Alert.
	 */
	public function choose( AlertEvent $event ): string {
		$matching = array();
		$open     = array( '' );

		foreach ( $this->values['variations'] as $variation ) {
			if ( ! $variation['enabled'] ) {
				continue;
			}

			if ( ! self::has_conditions( $variation['conditions'] ) ) {
				$open[] = $variation['id'];
			} elseif ( self::matches( $variation['conditions'], $event ) ) {
				$matching[] = $variation['id'];
			}
		}

		$pool = array() === $matching ? $open : $matching;

		return $pool[ crc32( $event->id . ':' . $event->order_item_id . ':' . $event->created_at->getTimestamp() ) % count( $pool ) ];
	}

	/**
	 * The settings with every text fill (the default's and each
	 * variation's) passed through a filter, e.g. to keep only images the
	 * owner may use.
	 *
	 * @param \Closure $clean Fills → fills.
	 * @phpstan-param \Closure(Fills): array<string, Fill> $clean
	 */
	public function with_fills( \Closure $clean ): self {
		$values               = $this->values;
		$values['text_fills'] = $clean( $values['text_fills'] );

		foreach ( $values['variations'] as $index => $variation ) {
			$look = ( new self( array_merge( $this->values, $variation['look'] ), false ) )->values;

			$values['variations'][ $index ]['look']['text_fills'] = $clean( $look['text_fills'] );
		}

		return new self( $values );
	}

	/**
	 * The settings with the default font wherever a font is no longer
	 * approved (the default's and each variation's).
	 *
	 * @param \Closure $approved Family → whether it may be used.
	 * @phpstan-param \Closure(string): bool $approved
	 */
	public function with_fonts( \Closure $approved ): self {
		$values = $this->values;
		$keep   = static fn( string $family ): string => 'inherit' === $family || $approved( $family ) ? $family : 'inherit';

		$values['font_family'] = $keep( $values['font_family'] );

		foreach ( $values['variations'] as $index => $variation ) {
			$values['variations'][ $index ]['look']['font_family'] = $keep( is_string( $variation['look']['font_family'] ?? null ) ? $variation['look']['font_family'] : 'inherit' );
		}

		return new self( $values );
	}

	/**
	 * Fills without an image: a fill with it becomes no fill.
	 *
	 * @phpstan-param Fills $fills
	 *
	 * @param array<string, mixed> $fills      Fills.
	 * @param int                  $attachment The image.
	 * @return Fills
	 */
	public static function fills_without( array $fills, int $attachment ): array {
		foreach ( $fills as $text => $fill ) {
			if ( 'image' === $fill['type'] && $attachment === $fill['image'] ) {
				$fills[ $text ] = array_merge(
					$fill,
					array(
						'type'  => 'none',
						'image' => 0,
					)
				);
			}
		}

		return $fills;
	}

	/**
	 * Whether a variation has a condition.
	 *
	 * @phpstan-param Conditions $conditions
	 *
	 * @param array<string, mixed> $conditions Conditions.
	 */
	private static function has_conditions( array $conditions ): bool {
		return $conditions['min_amount'] > 0 || $conditions['min_quantity'] > 1 || array() !== $conditions['categories'];
	}

	/**
	 * Whether an alert meets every condition: the line total (what the
	 * customer paid for the line) at least the amount, at least the quantity,
	 * and one of the categories.
	 *
	 * @phpstan-param Conditions $conditions
	 *
	 * @param array<string, mixed> $conditions Conditions.
	 * @param AlertEvent           $event      Alert.
	 */
	private static function matches( array $conditions, AlertEvent $event ): bool {
		if ( $conditions['min_amount'] > 0 && ( null === $event->line_total || (float) $event->line_total < $conditions['min_amount'] ) ) {
			return false;
		}

		if ( $event->quantity < $conditions['min_quantity'] ) {
			return false;
		}

		return array() === $conditions['categories'] || array() !== array_intersect( $conditions['categories'], $event->category_ids );
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
	 * The animations of the alert box and of the main and the secondary
	 * text (step 6.9, ADR-088).
	 *
	 * @return Animations
	 */
	public function animations(): array {
		return $this->values['animations'];
	}

	/**
	 * The text fills of the main and the secondary text (step 6.10). An
	 * image is only an attachment id here; AlertImages decides whether the
	 * creator may use it.
	 *
	 * @return Fills
	 */
	public function text_fills(): array {
		return $this->values['text_fills'];
	}

	/**
	 * The main text around the image instead of in its box (step 6.11).
	 *
	 * @return array{enabled: bool, direction: string, speed: string, repeat: bool}
	 */
	public function text_frame(): array {
		return $this->values['text_frame'];
	}

	/**
	 * Whether the main text runs around the image.
	 */
	public function frames_image(): bool {
		return $this->values['text_frame']['enabled'];
	}

	/**
	 * Whether an alert with a sound is shown as long as its sound plays
	 * instead of the display time (step 6.8).
	 */
	public function display_from_sound(): bool {
		return $this->values['display_from_sound'];
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
	 * width and grows with the text; its height is the least; near the
	 * bottom of the canvas it grows up, see assets/src/overlay/render.ts).
	 */
	public function text_fit(): string {
		return $this->values['text_fit'];
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
	 * Everything the overlay page draws an alert with (ADR-088): the box,
	 * the layout, the text style, timing, sound volume, read-out,
	 * animations, the frame and the fills. assets/src/overlay/render.ts
	 * draws it, in the overlay and on the designer's canvas; it validates
	 * every value again.
	 *
	 * @param \Closure $fill A text fill's look (AlertImages::look()).
	 * @phpstan-param \Closure(Fill): (array<string, mixed>|null) $fill
	 * @return array<string, mixed>
	 */
	public function render_look( \Closure $fill ): array {
		$v    = $this->values;
		$look = array();

		foreach ( array( 'widget', 'layout_mode', 'font_family', 'font_size', 'font_weight', 'text_color', 'text_opacity', 'text_align', 'text_fit', 'line_height', 'letter_spacing', 'word_spacing', 'text_direction', 'italic', 'underline', 'text_case', 'stroke_width', 'stroke_color', 'stroke_opacity', 'highlight', 'margin', 'bg_color', 'bg_opacity', 'border_radius', 'padding', 'display_duration', 'display_from_sound', 'audio_volume', 'text_timing', 'tts', 'animations' ) as $key ) {
			$look[ $key ] = $v[ $key ];
		}

		$look['elements']       = $v['layout']['elements'];
		$look['show_secondary'] = $this->shows_secondary();
		$look['text_shadow']    = $v['text_shadow_enabled'] ? $v['text_shadow'] : '';
		// The main text around the image (step 6.11); null when it is in its box.
		$look['frame'] = $this->frames_image() ? $v['text_frame'] : null;
		$look['fills'] = array(
			'main'      => $fill( $v['text_fills']['main_text'] ),
			'secondary' => $fill( $v['text_fills']['secondary_text'] ),
		);

		return $look;
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
	 * Animations of the alert box and both texts; unknown values get the
	 * default. Settings from before 2.0 have no box animation or text
	 * durations: they get the fade they had, of `anim_duration`.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $anim  The animation time in milliseconds (anim_duration).
	 * @return Animations
	 */
	private static function clean_animations( mixed $value, int $anim ): array {
		$value = is_array( $value ) ? $value : array();
		$alert = is_array( $value['alert'] ?? null ) ? $value['alert'] : array();

		return array(
			'alert'          => array(
				// The default fades and rises 20 px, as every alert did before 2.0 (30.15 review).
				'in'     => self::choice( $alert['in'] ?? null, self::ALERT_ANIMATIONS, 'rise' ),
				'out'    => self::choice( $alert['out'] ?? null, self::ALERT_ANIMATIONS, 'rise' ),
				'in_ms'  => self::int( $alert['in_ms'] ?? null, 0, 10000, $anim ),
				'out_ms' => self::int( $alert['out_ms'] ?? null, 0, 10000, $anim ),
			),
			'main_text'      => self::clean_animation( $value['main_text'] ?? null, $anim ),
			'secondary_text' => self::clean_animation( $value['secondary_text'] ?? null, $anim ),
		);
	}

	/**
	 * One text animation.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $anim  The animation time in milliseconds (anim_duration).
	 * @return TextAnimation
	 */
	private static function clean_animation( mixed $value, int $anim ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'in'     => self::choice( $value['in'] ?? null, self::ANIMATIONS_IN, 'fade' ),
			'effect' => self::choice( $value['effect'] ?? null, self::ANIMATION_EFFECTS, 'none' ),
			'out'    => self::choice( $value['out'] ?? null, self::ANIMATIONS_OUT, 'fade' ),
			'color'  => self::hex( $value['color'] ?? null, '#ff2bd6' ),
			'speed'  => self::choice( $value['speed'] ?? null, self::ANIMATION_SPEEDS, 'normal' ),
			'in_ms'  => self::int( $value['in_ms'] ?? null, 0, 10000, $anim ),
			'out_ms' => self::int( $value['out_ms'] ?? null, 0, 10000, $anim ),
		);
	}

	/**
	 * The highlight of the inserted values; off by default.
	 *
	 * @param mixed $value Raw value.
	 * @return Highlight
	 */
	private static function clean_highlight( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'enabled' => self::flag( $value['enabled'] ?? null ),
			'color'   => self::hex( $value['color'] ?? null, '#6a6ffc' ),
			'effect'  => self::choice( $value['effect'] ?? null, self::HIGHLIGHT_EFFECTS, 'none' ),
		);
	}

	/**
	 * The text's margin in pixels on each side.
	 *
	 * @param mixed $value Raw value.
	 * @return Margin
	 */
	private static function clean_margin( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'top'    => self::int( $value['top'] ?? null, -500, 500, 0 ),
			'right'  => self::int( $value['right'] ?? null, -500, 500, 0 ),
			'bottom' => self::int( $value['bottom'] ?? null, -500, 500, 0 ),
			'left'   => self::int( $value['left'] ?? null, -500, 500, 0 ),
		);
	}

	/**
	 * The read-out (ADR-089); off by default. The delay is in milliseconds,
	 * the least amount in whole units of the shop's currency.
	 *
	 * @param mixed $value Raw value.
	 * @return Tts
	 */
	private static function clean_tts( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'enabled'    => self::flag( $value['enabled'] ?? null ),
			'voice'      => TtsVoices::clean( $value['voice'] ?? null ),
			'volume'     => self::int( $value['volume'] ?? null, 0, 100, 75 ),
			'delay'      => self::int( $value['delay'] ?? null, 0, 10000, 0 ),
			'min_amount' => self::int( $value['min_amount'] ?? null, 0, 1000000, 0 ),
		);
	}

	/**
	 * The alert box. A missing value is taken from the box around the
	 * elements (settings from before 2.0 have no box at all).
	 *
	 * @phpstan-param Widget $around
	 *
	 * @param mixed             $value  Raw value.
	 * @param array<string,int> $around The box around the visible elements.
	 * @return Widget
	 */
	private static function widget( mixed $value, array $around ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'x'        => self::int( $value['x'] ?? null, -3840, 3840, $around['x'] ),
			'y'        => self::int( $value['y'] ?? null, -2160, 2160, $around['y'] ),
			'w'        => self::int( $value['w'] ?? null, 10, 3840, $around['w'] ),
			'h'        => self::int( $value['h'] ?? null, 10, 2160, $around['h'] ),
			'opacity'  => self::int( $value['opacity'] ?? null, 0, 100, 100 ),
			'rotation' => self::int( $value['rotation'] ?? null, -180, 180, 0 ),
		);
	}

	/**
	 * The box around the visible elements: the image, the main text (around
	 * the image: the image's box with its margin) and the secondary text
	 * when it has a template. Without visible elements, the main text's box.
	 *
	 * @phpstan-param Layout $layout
	 *
	 * @param array<string, mixed> $layout    The layout.
	 * @param bool                 $frames    Whether the main text runs around the image.
	 * @param int                  $font_size Font size in pixels.
	 * @param bool                 $secondary Whether the secondary text has a template.
	 * @return Widget
	 */
	private static function around( array $layout, bool $frames, int $font_size, bool $secondary ): array {
		$elements = $layout['elements'];
		$boxes    = array();

		if ( $elements['product_image']['visible'] ) {
			$boxes[] = $elements['product_image'];
		}

		if ( $elements['main_text']['visible'] ) {
			$margin  = (int) round( $font_size * self::FRAME_MARGIN );
			$image   = $elements['product_image'];
			$boxes[] = $frames ? array(
				'x' => $image['x'] - $margin,
				'y' => $image['y'] - $margin,
				'w' => $image['w'] + 2 * $margin,
				'h' => $image['h'] + 2 * $margin,
			) : $elements['main_text'];
		}

		if ( $secondary && $elements['secondary_text']['visible'] ) {
			$boxes[] = $elements['secondary_text'];
		}

		if ( array() === $boxes ) {
			$boxes[] = $elements['main_text'];
		}

		$left   = min( array_map( static fn( array $box ): int => $box['x'], $boxes ) );
		$top    = min( array_map( static fn( array $box ): int => $box['y'], $boxes ) );
		$right  = max( array_map( static fn( array $box ): int => $box['x'] + $box['w'], $boxes ) );
		$bottom = max( array_map( static fn( array $box ): int => $box['y'] + $box['h'], $boxes ) );

		return array(
			'x'        => max( -3840, min( 3840, $left ) ),
			'y'        => max( -2160, min( 2160, $top ) ),
			'w'        => max( 10, min( 3840, $right - $left ) ),
			'h'        => max( 10, min( 2160, $bottom - $top ) ),
			'opacity'  => 100,
			'rotation' => 0,
		);
	}

	/**
	 * The variations: at most MAX_VARIATIONS, each with a unique id, a name,
	 * whether it is on, its conditions and its look (LOOK_KEYS, normalized
	 * on top of the default look, so a missing key is the default's).
	 *
	 * @phpstan-param Values $base
	 *
	 * @param mixed                $value Raw value.
	 * @param array<string, mixed> $base  The overlay's normalized values.
	 * @return list<Variation>
	 */
	private static function clean_variations( mixed $value, array $base ): array {
		$variations = array();
		$ids        = array();

		foreach ( is_array( $value ) ? array_values( $value ) : array() as $index => $raw ) {
			if ( ! is_array( $raw ) || count( $variations ) >= self::MAX_VARIATIONS ) {
				continue;
			}

			$id = is_string( $raw['id'] ?? null ) && 1 === preg_match( '/^[a-z0-9]{6,32}$/', $raw['id'] ) ? $raw['id'] : 'v' . substr( md5( (string) wp_json_encode( $raw ) . $index ), 0, 12 );

			if ( in_array( $id, $ids, true ) ) {
				continue;
			}

			$ids[]      = $id;
			$conditions = is_array( $raw['conditions'] ?? null ) ? $raw['conditions'] : array();
			$categories = array();

			foreach ( is_array( $conditions['categories'] ?? null ) ? $conditions['categories'] : array() as $term ) {
				$term = self::int( $term, 0, PHP_INT_MAX, 0 );

				if ( $term > 0 && ! in_array( $term, $categories, true ) && count( $categories ) < 50 ) {
					$categories[] = $term;
				}
			}

			$look  = ( new self( array_merge( $base, is_array( $raw['look'] ?? null ) ? $raw['look'] : array() ), false ) )->values;
			$name  = is_string( $raw['name'] ?? null ) ? mb_substr( trim( sanitize_text_field( $raw['name'] ) ), 0, self::VARIATION_NAME_LENGTH ) : '';
			$clean = array();

			foreach ( self::LOOK_KEYS as $key ) {
				$clean[ $key ] = $look[ $key ];
			}

			// Without its own sound (0) a variation plays the overlay's sounds, also after they change.
			if ( ! is_array( $raw['look'] ?? null ) || ! array_key_exists( 'audio_default_sound', $raw['look'] ) ) {
				$clean['audio_default_sound'] = 0;
			}

			$variations[] = array(
				'id'         => $id,
				'name'       => $name,
				'enabled'    => array_key_exists( 'enabled', $raw ) ? self::flag( $raw['enabled'] ) : true,
				'conditions' => array(
					'min_amount'   => self::int( $conditions['min_amount'] ?? null, 0, 1000000, 0 ),
					'min_quantity' => self::int( $conditions['min_quantity'] ?? null, 0, 1000, 0 ),
					'categories'   => $categories,
				),
				'look'       => $clean,
			);
		}

		return $variations;
	}

	/**
	 * Text fills of both texts; unknown values get the default (none).
	 *
	 * @param mixed $value Raw value.
	 * @return array{main_text: array{type: string, preset: string, image: int, motion: string}, secondary_text: array{type: string, preset: string, image: int, motion: string}}
	 */
	private static function clean_fills( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'main_text'      => self::clean_fill( $value['main_text'] ?? null ),
			'secondary_text' => self::clean_fill( $value['secondary_text'] ?? null ),
		);
	}

	/**
	 * One text fill.
	 *
	 * @param mixed $value Raw value.
	 * @return array{type: string, preset: string, image: int, motion: string}
	 */
	private static function clean_fill( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'type'   => self::choice( $value['type'] ?? null, self::FILL_TYPES, 'none' ),
			'preset' => self::choice( $value['preset'] ?? null, self::FILL_PRESETS, 'rainbow' ),
			'image'  => self::int( $value['image'] ?? null, 0, PHP_INT_MAX, 0 ),
			'motion' => self::choice( $value['motion'] ?? null, self::FILL_MOTIONS, 'none' ),
		);
	}

	/**
	 * The main text around the image; unknown values get the default (off).
	 *
	 * @param mixed $value Raw value.
	 * @return array{enabled: bool, direction: string, speed: string, repeat: bool}
	 */
	private static function clean_frame( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'enabled'   => self::flag( $value['enabled'] ?? null ),
			'direction' => self::choice( $value['direction'] ?? null, self::FRAME_DIRECTIONS, 'clockwise' ),
			'speed'     => self::choice( $value['speed'] ?? null, self::FRAME_SPEEDS, 'normal' ),
			// Repeating is the default: the text then goes all the way round.
			'repeat'    => array_key_exists( 'repeat', $value ) ? self::flag( $value['repeat'] ) : true,
		);
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
