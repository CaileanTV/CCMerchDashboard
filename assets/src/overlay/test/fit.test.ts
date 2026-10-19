/**
 * Tests for "shrink to fit" (phase 25, step 6.5).
 */
import { afterEach, describe, expect, it } from '@jest/globals';
import {
	MIN_FONT_SIZE,
	fitText,
	followText,
	isTextFit,
	overflows,
} from '../fit';

const computedStyle = window.getComputedStyle.bind( window );

/**
 * A box of 100 × 40 pixels whose content height grows with the font size;
 * jsdom has no layout, so the sizes come from the font size.
 *
 * @param largest  The style sheet's font size in pixels.
 * @param perPixel Content height per font pixel.
 */
function box( largest: number, perPixel: number ): HTMLElement {
	const element = document.createElement( 'div' );
	const size = () =>
		element.style.fontSize === ''
			? largest
			: Number.parseFloat( element.style.fontSize );

	Object.defineProperty( element, 'clientHeight', { value: 40 } );
	Object.defineProperty( element, 'clientWidth', { value: 100 } );
	Object.defineProperty( element, 'scrollWidth', { get: () => 100 } );
	Object.defineProperty( element, 'scrollHeight', {
		get: () => size() * perPixel,
	} );

	// The style sheet's font size, as getComputedStyle() reports it.
	window.getComputedStyle = ( target: Element ) =>
		target === element
			? ( { fontSize: `${ largest }px` } as CSSStyleDeclaration )
			: computedStyle( target );

	return element;
}

describe( 'fitText', () => {
	afterEach( () => {
		window.getComputedStyle = computedStyle;
	} );

	it( 'keeps the font size when the text fits', () => {
		const element = box( 10, 4 );

		fitText( element );

		expect( element.style.fontSize ).toBe( '' );
		expect( overflows( element ) ).toBe( false );
	} );

	it( 'finds the largest whole size that fits', () => {
		const element = box( 32, 4 );

		fitText( element );

		expect( element.style.fontSize ).toBe( '10px' );
		expect( overflows( element ) ).toBe( false );
	} );

	it( 'stops at the smallest size', () => {
		const element = box( 32, 100 );

		fitText( element );

		expect( element.style.fontSize ).toBe( `${ MIN_FONT_SIZE }px` );
	} );

	it( 'knows the modes', () => {
		expect( isTextFit( 'shrink' ) ).toBe( true );
		expect( isTextFit( 'stretch' ) ).toBe( false );
	} );
} );

describe( 'followText', () => {
	afterEach( () => {
		window.getComputedStyle = computedStyle;
	} );

	function text( height: number ): HTMLElement {
		const element = document.createElement( 'div' );

		Object.defineProperty( element, 'offsetHeight', { value: height } );
		window.getComputedStyle = ( target: Element ) =>
			target === element
				? ( { minHeight: '80px' } as CSSStyleDeclaration )
				: computedStyle( target );

		return element;
	}

	it( 'moves the image up as far as the text grew', () => {
		const image = document.createElement( 'div' );

		followText( image, text( 130 ) );
		expect( image.style.marginTop ).toBe( '-50px' );

		followText( image, text( 80 ) );
		expect( image.style.marginTop ).toBe( '' );
	} );
} );
