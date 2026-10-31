/**
 * Tests for mockups from a PSD with smart objects: the import and the drawing.
 */
import { describe, expect, it } from '@jest/globals';
import type { Layer, Psd } from 'ag-psd';

import { sceneFromPsd } from '../../admin/psd-scene';
import { TemplateError } from '../../admin/psd-template';
import {
	FLAT_MESH,
	placed,
	renderScene,
	shownPart,
} from '../sections/designer/scene';
import type { Pixels, SceneDesign } from '../sections/designer/scene';

/**
 * An image of one colour.
 *
 * @param width  Width.
 * @param height Height.
 * @param rgba   Colour.
 */
function plain(
	width: number,
	height: number,
	rgba: [ number, number, number, number ]
): Pixels {
	const data = new Uint8ClampedArray( width * height * 4 );

	for ( let index = 0; index < width * height; index++ ) {
		data.set( rgba, index * 4 );
	}

	return { width, height, data };
}

/**
 * The colour of a pixel.
 *
 * @param image The image.
 * @param x     X.
 * @param y     Y.
 */
function pixel( image: Pixels, x: number, y: number ): number[] {
	const at = ( y * image.width + x ) * 4;

	return Array.from( image.data.subarray( at, at + 3 ) );
}

const DESIGN: SceneDesign = {
	type: 'design',
	end: 'start',
	width: 10,
	height: 10,
	quad: [ 2, 2, 8, 2, 8, 8, 2, 8 ],
	mesh: FLAT_MESH,
	mask: null,
};

/**
 * A layer of one colour.
 *
 * @param name  Name.
 * @param extra Other fields.
 */
function layer( name: string, extra: Partial< Layer > = {} ): Layer {
	const image = plain( 10, 10, [ 200, 100, 50, 255 ] );

	return {
		name,
		left: 0,
		top: 0,
		right: 10,
		bottom: 10,
		imageData: { ...image, colorSpace: 'srgb' } as ImageData,
		...extra,
	};
}

/**
 * A smart object with a design.
 *
 * @param name Its name.
 * @param warp Its warp.
 */
function smartObject(
	name: string,
	warp: NonNullable< Layer[ 'placedLayer' ] >[ 'warp' ] = { style: 'none' }
): Layer {
	return layer( name, {
		placedLayer: {
			id: 'x',
			type: 'raster',
			transform: [ 1, 1, 9, 1, 9, 9, 1, 9 ],
			width: 40,
			height: 20,
			warp,
		},
	} );
}

/**
 * A document of 10 × 10 px.
 *
 * @param children Its layers, bottom first.
 */
function psd( children: Layer[] ): Psd {
	return { width: 10, height: 10, bitsPerChannel: 8, children };
}

describe( 'a mockup scene', () => {
	it( 'shows the end of the print file its smart object stands for', () => {
		expect( shownPart( DESIGN, 300, 100 ) ).toEqual( {
			x: 0,
			y: 0,
			width: 100,
			height: 100,
		} );
		expect( shownPart( { ...DESIGN, end: 'end' }, 300, 100 ).x ).toBe(
			200
		);
		expect( shownPart( { ...DESIGN, end: 'middle' }, 300, 100 ).x ).toBe(
			100
		);
	} );

	it( 'never shows more than the print file has', () => {
		expect(
			shownPart( { ...DESIGN, width: 40, height: 10 }, 100, 100 )
		).toEqual( { x: 0, y: 37.5, width: 100, height: 25 } );
	} );

	it( 'puts a flat smart object on its transform', () => {
		expect( placed( DESIGN, 0, 0 ) ).toEqual( [ 2, 2 ] );
		expect( placed( DESIGN, 1, 1 ) ).toEqual( [ 8, 8 ] );
		expect( placed( DESIGN, 0.5, 0.5 ) ).toEqual( [ 5, 5 ] );
	} );

	it( 'draws the design on white, inside its mask', () => {
		const atlas = plain( 4, 4, [ 0, 0, 0, 255 ] );
		const scene = {
			width: 10,
			height: 10,
			steps: [
				{
					...DESIGN,
					mask: { x: 0, y: 0, width: 4, height: 4, left: 2, top: 2 },
				},
			],
		};
		const drawn = renderScene(
			scene,
			atlas,
			plain( 20, 20, [ 10, 200, 30, 255 ] )
		);

		expect( pixel( drawn, 3, 3 ) ).toEqual( [ 10, 200, 30 ] );
		expect( pixel( drawn, 7, 7 ) ).toEqual( [ 255, 255, 255 ] );
		expect( pixel( drawn, 0, 0 ) ).toEqual( [ 255, 255, 255 ] );
	} );

	it( 'blends a layer with its strength', () => {
		const scene = {
			width: 2,
			height: 2,
			steps: [
				{
					type: 'image' as const,
					rect: { x: 0, y: 0, width: 2, height: 2 },
					left: 0,
					top: 0,
					blend: 'multiply' as const,
					opacity: 0.5,
				},
			],
		};

		expect(
			pixel(
				renderScene(
					scene,
					plain( 2, 2, [ 0, 100, 255, 255 ] ),
					plain( 1, 1, [ 0, 0, 0, 0 ] )
				),
				0,
				0
			)
		).toEqual( [ 128, 178, 255 ] );
	} );
} );

describe( 'the import of a mockup PSD', () => {
	it( 'keeps the order of the layers and each design’s end of the print', () => {
		const { scene, designs, atlas } = sceneFromPsd(
			psd( [
				layer( 'Base' ),
				smartObject( 'Venstre design' ),
				layer( 'Shade', { blendMode: 'multiply', fillOpacity: 0.25 } ),
				smartObject( 'Højre design' ),
				layer( 'Glass', { blendMode: 'darker color', opacity: 0.5 } ),
			] )
		);

		expect( designs ).toEqual( [ 'Venstre design', 'Højre design' ] );
		expect(
			scene.steps.map( ( step ) =>
				step.type === 'design' ? step.end : step.blend
			)
		).toEqual( [ 'normal', 'start', 'multiply', 'end', 'darker-color' ] );
		expect( scene.steps[ 2 ] ).toMatchObject( { opacity: 0.25 } );
		expect( scene.steps[ 4 ] ).toMatchObject( { opacity: 0.5 } );
		expect( scene.steps[ 1 ] ).toMatchObject( { width: 40, height: 20 } );
		expect( atlas.width ).toBeGreaterThan( 9 );
	} );

	it( 'clips a design to the layer below it', () => {
		const { scene } = sceneFromPsd(
			psd( [
				layer( 'Mug Shape' ),
				layer( 'Right design', {
					...smartObject( 'Right design' ),
					clipping: true,
				} ),
			] )
		);

		expect( scene.steps[ 1 ] ).toMatchObject( {
			end: 'end',
			mask: { left: 0, top: 0, width: 10, height: 10 },
		} );
	} );

	it( 'leaves hidden layers out and names what it cannot redraw', () => {
		const { scene, warnings } = sceneFromPsd(
			psd( [
				layer( 'Hidden', { hidden: true, blendMode: 'multiply' } ),
				layer( 'Glow', {
					effects: { dropShadow: [ { enabled: true } ] },
				} as Partial< Layer > ),
				smartObject( 'Design' ),
			] )
		);

		expect( scene.steps ).toHaveLength( 2 );
		expect( warnings ).toEqual( [ 'Glow' ] );
	} );

	it( 'keeps a warp’s points in their own box', () => {
		const meshPoints = FLAT_MESH.flatMap( ( value, index ) =>
			index % 2 === 0
				? [
						{
							x: 100 + value * 200,
							y: 50 + ( FLAT_MESH[ index + 1 ] as number ) * 100,
						},
					]
				: []
		);
		const { scene } = sceneFromPsd(
			psd( [
				layer( 'Base' ),
				smartObject( 'Design', {
					style: 'custom',
					customEnvelopeWarp: { meshPoints },
				} ),
			] )
		);
		const step = scene.steps[ 1 ] as SceneDesign;

		expect( step.mesh[ 0 ] ).toBe( 0 );
		expect( step.mesh[ 30 ] ).toBe( 1 );
		expect( step.quad ).toEqual( [ 1, 1, 9, 1, 9, 9, 1, 9 ] );
	} );

	it( 'refuses a PSD without a design and a warp it cannot read', () => {
		const code = ( children: Layer[] ): string | null => {
			try {
				sceneFromPsd( psd( children ) );

				return null;
			} catch ( error ) {
				return error instanceof TemplateError ? error.code : 'other';
			}
		};

		expect( code( [ layer( 'Base' ) ] ) ).toBe( 'no_design' );
		expect( code( [ smartObject( 'Design', { style: 'arc' } ) ] ) ).toBe(
			'warp'
		);
	} );

	it( 'keeps a big document small', () => {
		const big = {
			...psd( [ smartObject( 'Design' ) ] ),
			width: 4000,
			height: 3000,
		};

		expect( sceneFromPsd( big ).scene ).toMatchObject( {
			width: 2000,
			height: 1500,
		} );
	} );
} );
