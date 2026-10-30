import { describe, expect, it } from '@jest/globals';

import type { DesignerEmbroidery, DesignerThread } from '../api/designer';
import {
	parseDesign,
	withKnownThreads,
	withObjects,
	withThread,
} from '../sections/designer/design-model';
import type {
	DesignDocument,
	DesignObject,
} from '../sections/designer/design-model';
import {
	histogramOf,
	nearestThread,
	paintPixels,
	threadObject,
	threadPlan,
} from '../sections/designer/threads';

const threads: DesignerThread[] = [
	{ code: '1801', name: 'White', hex: '#ffffff' },
	{ code: '1800', name: 'Black', hex: '#000000' },
	{ code: '1838', name: 'Red', hex: '#cb333b' },
	{ code: '1934', name: 'Royal', hex: '#015498' },
	{ code: '1624', name: 'Yellow', hex: '#ffcd01' },
];

const pack: DesignerEmbroidery = { max: 2, threads };

const shape = (
	id: string,
	fill: string,
	width = 100,
	stroke: string | null = null
): DesignObject => ( {
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
	opacity: 0.5,
	locked: false,
	hidden: false,
	fill,
	stroke,
	strokeWidth: stroke === null ? 0 : 4,
	radius: 0,
} );

describe( 'the nearest thread', () => {
	it( 'is the thread itself for a thread colour', () => {
		for ( const thread of threads ) {
			expect( nearestThread( thread.hex, threads ).code ).toBe(
				thread.code
			);
		}
	} );

	it( 'is found by how the colours look', () => {
		expect( nearestThread( '#e01020', threads ).name ).toBe( 'Red' );
		expect( nearestThread( '#0a0a12', threads ).name ).toBe( 'Black' );
		expect( nearestThread( '#2060a0', threads ).name ).toBe( 'Royal' );
		expect( nearestThread( '#f8f4e8', threads ).name ).toBe( 'White' );
	} );
} );

describe( 'the threads of a side', () => {
	it( 'keeps the threads that fill the most, up to the limit of the pack', () => {
		const plan = threadPlan(
			[
				shape( 'a', '#cb333b', 300 ),
				shape( 'b', '#015498', 200 ),
				shape( 'c', '#ffcd01', 10 ),
			],
			new Map(),
			{},
			pack
		);

		expect( plan.rows.map( ( row ) => row.found.name ) ).toEqual( [
			'Red',
			'Royal',
		] );
		expect( plan.merged ).toBe( 1 );
		// The yellow moved to the nearest kept thread.
		expect( plan.colorOf( '#ffcd01' ) ).toBe( '#cb333b' );
	} );

	it( 'counts the colours of images by the area they fill', () => {
		const plan = threadPlan(
			[
				shape( 'a', '#015498', 10 ),
				{
					...shape( 'b', '#000000', 100 ),
					type: 'image',
					asset: 7,
				} as DesignObject,
			],
			new Map( [
				[
					7,
					new Map( [
						[ '#cb333b', 0.75 ],
						[ '#ffcd01', 0.25 ],
					] ),
				],
			] ),
			{},
			pack
		);

		expect( plan.rows.map( ( row ) => row.found.name ) ).toEqual( [
			'Red',
			'Yellow',
		] );
	} );

	it( 'leaves out what is hidden', () => {
		const plan = threadPlan(
			[ { ...shape( 'a', '#cb333b' ), hidden: true } ],
			new Map(),
			{},
			pack
		);

		expect( plan.rows ).toEqual( [] );
	} );

	it( "uses the creator's thread instead of the one found", () => {
		const plan = threadPlan(
			[ shape( 'a', '#cb333b' ) ],
			new Map(),
			{ 1838: '1624' },
			pack
		);

		expect( plan.rows[ 0 ]?.found.name ).toBe( 'Red' );
		expect( plan.rows[ 0 ]?.used.name ).toBe( 'Yellow' );
		expect( plan.colorOf( '#d03040' ) ).toBe( '#ffcd01' );
	} );

	it( 'counts two threads swapped to the same as one', () => {
		const plan = threadPlan(
			[ shape( 'a', '#cb333b' ), shape( 'b', '#015498' ) ],
			new Map(),
			{ 1838: '1934' },
			pack
		);

		expect( plan.threads.map( ( thread ) => thread.name ) ).toEqual( [
			'Royal',
		] );
	} );

	it( 'ignores a swap to a thread the pack no longer has', () => {
		const plan = threadPlan(
			[ shape( 'a', '#cb333b' ) ],
			new Map(),
			{ 1838: '9999' },
			pack
		);

		expect( plan.rows[ 0 ]?.used.name ).toBe( 'Red' );
	} );

	it( "shows objects solid in their thread and keeps the design's colours", () => {
		const object = shape( 'a', '#e01020', 100, '#0a0a12' );
		const plan = threadPlan( [ object ], new Map(), {}, pack );

		expect( threadObject( object, plan ) ).toMatchObject( {
			fill: '#cb333b',
			stroke: '#000000',
			opacity: 1,
		} );
		expect( object ).toMatchObject( { fill: '#e01020', opacity: 0.5 } );
	} );
} );

describe( 'pixels', () => {
	it( 'counts the visible colours of an image', () => {
		const histogram = histogramOf(
			new Uint8ClampedArray( [
				255, 0, 0, 255, 255, 3, 2, 255, 0, 0, 255, 255, 9, 9, 9, 10,
			] )
		);

		expect( Array.from( histogram.keys() ) ).toEqual( [
			'#f80000',
			'#0000f8',
		] );
		expect( histogram.get( '#f80000' ) ).toBeCloseTo( 2 / 3 );
	} );

	it( 'leaves only solid threads and nothing half transparent', () => {
		const data = new Uint8ClampedArray( [
			10, 20, 30, 200, 250, 240, 230, 255, 1, 2, 3, 100,
		] );

		paintPixels( data, ( color ) => nearestThread( color, threads ).hex );

		expect( Array.from( data ) ).toEqual( [
			0, 0, 0, 255, 255, 255, 255, 255, 0, 0, 0, 0,
		] );
	} );
} );

describe( 'the choice of threads in the design', () => {
	const base: DesignDocument = { version: 1, sides: {} };

	it( 'is kept when objects change and goes back when unset', () => {
		const swapped = withThread( base, 'front', '1838', '1624' );

		expect( swapped.threads ).toEqual( { front: { 1838: '1624' } } );
		expect(
			withObjects( swapped, 'front', [ shape( 'a', '#000000' ) ] ).threads
		).toEqual( swapped.threads );
		expect(
			withThread( swapped, 'front', '1838', null ).threads
		).toBeUndefined();
	} );

	it( 'comes back from the server', () => {
		expect(
			parseDesign( {
				version: 1,
				sides: {},
				threads: { front: { 1838: '1624' } },
			} )?.threads
		).toEqual( { front: { 1838: '1624' } } );
		expect(
			parseDesign( { version: 1, sides: {} } )?.threads
		).toBeUndefined();
	} );

	it( 'is only saved for embroidered sides and threads the pack has', () => {
		const design: DesignDocument = {
			version: 1,
			sides: {},
			threads: {
				front: { 1838: '1624', 1934: '9999' },
				back: { 1838: '1624' },
			},
		};

		expect(
			withKnownThreads( design, [
				{ key: 'front', embroidery: pack },
				{ key: 'back', embroidery: null },
			] ).threads
		).toEqual( { front: { 1838: '1624' } } );
	} );
} );
