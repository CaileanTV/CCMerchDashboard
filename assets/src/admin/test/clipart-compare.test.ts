/**
 * Tests for comparing a bake with the PSD's composite (phase 56, step 56.3).
 */
import { describe, expect, it } from '@jest/globals';

import { differenceOf } from '../clipart-compare';

/**
 * A picture of one pixel.
 *
 * @param rgba The pixel.
 */
function pixel( ...rgba: number[] ) {
	return { width: 1, height: 1, data: new Uint8ClampedArray( rgba ) };
}

describe( 'differenceOf', () => {
	it( 'is none for the same picture', () => {
		expect(
			differenceOf( pixel( 9, 8, 7, 255 ), pixel( 9, 8, 7, 255 ) )
		).toEqual( { mean: 0, seen: 0 } );
	} );

	it( 'does not count the colour of what is see-through', () => {
		expect(
			differenceOf( pixel( 255, 0, 0, 0 ), pixel( 0, 0, 255, 0 ) )
		).toEqual( { mean: 0, seen: 0 } );
	} );

	it( 'finds black missing on a see-through ground', () => {
		expect(
			differenceOf( pixel( 0, 0, 0, 0 ), pixel( 0, 0, 0, 255 ) )
		).toEqual( { mean: 255, seen: 1 } );
	} );

	it( 'compares only the same size', () => {
		expect(
			differenceOf( pixel( 0, 0, 0, 0 ), {
				width: 2,
				height: 1,
				data: new Uint8ClampedArray( 8 ),
			} )
		).toBeNull();
	} );
} );
