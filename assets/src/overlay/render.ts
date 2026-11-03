/**
 * One renderer for an alert (ADR-088): the alert box, its layout and the
 * texts' style, from a look. Used by the overlay page and by the designer's
 * canvas, so the preview is what OBS shows. Every value is validated by
 * parseLook(); styles are set property by property, never as CSS text.
 *
 * - **Box:** placed on the 1920 × 1080 canvas, with its opacity and
 *   rotation (around its middle).
 * - **Layouts:** `above` (image over the texts), `side` (image left of the
 *   texts), `overlay` (texts on the image) and `free` (each element's own
 *   box, as before 2.0; their boxes are in canvas coordinates).
 * - **Texts:** font, colour with opacity, shadow, alignment, line height,
 *   spacing, direction, italic, underline, case, stroke and margin; the box
 *   style (background, corners, padding) is on the text boxes.
 * - **Highlight:** the inserted values' letters get the highlight colour
 *   and effect (highlight()).
 */
import { parseAnimation, splitLetters } from './animations';
import type { TextAnimation } from './animations';
import { isTextFit } from './fit';
import type { TextFit } from './fit';
import { frameBox, parseTextFrame } from './frame';
import type { TextFrame } from './frame';
import {
	LAYOUT_MODES,
	TEXT_ALIGNS,
	TEXT_CASES,
	TEXT_DIRECTIONS,
	hexColor,
	intIn,
	oneOf,
	parseAlertAnimation,
	parseHighlight,
	parseMargin,
	parseTextTiming,
	parseTts,
	parseWidget,
} from './look';
import type {
	AlertAnimation,
	Highlight,
	LayoutMode,
	Margin,
	TextAlign,
	TextCase,
	TextDirection,
	TextPart,
	TextTiming,
	Tts,
	Widget,
} from './look';
import { parseFillLook } from './textfill';
import type { FillLook } from './textfill';

/** The canvas of OBS' default browser source. */
export const CANVAS_WIDTH = 1920;
export const CANVAS_HEIGHT = 1080;

/** An element's box on the canvas (layout `free`). */
export interface ElementBox {
	readonly x: number;
	readonly y: number;
	readonly w: number;
	readonly h: number;
	readonly visible: boolean;
	readonly border_radius: number;
}

/** Everything an alert is drawn with. */
export interface AlertLook {
	readonly widget: Widget;
	readonly layout_mode: LayoutMode;
	readonly elements: {
		readonly product_image: ElementBox;
		readonly main_text: ElementBox;
		readonly secondary_text: ElementBox;
	};
	readonly show_secondary: boolean;
	readonly font_family: string;
	readonly font_size: number;
	readonly font_weight: string;
	readonly text_color: string;
	readonly text_opacity: number;
	/** '' for none (also when switched off). */
	readonly text_shadow: string;
	readonly text_align: TextAlign;
	readonly text_fit: TextFit;
	readonly line_height: number;
	readonly letter_spacing: number;
	readonly word_spacing: number;
	readonly text_direction: TextDirection;
	readonly italic: boolean;
	readonly underline: boolean;
	readonly text_case: TextCase;
	readonly stroke_width: number;
	readonly stroke_color: string;
	readonly stroke_opacity: number;
	readonly highlight: Highlight;
	readonly margin: Margin;
	readonly bg_color: string;
	readonly bg_opacity: number;
	readonly border_radius: number;
	readonly padding: number;
	readonly display_duration: number;
	readonly display_from_sound: boolean;
	readonly audio_volume: number;
	readonly text_timing: TextTiming;
	readonly tts: Tts;
	readonly animations: {
		readonly alert: AlertAnimation;
		readonly main: TextAnimation;
		readonly secondary: TextAnimation;
	};
	/** The main text around the image; null when it is in its box. */
	readonly frame: TextFrame | null;
	readonly fills: {
		readonly main: FillLook | null;
		readonly secondary: FillLook | null;
	};
}

/** The parts of the alert's DOM the renderer works on. */
export interface AlertElements {
	/** The alert box. */
	readonly alert: HTMLElement;
	readonly image: HTMLElement;
	/** Holds the texts (a column in the layouts, no box in `free`). */
	readonly texts: HTMLElement;
	readonly text: HTMLElement;
	readonly secondary: HTMLElement;
}

const SHADOW =
	/^-?\d{1,3}(?:px)? -?\d{1,3}(?:px)?(?: -?\d{1,3}(?:px)?)? (?:#[0-9a-f]{3}|#[0-9a-f]{6}|#[0-9a-f]{8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d{1,3}|1\.0{1,3})\s*)?\))$/;

const FONT_FAMILY = /^[A-Za-z0-9][A-Za-z0-9 -]{0,63}$/;

const FONT_WEIGHTS = [
	'100',
	'200',
	'300',
	'400',
	'500',
	'600',
	'700',
	'800',
	'900',
	'normal',
	'bold',
] as const;

const DEFAULT_ELEMENTS = {
	product_image: {
		x: 40,
		y: 880,
		w: 120,
		h: 120,
		visible: true,
		border_radius: 4,
	},
	main_text: {
		x: 176,
		y: 895,
		w: 700,
		h: 80,
		visible: true,
		border_radius: 0,
	},
	secondary_text: {
		x: 176,
		y: 985,
		w: 700,
		h: 50,
		visible: false,
		border_radius: 0,
	},
} as const;

function isRecord( value: unknown ): value is Record< string, unknown > {
	return (
		typeof value === 'object' && value !== null && ! Array.isArray( value )
	);
}

function parseBox( value: unknown, fallback: ElementBox ): ElementBox {
	const data = isRecord( value ) ? value : {};

	return {
		x: intIn( data.x, -3840, 3840, fallback.x ),
		y: intIn( data.y, -2160, 2160, fallback.y ),
		w: intIn( data.w, 10, 3840, fallback.w ),
		h: intIn( data.h, 10, 2160, fallback.h ),
		visible:
			typeof data.visible === 'boolean' ? data.visible : fallback.visible,
		border_radius: intIn(
			data.border_radius,
			0,
			500,
			fallback.border_radius
		),
	};
}

/**
 * A look from untrusted data (the page's configuration); anything missing
 * or unknown gets the default.
 *
 * @param value  Candidate.
 * @param origin Origin a fill image must come from.
 */
export function parseLook( value: unknown, origin: string ): AlertLook {
	const data = isRecord( value ) ? value : {};
	const elements = isRecord( data.elements ) ? data.elements : {};
	const animations = isRecord( data.animations ) ? data.animations : {};
	const fills = isRecord( data.fills ) ? data.fills : {};
	const parsed = {
		product_image: parseBox(
			elements.product_image,
			DEFAULT_ELEMENTS.product_image
		),
		main_text: parseBox( elements.main_text, DEFAULT_ELEMENTS.main_text ),
		secondary_text: parseBox(
			elements.secondary_text,
			DEFAULT_ELEMENTS.secondary_text
		),
	};
	const fontSize = intIn( data.font_size, 8, 200, 32 );

	return {
		widget: parseWidget(
			data.widget,
			around( parsed, fontSize, isRecord( data.frame ) )
		),
		layout_mode: oneOf( data.layout_mode, LAYOUT_MODES, 'free' ),
		elements: parsed,
		show_secondary: data.show_secondary === true,
		font_family:
			typeof data.font_family === 'string' &&
			FONT_FAMILY.test( data.font_family )
				? data.font_family
				: 'inherit',
		font_size: fontSize,
		font_weight: oneOf( data.font_weight, FONT_WEIGHTS, '700' ),
		text_color: hexColor( data.text_color, '#ffffff' ),
		text_opacity: intIn( data.text_opacity, 0, 100, 100 ),
		text_shadow:
			typeof data.text_shadow === 'string' &&
			SHADOW.test( data.text_shadow )
				? data.text_shadow
				: '',
		text_align: oneOf( data.text_align, TEXT_ALIGNS, 'center' ),
		text_fit: isTextFit( data.text_fit ) ? data.text_fit : 'fixed',
		line_height: intIn( data.line_height, 50, 300, 130 ),
		letter_spacing: intIn( data.letter_spacing, -20, 100, 0 ),
		word_spacing: intIn( data.word_spacing, -20, 200, 0 ),
		text_direction: oneOf( data.text_direction, TEXT_DIRECTIONS, 'ltr' ),
		italic: data.italic === true,
		underline: data.underline === true,
		text_case: oneOf( data.text_case, TEXT_CASES, 'none' ),
		stroke_width: intIn( data.stroke_width, 0, 20, 0 ),
		stroke_color: hexColor( data.stroke_color, '#000000' ),
		stroke_opacity: intIn( data.stroke_opacity, 0, 100, 100 ),
		highlight: parseHighlight( data.highlight ),
		margin: parseMargin( data.margin ),
		bg_color: hexColor( data.bg_color, '#000000' ),
		bg_opacity: intIn( data.bg_opacity, 0, 100, 80 ),
		border_radius: intIn( data.border_radius, 0, 100, 8 ),
		padding: intIn( data.padding, 0, 200, 20 ),
		display_duration: intIn( data.display_duration, 1000, 90000, 5000 ),
		display_from_sound: data.display_from_sound === true,
		audio_volume: intIn( data.audio_volume, 0, 100, 100 ),
		text_timing: parseTextTiming( data.text_timing ),
		tts: parseTts( data.tts ),
		animations: {
			alert: parseAlertAnimation( animations.alert, 500 ),
			main: parseAnimation( animations.main_text ),
			secondary: parseAnimation( animations.secondary_text ),
		},
		frame: isRecord( data.frame )
			? { ...parseTextFrame( data.frame ), enabled: true }
			: null,
		fills: {
			main: parseFillLook( fills.main, origin ),
			secondary: parseFillLook( fills.secondary, origin ),
		},
	};
}

/**
 * The box around the visible elements (settings without a box).
 *
 * @param elements Elements.
 * @param fontSize Font size, for the frame's margin.
 * @param frames   Whether the main text runs around the image.
 */
function around(
	elements: AlertLook[ 'elements' ],
	fontSize: number,
	frames: boolean
): Widget {
	const text = frames
		? frameBox( elements.product_image, fontSize )
		: elements.main_text;
	const boxes = [
		elements.product_image.visible ? elements.product_image : null,
		elements.main_text.visible ? text : null,
	].filter( ( box ) => box !== null );
	const all = boxes.length > 0 ? boxes : [ elements.main_text ];
	const left = Math.min( ...all.map( ( box ) => box.x ) );
	const top = Math.min( ...all.map( ( box ) => box.y ) );

	return {
		x: left,
		y: top,
		w: Math.max(
			10,
			Math.max( ...all.map( ( box ) => box.x + box.w ) ) - left
		),
		h: Math.max(
			10,
			Math.max( ...all.map( ( box ) => box.y + box.h ) ) - top
		),
		opacity: 100,
		rotation: 0,
	};
}

/**
 * A colour with an opacity as rgba().
 *
 * @param hex     #rrggbb.
 * @param percent Opacity in percent.
 */
export function rgba( hex: string, percent: number ): string {
	const channel = ( at: number ) =>
		Number.parseInt( hex.slice( at, at + 2 ), 16 );

	return `rgba(${ channel( 1 ) },${ channel( 3 ) },${ channel( 5 ) },${
		Math.round( percent ) / 100
	})`;
}

const CASES: Readonly< Record< TextCase, string > > = {
	none: 'none',
	upper: 'uppercase',
	lower: 'lowercase',
	capitalize: 'capitalize',
};

/**
 * The font family as CSS.
 *
 * @param family "inherit" or a plain family name.
 */
export function fontStack( family: string ): string {
	return family === 'inherit' ? 'inherit' : `"${ family }", sans-serif`;
}

/**
 * Places and styles the alert box, its layout and the texts' style. Call
 * before the texts are filled; arrange() again changes nothing else.
 *
 * @param elements The alert's elements.
 * @param look     Look.
 */
export function arrange( elements: AlertElements, look: AlertLook ): void {
	const { alert, image, texts, text, secondary } = elements;
	const { widget } = look;

	alert.dataset.layout = look.layout_mode;
	setStyles( alert, {
		left: `${ widget.x }px`,
		top: `${ widget.y }px`,
		width: `${ widget.w }px`,
		height: `${ widget.h }px`,
		'--merchboard-alert-opacity': String( widget.opacity / 100 ),
		'--merchboard-alert-rotation': `${ widget.rotation }deg`,
		'--merchboard-alert-in-time': `${ look.animations.alert.in_ms }ms`,
		'--merchboard-alert-out-time': `${ look.animations.alert.out_ms }ms`,
		'--merchboard-img-border-radius': `${ look.elements.product_image.border_radius }px`,
	} );
	alert.dataset.in = look.animations.alert.in;
	alert.dataset.out = look.animations.alert.out;

	image.hidden = ! look.elements.product_image.visible;
	text.hidden = ! look.elements.main_text.visible;
	secondary.hidden = ! look.show_secondary;

	for ( const box of [ text, secondary ] ) {
		styleText( box, look );
	}

	for ( const element of [ image, texts, text, secondary ] ) {
		clearPlace( element );
	}

	if ( look.layout_mode === 'free' ) {
		placeFree( elements, look );
	}
}

/**
 * Sets a text box's style.
 *
 * @param element Text box.
 * @param look    Look.
 */
export function styleText( element: HTMLElement, look: AlertLook ): void {
	const { margin } = look;
	const shadow = look.text_shadow === '' ? 'none' : look.text_shadow;

	setStyles( element, {
		'font-family': fontStack( look.font_family ),
		// Through a variable, so "shrink" (fitText()) can set and reset its own size.
		'--merchboard-font-size': `${ look.font_size }px`,
		'font-weight': look.font_weight,
		color: rgba( look.text_color, look.text_opacity ),
		'text-shadow': shadow,
		'--merchboard-text-shadow':
			shadow === 'none' ? '0 0 0 transparent' : shadow,
		'text-align': look.text_align,
		'line-height': String( look.line_height / 100 ),
		'letter-spacing': `${ look.letter_spacing }px`,
		'word-spacing': `${ look.word_spacing }px`,
		direction: look.text_direction,
		'font-style': look.italic ? 'italic' : 'normal',
		'text-decoration': look.underline ? 'underline' : 'none',
		'text-transform': CASES[ look.text_case ],
		'-webkit-text-stroke':
			look.stroke_width > 0
				? `${ look.stroke_width }px ${ rgba(
						look.stroke_color,
						look.stroke_opacity
					) }`
				: '0 transparent',
		'paint-order': 'stroke fill',
		margin: `${ margin.top }px ${ margin.right }px ${ margin.bottom }px ${ margin.left }px`,
		background: rgba( look.bg_color, look.bg_opacity ),
		'border-radius': `${ look.border_radius }px`,
		padding: `${ look.padding }px`,
		'--merchboard-hl-color': look.highlight.color,
	} );
	element.dataset.highlight = look.highlight.enabled
		? look.highlight.effect
		: '';
}

/**
 * Places the elements in their own boxes (layout `free`): canvas
 * coordinates made relative to the alert box. Around the image the main
 * text takes the image's box with its margin; a growing text box keeps its
 * height as the least and, near the bottom of the canvas, grows up.
 *
 * @param elements The alert's elements.
 * @param look     Look.
 */
function placeFree( elements: AlertElements, look: AlertLook ): void {
	const { widget } = look;
	const place = (
		element: HTMLElement,
		box: { x: number; y: number; w: number; h: number },
		grows: boolean
	) => {
		const left = box.x - widget.x;
		const top = box.y - widget.y;

		if ( grows && 2 * box.y + box.h > CANVAS_HEIGHT ) {
			setStyles( element, {
				left: `${ left }px`,
				bottom: `${ widget.h - top - box.h }px`,
				width: `${ box.w }px`,
				height: 'auto',
				'min-height': `${ box.h }px`,
			} );
			element.dataset.growsUp = '1';
			return;
		}

		setStyles( element, {
			left: `${ left }px`,
			top: `${ top }px`,
			width: `${ box.w }px`,
			height: grows ? 'auto' : `${ box.h }px`,
			'min-height': grows ? `${ box.h }px` : '',
		} );
	};
	const grows = look.text_fit === 'grow';

	place( elements.image, look.elements.product_image, false );
	place(
		elements.text,
		look.frame
			? frameBox( look.elements.product_image, look.font_size )
			: look.elements.main_text,
		grows && look.frame === null
	);
	place( elements.secondary, look.elements.secondary_text, grows );
}

/**
 * Removes what placeFree() set.
 *
 * @param element Element.
 */
function clearPlace( element: HTMLElement ): void {
	for ( const property of [
		'left',
		'top',
		'bottom',
		'width',
		'height',
		'min-height',
		'margin-top',
	] ) {
		element.style.removeProperty( property );
	}

	delete element.dataset.growsUp;
}

/**
 * Sets inline style properties.
 *
 * @param element Element.
 * @param styles  Property → value.
 */
function setStyles(
	element: HTMLElement,
	styles: Readonly< Record< string, string > >
): void {
	for ( const [ property, value ] of Object.entries( styles ) ) {
		if ( value === '' ) {
			element.style.removeProperty( property );
		} else {
			element.style.setProperty( property, value );
		}
	}
}

/**
 * Marks the letters of the inserted values (after the text is set, see
 * renderText(), and before a fill or a frame): they get the highlight
 * colour and effect. Text that is not in letters yet is split. Does
 * nothing while the highlight is off or the text has no values.
 *
 * @param element Text box.
 * @param parts   The text in pieces.
 * @param look    Look.
 */
export function highlight(
	element: HTMLElement,
	parts: readonly TextPart[],
	look: AlertLook
): void {
	if ( ! look.highlight.enabled || ! parts.some( ( part ) => part.value ) ) {
		return;
	}

	const text = parts.map( ( part ) => part.text ).join( '' );

	if ( element.querySelector( '.merchboard-anim__letter' ) === null ) {
		const cursor = element.querySelector( '.merchboard-anim__cursor' );
		element.replaceChildren( ...splitLetters( text ) );

		if ( cursor !== null ) {
			element.append( cursor );
		}
	}

	const marked = new Set< number >();
	let index = 0;

	for ( const part of parts ) {
		const length = Array.from( part.text ).length;

		if ( part.value ) {
			for ( let i = index; i < index + length; i++ ) {
				marked.add( i );
			}
		}

		index += length;
	}

	element
		.querySelectorAll< HTMLElement >( '.merchboard-anim__letter' )
		.forEach( ( letter ) => {
			const i = Number( letter.style.getPropertyValue( '--i' ) );

			if ( marked.has( i ) ) {
				letter.classList.add( 'merchboard-hl' );
			}
		} );
}
