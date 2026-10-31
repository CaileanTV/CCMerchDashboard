/**
 * Tests for embroidered sides: the stitched image and the close-ups they send.
 */
import { describe, expect, it, jest } from '@jest/globals';

import type { DesignerView } from '../api/designer';
import {
	distanceField,
	stitched,
	stitchMargin,
} from '../sections/designer/embroidery';
import type { Pixels } from '../sections/designer/scene';
import { hasStitches, submissionSize } from '../sections/designer/submission';
import type { DesignDocument } from '../sections/designer/design-model';

jest.mock( '../sections/designer/stitch-surface', () => ( {
	STITCH_PX_PER_MM: 9.2,
	STITCH_SURFACE: 'data:image/jpeg;base64,',
} ) );

/**
 * An image of one colour.
 *
 * @param width  Width.
 * @param height Height.
 * @param rgba   The colour.
 */
function plain(
	width: number,
	height: number,
	rgba: readonly number[]
): Pixels {
	const data = new Uint8ClampedArray( width * height * 4 );

	for ( let i = 0; i < data.length; i++ ) {
		data[ i ] = rgba[ i % 4 ] as number;
	}

	return { width, height, data };
}

/**
 * A transparent drawing with a square of thread in the middle.
 *
 * @param thread The thread's colour.
 */
function square( thread: readonly number[] ): Pixels {
	const print = plain( 80, 80, [ 0, 0, 0, 0 ] );

	for ( let y = 25; y < 55; y++ ) {
		for ( let x = 25; x < 55; x++ ) {
			print.data.set( thread, ( y * 80 + x ) * 4 );
		}
	}

	return print;
}

const TILE = plain( 32, 32, [ 26, 26, 26, 255 ] );

/**
 * A pixel of an image.
 *
 * @param image The image.
 * @param x     Column.
 * @param y     Row.
 */
function pixel( image: Pixels, x: number, y: number ): number[] {
	return Array.from(
		image.data.subarray(
			( y * image.width + x ) * 4,
			( y * image.width + x ) * 4 + 4
		)
	);
}

describe( 'distanceField', () => {
	it( 'measures the distance to the nearest pixel that is not set', () => {
		const set = new Uint8Array( 25 ).fill( 1 );

		set[ 12 ] = 0;

		const field = distanceField( set, 5, 5 );

		expect( field[ 12 ] ).toBe( 0 );
		expect( field[ 13 ] ).toBe( 1 );
		expect( field[ 14 ] ).toBe( 2 );
		expect( field[ 0 ] ).toBeCloseTo( Math.SQRT2 * 2, 5 );
	} );
} );

describe( 'stitched', () => {
	const black = square( [ 0, 0, 0, 255 ] );

	it( 'fills the drawing with thread and leaves the rest of the product alone', () => {
		const thread = stitched( black, TILE, 9.2, 9.2 );

		expect( pixel( thread, 40, 40 )[ 3 ] ).toBe( 255 );
		expect( pixel( thread, 2, 2 )[ 3 ] ).toBe( 0 );
	} );

	it( 'lets the thread lie a little outside the drawing, with a faint shadow beyond', () => {
		const thread = stitched( black, TILE, 9.2, 9.2 );
		const edge = [ 24, 23, 22, 21, 20, 19, 18 ].map(
			( x ) => pixel( thread, x, 40 )[ 3 ] as number
		);

		expect( edge[ 0 ] ).toBeGreaterThan( 200 );
		expect( Math.min( ...edge ) ).toBeLessThan( 40 );
		expect( Math.max( ...edge.slice( 3 ) ) ).toBeLessThan( 128 );
	} );

	it( 'draws the same image every time, so a design does not change', () => {
		expect( stitched( black, TILE, 9.2, 9.2 ).data ).toEqual(
			stitched( black, TILE, 9.2, 9.2 ).data
		);
	} );

	it( 'keeps the colour of the thread', () => {
		const thread = stitched(
			square( [ 200, 20, 30, 255 ] ),
			TILE,
			9.2,
			9.2
		);
		const [ red, green, blue ] = pixel( thread, 40, 40 ) as [
			number,
			number,
			number,
		];

		expect( red ).toBeGreaterThan( green + 80 );
		expect( red ).toBeGreaterThan( blue + 80 );
	} );

	it( 'shows the light of the tile, also on a smaller scale', () => {
		const bright = plain( 32, 32, [ 60, 60, 60, 255 ] );
		const shade = ( tile: Pixels ) =>
			pixel( stitched( black, tile, 9.2, 3.7 ), 40, 40 )[ 0 ] as number;

		expect( shade( bright ) ).toBeGreaterThan( shade( TILE ) );
	} );

	it( 'makes room for the thread around the print', () => {
		expect( stitchMargin( 3.7 ) ).toBeLessThan( stitchMargin( 12 ) );
		expect( stitchMargin( 12 ) ).toBeGreaterThan( 8 );
	} );
} );

describe( 'submissionSize', () => {
	const design = {
		version: 1,
		sides: { front: [ { id: 'a' } ] },
	} as unknown as DesignDocument;
	const side = ( stitches: boolean, scene: unknown ) =>
		( {
			key: 'front',
			scene,
			embroidery: { max: 2, threads: [], stitched: stitches },
		} ) as unknown as DesignerView;

	it( 'counts a close-up per colour on a stitched side', () => {
		const views = [ side( true, null ) ];

		expect( hasStitches( side( true, null ) ) ).toBe( true );
		expect( submissionSize( design, [ 'black', 'white' ], views ) ).toBe(
			5
		);
	} );

	it( 'leaves it out where another image is the product', () => {
		const views = [ side( false, null ) ];

		expect( hasStitches( side( true, { width: 1 } ) ) ).toBe( false );
		expect( submissionSize( design, [ 'black', 'white' ], views ) ).toBe(
			3
		);
	} );
} );
