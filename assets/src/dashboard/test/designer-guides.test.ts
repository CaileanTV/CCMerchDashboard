/**
 * Tests for the guides of a print around the product (ADR-122, steps 57.16
 * and 57.19).
 */
import { describe, expect, it, jest } from '@jest/globals';

import { parseOverview } from '../api/designer';
import type { DesignerGuide } from '../api/designer';
import { guideFigures, guideLines } from '../sections/designer/guides';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const guide = (
	x: number,
	label: string,
	figure = 0,
	box: DesignerGuide[ 'box' ] = null,
	seam: number | null = null
): DesignerGuide => ( {
	x,
	label,
	figure,
	box,
	seam,
} );

/** The part of the print the bottle's drawings show (step 57.19). */
const BOX = { x: 0.06, y: 0.28, width: 0.88, height: 0.68 };

/**
 * An overview with one bottle whose side has the given guides.
 *
 * @param guides The side's guides as the API sends them.
 */
function overview( guides: unknown ) {
	return parseOverview( {
		bases: [
			{
				id: 3,
				name: 'Bottle',
				description: '',
				base_price: '149.9500',
				extra_side_price: '0.0000',
				small_side_price: '0.0000',
				currency: 'DKK',
				colors: [ { key: 'white', name: 'White', hex: '#ffffff' } ],
				sizes: [ '21.9oz' ],
				version: 1,
				views: [
					{
						key: 'front',
						name: 'Front',
						width: 1000,
						height: 1000,
						layers: { background: 7 },
						area: { x: 30, y: 200, width: 940, height: 600 },
						print_width: 2534,
						print_height: 2067,
						dpi: 300,
						guides,
					},
				],
			},
		],
		cliparts: [],
		uploads: [],
		fonts: [],
		products: [],
		limits: {
			max_upload_bytes: 26214400,
			max_uploads: 200,
			max_objects: 100,
			price_decimals: 2,
		},
	} );
}

describe( 'the guides of a print around the product', () => {
	it( 'puts the lines half way between the views, also across the ends', () => {
		// As measured on the Sky Water Bottle (step 57.14).
		const lines = guideLines( [
			guide( 0, 'Back' ),
			guide( 0.249, 'Left side' ),
			guide( 0.497, 'Front' ),
			guide( 0.748, 'Right side' ),
		] );

		expect( lines.map( ( x ) => Math.round( x * 1000 ) / 1000 ) ).toEqual( [
			0.125, 0.373, 0.623, 0.874,
		] );
	} );

	it( 'draws no line for one view or none', () => {
		expect( guideLines( [] ) ).toEqual( [] );
		expect( guideLines( [ guide( 0.5, 'Front' ) ] ) ).toEqual( [] );
	} );

	it( 'reads the guides of a side, sorted, and leaves out anything off', () => {
		const view = overview( [
			guide( 0.75, 'Right side', 41 ),
			guide( 0.5, 'Front', 40 ),
			guide( 1.5, 'Too far' ),
			{ x: 0.2, label: '' },
			{ x: 'a', label: 'Back' },
			guide( 0.25, 'Left side', -3 ),
		] )?.bases[ 0 ]?.views[ 0 ];

		expect( view?.guides ).toEqual( [
			guide( 0.25, 'Left side', 0 ),
			guide( 0.5, 'Front', 40 ),
			guide( 0.75, 'Right side', 41 ),
		] );
	} );

	it( 'reads the part of the print on a drawing and where the sheet starts', () => {
		const view = overview( [
			guide( 0, 'Back', 42, BOX, 0.494 ),
			guide( 0.5, 'Front', 40, BOX, null ),
			// A box outside its drawing, a seam without a box or off the drawing.
			guide( 0.25, 'Left side', 43, {
				x: 0.5,
				y: 0,
				width: 0.8,
				height: 1,
			} ),
			{ ...guide( 0.75, 'Right side', 44 ), seam: 0.5 },
			{ ...guide( 0.9, 'Context', 45, BOX ), seam: 1.2 },
		] )?.bases[ 0 ]?.views[ 0 ];

		expect( view?.guides ).toEqual( [
			guide( 0, 'Back', 42, BOX, 0.494 ),
			guide( 0.25, 'Left side', 43 ),
			guide( 0.5, 'Front', 40, BOX ),
			guide( 0.75, 'Right side', 44 ),
			guide( 0.9, 'Context', 45, BOX ),
		] );
	} );

	it( 'shows the view where the sheet meets at both ends, each with its half', () => {
		const figures = guideFigures( [
			guide( 0.497, 'Front', 40, BOX ),
			guide( 0, 'Back', 42, BOX, 0.494 ),
			guide( 0.249, 'Left side', 43, BOX ),
			guide( 0.748, 'Right side', 44, BOX ),
		] );
		const round = ( value: number ) => Math.round( value * 1000 ) / 1000;

		expect(
			figures.map( ( figure ) => [ figure.key, figure.x, figure.anchor ] )
		).toEqual( [
			[ 'Back-start', 0, 0.494 ],
			[ 'Left side-0.249', 0.249, 0.5 ],
			[ 'Front-0.497', 0.497, 0.5 ],
			[ 'Right side-0.748', 0.748, 0.5 ],
			[ 'Back-end', 1, 0.494 ],
		] );
		// The sheet starts right of the seam and ends left of it.
		expect(
			[ figures[ 0 ]?.box, figures[ 4 ]?.box ].map( ( box ) => [
				round( box?.x ?? -1 ),
				round( box?.width ?? -1 ),
				box?.y,
				box?.height,
			] )
		).toEqual( [
			[ 0.494, 0.446, 0.28, 0.68 ],
			[ 0.06, 0.434, 0.28, 0.68 ],
		] );
		expect( figures[ 2 ]?.box ).toEqual( BOX );
	} );

	it( 'keeps a photo without a part of the print under its centre', () => {
		expect( guideFigures( [ guide( 0.5, 'Front', 40 ) ] ) ).toEqual( [
			{
				key: 'Front-0.5',
				label: 'Front',
				figure: 40,
				x: 0.5,
				anchor: 0.5,
				box: null,
				repeat: false,
			},
		] );
	} );

	it( 'reads the seam view out once and frames no half without width', () => {
		const figures = guideFigures( [ guide( 0, 'Back', 42, BOX, 0.06 ) ] );

		expect(
			figures.map( ( figure ) => [ figure.key, figure.repeat ] )
		).toEqual( [
			[ 'Back-start', false ],
			[ 'Back-end', true ],
		] );
		expect( figures[ 0 ]?.box?.width ).toBeCloseTo( 0.88 );
		expect( figures[ 1 ]?.box ).toBeNull();
	} );

	it( 'gives a side without guides none', () => {
		expect( overview( undefined )?.bases[ 0 ]?.views[ 0 ]?.guides ).toEqual(
			[]
		);
	} );
} );
