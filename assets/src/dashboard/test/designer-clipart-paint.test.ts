/**
 * Tests for drawing and placing cliparts with coloured parts (phase 56, step
 * 56.3).
 */
import { describe, expect, it, jest } from '@jest/globals';

import type {
	ClipartArt,
	ClipartPaint,
} from '../sections/designer/clipart-model';
import {
	drawClipart,
	eased,
	gradientLength,
	lockedPlacement,
} from '../sections/designer/clipart-paint';
import { fitted, newImage } from '../sections/designer/design-model';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const RED: ClipartPaint = { type: 'solid', color: '#ff0000' };

/**
 * A clipart of one row: one part with these coverages.
 *
 * @param fills  The fill's alpha per pixel.
 * @param grown  The grown fill's alpha per pixel, or null without a stroke.
 * @param stroke The stroke's colour.
 */
function row(
	fills: number[],
	grown: number[] | null,
	stroke = '#0000ff'
): {
	art: ClipartArt;
	atlas: { width: number; height: number; data: Uint8ClampedArray };
} {
	const width = fills.length;
	const alphas = [ ...fills, ...( grown ?? [] ) ];
	const rect = { x: 0, y: 0, width, height: 1 };

	return {
		art: {
			width,
			height: 1,
			parts: [
				{
					label: '',
					box: rect,
					stroke: grown === null ? null : stroke,
					scale: 1,
					paint: RED,
					gradient: {
						type: 'gradient',
						from: '#ffffff',
						to: '#000000',
						angle: 0,
					},
				},
			],
			steps: [
				{
					type: 'part',
					part: 0,
					rect,
					fill: { x: 0, y: 0 },
					grown: grown === null ? null : { x: 0, y: 1 },
				},
			],
		},
		atlas: {
			width,
			height: grown === null ? 1 : 2,
			data: new Uint8ClampedArray(
				alphas.flatMap( ( alpha ) => [ 255, 255, 255, alpha ] )
			),
		},
	};
}

/**
 * A drawn pixel.
 *
 * @param picture The picture.
 * @param x       Column.
 */
function pixel(
	picture: ReturnType< typeof drawClipart >,
	x: number
): number[] {
	return Array.from( picture?.data.subarray( x * 4, x * 4 + 4 ) ?? [] );
}

describe( 'drawClipart', () => {
	it( 'fills the part and shows the stroke only outside it', () => {
		const { art, atlas } = row( [ 255, 128, 0, 0 ], [ 255, 255, 128, 0 ] );
		const picture = drawClipart( art, atlas, [ RED ] );

		expect( pixel( picture, 0 ) ).toEqual( [ 255, 0, 0, 255 ] );
		expect( pixel( picture, 1 ) ).toEqual( [ 128, 0, 127, 255 ] );
		expect( pixel( picture, 2 ) ).toEqual( [ 0, 0, 255, 128 ] );
		expect( pixel( picture, 3 ) ).toEqual( [ 0, 0, 0, 0 ] );
	} );

	it.each( [
		RED,
		{ type: 'solid', color: '#00ff00' },
		{ type: 'gradient', from: '#ffffff', to: '#123456', angle: 90 },
	] as ClipartPaint[] )( 'never changes the stroke with %o', ( paint ) => {
		const { art, atlas } = row( [ 255, 0 ], [ 255, 255 ], '#0a0b0c' );

		expect( pixel( drawClipart( art, atlas, [ paint ] ), 1 ) ).toEqual( [
			10, 11, 12, 255,
		] );
	} );

	it( 'draws a part without a stroke as its fill', () => {
		const { art, atlas } = row( [ 255, 64, 0 ], null );
		const picture = drawClipart( art, atlas, [ RED ] );

		expect( pixel( picture, 1 ) ).toEqual( [ 255, 0, 0, 64 ] );
		expect( pixel( picture, 2 ) ).toEqual( [ 0, 0, 0, 0 ] );
	} );

	it( 'draws the steps in their order', () => {
		const { art, atlas } = row( [ 255, 255 ], null );
		const data = new Uint8ClampedArray( [
			...atlas.data,
			9,
			9,
			9,
			255,
			0,
			0,
			0,
			0,
		] );
		const picture = drawClipart(
			{
				...art,
				steps: [
					...art.steps,
					{
						type: 'image',
						rect: { x: 0, y: 0, width: 2, height: 1 },
						image: { x: 0, y: 1 },
					},
				],
			},
			{ width: 2, height: 2, data },
			[ RED ]
		);

		expect( pixel( picture, 0 ) ).toEqual( [ 9, 9, 9, 255 ] );
		expect( pixel( picture, 1 ) ).toEqual( [ 255, 0, 0, 255 ] );
	} );

	it( 'has only the given colours on embroidery', () => {
		const { art, atlas } = row(
			[ 255, 130, 120, 0, 0 ],
			[ 255, 255, 200, 127, 128 ]
		);
		const picture = drawClipart( art, atlas, [ RED ], true );

		expect( [ 0, 1, 2, 3, 4 ].map( ( x ) => pixel( picture, x ) ) ).toEqual(
			[
				[ 255, 0, 0, 255 ],
				[ 255, 0, 0, 255 ],
				[ 0, 0, 255, 255 ],
				[ 0, 0, 0, 0 ],
				[ 0, 0, 255, 255 ],
			]
		);
	} );

	it.each( [ 0.37, 0.5, 0.9 ] )(
		'draws a smaller picture as the full one sampled at %d (step 57.20)',
		( scale ) => {
			// 10 × 6: a gradient part with a stroke, and an image step on it.
			const width = 10;
			const height = 6;
			const alpha = ( x: number, y: number, seed: number ): number =>
				( ( x * 37 + y * 91 + seed * 53 ) % 7 ) * 40;
			const data = new Uint8ClampedArray( width * height * 3 * 4 );

			for ( let y = 0; y < height * 3; y++ ) {
				for ( let x = 0; x < width; x++ ) {
					const at = ( y * width + x ) * 4;

					data.set(
						[
							( x * 25 ) % 256,
							( y * 13 ) % 256,
							90,
							alpha( x, y, 1 ),
						],
						at
					);
				}
			}

			const box = { x: 0, y: 0, width, height };
			const art: ClipartArt = {
				width,
				height,
				parts: [
					{
						label: '',
						box,
						stroke: '#0a0b0c',
						scale: 1,
						paint: RED,
						gradient: {
							type: 'gradient',
							from: '#ffffff',
							to: '#000000',
							angle: 30,
						},
					},
				],
				steps: [
					{
						type: 'part',
						part: 0,
						rect: box,
						fill: { x: 0, y: 0 },
						grown: { x: 0, y: height },
					},
					{
						type: 'image',
						rect: { x: 3, y: 2, width: 4, height: 3 },
						image: { x: 1, y: height * 2 },
					},
				],
			};
			const paints: ClipartPaint[] = [
				{ type: 'gradient', from: '#ff0000', to: '#00ffff', angle: 30 },
			];
			const atlas = { width, height: height * 3, data };
			const full = drawClipart( art, atlas, paints );
			const small = drawClipart( art, atlas, paints, false, scale );

			expect( small?.width ).toBe( Math.round( width * scale ) );
			expect( small?.height ).toBe( Math.round( height * scale ) );

			const shownWidth = small?.width ?? 0;
			const shownHeight = small?.height ?? 0;

			for ( let oy = 0; oy < shownHeight; oy++ ) {
				for ( let ox = 0; ox < shownWidth; ox++ ) {
					const x = Math.floor(
						( ( ox + 0.5 ) * width ) / shownWidth
					);
					const y = Math.floor(
						( ( oy + 0.5 ) * height ) / shownHeight
					);
					const at = ( oy * shownWidth + ox ) * 4;
					const from = ( y * width + x ) * 4;

					expect(
						Array.from( small?.data.subarray( at, at + 4 ) ?? [] )
					).toEqual(
						Array.from(
							full?.data.subarray( from, from + 4 ) ?? []
						)
					);
				}
			}

			// 1 or more is the full size.
			expect( drawClipart( art, atlas, paints, false, 1 ) ).toEqual(
				full
			);
			expect( drawClipart( art, atlas, paints, false, 2 ) ).toEqual(
				full
			);
		}
	);

	it( 'draws nothing at all rather than leave something out', () => {
		const { art, atlas } = row( [ 255, 255 ], [ 255, 255 ] );

		expect( drawClipart( art, atlas, [] ) ).toBeNull();
		expect( drawClipart( art, atlas, [ RED, RED ] ) ).toBeNull();
		expect(
			drawClipart( art, { ...atlas, height: 1 }, [ RED ] )
		).toBeNull();
	} );
} );

describe( 'the gradient', () => {
	it( 'is as long as the line through the box times the scale', () => {
		const box = { x: 37, y: 65, width: 1244, height: 1900 };

		expect( gradientLength( box, 45, 0.75 ) ).toBeCloseTo( 1319.46, 1 );
		expect( gradientLength( box, 0, 1 ) ).toBeCloseTo( 1244 );
		expect( gradientLength( box, 90, 1 ) ).toBeCloseTo( 1900 );
		expect( gradientLength( box, 180, 1 ) ).toBeCloseTo( 1244 );
	} );

	it( 'eases evenly around the middle', () => {
		expect( eased( 0 ) ).toBe( 0 );
		expect( eased( 0.5 ) ).toBe( 0.5 );
		expect( eased( 1 ) ).toBe( 1 );
		expect( eased( 0.25 ) + eased( 0.75 ) ).toBeCloseTo( 1 );
		expect( eased( 0.25 ) ).toBeCloseTo( 0.198, 3 );
	} );

	it( 'runs from the first colour to the last along the angle', () => {
		const { art, atlas } = row(
			new Array< number >( 101 ).fill( 255 ),
			null
		);
		const paint = ( angle: number ): ClipartPaint => ( {
			type: 'gradient',
			from: '#ffffff',
			to: '#000000',
			angle,
		} );
		const right = drawClipart( art, atlas, [ paint( 0 ) ] );
		const left = drawClipart( art, atlas, [ paint( 180 ) ] );

		expect( pixel( right, 0 )[ 0 ] ).toBeGreaterThanOrEqual( 253 );
		expect( pixel( right, 100 )[ 0 ] ).toBeLessThanOrEqual( 2 );
		// The middle of CIELAB, as Photoshop's export.
		expect( pixel( right, 50 ) ).toEqual( [ 119, 119, 119, 255 ] );
		expect( pixel( left, 100 ) ).toEqual( pixel( right, 0 ) );
	} );

	it( 'runs upwards at 90 degrees', () => {
		const { art } = row( [ 255 ], null );
		const rect = { x: 0, y: 0, width: 1, height: 11 };
		const picture = drawClipart(
			{
				...art,
				height: 11,
				parts: art.parts.map( ( part ) => ( { ...part, box: rect } ) ),
				steps: art.steps.map( ( step ) => ( { ...step, rect } ) ),
			},
			{
				width: 1,
				height: 11,
				data: new Uint8ClampedArray( 44 ).fill( 255 ),
			},
			[ { type: 'gradient', from: '#ffffff', to: '#000000', angle: 90 } ]
		);

		expect( pixel( picture, 10 )[ 0 ] ).toBeGreaterThan( 230 );
		expect( pixel( picture, 0 )[ 0 ] ).toBeLessThan( 25 );
	} );

	it( 'keeps a colour as it is at both ends', () => {
		const { art, atlas } = row( [ 255, 255, 255 ], null );
		const picture = drawClipart( art, atlas, [
			{ type: 'gradient', from: '#65b6f5', to: '#65b6f5', angle: 45 },
		] );

		expect( pixel( picture, 1 ) ).toEqual( [ 101, 182, 245, 255 ] );
	} );
} );

describe( 'lockedPlacement', () => {
	it( 'starts in the middle and fills from top to bottom', () => {
		const placed = lockedPlacement( 1317, 2066, 2540, 2066 );

		expect( placed ).toEqual( {
			x: 1270,
			y: 1033,
			scaleX: 1,
			scaleY: 1,
			angle: 0,
			flipX: false,
			flipY: false,
		} );
	} );

	it( 'moves sideways only as far as it stays whole', () => {
		const move = ( x: number ) =>
			lockedPlacement( 1000, 2000, 3000, 2000, x ).x;

		expect( move( 700 ) ).toBe( 700 );
		expect( move( -50 ) ).toBe( 500 );
		expect( move( 9999 ) ).toBe( 2500 );
		expect( move( Number.NaN ) ).toBe( 1500 );
	} );

	it( 'takes the width when it would be too wide, and then cannot move', () => {
		const placed = lockedPlacement( 2000, 1000, 1000, 3000, 40 );

		expect( placed.scaleX ).toBe( 0.5 );
		expect( placed.x ).toBe( 500 );
		expect( placed.y ).toBe( 1500 );
	} );

	it( 'has the scale of "fit"', () => {
		const image = newImage( 7, 1317, 2066, 2400, 3600 );

		expect( lockedPlacement( 1317, 2066, 2400, 3600 ).scaleX ).toBe(
			fitted( image, 2400, 3600, 'fit' ).scaleX
		);
	} );
} );
