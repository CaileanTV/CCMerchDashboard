/**
 * Tests for the text animations (phase 25, step 6.9).
 */
import { describe, expect, it } from '@jest/globals';
import {
	DEFAULT_ANIMATION,
	LETTER_STEP,
	isDefault,
	leaveText,
	needsLetters,
	parseAnimation,
	renderText,
	resetText,
} from '../animations';

describe( 'text animations', () => {
	it( 'reads untrusted data and falls back to the default', () => {
		expect( parseAnimation( null ) ).toEqual( DEFAULT_ANIMATION );
		expect(
			parseAnimation( {
				in: 'typewriter',
				effect: 'explode',
				out: 'wave',
				color: '#00FF00',
				speed: 'fast',
			} )
		).toEqual( {
			in: 'typewriter',
			effect: 'none',
			out: 'wave',
			color: DEFAULT_ANIMATION.color,
			speed: 'fast',
		} );
		expect( isDefault( DEFAULT_ANIMATION ) ).toBe( true );
		expect( needsLetters( { ...DEFAULT_ANIMATION, effect: 'melt' } ) ).toBe(
			true
		);
		expect( needsLetters( { ...DEFAULT_ANIMATION, in: 'pop' } ) ).toBe(
			false
		);
	} );

	it( 'keeps the default as plain text without any marks', () => {
		const element = document.createElement( 'div' );

		expect( renderText( element, 'Hi', DEFAULT_ANIMATION, 500 ) ).toEqual( {
			inTotal: 500,
			outTotal: 500,
		} );
		expect( element.innerHTML ).toBe( 'Hi' );
		expect( element.classList.contains( 'merchboard-anim' ) ).toBe( false );
	} );

	it( 'animates the default too when asked (a sample in the dashboard)', () => {
		const element = document.createElement( 'div' );

		renderText( element, 'Hi', DEFAULT_ANIMATION, 500, true );

		expect( element.classList.contains( 'merchboard-anim' ) ).toBe( true );
		expect( element.dataset.in ).toBe( 'fade' );
		expect( element.textContent ).toBe( 'Hi' );
	} );

	it( 'splits letters, keeps spaces as text and adds a typewriter cursor', () => {
		const element = document.createElement( 'div' );
		const times = renderText(
			element,
			'Hi <b>!',
			{ ...DEFAULT_ANIMATION, in: 'typewriter', effect: 'neon' },
			500
		);

		const letters = element.querySelectorAll( '.merchboard-anim__letter' );

		expect( letters ).toHaveLength( 6 );
		expect( element.textContent ).toBe( 'Hi <b>!' );
		expect( element.querySelector( 'b' ) ).toBeNull();
		expect(
			element.querySelector( '.merchboard-anim__cursor' )
		).not.toBeNull();
		expect( element.dataset.in ).toBe( 'typewriter' );
		expect( element.dataset.effect ).toBe( 'neon' );
		expect( element.dataset.text ).toBe( 'Hi <b>!' );
		expect(
			( letters[ 2 ] as HTMLElement ).style.getPropertyValue( '--i' )
		).toBe( '3' );
		// The caret's turn: "i" lasts over the space, "!" until the end.
		expect(
			( letters[ 1 ] as HTMLElement ).style.getPropertyValue( '--n' )
		).toBe( '2' );
		expect(
			( letters[ 5 ] as HTMLElement ).style.getPropertyValue( '--n' )
		).toBe( '1' );
		expect( times.inTotal ).toBe( 500 + 7 * LETTER_STEP.normal );
		expect( times.outTotal ).toBe( 500 );
	} );

	it( 'starts the out-animation and cleans up afterwards', () => {
		const element = document.createElement( 'div' );

		renderText( element, 'Hi', { ...DEFAULT_ANIMATION, out: 'blur' }, 500 );
		leaveText( element );
		expect( element.classList.contains( 'merchboard-anim--out' ) ).toBe(
			true
		);

		resetText( element );
		expect( element.className ).toBe( '' );
		expect( element.dataset.out ).toBeUndefined();
		expect(
			element.style.getPropertyValue( '--merchboard-anim-time' )
		).toBe( '' );
	} );
} );
