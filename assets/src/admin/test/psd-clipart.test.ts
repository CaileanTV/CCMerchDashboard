/**
 * Tests for the baking of cliparts with coloured parts (phase 56, step 56.2).
 * Every PSD here is synthetic.
 */
import { describe, expect, it } from '@jest/globals';
import { initializeCanvas, writePsd } from 'ag-psd';
import type { Layer, LayerEffectsInfo, Psd } from 'ag-psd';

import type {
	AtlasPlace,
	PartStep,
} from '../../dashboard/sections/designer/clipart-model';
import { bakeClipart, grownCoverage } from '../clipart-bake';
import {
	ClipartError,
	clipartFromPsd,
	colorProfile,
	hexOf,
	partLabel,
	readClipart,
} from '../psd-clipart';
import type { ClipartErrorCode } from '../psd-clipart';

// jsdom has no canvas: ag-psd gets plain image data.
initializeCanvas(
	() => {
		throw new Error( 'No canvas in tests.' );
	},
	( width: number, height: number ) =>
		( {
			width,
			height,
			data: new Uint8ClampedArray( width * height * 4 ),
			colorSpace: 'srgb',
		} ) as ImageData
);

type Box = [ number, number, number, number ];

const BLACK = { r: 0, g: 0, b: 0 };
const WHITE = { r: 255, g: 255, b: 255 };

/**
 * A layer of one colour.
 *
 * @param name  Name.
 * @param box   Left, top, width, height.
 * @param rgba  Colour.
 * @param extra Other properties.
 */
function layer(
	name: string,
	box: Box,
	rgba: [ number, number, number, number ] = [ 10, 20, 30, 255 ],
	extra: Partial< Layer > = {}
): Layer {
	const [ left, top, width, height ] = box;
	const data = new Uint8ClampedArray( width * height * 4 );

	for ( let i = 0; i < width * height; i++ ) {
		data.set( rgba, i * 4 );
	}

	return {
		name,
		left,
		top,
		right: left + width,
		bottom: top + height,
		opacity: 1,
		blendMode: 'normal',
		imageData: { width, height, data },
		...extra,
	};
}

/**
 * A white overlay, a gradient that is off and a 2 px black outer stroke.
 */
function effects(): LayerEffectsInfo {
	return {
		solidFill: [
			{ enabled: true, blendMode: 'normal', color: WHITE, opacity: 1 },
		],
		gradientOverlay: [
			{
				enabled: false,
				blendMode: 'normal',
				opacity: 1,
				angle: 45,
				scale: 0.75,
				type: 'linear',
				gradient: {
					type: 'solid',
					name: 'Own',
					smoothness: 1,
					colorStops: [
						{
							color: { r: 101, g: 182, b: 245 },
							location: 0,
							midpoint: 0.5,
						},
						{
							color: { r: 75, g: 57, b: 117 },
							location: 0.3,
							midpoint: 0.5,
						},
						{
							color: { r: 241, g: 169, b: 12 },
							location: 1,
							midpoint: 0.5,
						},
					],
					opacityStops: [
						{ opacity: 1, location: 0, midpoint: 0.5 },
						{ opacity: 1, location: 1, midpoint: 0.5 },
					],
				},
			},
		],
		stroke: [
			{
				enabled: true,
				position: 'outside',
				fillType: 'color',
				blendMode: 'normal',
				opacity: 1,
				size: { value: 2, units: 'Pixels' },
				color: BLACK,
			},
		],
	};
}

/**
 * A part.
 *
 * @param extra Other properties.
 * @param box   Left, top, width, height.
 */
function part(
	extra: Partial< Layer > = {},
	box: Box = [ 5, 5, 4, 4 ]
): Layer {
	return layer( 'Farvet lag', box, [ 109, 188, 212, 255 ], {
		effects: effects(),
		...extra,
	} );
}

/**
 * A document of 20 × 20 px.
 *
 * @param children Layers.
 * @param extra    Other properties.
 */
function psd( children: Layer[], extra: Partial< Psd > = {} ): Psd {
	return { width: 20, height: 20, children, ...extra };
}

/**
 * Why a document is refused.
 *
 * @param document The PSD.
 */
function refusal(
	document: Psd
): { code: ClipartErrorCode; detail: string } | null {
	try {
		bakeClipart( clipartFromPsd( document ) );
	} catch ( error ) {
		if ( error instanceof ClipartError ) {
			return { code: error.code, detail: error.detail };
		}

		throw error;
	}

	return null;
}

/**
 * An atlas image's RGBA at a pixel of its rectangle.
 *
 * @param baked The bake.
 * @param place The image's place.
 * @param x     Column.
 * @param y     Row.
 */
function at(
	baked: ReturnType< typeof bakeClipart >,
	place: AtlasPlace,
	x: number,
	y: number
): number[] {
	const { width, data } = baked.atlas;
	const start = ( ( place.y + y ) * width + place.x + x ) * 4;

	return Array.from( data.subarray( start, start + 4 ) );
}

describe( 'partLabel', () => {
	it( 'knows a part in any case and takes the rest as its name', () => {
		expect( partLabel( 'Farvet lag' ) ).toBe( '' );
		expect( partLabel( ' FARVET LAG låg ' ) ).toBe( 'låg' );
		expect( partLabel( 'farvet lag - Dråber' ) ).toBe( 'Dråber' );
		expect( partLabel( 'Lag 3' ) ).toBeNull();
		expect( partLabel( 'Mit farvet lag' ) ).toBeNull();
	} );
} );

describe( 'hexOf', () => {
	it( 'reads the colour models of effects and refuses the others', () => {
		expect( hexOf( { r: 101.002, g: 181.997, b: 245 }, '' ) ).toBe(
			'#65b6f5'
		);
		expect( hexOf( { fr: 1, fg: 0, fb: 0.2 }, '' ) ).toBe( '#ff0033' );
		expect( hexOf( { h: 1 / 3, s: 100, b: 100 }, '' ) ).toBe( '#00ff00' );
		expect( () => hexOf( { c: 1, m: 2, y: 3, k: 4 }, '' ) ).toThrow(
			ClipartError
		);
	} );
} );

describe( 'clipartFromPsd', () => {
	it( 'takes a part’s stroke, colour and the gradient it starts as', () => {
		const [ taken ] = clipartFromPsd( psd( [ part() ] ) ).layers;

		expect( taken ).toMatchObject( {
			type: 'part',
			label: '',
			box: { x: 5, y: 5, width: 4, height: 4 },
			stroke: { color: '#000000', size: 2 },
			scale: 0.75,
			paint: { type: 'solid', color: '#ffffff' },
			gradient: {
				type: 'gradient',
				from: '#65b6f5',
				to: '#f1a90c',
				angle: 45,
			},
		} );
	} );

	it( 'starts as the gradient when only that is on', () => {
		const set = effects();
		const [ overlay ] = set.solidFill ?? [];
		const [ gradient ] = set.gradientOverlay ?? [];

		Object.assign( overlay ?? {}, { enabled: false } );
		Object.assign( gradient ?? {}, { enabled: true, reverse: true } );

		const [ taken ] = clipartFromPsd(
			psd( [ part( { effects: set } ) ] )
		).layers;

		expect( taken ).toMatchObject( {
			paint: { type: 'gradient', from: '#f1a90c', to: '#65b6f5' },
		} );
	} );

	it( 'keeps the layer’s own colour without an overlay', () => {
		const [ taken ] = clipartFromPsd(
			psd( [ part( { effects: {} } ) ] )
		).layers;

		expect( taken ).toMatchObject( {
			stroke: null,
			paint: { type: 'solid', color: '#6dbcd4' },
		} );
	} );

	it( 'leaves out hidden and empty layers and opens plain groups', () => {
		const source = clipartFromPsd(
			psd( [
				layer( 'Hidden', [ 0, 0, 2, 2 ], [ 1, 1, 1, 255 ], {
					hidden: true,
					blendMode: 'multiply',
				} ),
				layer( 'Empty', [ 0, 0, 2, 2 ], [ 0, 0, 0, 0 ] ),
				{
					name: '8.45x6.9in',
					blendMode: 'pass through',
					artboard: {
						rect: { left: 0, top: 0, right: 20, bottom: 20 },
						backgroundType: 3,
					},
					children: [ layer( '8:00', [ 0, 0, 2, 2 ] ), part() ],
				},
			] )
		);

		expect( source.layers.map( ( taken ) => taken.name ) ).toEqual( [
			'8:00',
			'Farvet lag',
		] );
	} );

	const refused: Array< [ ClipartErrorCode, string, Layer ] > = [
		[
			'blend',
			'A',
			layer( 'A', [ 0, 0, 2, 2 ], undefined, { blendMode: 'multiply' } ),
		],
		[ 'mask', 'B', layer( 'B', [ 0, 0, 2, 2 ], undefined, { mask: {} } ) ],
		[
			'clipping',
			'C',
			layer( 'C', [ 0, 0, 2, 2 ], undefined, { clipping: true } ),
		],
		[ 'adjustment', 'D', { name: 'D', adjustment: { type: 'invert' } } ],
		[
			'blend_if',
			'E',
			layer( 'E', [ 0, 0, 2, 2 ], undefined, {
				blendingRanges: {
					compositeGrayBlendSource: [ 0, 0, 200, 255 ],
					compositeGraphBlendDestinationRange: [ 0, 0, 255, 255 ],
					ranges: [],
				},
			} ),
		],
		[
			'blend_if',
			'F',
			layer( 'F', [ 0, 0, 2, 2 ], undefined, { knockout: true } ),
		],
		[
			'opacity',
			'G',
			layer( 'G', [ 0, 0, 2, 2 ], undefined, { fillOpacity: 0.5 } ),
		],
		[
			'pixels',
			'H',
			{ name: 'H', vectorFill: { type: 'color', color: BLACK } },
		],
		[
			'effect',
			'I',
			layer( 'I', [ 0, 0, 2, 2 ], undefined, { effects: effects() } ),
		],
		[ 'group', 'Farvet lag', { name: 'Farvet lag', children: [] } ],
		[
			'artboard',
			'J',
			{
				name: 'J',
				artboard: {
					rect: { left: 0, top: 0, right: 20, bottom: 20 },
					backgroundType: 1,
				},
				children: [],
			},
		],
	];

	it.each( refused )(
		'refuses %s by the name of the layer %s',
		( code, detail, bad ) => {
			expect( refusal( psd( [ bad, part() ] ) ) ).toEqual( {
				code,
				detail,
			} );
		}
	);

	const badEffects: Array< [ ClipartErrorCode, LayerEffectsInfo ] > = [
		[ 'effect', { dropShadow: [ { enabled: true } ] } ],
		[
			'effect',
			{
				solidFill: [
					{ enabled: true, color: BLACK },
					{ enabled: true, color: WHITE },
				],
			},
		],
		[
			'overlay',
			{ solidFill: [ { enabled: true, color: BLACK, opacity: 0.5 } ] },
		],
		[
			'gradient',
			{ gradientOverlay: [ { enabled: true, type: 'radial' } ] },
		],
		[
			'stroke',
			{
				stroke: [
					{
						enabled: true,
						position: 'inside',
						color: BLACK,
						size: { value: 2, units: 'Pixels' },
					},
				],
			},
		],
		[
			'stroke',
			{
				stroke: [
					{
						enabled: true,
						position: 'outside',
						color: BLACK,
						size: { value: 101, units: 'Pixels' },
					},
				],
			},
		],
	];

	it.each( badEffects )( 'refuses a part with %s', ( code, set ) => {
		expect( refusal( psd( [ part( { effects: set } ) ] ) ) ).toEqual( {
			code,
			detail: 'Farvet lag',
		} );
	} );

	it( 'refuses a part that is see-through or empty', () => {
		expect( refusal( psd( [ part( { opacity: 0.5 } ) ] ) )?.code ).toBe(
			'opacity'
		);
		expect(
			refusal(
				psd( [ layer( 'Farvet lag', [ 0, 0, 2, 2 ], [ 0, 0, 0, 0 ] ) ] )
			)?.code
		).toBe( 'pixels' );
	} );

	it( 'refuses the wrong document', () => {
		expect(
			refusal( psd( [ layer( 'Only', [ 0, 0, 2, 2 ] ) ] ) )?.code
		).toBe( 'no_parts' );
		expect(
			refusal( psd( Array.from( { length: 9 }, () => part() ) ) )?.code
		).toBe( 'too_many' );
		expect( refusal( psd( [ part() ], { width: 8001 } ) )?.code ).toBe(
			'size'
		);
		expect(
			refusal( psd( [ part() ], { width: 4001, height: 4001 } ) )?.code
		).toBe( 'size' );
		expect(
			refusal( psd( [ part() ], { bitsPerChannel: 16 } ) )?.code
		).toBe( 'depth' );
		expect(
			refusal( psd( [ part() ], { colorMode: 4 } as Partial< Psd > ) )
				?.code
		).toBe( 'mode' );
	} );
} );

describe( 'readClipart', () => {
	it( 'reads a written PSD with its effects and stroke in pixels', () => {
		const set = { ...effects(), scale: 4.167 };
		const [ taken ] = readClipart(
			writePsd( psd( [ part( { effects: set } ) ] ) )
		).layers;

		expect( taken ).toMatchObject( {
			stroke: { color: '#000000', size: 2 },
			paint: { color: '#ffffff' },
			scale: 0.75,
		} );
	} );

	it( 'refuses what is no PSD', () => {
		expect( () => readClipart( new ArrayBuffer( 40 ) ) ).toThrow(
			ClipartError
		);
	} );

	it( 'finds a colour profile among the resources', () => {
		const name = [ 0, 0 ];
		const resource = ( id: number, body: number[] ) => [
			56,
			66,
			73,
			77,
			Math.floor( id / 256 ),
			id % 256,
			...name,
			0,
			0,
			0,
			body.length,
			...body,
			...( body.length % 2 ? [ 0 ] : [] ),
		];
		const resources = [
			...resource( 1005, [ 1, 2, 3 ] ),
			...resource( 1039, [ 115, 82, 71, 66 ] ),
		];
		const bytes = new Uint8Array( [
			...new Array< number >( 26 ).fill( 0 ),
			0,
			0,
			0,
			0,
			0,
			0,
			0,
			resources.length,
			...resources,
		] );

		expect( Array.from( colorProfile( bytes ) ?? [] ) ).toEqual( [
			115, 82, 71, 66,
		] );
	} );
} );

describe( 'grownCoverage', () => {
	const pixel = ( alpha: number ) => ( {
		left: 5,
		top: 5,
		width: 1,
		height: 1,
		data: new Uint8ClampedArray( [ 0, 0, 0, alpha ] ),
	} );
	const rect = { x: 0, y: 0, width: 11, height: 11 };

	it( 'reaches size + a − 0.5 from each pixel', () => {
		const grown = grownCoverage( pixel( 255 ), 2, rect );

		expect( grown[ 5 * 11 + 5 ] ).toBe( 1 );
		expect( grown[ 5 * 11 + 6 ] ).toBe( 1 );
		expect( grown[ 5 * 11 + 7 ] ).toBeCloseTo( 0.5 );
		expect( grown[ 6 * 11 + 7 ] ).toBeCloseTo( 2.5 - Math.sqrt( 5 ) );
		expect( grown[ 5 * 11 + 8 ] ).toBe( 0 );
	} );

	it( 'reaches less from a partly covered pixel', () => {
		const grown = grownCoverage( pixel( 51 ), 2, rect );

		expect( grown[ 5 * 11 + 6 ] ).toBeCloseTo( 0.7 );
		expect( grown[ 5 * 11 + 7 ] ).toBe( 0 );
	} );

	it( 'is the shape itself without a stroke', () => {
		const grown = grownCoverage( pixel( 51 ), 0, rect );

		expect( grown[ 5 * 11 + 5 ] ).toBeCloseTo( 0.2 );
		expect( grown[ 5 * 11 + 6 ] ).toBe( 0 );
	} );
} );

describe( 'bakeClipart', () => {
	it( 'merges fixed layers between parts and keeps the order', () => {
		const baked = bakeClipart(
			clipartFromPsd(
				psd( [
					layer( 'Red', [ 0, 0, 2, 2 ], [ 255, 0, 0, 255 ] ),
					layer( 'Blue', [ 1, 0, 2, 2 ], [ 0, 0, 255, 255 ], {
						opacity: 0.5,
					} ),
					part(),
					layer( 'Top', [ 16, 16, 2, 2 ] ),
				] )
			)
		);
		const [ below, middle, above ] = baked.art.steps;

		expect( baked.art.steps.map( ( step ) => step.type ) ).toEqual( [
			'image',
			'part',
			'image',
		] );
		expect( below?.rect ).toEqual( { x: 0, y: 0, width: 3, height: 2 } );
		expect( middle?.rect ).toEqual( { x: 2, y: 2, width: 10, height: 10 } );
		expect( above?.rect ).toEqual( { x: 16, y: 16, width: 2, height: 2 } );

		if ( below?.type !== 'image' ) {
			throw new Error( 'The first step is the fixed image.' );
		}

		expect( at( baked, below.image, 0, 0 ) ).toEqual( [ 255, 0, 0, 255 ] );
		expect( at( baked, below.image, 1, 0 ) ).toEqual( [
			128, 0, 128, 255,
		] );
		expect( at( baked, below.image, 2, 0 ) ).toEqual( [ 0, 0, 255, 128 ] );
	} );

	it( 'puts the coverage of fill and stroke in the masks’ alpha', () => {
		const baked = bakeClipart( clipartFromPsd( psd( [ part() ] ) ) );
		const step = baked.art.steps[ 0 ] as PartStep;

		// The shape is at 5–8, the rectangle starts at 2.
		expect( at( baked, step.fill, 3, 3 ) ).toEqual( [
			255, 255, 255, 255,
		] );
		expect( at( baked, step.fill, 2, 3 )[ 3 ] ).toBe( 0 );
		expect( at( baked, step.grown ?? step.fill, 2, 3 )[ 3 ] ).toBe( 255 );
		expect( at( baked, step.grown ?? step.fill, 1, 3 )[ 3 ] ).toBe( 128 );
		expect( at( baked, step.grown ?? step.fill, 0, 3 )[ 3 ] ).toBe( 0 );
	} );

	it( 'draws the stroke of a shape past the edge into the document', () => {
		const baked = bakeClipart(
			clipartFromPsd( psd( [ part( {}, [ -3, 5, 3, 3 ] ) ] ) )
		);
		const step = baked.art.steps[ 0 ] as PartStep;

		expect( step.rect.x ).toBe( 0 );
		expect(
			at( baked, step.grown ?? step.fill, 0, 5 - step.rect.y + 1 )[ 3 ]
		).toBe( 255 );
		expect( at( baked, step.fill, 0, 5 - step.rect.y + 1 )[ 3 ] ).toBe( 0 );
		expect( baked.art.parts[ 0 ]?.box.x ).toBe( -3 );
	} );

	it( 'has no second mask without a stroke', () => {
		const baked = bakeClipart(
			clipartFromPsd( psd( [ part( { effects: {} } ) ] ) )
		);
		const step = baked.art.steps[ 0 ] as PartStep;

		expect( step.grown ).toBeNull();
		expect( step.rect ).toEqual( { x: 5, y: 5, width: 4, height: 4 } );
		expect( baked.art.parts[ 0 ]?.stroke ).toBeNull();
	} );

	it( 'gives each image its own place in the atlas', () => {
		const baked = bakeClipart(
			clipartFromPsd(
				psd( [ layer( 'Below', [ 0, 0, 3, 3 ] ), part() ] )
			)
		);
		const seen = new Set< string >();

		for ( const step of baked.art.steps ) {
			const places =
				step.type === 'image'
					? [ step.image ]
					: [ step.fill, step.grown ];

			for ( const place of places ) {
				if ( place === null ) {
					continue;
				}

				for ( let y = 0; y < step.rect.height; y++ ) {
					for ( let x = 0; x < step.rect.width; x++ ) {
						const key = `${ place.x + x },${ place.y + y }`;

						expect( seen.has( key ) ).toBe( false );
						seen.add( key );
					}
				}
			}
		}
	} );
} );
