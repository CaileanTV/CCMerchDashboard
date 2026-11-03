import { describe, expect, it } from '@jest/globals';

import {
	MAX_VARIATIONS,
	parseAlertAnimation,
	parseParts,
	parseTts,
	parseVariations,
	parseWidget,
} from '../look';

const AROUND = { x: 40, y: 880, w: 836, h: 120, opacity: 100, rotation: 0 };

describe( 'alert designer 2.0 values', () => {
	it( 'reads the box within bounds, a missing value from the fallback', () => {
		expect(
			parseWidget(
				{ x: 99999, w: 5, opacity: -3, rotation: 45.7 },
				AROUND
			)
		).toEqual( {
			x: 3840,
			y: 880,
			w: 10,
			h: 120,
			opacity: 0,
			rotation: 45,
		} );
		expect( parseWidget( 'nope', AROUND ) ).toEqual( AROUND );
	} );

	it( 'gives unknown animations and voices the default', () => {
		expect(
			parseAlertAnimation( { in: 'spin', in_ms: 200 }, 700 )
		).toEqual( { in: 'rise', out: 'rise', in_ms: 200, out_ms: 700 } );
		expect( parseTts( { voice: 'xx-Robot', enabled: 'yes' } ) ).toEqual( {
			enabled: false,
			voice: 'da-DK-Standard-A',
			volume: 75,
			delay: 0,
			min_amount: 0,
		} );
	} );

	it( 'keeps valid variations, at most ten', () => {
		const many = Array.from( { length: 14 }, ( _, i ) => ( {
			id: `var${ String( i ).padStart( 4, '0' ) }`,
		} ) );

		expect( parseVariations( many ) ).toHaveLength( MAX_VARIATIONS );
		expect(
			parseVariations( [
				{ id: '../x' },
				{
					id: 'big001',
					name: 'Big',
					enabled: false,
					conditions: { min_amount: 500, categories: [ 5, 'x', -1 ] },
					look: { template: 'Wow' },
				},
			] )
		).toEqual( [
			{
				id: 'big001',
				name: 'Big',
				enabled: false,
				conditions: {
					min_amount: 500,
					min_quantity: 0,
					categories: [ 5 ],
				},
				look: { template: 'Wow' },
			},
		] );
	} );

	it( 'uses the pieces only when they make up the text', () => {
		const parts = [
			{ text: 'nova', value: true },
			{ text: ' bought', value: false },
		];

		expect( parseParts( parts, 'nova bought' ) ).toEqual( parts );
		expect( parseParts( parts, 'other' ) ).toEqual( [
			{ text: 'other', value: false },
		] );
		expect( parseParts( [ { text: 1 } ], 'x' ) ).toEqual( [
			{ text: 'x', value: false },
		] );
		expect( parseParts( undefined, '' ) ).toEqual( [] );
	} );
} );
