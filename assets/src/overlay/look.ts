/**
 * Alert designer 2.0 (ADR-088): the alert box, its layouts, the new text
 * styles, the highlight of inserted values, timing, read-out and the
 * variations. Shared by the overlay and the dashboard; the same lists and
 * bounds as OverlaySettings on the server. Parsers are lenient: anything
 * missing or unknown gets the default, as on the server.
 */

/**
 * Every layout an overlay can have: the image's place in the box (ADR-109:
 * `top`, `left`, `right`, `background` and `free`; the texts are always
 * free) and the layouts of 2.0 before it (`above`, `side`, `overlay`), which
 * are still drawn as before but no longer offered in the designer.
 */
export const LAYOUT_MODES = [
	'top',
	'left',
	'right',
	'background',
	'free',
	'above',
	'side',
	'overlay',
] as const;

/** The layouts the designer offers (ADR-109): where the image is. */
export const IMAGE_LAYOUTS = [
	'top',
	'left',
	'right',
	'background',
	'free',
] as const;

/** The layouts of 2.0 before ADR-109, and the image layout each becomes. */
export const LEGACY_LAYOUTS = {
	above: 'top',
	side: 'left',
	overlay: 'background',
} as const;
export const TEXT_ALIGNS = [ 'left', 'center', 'right', 'justify' ] as const;
export const TEXT_DIRECTIONS = [ 'ltr', 'rtl' ] as const;
export const TEXT_CASES = [ 'none', 'upper', 'lower', 'capitalize' ] as const;
export const NUMBER_FORMATS = [ 'shop', 'whole', 'plain' ] as const;
export const HIGHLIGHT_EFFECTS = [
	'none',
	'pulse',
	'glow',
	'bounce',
	'shake',
] as const;
export const ALERT_ANIMATIONS = [
	'none',
	'rise',
	'fade',
	'slide-up',
	'slide-down',
	'slide-left',
	'slide-right',
	'zoom',
	'bounce',
	'flip',
] as const;

/** Google Cloud standard voices (ADR-089), as TtsVoices on the server. */
export const TTS_VOICES = [
	'da-DK-Standard-A',
	'da-DK-Standard-C',
	'en-GB-Standard-A',
	'en-GB-Standard-B',
	'en-US-Standard-C',
	'en-US-Standard-D',
	'de-DE-Standard-A',
	'de-DE-Standard-B',
	'sv-SE-Standard-A',
	'nb-NO-Standard-A',
] as const;

/** Longest display time in milliseconds. */
export const MAX_DISPLAY = 90000;

/** Most variations of an overlay. */
export const MAX_VARIATIONS = 10;

/** Longest name of a variation. */
export const VARIATION_NAME_LENGTH = 60;

export type LayoutMode = ( typeof LAYOUT_MODES )[ number ];
export type ImageLayout = ( typeof IMAGE_LAYOUTS )[ number ];
export type LegacyLayout = keyof typeof LEGACY_LAYOUTS;

/**
 * Whether a layout is one of 2.0 before ADR-109 (image and texts in a flow).
 *
 * @param mode Layout.
 */
export function isLegacyLayout( mode: LayoutMode ): mode is LegacyLayout {
	return Object.prototype.hasOwnProperty.call( LEGACY_LAYOUTS, mode );
}
export type TextAlign = ( typeof TEXT_ALIGNS )[ number ];
export type TextDirection = ( typeof TEXT_DIRECTIONS )[ number ];
export type TextCase = ( typeof TEXT_CASES )[ number ];
export type NumberFormat = ( typeof NUMBER_FORMATS )[ number ];
export type HighlightEffect = ( typeof HIGHLIGHT_EFFECTS )[ number ];
export type AlertAnimationName = ( typeof ALERT_ANIMATIONS )[ number ];
export type TtsVoice = ( typeof TTS_VOICES )[ number ];

/** The alert box on the 1920 × 1080 canvas. */
export interface Widget {
	readonly x: number;
	readonly y: number;
	readonly w: number;
	readonly h: number;
	/** Percent. */
	readonly opacity: number;
	/** Degrees, -180 … 180. */
	readonly rotation: number;
}

/** How the inserted values stand out. */
export interface Highlight {
	readonly enabled: boolean;
	readonly color: string;
	readonly effect: HighlightEffect;
}

/** The text's margin in pixels. */
export interface Margin {
	readonly top: number;
	readonly right: number;
	readonly bottom: number;
	readonly left: number;
}

/** The read-out of an alert (ADR-089). */
export interface Tts {
	readonly enabled: boolean;
	readonly voice: TtsVoice;
	/** Percent. */
	readonly volume: number;
	/** Milliseconds after the alert appears. */
	readonly delay: number;
	/** Least line total in whole units of the shop's currency. */
	readonly min_amount: number;
}

/** In- and out-animation of the whole alert box. */
export interface AlertAnimation {
	readonly in: AlertAnimationName;
	readonly out: AlertAnimationName;
	readonly in_ms: number;
	readonly out_ms: number;
}

/** When the texts appear and go within the display time (ms). */
export interface TextTiming {
	readonly show_after: number;
	readonly hide_before: number;
}

/** When a variation is shown (ADR-088). */
export interface Conditions {
	/** Least line total in whole units; 0 for any. */
	readonly min_amount: number;
	/** Least quantity; 0 or 1 for any. */
	readonly min_quantity: number;
	/** Product categories (term ids); empty for any. */
	readonly categories: readonly number[];
}

/**
 * A variation: its own look (the server's keys, OverlaySettings::LOOK_KEYS),
 * kept as the server sent it; the dashboard reads it on top of the default.
 */
export interface Variation {
	readonly id: string;
	readonly name: string;
	readonly enabled: boolean;
	readonly conditions: Conditions;
	readonly look: Readonly< Record< string, unknown > >;
}

/** A piece of an alert's text: the template's own text or an inserted value. */
export interface TextPart {
	readonly text: string;
	readonly value: boolean;
}

function isRecord( value: unknown ): value is Record< string, unknown > {
	return (
		typeof value === 'object' && value !== null && ! Array.isArray( value )
	);
}

/**
 * One of a list, or the fallback.
 *
 * @param value    Candidate.
 * @param list     Allowed values.
 * @param fallback Default.
 */
export function oneOf< T extends string >(
	value: unknown,
	list: readonly T[],
	fallback: T
): T {
	return ( list as readonly unknown[] ).includes( value )
		? ( value as T )
		: fallback;
}

/**
 * A whole number within bounds (clamped), or the fallback.
 *
 * @param value    Candidate.
 * @param min      Least.
 * @param max      Most.
 * @param fallback Default.
 */
export function intIn(
	value: unknown,
	min: number,
	max: number,
	fallback: number
): number {
	return typeof value === 'number' && Number.isFinite( value )
		? Math.max( min, Math.min( max, Math.trunc( value ) ) )
		: fallback;
}

/**
 * A #rrggbb colour, or the fallback.
 *
 * @param value    Candidate.
 * @param fallback Default.
 */
export function hexColor( value: unknown, fallback: string ): string {
	return typeof value === 'string' && /^#[0-9a-f]{6}$/.test( value )
		? value
		: fallback;
}

/**
 * The alert box.
 *
 * @param value    Candidate.
 * @param fallback The box when a value is missing.
 */
export function parseWidget( value: unknown, fallback: Widget ): Widget {
	const data = isRecord( value ) ? value : {};

	return {
		x: intIn( data.x, -3840, 3840, fallback.x ),
		y: intIn( data.y, -2160, 2160, fallback.y ),
		w: intIn( data.w, 10, 3840, fallback.w ),
		h: intIn( data.h, 10, 2160, fallback.h ),
		opacity: intIn( data.opacity, 0, 100, 100 ),
		rotation: intIn( data.rotation, -180, 180, 0 ),
	};
}

/**
 * The highlight of inserted values.
 *
 * @param value Candidate.
 */
export function parseHighlight( value: unknown ): Highlight {
	const data = isRecord( value ) ? value : {};

	return {
		enabled: data.enabled === true,
		color: hexColor( data.color, '#6a6ffc' ),
		effect: oneOf( data.effect, HIGHLIGHT_EFFECTS, 'none' ),
	};
}

/**
 * The text's margin.
 *
 * @param value Candidate.
 */
export function parseMargin( value: unknown ): Margin {
	const data = isRecord( value ) ? value : {};

	return {
		top: intIn( data.top, -500, 500, 0 ),
		right: intIn( data.right, -500, 500, 0 ),
		bottom: intIn( data.bottom, -500, 500, 0 ),
		left: intIn( data.left, -500, 500, 0 ),
	};
}

/**
 * The read-out.
 *
 * @param value Candidate.
 */
export function parseTts( value: unknown ): Tts {
	const data = isRecord( value ) ? value : {};

	return {
		enabled: data.enabled === true,
		voice: oneOf( data.voice, TTS_VOICES, 'da-DK-Standard-A' ),
		volume: intIn( data.volume, 0, 100, 75 ),
		delay: intIn( data.delay, 0, 10000, 0 ),
		min_amount: intIn( data.min_amount, 0, 1000000, 0 ),
	};
}

/**
 * The animation of the alert box.
 *
 * @param value Candidate.
 * @param ms    Duration when one is missing (the old anim_duration).
 */
export function parseAlertAnimation(
	value: unknown,
	ms: number
): AlertAnimation {
	const data = isRecord( value ) ? value : {};

	return {
		// Fade and rise 20 px: every alert before 2.0 moved so.
		in: oneOf( data.in, ALERT_ANIMATIONS, 'rise' ),
		out: oneOf( data.out, ALERT_ANIMATIONS, 'rise' ),
		in_ms: intIn( data.in_ms, 0, 10000, ms ),
		out_ms: intIn( data.out_ms, 0, 10000, ms ),
	};
}

/**
 * When the texts appear and go.
 *
 * @param value Candidate.
 */
export function parseTextTiming( value: unknown ): TextTiming {
	const data = isRecord( value ) ? value : {};

	return {
		show_after: intIn( data.show_after, 0, MAX_DISPLAY, 0 ),
		hide_before: intIn( data.hide_before, 0, MAX_DISPLAY, 0 ),
	};
}

/**
 * The variations; broken entries are left out.
 *
 * @param value Candidate.
 */
export function parseVariations( value: unknown ): Variation[] {
	const variations: Variation[] = [];

	for ( const item of Array.isArray( value ) ? value : [] ) {
		if (
			! isRecord( item ) ||
			typeof item.id !== 'string' ||
			! /^[a-z0-9]{6,32}$/.test( item.id ) ||
			variations.length >= MAX_VARIATIONS
		) {
			continue;
		}

		const conditions = isRecord( item.conditions ) ? item.conditions : {};
		const categories = Array.isArray( conditions.categories )
			? conditions.categories.filter(
					( term ): term is number =>
						typeof term === 'number' &&
						Number.isSafeInteger( term ) &&
						term > 0
				)
			: [];

		variations.push( {
			id: item.id,
			name:
				typeof item.name === 'string'
					? item.name.slice( 0, VARIATION_NAME_LENGTH )
					: '',
			enabled: item.enabled !== false,
			conditions: {
				min_amount: intIn( conditions.min_amount, 0, 1000000, 0 ),
				min_quantity: intIn( conditions.min_quantity, 0, 1000, 0 ),
				categories,
			},
			look: isRecord( item.look ) ? item.look : {},
		} );
	}

	return variations;
}

/**
 * An alert's text in pieces, or the whole text as one piece when they are
 * missing or broken.
 *
 * @param value Candidate.
 * @param text  The whole text.
 */
export function parseParts( value: unknown, text: string ): TextPart[] {
	if ( Array.isArray( value ) ) {
		const parts: TextPart[] = [];

		for ( const part of value ) {
			if (
				! isRecord( part ) ||
				typeof part.text !== 'string' ||
				typeof part.value !== 'boolean'
			) {
				return text === '' ? [] : [ { text, value: false } ];
			}

			parts.push( { text: part.text, value: part.value } );
		}

		if ( parts.map( ( part ) => part.text ).join( '' ) === text ) {
			return parts;
		}
	}

	return text === '' ? [] : [ { text, value: false } ];
}
