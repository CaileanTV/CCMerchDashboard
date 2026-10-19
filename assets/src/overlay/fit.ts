/**
 * "Shrink to fit" for alert text boxes (phase 25, step 6.5): the box's
 * font size is the largest; the text gets the largest whole pixel size at
 * which it fits the box, down to MIN_FONT_SIZE. "Grow": a box near the
 * bottom grows up, and the image follows the main text (followText()). Used
 * by the overlay and by the designer's preview.
 */

/** Smallest font size in pixels. */
export const MIN_FONT_SIZE = 8;

/** How text meets its box. */
export const TEXT_FITS = [ 'fixed', 'shrink', 'grow' ] as const;

export type TextFit = ( typeof TEXT_FITS )[ number ];

/**
 * Whether a value is a text fit mode.
 *
 * @param value Candidate.
 */
export function isTextFit( value: unknown ): value is TextFit {
	return ( TEXT_FITS as readonly unknown[] ).includes( value );
}

/**
 * Whether an element's content is larger than its box.
 *
 * @param element Element.
 */
export function overflows( element: HTMLElement ): boolean {
	return (
		element.scrollHeight > element.clientHeight + 1 ||
		element.scrollWidth > element.clientWidth + 1
	);
}

/**
 * Shrinks an element's text until it fits (binary search over whole pixels).
 * The style sheet's font size is the largest; a fitting text keeps it.
 *
 * @param element Text box.
 */
export function fitText( element: HTMLElement ): void {
	element.style.fontSize = '';

	const largest = Math.floor(
		Number.parseFloat( window.getComputedStyle( element ).fontSize )
	);

	if ( ! Number.isFinite( largest ) || ! overflows( element ) ) {
		return;
	}

	let low = MIN_FONT_SIZE;
	let high = Math.max( MIN_FONT_SIZE, largest - 1 );

	while ( low < high ) {
		const middle = Math.ceil( ( low + high ) / 2 );
		element.style.fontSize = `${ middle }px`;

		if ( overflows( element ) ) {
			high = middle - 1;
		} else {
			low = middle;
		}
	}

	element.style.fontSize = `${ low }px`;
}

/**
 * How far a growing box is taller than its least height (its min-height).
 *
 * @param box Text box.
 */
export function growth( box: HTMLElement ): number {
	const least = Number.parseFloat( window.getComputedStyle( box ).minHeight );

	return Number.isFinite( least )
		? Math.max( 0, box.offsetHeight - least )
		: 0;
}

/**
 * Moves the image up as far as the main text grew up, so the distance
 * between them stays.
 *
 * @param image Image box.
 * @param text  Main text box (grows up).
 */
export function followText( image: HTMLElement, text: HTMLElement ): void {
	const grown = growth( text );

	image.style.marginTop = grown > 0 ? `${ -grown }px` : '';
}
