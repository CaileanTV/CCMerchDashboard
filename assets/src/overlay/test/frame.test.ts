/**
 * Tests for the text around the image (phase 25, step 6.11).
 */
import { describe, expect, it } from '@jest/globals';

import { DEFAULT_ANIMATION, renderText } from '../animations';
import {
	DEFAULT_FRAME,
	FRAME_MARGIN,
	applyFrame,
	clearFrame,
	frameBox,
	frameLength,
	framePath,
	parseTextFrame,
} from '../frame';

describe( 'text around the image', () => {
	it( 'reads stored frames and falls back to off', () => {
		expect( parseTextFrame( null ) ).toEqual( DEFAULT_FRAME );
		expect(
			parseTextFrame( {
				enabled: true,
				direction: 'counterclockwise',
				speed: 'warp',
				repeat: false,
			} )
		).toEqual( {
			enabled: true,
			direction: 'counterclockwise',
			speed: 'normal',
			repeat: false,
		} );
		expect( parseTextFrame( { enabled: 'yes', repeat: 1 } ) ).toEqual(
			DEFAULT_FRAME
		);
	} );

	it( 'puts the text box round the image with a margin', () => {
		const margin = Math.round( 40 * FRAME_MARGIN );

		expect( frameBox( { x: 100, y: 50, w: 200, h: 100 }, 40 ) ).toEqual( {
			x: 100 - margin,
			y: 50 - margin,
			w: 200 + 2 * margin,
			h: 100 + 2 * margin,
		} );
	} );

	it( 'draws the frame as a rounded rectangle of known length', () => {
		expect( frameLength( 100, 50, 0 ) ).toBe( 300 );
		expect( frameLength( 100, 50, 10 ) ).toBeCloseTo(
			300 - ( 8 - 2 * Math.PI ) * 10
		);
		expect( framePath( 5, 5, 100, 50, 0 ) ).toBe(
			'M 5 5 H 105 A 0 0 0 0 1 105 5 V 55 A 0 0 0 0 1 105 55 H 5 A 0 0 0 0 1 5 55 V 5 A 0 0 0 0 1 5 5 Z'
		);
		// The radius never exceeds half a side.
		expect( framePath( 0, 0, 10, 10, 50 ) ).toContain( 'A 5 5' );
	} );

	it( 'makes every character a letter on the frame and cleans up', () => {
		const element = document.createElement( 'div' );
		renderText(
			element,
			'Hi you',
			{ ...DEFAULT_ANIMATION, in: 'typewriter' },
			500
		);

		applyFrame( element, {
			enabled: true,
			direction: 'counterclockwise',
			speed: 'fast',
			repeat: false,
		} );

		const letters = element.querySelectorAll< HTMLElement >(
			'.merchboard-anim__letter'
		);
		expect( letters ).toHaveLength( 6 );
		expect( letters[ 2 ]?.textContent ).toBe( ' ' );
		expect( letters[ 5 ]?.style.getPropertyValue( '--i' ) ).toBe( '5' );
		expect( letters[ 5 ]?.style.getPropertyValue( '--d' ) ).toMatch(
			/px$/
		);
		expect( element.classList.contains( 'merchboard-frame' ) ).toBe( true );
		expect(
			element.style.getPropertyValue( '--merchboard-frame-path' )
		).toMatch( /^path\('M / );
		expect(
			element.style.getPropertyValue( '--merchboard-frame-dir' )
		).toBe( 'reverse' );
		expect( element.style.getPropertyValue( '--merchboard-letters' ) ).toBe(
			'6'
		);

		clearFrame( element );
		expect( element.classList.contains( 'merchboard-frame' ) ).toBe(
			false
		);
		expect( element.dataset.frameSpeed ).toBeUndefined();
		expect(
			element.style.getPropertyValue( '--merchboard-frame-path' )
		).toBe( '' );
	} );

	it( 'leaves the box alone without a frame', () => {
		const element = document.createElement( 'div' );
		element.textContent = 'Hi';

		applyFrame( element, DEFAULT_FRAME );
		applyFrame( element, null );

		expect( element.innerHTML ).toBe( 'Hi' );
		expect( element.className ).toBe( '' );
	} );
} );
