import { describe, expect, it } from '@jest/globals';

import { backgroundOf, removeBackground } from '../sections/community/cutout';
import type { Pixels } from '../sections/community/cutout';

/**
 * An image with a background colour and a square in another colour.
 *
 * @param size       Side.
 * @param background RGB of the background.
 * @param square     RGB of the square in the middle.
 * @param noisy      Whether the edge alternates colours (not plain).
 */
function picture(
	size: number,
	background: [ number, number, number ],
	square: [ number, number, number ],
	noisy = false
): Pixels {
	const data = new Uint8ClampedArray( size * size * 4 );

	for ( let y = 0; y < size; y++ ) {
		for ( let x = 0; x < size; x++ ) {
			const inside = x >= 6 && x < size - 6 && y >= 6 && y < size - 6;
			const odd: [ number, number, number ] = [
				255 - background[ 0 ],
				0,
				255,
			];
			let colour = noisy && ( x + y ) % 2 === 0 ? odd : background;

			if ( inside ) {
				colour = square;
			}

			const index = ( y * size + x ) * 4;
			data[ index ] = colour[ 0 ];
			data[ index + 1 ] = colour[ 1 ];
			data[ index + 2 ] = colour[ 2 ];
			data[ index + 3 ] = 255;
		}
	}

	return { data, width: size, height: size };
}

describe( 'removing a plain background (ADR-113)', () => {
	it( 'finds the background colour along the edges', () => {
		expect(
			backgroundOf( picture( 20, [ 250, 250, 250 ], [ 200, 0, 0 ] ) )
		).toEqual( [ 250, 250, 250 ] );
	} );

	it( 'makes the background transparent and keeps the product', () => {
		const image = picture( 20, [ 252, 252, 250 ], [ 200, 0, 0 ] );

		expect( removeBackground( image ) ).toBe( true );
		expect( image.data[ 3 ] ).toBe( 0 );
		// The middle of the product is untouched, its edge is softened.
		expect( image.data[ ( 10 * 20 + 10 ) * 4 + 3 ] ).toBe( 255 );
		expect( image.data[ ( 6 * 20 + 10 ) * 4 + 3 ] ).toBe( 160 );
	} );

	it( 'leaves an image without a plain background alone', () => {
		const image = picture( 20, [ 20, 200, 20 ], [ 200, 0, 0 ], true );
		const before = image.data.slice();

		expect( removeBackground( image ) ).toBe( false );
		expect( image.data ).toEqual( before );
	} );
} );
