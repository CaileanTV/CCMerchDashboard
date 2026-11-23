/**
 * Tests for cliparts on embroidered sides (phase 56, step 56.12, ADR-121):
 * their threads are kept, never swapped or merged, and too many cannot be
 * sent.
 */
import { describe, expect, it, jest } from '@jest/globals';

import type {
	DesignerAsset,
	DesignerEmbroidery,
	DesignerThread,
} from '../api/designer';
import type { ClipartArt } from '../sections/designer/clipart-model';
import { ClipartPictures } from '../sections/designer/clipart-objects';
import type { ClipartPixels } from '../sections/designer/clipart-paint';
import type {
	DesignObject,
	ImageObject,
} from '../sections/designer/design-model';
import { clipartThreads, threadPlan } from '../sections/designer/threads';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const threads: DesignerThread[] = [
	{ code: '1801', name: 'White', hex: '#ffffff' },
	{ code: '1800', name: 'Black', hex: '#000000' },
	{ code: '1838', name: 'Red', hex: '#cb333b' },
	{ code: '1934', name: 'Royal', hex: '#015498' },
];

const pack: DesignerEmbroidery = { max: 2, threads, stitched: false };

/**
 * A rectangle of one colour.
 *
 * @param id    Object id.
 * @param fill  Colour.
 * @param width Width.
 */
const shape = ( id: string, fill: string, width = 100 ): DesignObject => ( {
	type: 'rect',
	id,
	x: 0,
	y: 0,
	width,
	height: 100,
	angle: 0,
	scaleX: 1,
	scaleY: 1,
	flipX: false,
	flipY: false,
	opacity: 1,
	locked: false,
	hidden: false,
	fill,
	stroke: null,
	strokeWidth: 0,
	radius: 0,
} );

/**
 * Pixels of these colours, one each.
 *
 * @param colors `#rrggbb`.
 */
function pixels( ...colors: string[] ): ClipartPixels {
	return {
		width: colors.length,
		height: 1,
		data: new Uint8ClampedArray(
			colors.flatMap( ( hex ) => [
				...[ 1, 3, 5 ].map( ( at ) =>
					Number.parseInt( hex.slice( at, at + 2 ), 16 )
				),
				255,
			] )
		),
	};
}

const clipart = ( id: string, hidden = false ): ImageObject => ( {
	type: 'image',
	id,
	asset: 7,
	x: 0,
	y: 0,
	width: 2,
	height: 1,
	angle: 0,
	scaleX: 1,
	scaleY: 1,
	flipX: false,
	flipY: false,
	opacity: 1,
	locked: false,
	hidden,
	paints: [ { type: 'solid', color: '#cb333b' } ],
} );

describe( 'the threads of a clipart', () => {
	it( 'are kept before the threads that fill the most', () => {
		// The clipart is small, the royal and white shapes are large.
		const plan = threadPlan(
			[
				shape( 'a', '#015498', 400 ),
				shape( 'b', '#ffffff', 300 ),
				clipart( 'c' ),
			],
			new Map(),
			{},
			pack,
			new Map( [ [ 'c', new Map( [ [ '#cb333b', 1 ] ] ) ] ] )
		);

		expect( plan.rows.map( ( row ) => row.found.name ) ).toEqual( [
			'Red',
			'Royal',
		] );
		expect( plan.rows.map( ( row ) => row.pinned ) ).toEqual( [
			true,
			false,
		] );
		expect( plan.merged ).toBe( 1 );
		expect( plan.over ).toBe( false );
	} );

	it( 'is never swapped, also when a shape shares it', () => {
		const plan = threadPlan(
			[ shape( 'a', '#cb333b' ), clipart( 'c' ) ],
			new Map(),
			{ 1838: '1934' },
			pack,
			new Map( [ [ 'c', new Map( [ [ '#cb333b', 1 ] ] ) ] ] )
		);

		expect( plan.rows[ 0 ]?.used.name ).toBe( 'Red' );
		expect( plan.colorOf( '#cb333b' ) ).toBe( '#cb333b' );
	} );

	it( 'are too many when the cliparts alone need more than the side may have', () => {
		const plan = threadPlan(
			[ shape( 'a', '#015498', 400 ), clipart( 'c' ) ],
			new Map(),
			{},
			pack,
			new Map( [
				[
					'c',
					new Map( [
						[ '#cb333b', 1 ],
						[ '#000000', 1 ],
						[ '#ffffff', 1 ],
					] ),
				],
			] )
		);

		expect( plan.over ).toBe( true );
		expect( plan.rows.every( ( row ) => row.pinned ) ).toBe( true );
		// The shape goes to the nearest kept thread.
		expect( plan.threads ).toHaveLength( 3 );
	} );

	it( 'counts the threads of the visible cliparts', () => {
		const picture = () => pixels( '#cb333b', '#020202' );

		expect(
			clipartThreads(
				[ clipart( 'c' ), shape( 'a', '#015498' ) ],
				picture,
				pack
			).map( ( thread ) => thread.name )
		).toEqual( [ 'Black', 'Red' ] );
		expect(
			clipartThreads( [ clipart( 'c', true ) ], picture, pack )
		).toEqual( [] );
	} );
} );

describe( 'a clipart drawn for embroidery', () => {
	const art: ClipartArt = {
		width: 2,
		height: 1,
		parts: [
			{
				label: '',
				box: { x: 0, y: 0, width: 2, height: 1 },
				stroke: '#101010',
				scale: 1,
				paint: { type: 'solid', color: '#ffffff' },
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
				rect: { x: 0, y: 0, width: 2, height: 1 },
				fill: { x: 0, y: 0 },
				grown: { x: 0, y: 1 },
			},
		],
	};
	// The fill covers the left pixel, the stroke the right one.
	const atlas: ClipartPixels = {
		width: 2,
		height: 2,
		data: new Uint8ClampedArray( [
			255, 255, 255, 255, 255, 255, 255, 0, 255, 255, 255, 255, 255, 255,
			255, 255,
		] ),
	};
	const asset: DesignerAsset = {
		id: 7,
		name: 'Drikkedunk',
		width: 2,
		height: 1,
		mime: 'image/png',
		clipart: {
			bases: null,
			locked: false,
			art: { art, atlas: { width: 2, height: 2 }, palette: null },
		},
	};

	it( 'is in the pack’s threads, the stroke in its nearest', async () => {
		const pictures = new ClipartPictures(
			() => Promise.resolve( atlas ),
			() => null
		);
		const object = {
			...clipart( 'c' ),
			paints: [ { type: 'solid' as const, color: '#c03040' } ],
		};

		await pictures.ready( [ object ], () => asset );

		const drawn = pictures.pixels( object, () => asset, true, threads );

		expect( Array.from( drawn?.data ?? [] ) ).toEqual( [
			203, 51, 59, 255, 0, 0, 0, 255,
		] );
		expect( pictures.resolver( () => asset, true, threads ).key ).not.toBe(
			pictures.resolver( () => asset, true ).key
		);
	} );
} );
