/**
 * Text fills of the alert overlay (phase 25, step 6.10): the letters are
 * filled with a ready-made gradient or pattern, or with an image (an
 * animated GIF keeps moving), which can glide sideways or fall. Our own
 * presets; the rules are in textfill.css. Used by the overlay, the
 * designer and the sample in the settings.
 *
 * - **Letters:** each letter carries the fill (`background-clip: text`),
 *   shifted by its position in the box (`--fx`, `--fy`; layoutFill()
 *   measures them), so the fill runs across the whole text. On the box
 *   itself it would also clip the box's background, and a letter that moves
 *   on its own is not part of the box's text clip. Text without a letter
 *   animation is split into letters for the fill.
 * - **Shadows:** a text shadow would cover the fill, so with a fill the
 *   shadow and the glow effects are drop shadows (filter) on the letters.
 * - **Size:** a preset spans the text; an image covers it (like
 *   `background-size: cover`), centred. Moving fills repeat seamlessly.
 */
import { splitLetters } from './animations';

export const FILL_TYPES = [ 'none', 'preset', 'image' ] as const;

export const FILL_PRESETS = [
	'rainbow',
	'aurora',
	'sunset',
	'ocean',
	'fire',
	'gold',
	'candy',
	'matrix',
] as const;

export const FILL_MOTIONS = [ 'none', 'pan', 'fall' ] as const;

export type FillType = ( typeof FILL_TYPES )[ number ];
export type FillPreset = ( typeof FILL_PRESETS )[ number ];
export type FillMotion = ( typeof FILL_MOTIONS )[ number ];

/** A text fill as stored in the overlay settings (`text_fills`). */
export interface TextFill {
	readonly type: FillType;
	readonly preset: FillPreset;
	/** Attachment id of the image; 0 for none. */
	readonly image: number;
	readonly motion: FillMotion;
}

export const DEFAULT_FILL: TextFill = {
	type: 'none',
	preset: 'rainbow',
	image: 0,
	motion: 'none',
};

/** What a text is filled with: a preset or an image on this site. */
export type FillLook =
	| {
			readonly kind: 'preset';
			readonly preset: FillPreset;
			readonly motion: FillMotion;
	  }
	| {
			readonly kind: 'image';
			readonly url: string;
			readonly width: number;
			readonly height: number;
			readonly motion: FillMotion;
	  };

/** An image that can fill text. */
export interface FillImage {
	readonly id: number;
	readonly url: string;
	readonly width: number;
	readonly height: number;
}

/**
 * The presets. Each starts and ends with the same colour, so a moving fill
 * repeats without a seam.
 */
export const PRESET_IMAGES: Readonly< Record< FillPreset, string > > = {
	rainbow:
		'linear-gradient(90deg, #ff004c, #ff8a00, #ffe600, #2bff88, #00c3ff, #7a5cff, #ff004c)',
	aurora: 'linear-gradient(90deg, #00ffa3, #03e1ff, #8f5cff, #dc1fff, #00ffa3)',
	sunset: 'linear-gradient(90deg, #ff512f, #f09819, #ffd86f, #ff2e63, #ff512f)',
	ocean: 'linear-gradient(90deg, #00c6ff, #0072ff, #00f5d4, #00c6ff)',
	fire: 'linear-gradient(90deg, #ff2400, #ff7b00, #ffd000, #ff7b00, #ff2400)',
	gold: 'linear-gradient(90deg, #bf953f, #fcf6ba, #b38728, #fbf5b7, #bf953f)',
	candy: 'repeating-linear-gradient(45deg, #ff6ad5 0 0.3em, #ffffff 0.3em 0.6em, #8ef6ff 0.6em 0.9em)',
	matrix: 'repeating-linear-gradient(180deg, #00ff41 0 0.18em, #008f11 0.18em 0.4em, #003b00 0.4em 0.55em)',
};

const FILL_CLASS = 'merchboard-fill';

const FILL_PROPERTIES = [
	'--merchboard-fill-image',
	'--merchboard-fill-w',
	'--merchboard-fill-h',
	'--merchboard-fill-x',
	'--merchboard-fill-y',
];

function oneOf< T extends string >(
	value: unknown,
	options: readonly T[],
	fallback: T
): T {
	return options.find( ( option ) => option === value ) ?? fallback;
}

function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

function isSize( value: unknown ): value is number {
	return Number.isInteger( value ) && ( value as number ) > 0;
}

/**
 * A stored text fill; anything unknown gets the default.
 *
 * @param value Untrusted data.
 */
export function parseTextFill( value: unknown ): TextFill {
	const data = isRecord( value ) ? value : {};

	return {
		type: oneOf( data.type, FILL_TYPES, DEFAULT_FILL.type ),
		preset: oneOf( data.preset, FILL_PRESETS, DEFAULT_FILL.preset ),
		image:
			Number.isInteger( data.image ) && ( data.image as number ) > 0
				? ( data.image as number )
				: 0,
		motion: oneOf( data.motion, FILL_MOTIONS, DEFAULT_FILL.motion ),
	};
}

/**
 * The look of a fill from the overlay page; null for none or anything
 * invalid. An image must be on the given origin (this site).
 *
 * @param value  Untrusted data.
 * @param origin Allowed origin of an image.
 */
export function parseFillLook(
	value: unknown,
	origin: string
): FillLook | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const motion = oneOf( value.motion, FILL_MOTIONS, 'none' );

	if ( value.kind === 'preset' ) {
		const preset = FILL_PRESETS.find(
			( option ) => option === value.preset
		);

		return preset === undefined ? null : { kind: 'preset', preset, motion };
	}

	if (
		value.kind !== 'image' ||
		typeof value.url !== 'string' ||
		! isSize( value.width ) ||
		! isSize( value.height )
	) {
		return null;
	}

	try {
		const url = new URL( value.url, origin );

		return url.origin === origin &&
			( url.protocol === 'https:' || url.protocol === 'http:' )
			? {
					kind: 'image',
					url: url.href,
					width: value.width,
					height: value.height,
					motion,
				}
			: null;
	} catch {
		return null;
	}
}

/**
 * The look of a stored fill in the dashboard, with the images the creator
 * may use; null for none or an image that is gone.
 *
 * @param fill   Stored fill.
 * @param images The creator's own and the library's images.
 */
export function fillLook(
	fill: TextFill,
	images: readonly FillImage[]
): FillLook | null {
	if ( fill.type === 'preset' ) {
		return { kind: 'preset', preset: fill.preset, motion: fill.motion };
	}

	const image =
		fill.type === 'image'
			? images.find( ( candidate ) => candidate.id === fill.image )
			: undefined;

	return image === undefined
		? null
		: {
				kind: 'image',
				url: image.url,
				width: image.width,
				height: image.height,
				motion: fill.motion,
			};
}

/**
 * A URL as a CSS `url()` value; characters that could end it are escaped.
 *
 * @param url URL.
 */
export function cssUrl( url: string ): string {
	return `url("${ url.replace(
		/["'()\\\s]/g,
		( character ) =>
			`%${ character.charCodeAt( 0 ).toString( 16 ).padStart( 2, '0' ) }`
	) }")`;
}

/**
 * Removes a fill from a text box.
 *
 * @param element Text box.
 */
export function clearFill( element: HTMLElement ): void {
	element.classList.remove( FILL_CLASS );

	for ( const key of [ 'fillMotion', 'fillWidth', 'fillHeight' ] ) {
		delete element.dataset[ key ];
	}

	for ( const property of FILL_PROPERTIES ) {
		element.style.removeProperty( property );
	}
}

/**
 * Fills a text box (after its text is set, see renderText()); text that
 * is not in letters yet is split. Call layoutFill() again once the text has
 * its final size.
 *
 * @param element Text box.
 * @param look    Fill, or null for none.
 */
export function applyFill( element: HTMLElement, look: FillLook | null ): void {
	clearFill( element );

	if ( look === null ) {
		return;
	}

	if ( element.querySelector( '.merchboard-anim__letter' ) === null ) {
		element.replaceChildren( ...splitLetters( element.textContent ?? '' ) );
	}

	element.classList.add( FILL_CLASS );
	element.dataset.fillMotion = look.motion;
	element.style.setProperty(
		'--merchboard-fill-image',
		look.kind === 'preset'
			? PRESET_IMAGES[ look.preset ]
			: cssUrl( look.url )
	);

	if ( look.kind === 'image' ) {
		element.dataset.fillWidth = String( look.width );
		element.dataset.fillHeight = String( look.height );
	}

	layoutFill( element );
}

/**
 * Sizes the fill to the text and gives each letter its position in the box,
 * so the fill runs across the whole text. Positions ignore transforms, so a
 * moving letter takes its part of the fill along.
 *
 * @param element Text box.
 */
export function layoutFill( element: HTMLElement ): void {
	if ( ! element.classList.contains( FILL_CLASS ) ) {
		return;
	}

	const letters = Array.from(
		element.querySelectorAll< HTMLElement >( '.merchboard-anim__letter' )
	);
	// A letter's place: on a frame round the image (frame.ts) its distance along the frame.
	const place = ( letter: HTMLElement ) =>
		letter.dataset.frameX === undefined
			? { x: letter.offsetLeft, y: letter.offsetTop }
			: { x: Number( letter.dataset.frameX ), y: 0 };
	// The text's extent in the box: the fill spans the text, not the empty rest of the box.
	const left = Math.min( ...letters.map( ( letter ) => place( letter ).x ) );
	const top = Math.min( ...letters.map( ( letter ) => place( letter ).y ) );
	const right = Math.max(
		...letters.map( ( letter ) => place( letter ).x + letter.offsetWidth )
	);
	const bottom = Math.max(
		...letters.map( ( letter ) => place( letter ).y + letter.offsetHeight )
	);
	const origin = {
		x: Number.isFinite( left ) ? left : 0,
		y: Number.isFinite( top ) ? top : 0,
	};
	const width = Math.max(
		1,
		Number.isFinite( right ) ? right - origin.x : 1
	);
	const height = Math.max(
		1,
		Number.isFinite( bottom ) ? bottom - origin.y : 1
	);
	const imageWidth = Number( element.dataset.fillWidth ?? 0 );
	const imageHeight = Number( element.dataset.fillHeight ?? 0 );
	let size = { w: width, h: height, x: origin.x, y: origin.y };

	// An image covers the text, centred.
	if ( imageWidth > 0 && imageHeight > 0 ) {
		const scale = Math.max( width / imageWidth, height / imageHeight );
		const w = imageWidth * scale;
		const h = imageHeight * scale;

		size = {
			w,
			h,
			x: origin.x + ( width - w ) / 2,
			y: origin.y + ( height - h ) / 2,
		};
	}

	element.style.setProperty( '--merchboard-fill-w', `${ size.w }px` );
	element.style.setProperty( '--merchboard-fill-h', `${ size.h }px` );
	element.style.setProperty( '--merchboard-fill-x', `${ size.x }px` );
	element.style.setProperty( '--merchboard-fill-y', `${ size.y }px` );

	for ( const letter of letters ) {
		letter.style.setProperty( '--fx', `${ place( letter ).x }px` );
		letter.style.setProperty( '--fy', `${ place( letter ).y }px` );
	}
}
