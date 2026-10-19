/**
 * Text animations of the alert overlay (phase 25, step 6.9): an
 * in-animation, an effect while the text is shown and an out-animation,
 * chosen separately for the main and the secondary text. Our own
 * implementations, inspired by common CSS text effects; the keyframes are
 * in animations.css. Used by the overlay and by the designer's preview.
 *
 * - **Whole-text animations** run on the text box (`--merchboard-anim-in`,
 *   `-fx`, `-out` custom properties in the CSS).
 * - **Letter animations** (typewriter, split, wave, drop, melt, depth) need
 *   one span per letter (`--i` is its index); spaces and line breaks stay
 *   plain text, so the text wraps as before.
 * - The effect starts when the in-animation has ended
 *   (`--merchboard-in-total`).
 * - The default (fade, no effect, fade) adds nothing: the overlay's own
 *   fade is used, exactly as before this step.
 */

export const IN_ANIMATIONS = [
	'fade',
	'slide-up',
	'pop',
	'typewriter',
	'split',
	'wave',
	'drop',
	'spin-3d',
	'roll-3d',
	'blur',
	'wipe',
	'shadow-paint',
] as const;

export const EFFECTS = [
	'none',
	'neon',
	'glow-pulse',
	'heartbeat',
	'glitch',
	'dancing-shadow',
	'melt',
	'wave',
	'float-3d',
	'depth-pulse',
	'breathe',
] as const;

export const OUT_ANIMATIONS = [
	'fade',
	'slide-down',
	'pop',
	'typewriter',
	'split',
	'wave',
	'spin-3d',
	'roll-3d',
	'blur',
	'wipe',
] as const;

export const SPEEDS = [ 'slow', 'normal', 'fast' ] as const;

export type InAnimation = ( typeof IN_ANIMATIONS )[ number ];
export type Effect = ( typeof EFFECTS )[ number ];
export type OutAnimation = ( typeof OUT_ANIMATIONS )[ number ];
export type Speed = ( typeof SPEEDS )[ number ];

export interface TextAnimation {
	readonly in: InAnimation;
	readonly effect: Effect;
	readonly out: OutAnimation;
	/** Colour of glow, shadow and similar effects: #rrggbb. */
	readonly color: string;
	readonly speed: Speed;
}

export const DEFAULT_ANIMATION: TextAnimation = {
	in: 'fade',
	effect: 'none',
	out: 'fade',
	color: '#ff2bd6',
	speed: 'normal',
};

/** Animations that need one span per letter. */
const LETTER_IN: ReadonlySet< string > = new Set( [
	'typewriter',
	'split',
	'wave',
	'drop',
] );
const LETTER_EFFECT: ReadonlySet< string > = new Set( [
	'melt',
	'wave',
	'depth-pulse',
] );
const LETTER_OUT: ReadonlySet< string > = new Set( [
	'typewriter',
	'split',
	'wave',
] );

/** Delay between two letters, by speed (ms). */
export const LETTER_STEP: Readonly< Record< Speed, number > > = {
	slow: 90,
	normal: 55,
	fast: 30,
};

/** One cycle of an effect, by speed (ms). */
export const EFFECT_CYCLE: Readonly< Record< Speed, number > > = {
	slow: 3200,
	normal: 2000,
	fast: 1100,
};

function oneOf< T extends string >(
	value: unknown,
	list: readonly T[],
	fallback: T
): T {
	return ( list as readonly unknown[] ).includes( value )
		? ( value as T )
		: fallback;
}

/**
 * A text animation from untrusted data; unknown values get the default.
 *
 * @param value Candidate.
 */
export function parseAnimation( value: unknown ): TextAnimation {
	const data =
		typeof value === 'object' && value !== null
			? ( value as Record< string, unknown > )
			: {};

	return {
		in: oneOf( data.in, IN_ANIMATIONS, DEFAULT_ANIMATION.in ),
		effect: oneOf( data.effect, EFFECTS, DEFAULT_ANIMATION.effect ),
		out: oneOf( data.out, OUT_ANIMATIONS, DEFAULT_ANIMATION.out ),
		color:
			typeof data.color === 'string' &&
			/^#[0-9a-f]{6}$/.test( data.color )
				? data.color
				: DEFAULT_ANIMATION.color,
		speed: oneOf( data.speed, SPEEDS, DEFAULT_ANIMATION.speed ),
	};
}

/**
 * Whether an animation is the default (the overlay's own fade).
 *
 * @param animation Animation.
 */
export function isDefault( animation: TextAnimation ): boolean {
	return (
		animation.in === 'fade' &&
		animation.effect === 'none' &&
		animation.out === 'fade'
	);
}

/**
 * Whether an animation needs one span per letter.
 *
 * @param animation Animation.
 */
export function needsLetters( animation: TextAnimation ): boolean {
	return (
		LETTER_IN.has( animation.in ) ||
		LETTER_EFFECT.has( animation.effect ) ||
		LETTER_OUT.has( animation.out )
	);
}

/**
 * The text as letter spans; spaces and line breaks stay plain text.
 *
 * @param text Text.
 */
function letters( text: string ): Node[] {
	const characters = Array.from( text );
	const count = characters.length;

	return characters.map( ( character, index ) => {
		if ( /\s/.test( character ) ) {
			return document.createTextNode( character );
		}

		const span = document.createElement( 'span' );
		span.className = 'merchboard-anim__letter';
		span.textContent = character;
		span.style.setProperty( '--i', String( index ) );
		span.style.setProperty( '--side', index < count / 2 ? '-1' : '1' );

		// Steps to the next letter (spaces are no span): the typewriter caret's turn.
		let next = index + 1;

		while ( next < count && /\s/.test( characters[ next ] ?? '' ) ) {
			next++;
		}

		span.style.setProperty( '--n', String( next - index ) );

		return span;
	} );
}

/** How long an animated text takes to appear and to go (ms). */
export interface AnimationTimes {
	readonly inTotal: number;
	readonly outTotal: number;
}

/**
 * Puts the text into its box with the animation. Returns how long the
 * in- and out-animations take, so the player waits for them.
 *
 * @param element   Text box.
 * @param text      Text (plain text, never HTML).
 * @param animation Animation.
 * @param duration  In/out time of whole-text animations (ms).
 * @param always    Animate the default too (the overlay fades its boxes
 *                  itself; a sample in the dashboard does not).
 */
export function renderText(
	element: HTMLElement,
	text: string,
	animation: TextAnimation,
	duration: number,
	always = false
): AnimationTimes {
	resetText( element );

	if ( ! always && isDefault( animation ) ) {
		element.textContent = text;
		return { inTotal: duration, outTotal: duration };
	}

	const count = Array.from( text ).length;
	const step = LETTER_STEP[ animation.speed ];
	const letterIn = LETTER_IN.has( animation.in );
	const letterOut = LETTER_OUT.has( animation.out );
	const inTotal = letterIn ? duration + count * step : duration;
	const outTotal = letterOut ? duration + count * step : duration;

	if ( needsLetters( animation ) ) {
		element.replaceChildren( ...letters( text ) );
	} else {
		element.textContent = text;
	}

	if ( animation.in === 'typewriter' || animation.out === 'typewriter' ) {
		const cursor = document.createElement( 'span' );
		cursor.className = 'merchboard-anim__cursor';
		cursor.setAttribute( 'aria-hidden', 'true' );
		element.append( cursor );
	}

	element.classList.add( 'merchboard-anim' );
	element.dataset.text = text;
	element.dataset.in = animation.in;
	element.dataset.effect = animation.effect;
	element.dataset.out = animation.out;
	element.style.setProperty( '--merchboard-anim-time', `${ duration }ms` );
	element.style.setProperty( '--merchboard-letter-step', `${ step }ms` );
	element.style.setProperty( '--merchboard-letters', String( count ) );
	element.style.setProperty( '--merchboard-in-total', `${ inTotal }ms` );
	element.style.setProperty(
		'--merchboard-cycle',
		`${ EFFECT_CYCLE[ animation.speed ] }ms`
	);
	element.style.setProperty( '--merchboard-effect-color', animation.color );

	return { inTotal, outTotal };
}

/**
 * Starts the out-animation.
 *
 * @param element Text box.
 */
export function leaveText( element: HTMLElement ): void {
	if ( element.classList.contains( 'merchboard-anim' ) ) {
		element.classList.add( 'merchboard-anim--out' );
	}
}

/**
 * Removes every trace of an animation from a text box.
 *
 * @param element Text box.
 */
export function resetText( element: HTMLElement ): void {
	element.classList.remove( 'merchboard-anim', 'merchboard-anim--out' );

	for ( const key of [ 'text', 'in', 'effect', 'out' ] ) {
		delete element.dataset[ key ];
	}

	for ( const property of [
		'--merchboard-anim-time',
		'--merchboard-letter-step',
		'--merchboard-letters',
		'--merchboard-in-total',
		'--merchboard-cycle',
		'--merchboard-effect-color',
	] ) {
		element.style.removeProperty( property );
	}
}
