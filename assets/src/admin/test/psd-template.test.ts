/**
 * Tests for the PSD import of base products (phase 30, step 30.7).
 */
import { describe, expect, it } from '@jest/globals';
import { initializeCanvas, writePsd } from 'ag-psd';
import type { Layer, Psd } from 'ag-psd';

import { errorMessage, fillArea, fillName } from '../base-products-admin';
import { readTemplate, TemplateError, templateFromPsd } from '../psd-template';
import type { TemplateErrorCode } from '../psd-template';

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

/**
 * A layer of one colour.
 *
 * @param name    Name.
 * @param box     Left, top, width, height.
 * @param rgba    Colour.
 * @param opacity Layer opacity (0–1).
 */
function layer(
	name: string,
	box: [ number, number, number, number ],
	rgba: [ number, number, number, number ] = [ 255, 0, 0, 255 ],
	opacity = 1
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
		opacity,
		imageData: { width, height, data },
	};
}

/**
 * A document of 10 × 8 px.
 *
 * @param children Layers.
 * @param extra    Other properties.
 */
function psd( children: Layer[], extra: Partial< Psd > = {} ): Psd {
	return { width: 10, height: 8, children, ...extra };
}

/**
 * The alpha of a pixel.
 *
 * @param data Image.
 * @param x    Column.
 * @param y    Row.
 */
function alpha( data: Uint8ClampedArray, x: number, y: number ): number {
	return data[ ( y * 10 + x ) * 4 + 3 ] ?? -1;
}

/**
 * The code of the error a call throws.
 *
 * @param call The call.
 */
function codeOf( call: () => unknown ): TemplateErrorCode | null {
	try {
		call();
	} catch ( error ) {
		return error instanceof TemplateError ? error.code : null;
	}

	return null;
}

describe( 'templateFromPsd', () => {
	it( 'places the convention layers in the document and finds the print area', () => {
		const template = templateFromPsd(
			psd( [
				layer(
					'MB_Background ',
					[ 0, 0, 10, 8 ],
					[ 250, 250, 250, 255 ]
				),
				{
					name: 'Shirt',
					children: [
						layer( 'mb_color', [ 2, 1, 6, 6 ] ),
						layer(
							'mb_shading',
							[ -2, -1, 4, 3 ],
							[ 0, 0, 0, 200 ],
							0.5
						),
					],
				},
				layer( 'mb_print_area', [ 3, 2, 4, 3 ], [ 0, 255, 0, 128 ] ),
				layer( 'Notes', [ 0, 0, 10, 8 ] ),
			] )
		);

		expect( [ template.width, template.height ] ).toEqual( [ 10, 8 ] );
		expect( Object.keys( template.layers ) ).toEqual( [
			'background',
			'color',
			'shading',
		] );
		expect( template.area ).toEqual( { x: 3, y: 2, width: 4, height: 3 } );

		const color = template.layers.color!.data;
		expect( color.length ).toBe( 10 * 8 * 4 );
		expect( [
			alpha( color, 1, 1 ),
			alpha( color, 2, 1 ),
			alpha( color, 7, 6 ),
			alpha( color, 8, 6 ),
		] ).toEqual( [ 0, 255, 255, 0 ] );

		// Clipped at the document's edge, with the layer's opacity applied.
		const shading = template.layers.shading!.data;
		expect( [
			alpha( shading, 0, 0 ),
			alpha( shading, 1, 1 ),
			alpha( shading, 2, 0 ),
		] ).toEqual( [ 100, 100, 0 ] );
	} );

	it( 'leaves the print area to the form when the PSD has none', () => {
		expect(
			templateFromPsd( psd( [ layer( 'mb_color', [ 0, 0, 10, 8 ] ) ] ) )
				.area
		).toBeNull();
	} );

	it( 'refuses what the server would refuse, with a reason', () => {
		const color = layer( 'mb_color', [ 0, 0, 10, 8 ] );

		expect(
			codeOf( () =>
				templateFromPsd(
					psd( [ layer( 'mb_shading', [ 0, 0, 2, 2 ] ) ] )
				)
			)
		).toBe( 'no_layers' );
		expect(
			codeOf( () =>
				templateFromPsd(
					psd( [ color, layer( 'MB_COLOR', [ 0, 0, 2, 2 ] ) ] )
				)
			)
		).toBe( 'duplicate' );
		expect(
			codeOf( () =>
				templateFromPsd( psd( [ color ], { bitsPerChannel: 16 } ) )
			)
		).toBe( 'depth' );
		expect(
			codeOf( () => templateFromPsd( psd( [ color ], { width: 8001 } ) ) )
		).toBe( 'size' );
		expect(
			codeOf( () =>
				templateFromPsd( psd( [ color, { name: 'mb_print_area' } ] ) )
			)
		).toBe( 'empty_area' );
		expect( codeOf( () => readTemplate( new ArrayBuffer( 16 ) ) ) ).toBe(
			'unreadable'
		);
	} );
} );

describe( 'readTemplate', () => {
	it( 'reads a PSD written by ag-psd', () => {
		const buffer = writePsd(
			psd( [
				layer( 'mb_background', [ 0, 0, 10, 8 ], [ 10, 20, 30, 255 ] ),
				layer( 'mb_color', [ 1, 1, 8, 6 ] ),
				layer( 'mb_print_area', [ 2, 3, 5, 2 ], [ 0, 0, 0, 255 ] ),
			] ),
			{ noBackground: true }
		);
		const template = readTemplate( buffer );

		expect( Object.keys( template.layers ) ).toEqual( [
			'background',
			'color',
		] );
		expect( template.area ).toEqual( { x: 2, y: 3, width: 5, height: 2 } );
		expect(
			Array.from( template.layers.background!.data.slice( 0, 4 ) )
		).toEqual( [ 10, 20, 30, 255 ] );
		expect( alpha( template.layers.color!.data, 0, 0 ) ).toBe( 0 );
		expect( alpha( template.layers.color!.data, 1, 1 ) ).toBe( 255 );
	} );
} );

describe( 'the form', () => {
	it( 'fills the print area and an empty side name', () => {
		document.body.innerHTML =
			'<form><input name="views[1][name]" value=""><input name="views[1][x]"><input name="views[1][y]"><input name="views[1][width]"><input name="views[1][height]"><input name="views[2][name]" value="Back"></form>';
		const form = document.querySelector( 'form' )!;

		fillArea( form, '1', { x: 3, y: 2, width: 4, height: 5 } );
		fillName( form, '1', 'Front side.PSD' );
		fillName( form, '2', 'Other.psd' );

		const value = ( name: string ): string =>
			( form.elements.namedItem( name ) as HTMLInputElement ).value;

		expect(
			[ 'x', 'y', 'width', 'height' ].map( ( key ) =>
				value( `views[1][${ key }]` )
			)
		).toEqual( [ '3', '2', '4', '5' ] );
		expect( value( 'views[1][name]' ) ).toBe( 'Front side' );
		expect( value( 'views[2][name]' ) ).toBe( 'Back' );
	} );

	it( 'explains every refusal', () => {
		expect(
			errorMessage( new TemplateError( 'duplicate', 'mb_color' ) )
		).toContain( 'mb_color' );
		expect( errorMessage( new TemplateError( 'depth' ) ) ).toContain(
			'8 bits'
		);
		expect( errorMessage( new Error( 'x' ) ) ).toContain( 'try again' );
	} );
} );
