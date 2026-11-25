/**
 * What a side's print file is drawn from (phase 56, step 56.4). The
 * digitization fee follows the file's bytes (ADR-086), so a design without
 * coloured parts must be drawn from exactly these objects and options: a
 * change here would charge creators again.
 */
import { beforeEach, describe, expect, it, jest } from '@jest/globals';

import type { DesignObject } from '../sections/designer/design-model';
import { renderPrintFile } from '../sections/designer/fabric-stage';
import { threadObject, threadPlan } from '../sections/designer/threads';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

/** What Fabric was given, in order. */
const mockCalls: unknown[][] = [];

jest.mock( 'fabric', () => {
	class MockShape {
		public constructor( ...given: unknown[] ) {
			mockCalls.push( [ this.constructor.name, ...given ] );
		}
	}

	class Rect extends MockShape {}
	class Ellipse extends MockShape {}
	class Triangle extends MockShape {}
	class Textbox extends MockShape {}
	class Canvas extends MockShape {}

	class FabricImage extends MockShape {
		public static fromURL(
			...given: unknown[]
		): Promise< { readonly image: true } > {
			mockCalls.push( [ 'fromURL', ...given ] );

			return Promise.resolve( { image: true as const } );
		}
	}

	class StaticCanvas extends MockShape {
		public add( shown: object ): void {
			mockCalls.push( [ 'add', shown.constructor.name ] );
		}

		public renderAll(): void {
			mockCalls.push( [ 'renderAll' ] );
		}

		public toCanvasElement( multiplier: number ): string {
			mockCalls.push( [ 'toCanvasElement', multiplier ] );

			return 'print';
		}

		public dispose(): Promise< boolean > {
			return Promise.resolve( true );
		}
	}

	return {
		cache: { clearFontCache: () => undefined },
		Canvas,
		Ellipse,
		FabricImage,
		Rect,
		StaticCanvas,
		Textbox,
		Triangle,
	};
} );

const base = {
	x: 100,
	y: 200,
	width: 300,
	height: 400,
	angle: 15,
	scaleX: 0.5,
	scaleY: 0.25,
	flipX: true,
	flipY: false,
	opacity: 0.8,
	locked: false,
	hidden: false,
};

const common = {
	left: 100,
	top: 200,
	originX: 'center',
	originY: 'center',
	angle: 15,
	scaleX: 0.5,
	scaleY: 0.25,
	flipX: true,
	flipY: false,
	opacity: 0.8,
	visible: true,
	selectable: true,
	evented: true,
	lockScalingFlip: true,
	objectCaching: false,
	// Turning falls into place every 45° (step 60.1).
	snapAngle: 45,
	snapThreshold: 5,
};

const objects: DesignObject[] = [
	{ ...base, id: 'image', type: 'image', asset: 7 },
	{
		...base,
		id: 'text',
		type: 'text',
		text: 'Hej',
		font: 'Inter',
		weight: 700,
		italic: true,
		size: 48,
		fill: '#112233',
		align: 'center',
		lineHeight: 1.1,
		charSpacing: 20,
		stroke: null,
		strokeWidth: 3,
	},
	{
		...base,
		id: 'rect',
		type: 'rect',
		fill: null,
		stroke: '#ff0000',
		strokeWidth: 2,
		radius: 9,
	},
	{
		...base,
		id: 'ellipse',
		type: 'ellipse',
		fill: '#00ff00',
		stroke: null,
		strokeWidth: 2,
		radius: 0,
	},
	{
		...base,
		id: 'triangle',
		type: 'triangle',
		locked: true,
		fill: '#0000ff',
		stroke: null,
		strokeWidth: 0,
		radius: 0,
	},
	{ ...base, id: 'hidden', type: 'image', asset: 8, hidden: true },
];

const images = new Map( [
	[ 7, 'data:image/png;base64,AA' ],
	[ 8, 'data:image/png;base64,BB' ],
] );

beforeEach( () => {
	mockCalls.length = 0;
} );

describe( 'the print file of a design without coloured parts', () => {
	it( 'is drawn from the visible objects with exactly these options', async () => {
		expect(
			await renderPrintFile( objects, images, 2400, 3600, '#abcdef' )
		).toBe( 'print' );

		expect( mockCalls ).toEqual( [
			[
				'StaticCanvas',
				undefined,
				{
					width: 2400,
					height: 3600,
					enableRetinaScaling: false,
					renderOnAddRemove: false,
				},
			],
			[ 'fromURL', 'data:image/png;base64,AA', {}, common ],
			[
				'Textbox',
				'Hej',
				{
					...common,
					width: 300,
					fontFamily: 'Inter',
					fontWeight: 700,
					fontStyle: 'italic',
					fontSize: 48,
					fill: '#112233',
					textAlign: 'center',
					lineHeight: 1.1,
					charSpacing: 20,
					stroke: null,
					strokeWidth: 0,
					// A text's outline behind its letters (step 60.4).
					paintFirst: 'stroke',
					splitByGrapheme: false,
				},
			],
			[
				'Rect',
				{
					...common,
					fill: 'transparent',
					stroke: '#ff0000',
					strokeWidth: 2,
					strokeUniform: true,
					width: 300,
					height: 400,
					rx: 9,
					ry: 9,
				},
			],
			[
				'Ellipse',
				{
					...common,
					fill: '#00ff00',
					stroke: null,
					strokeWidth: 0,
					strokeUniform: true,
					rx: 150,
					ry: 200,
				},
			],
			[
				'Triangle',
				{
					...common,
					selectable: false,
					evented: false,
					fill: '#0000ff',
					stroke: null,
					strokeWidth: 0,
					strokeUniform: true,
					width: 300,
					height: 400,
				},
			],
			[
				'Rect',
				expect.objectContaining( {
					width: 2400,
					height: 3600,
					fill: '#abcdef',
				} ),
			],
			[ 'add', 'Rect' ],
			[ 'add', 'Object' ],
			[ 'add', 'Textbox' ],
			[ 'add', 'Rect' ],
			[ 'add', 'Ellipse' ],
			[ 'add', 'Triangle' ],
			[ 'renderAll' ],
			[ 'toCanvasElement', 1 ],
		] );
	} );

	it( 'gives an embroidered side the same image, solid', async () => {
		const pack = {
			max: 2,
			threads: [ { code: '1800', name: 'Black', hex: '#000000' } ],
			stitched: false,
		};
		const image = objects[ 0 ] as DesignObject;
		const plan = threadPlan(
			[ image ],
			new Map( [ [ 7, new Map( [ [ '#101010', 1 ] ] ) ] ] ),
			{},
			pack
		);

		await renderPrintFile(
			[ threadObject( image, plan ) ],
			images,
			10,
			20
		);

		expect( mockCalls ).toContainEqual( [
			'fromURL',
			'data:image/png;base64,AA',
			{},
			{ ...common, opacity: 1 },
		] );
		expect( mockCalls ).not.toContainEqual( [ 'add', 'Rect' ] );
	} );
} );

describe( 'an image that is more than its file', () => {
	it( 'is the picture the resolver draws', async () => {
		const picture = { picture: true } as unknown as HTMLCanvasElement;

		await renderPrintFile( objects, images, 10, 20, null, {
			key: 'one',
			image: () => picture,
		} );

		expect( mockCalls ).toContainEqual( [
			'FabricImage',
			picture,
			common,
		] );
		expect( mockCalls.some( ( [ name ] ) => name === 'fromURL' ) ).toBe(
			false
		);
	} );

	it( 'is its file when the resolver leaves it alone', async () => {
		await renderPrintFile( objects, images, 2400, 3600, '#abcdef' );

		const without = [ ...mockCalls ];

		mockCalls.length = 0;
		await renderPrintFile( objects, images, 2400, 3600, '#abcdef', {
			key: 'one',
			image: () => undefined,
		} );

		expect( mockCalls ).toEqual( without );
	} );

	it( 'fails the print file instead of being left out', async () => {
		expect(
			await renderPrintFile( objects, images, 10, 20, null, {
				key: 'one',
				image: () => null,
			} )
		).toBeNull();
		expect( mockCalls ).toEqual( [] );
	} );

	it( 'does not fail it while hidden', async () => {
		expect(
			await renderPrintFile( objects, images, 10, 20, null, {
				key: 'one',
				image: ( object ) => ( object.asset === 8 ? null : undefined ),
			} )
		).toBe( 'print' );
	} );
} );
