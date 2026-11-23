/**
 * Tests for the guides of a print around the product (ADR-122, step 57.16).
 */
import { describe, expect, it, jest } from '@jest/globals';

import { parseOverview } from '../api/designer';
import { guideLines } from '../sections/designer/guides';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const guide = ( x: number, label: string, figure = 0 ) => ( {
	x,
	label,
	figure,
} );

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

	it( 'gives a side without guides none', () => {
		expect( overview( undefined )?.bases[ 0 ]?.views[ 0 ]?.guides ).toEqual(
			[]
		);
	} );
} );
