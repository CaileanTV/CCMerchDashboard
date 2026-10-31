/**
 * Tests for drawing a print area on a template.
 */
import { describe, expect, it } from '@jest/globals';

import { drawnArea, isInside, movedArea } from '../area-picker';

describe( 'the print area on the template', () => {
	it( 'is the box of a drag, whichever way it goes', () => {
		const area = { x: 10, y: 20, width: 30, height: 40 };

		expect(
			drawnArea( { x: 10, y: 20 }, { x: 40, y: 60 }, 100, 100 )
		).toEqual( area );
		expect(
			drawnArea( { x: 40, y: 60 }, { x: 10, y: 20 }, 100, 100 )
		).toEqual( area );
	} );

	it( 'stays inside the template and in whole pixels', () => {
		expect(
			drawnArea( { x: -5, y: 10.4 }, { x: 140, y: 50.6 }, 100, 100 )
		).toEqual( { x: 0, y: 10, width: 100, height: 41 } );
	} );

	it( 'moves without leaving the template', () => {
		const area = { x: 10, y: 10, width: 30, height: 40 };

		expect( movedArea( area, 5.4, -3, 100, 100 ) ).toEqual( {
			...area,
			x: 15,
			y: 7,
		} );
		expect( movedArea( area, 500, 500, 100, 100 ) ).toEqual( {
			...area,
			x: 70,
			y: 60,
		} );
		expect( movedArea( area, -500, -500, 100, 100 ) ).toEqual( {
			...area,
			x: 0,
			y: 0,
		} );
	} );

	it( 'knows a drag that starts inside it', () => {
		const area = { x: 10, y: 10, width: 30, height: 40 };

		expect( isInside( area, { x: 20, y: 20 } ) ).toBe( true );
		expect( isInside( area, { x: 5, y: 20 } ) ).toBe( false );
	} );
} );
