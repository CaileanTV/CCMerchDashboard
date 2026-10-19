/**
 * Text around the image (phase 25, step 6.11): instead of a text box, the
 * main text runs along a frame around the product image and glides round
 * it. Our own implementation; the rules are in frame.css. Used by the
 * overlay, the designer and the sample in the settings.
 *
 * - **Box:** the text box covers the image with a margin (the server and
 *   the designer place it, see frameBox()); the frame is a rounded
 *   rectangle in the middle of that margin.
 * - **Letters:** every character (spaces too) is a letter on the frame
 *   (`offset-path`), placed by the widths of the letters before it, so the
 *   text reads as written. With "repeat", the text is repeated with a
 *   separator as often as it fits; a text longer than the frame is made
 *   smaller. The whole text glides round (`offset-distance`), clockwise or
 *   counterclockwise, or stands still.
 * - The letters keep their text animations and fills (`--fx` follows the
 *   frame, see textfill.ts).
 */
import { splitLetters } from './animations';

export const FRAME_DIRECTIONS = [ 'clockwise', 'counterclockwise' ] as const;

export const FRAME_SPEEDS = [ 'still', 'slow', 'normal', 'fast' ] as const;

export type FrameDirection = ( typeof FRAME_DIRECTIONS )[ number ];
export type FrameSpeed = ( typeof FRAME_SPEEDS )[ number ];

/** The main text around the image, as stored (`text_frame`). */
export interface TextFrame {
	readonly enabled: boolean;
	readonly direction: FrameDirection;
	readonly speed: FrameSpeed;
	/** Repeat the text round the whole frame. */
	readonly repeat: boolean;
}

export const DEFAULT_FRAME: TextFrame = {
	enabled: false,
	direction: 'clockwise',
	speed: 'normal',
	repeat: true,
};

/** How far the text glides per second, in letter heights (em). */
const SPEED: Readonly< Record< FrameSpeed, number > > = {
	still: 0,
	slow: 1.5,
	normal: 3,
	fast: 6,
};

/** The margin round the image that holds the text, in font sizes. */
export const FRAME_MARGIN = 1.2;

/** Between two copies of a repeated text. */
export const FRAME_SEPARATOR = ' • ';

const FRAME_CLASS = 'merchboard-frame';
const LETTER = 'merchboard-anim__letter';
const REPEAT = 'merchboard-frame__repeat';

function oneOf< T extends string >(
	value: unknown,
	options: readonly T[],
	fallback: T
): T {
	return options.find( ( option ) => option === value ) ?? fallback;
}

/**
 * A stored frame; anything unknown gets the default.
 *
 * @param value Untrusted data.
 */
export function parseTextFrame( value: unknown ): TextFrame {
	const data =
		typeof value === 'object' && value !== null
			? ( value as Record< string, unknown > )
			: {};

	return {
		enabled: data.enabled === true,
		direction: oneOf(
			data.direction,
			FRAME_DIRECTIONS,
			DEFAULT_FRAME.direction
		),
		speed: oneOf( data.speed, FRAME_SPEEDS, DEFAULT_FRAME.speed ),
		repeat: typeof data.repeat === 'boolean' ? data.repeat : true,
	};
}

/**
 * The box of the text around an image box: the image with a margin of
 * FRAME_MARGIN font sizes on every side (canvas pixels).
 *
 * @param image    Image box.
 * @param image.x  Left.
 * @param image.y  Top.
 * @param image.w  Width.
 * @param image.h  Height.
 * @param fontSize Font size in canvas pixels.
 */
export function frameBox(
	image: { x: number; y: number; w: number; h: number },
	fontSize: number
): { x: number; y: number; w: number; h: number } {
	const margin = Math.round( fontSize * FRAME_MARGIN );

	return {
		x: image.x - margin,
		y: image.y - margin,
		w: image.w + 2 * margin,
		h: image.h + 2 * margin,
	};
}

/**
 * Removes the frame from a text box (the letters stay).
 *
 * @param element Text box.
 */
export function clearFrame( element: HTMLElement ): void {
	element.classList.remove( FRAME_CLASS );
	element
		.querySelectorAll( `.${ REPEAT }` )
		.forEach( ( node ) => node.remove() );

	for ( const key of [ 'frameSpeed', 'frameDirection', 'frameRepeat' ] ) {
		delete element.dataset[ key ];
	}

	for ( const property of [
		'--merchboard-frame-path',
		'--merchboard-frame-length',
		'--merchboard-frame-time',
		'--merchboard-frame-dir',
		'--merchboard-frame-scale',
	] ) {
		element.style.removeProperty( property );
	}
}

/**
 * Puts the text of a box round the frame (after its text is set, see
 * renderText()). Call layoutFrame() again when the box changes size.
 *
 * @param element Text box.
 * @param frame   Frame, or null for none.
 */
export function applyFrame(
	element: HTMLElement,
	frame: TextFrame | null
): void {
	clearFrame( element );

	if ( frame === null || ! frame.enabled ) {
		return;
	}

	if ( element.querySelector( `.${ LETTER }` ) === null ) {
		element.replaceChildren( ...splitLetters( element.textContent ?? '' ) );
	}

	// Spaces become letters too, so they take their place on the frame.
	for ( const node of Array.from( element.childNodes ) ) {
		if ( node.nodeType === Node.TEXT_NODE ) {
			node.replaceWith( ...spaces( node.textContent ?? '' ) );
		}
	}

	element.dataset.frameSpeed = frame.speed;
	element.dataset.frameDirection = frame.direction;
	element.dataset.frameRepeat = frame.repeat ? '1' : '0';
	layoutFrame( element );
}

/**
 * Spans for the characters of a text node (spaces as no-break spaces).
 *
 * @param text Text.
 */
function spaces( text: string ): HTMLElement[] {
	return Array.from( text ).map( ( character ) => {
		const span = document.createElement( 'span' );
		span.className = LETTER;
		span.textContent = /\s/.test( character ) ? ' ' : character;

		return span;
	} );
}

/**
 * The frame as an SVG path: a rounded rectangle, clockwise from the top
 * left corner.
 *
 * @param left   Left.
 * @param top    Top.
 * @param width  Width.
 * @param height Height.
 * @param radius Corner radius.
 */
export function framePath(
	left: number,
	top: number,
	width: number,
	height: number,
	radius: number
): string {
	const r = Math.max( 0, Math.min( radius, width / 2, height / 2 ) );
	const right = left + width;
	const bottom = top + height;
	const n = ( value: number ) => String( Math.round( value * 100 ) / 100 );

	return [
		`M ${ n( left + r ) } ${ n( top ) }`,
		`H ${ n( right - r ) }`,
		`A ${ n( r ) } ${ n( r ) } 0 0 1 ${ n( right ) } ${ n( top + r ) }`,
		`V ${ n( bottom - r ) }`,
		`A ${ n( r ) } ${ n( r ) } 0 0 1 ${ n( right - r ) } ${ n( bottom ) }`,
		`H ${ n( left + r ) }`,
		`A ${ n( r ) } ${ n( r ) } 0 0 1 ${ n( left ) } ${ n( bottom - r ) }`,
		`V ${ n( top + r ) }`,
		`A ${ n( r ) } ${ n( r ) } 0 0 1 ${ n( left + r ) } ${ n( top ) }`,
		'Z',
	].join( ' ' );
}

/**
 * The length of framePath().
 *
 * @param width  Width.
 * @param height Height.
 * @param radius Corner radius.
 */
export function frameLength(
	width: number,
	height: number,
	radius: number
): number {
	const r = Math.max( 0, Math.min( radius, width / 2, height / 2 ) );

	return 2 * ( width + height ) - ( 8 - 2 * Math.PI ) * r;
}

/**
 * Places the letters on the frame of the box's current size: repeats the
 * text where it fits, makes a too long text smaller, and starts the glide.
 *
 * @param element Text box with a frame (applyFrame()).
 */
export function layoutFrame( element: HTMLElement ): void {
	const speed = element.dataset.frameSpeed;

	if ( speed === undefined ) {
		return;
	}

	// Measured in the normal text flow.
	element.classList.remove( FRAME_CLASS );
	element.style.removeProperty( '--merchboard-frame-scale' );
	element
		.querySelectorAll( `.${ REPEAT }` )
		.forEach( ( node ) => node.remove() );

	const own = Array.from(
		element.querySelectorAll< HTMLElement >( `.${ LETTER }` )
	);
	const fontSize =
		Number.parseFloat( window.getComputedStyle( element ).fontSize ) || 32;
	const inset = fontSize * ( FRAME_MARGIN / 2 );
	const width = Math.max( 1, element.clientWidth - 2 * inset );
	const height = Math.max( 1, element.clientHeight - 2 * inset );
	const radius = fontSize / 2;
	const length = frameLength( width, height, radius );
	const textWidth = own.reduce(
		( sum, letter ) => sum + letter.offsetWidth,
		0
	);

	// The text as often as it fits, with a separator after each copy.
	let letters = own;

	if ( element.dataset.frameRepeat === '1' && textWidth > 0 ) {
		const separator = spaces( FRAME_SEPARATOR );
		separator.forEach( ( span ) => span.classList.add( REPEAT ) );
		element.append( ...separator );

		const copyWidth =
			textWidth +
			separator.reduce( ( sum, letter ) => sum + letter.offsetWidth, 0 );
		const copies = Math.max( 1, Math.floor( length / copyWidth ) );

		letters = [ ...own, ...separator ];

		for ( let copy = 1; copy < copies; copy++ ) {
			const clones = [ ...own, ...separator ].map( ( letter ) => {
				const clone = letter.cloneNode( true ) as HTMLElement;
				clone.classList.add( REPEAT );

				return clone;
			} );

			element.append( ...clones );
			letters = [ ...letters, ...clones ];
		}
	}

	const widths = letters.map( ( letter ) => letter.offsetWidth );
	const total = widths.reduce( ( sum, value ) => sum + value, 0 );
	// A text longer than the frame is made smaller to fit once round.
	const scale = total > length ? length / total : 1;
	// A repeated text fills the frame exactly: the rest is spread between the letters.
	const gap =
		letters.length > own.length && total < length
			? ( length - total ) / letters.length
			: 0;
	let distance = 0;

	letters.forEach( ( letter, index ) => {
		const letterWidth = ( widths[ index ] ?? 0 ) * scale + gap;

		letter.style.setProperty( '--i', String( index ) );
		letter.style.setProperty( '--n', '1' );
		letter.style.setProperty(
			'--d',
			`${ Math.round( ( distance + letterWidth / 2 ) * 100 ) / 100 }px`
		);
		letter.dataset.frameX = String( distance );
		distance += letterWidth;
	} );

	const perSecond = SPEED[ speed as FrameSpeed ] * fontSize;

	if ( element.style.getPropertyValue( '--merchboard-letters' ) !== '' ) {
		element.style.setProperty(
			'--merchboard-letters',
			String( letters.length )
		);
	}

	if ( scale < 1 ) {
		element.style.setProperty(
			'--merchboard-frame-scale',
			String( scale )
		);
	}

	element.style.setProperty(
		'--merchboard-frame-path',
		`path('${ framePath( inset, inset, width, height, radius ) }')`
	);
	element.style.setProperty( '--merchboard-frame-length', `${ length }px` );
	element.style.setProperty(
		'--merchboard-frame-time',
		perSecond > 0 ? `${ length / perSecond }s` : '0s'
	);
	element.style.setProperty(
		'--merchboard-frame-dir',
		element.dataset.frameDirection === 'counterclockwise'
			? 'reverse'
			: 'normal'
	);
	element.classList.add( FRAME_CLASS );
}
