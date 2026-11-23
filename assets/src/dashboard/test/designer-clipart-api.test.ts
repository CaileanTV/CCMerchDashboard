/**
 * Tests for cliparts with a definition in the designer API (phase 56, step
 * 56.9).
 */
import { describe, expect, it, jest } from '@jest/globals';

import { parseAsset, parseProduct } from '../api/designer';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const RED = { type: 'solid', color: '#ff0000' };
const GRADIENT = {
	type: 'gradient',
	from: '#65b6f5',
	to: '#f1a90c',
	angle: 45,
};
const RECT = { x: 0, y: 0, width: 8, height: 8 };

const ARTWORK = {
	id: 12,
	name: 'Bottle',
	width: 32,
	height: 32,
	mime: 'image/png',
	bases: [ 3 ],
	locked: true,
	art: {
		parts: [
			{
				label: 'Låg',
				box: RECT,
				stroke: '#000000',
				scale: 0.75,
				paint: RED,
				gradient: GRADIENT,
			},
		],
		steps: [
			{
				type: 'image',
				rect: RECT,
				image: { x: 18, y: 0 },
			},
			{
				type: 'part',
				part: 0,
				rect: RECT,
				fill: { x: 0, y: 0 },
				grown: { x: 9, y: 0 },
			},
		],
		atlas: { width: 26, height: 8 },
		palette: [ '#ff0000', '#65b6f5', '#f1a90c' ],
	},
};

describe( 'a clipart in the designer API', () => {
	it( 'reads a clipart with parts, its base products and its lock', () => {
		const asset = parseAsset( ARTWORK );

		expect( asset?.clipart?.bases ).toEqual( [ 3 ] );
		expect( asset?.clipart?.locked ).toBe( true );
		expect( asset?.clipart?.art?.art ).toEqual( {
			width: 32,
			height: 32,
			parts: ARTWORK.art.parts,
			steps: ARTWORK.art.steps,
		} );
		expect( asset?.clipart?.art?.atlas ).toEqual( {
			width: 26,
			height: 8,
		} );
	} );

	it( 'keeps a clipart from before and an upload as they were', () => {
		const asset = parseAsset( {
			id: 9,
			name: 'Star',
			width: 32,
			height: 32,
			mime: 'image/png',
		} );

		expect( asset ).toEqual( {
			id: 9,
			name: 'Star',
			width: 32,
			height: 32,
			mime: 'image/png',
		} );
		expect(
			parseAsset( { ...ARTWORK, art: undefined, bases: null } )?.clipart
		).toEqual( { bases: null, locked: true, art: null } );
	} );

	it( 'refuses a clipart that does not read whole', () => {
		const broken = [
			{ ...ARTWORK, locked: 'yes' },
			{ ...ARTWORK, bases: [ '3' ] },
			{ ...ARTWORK, art: { ...ARTWORK.art, parts: [] } },
			{ ...ARTWORK, art: { ...ARTWORK.art, palette: [ 'red' ] } },
			{
				...ARTWORK,
				art: { ...ARTWORK.art, atlas: { width: 0, height: 8 } },
			},
			{
				...ARTWORK,
				art: {
					...ARTWORK.art,
					steps: [ { ...ARTWORK.art.steps[ 1 ], part: 1 } ],
				},
			},
			{
				...ARTWORK,
				art: {
					...ARTWORK.art,
					parts: [ { ...ARTWORK.art.parts[ 0 ], stroke: 'black' } ],
				},
			},
			{
				...ARTWORK,
				art: {
					...ARTWORK.art,
					parts: [
						{
							...ARTWORK.art.parts[ 0 ],
							gradient: { ...GRADIENT, angle: 360 },
						},
					],
				},
			},
		];

		for ( const asset of broken ) {
			expect( parseAsset( asset ) ).toBeNull();
		}
	} );

	it( 'gives a product the cliparts of its draft, none in an answer from before', () => {
		const product = {
			id: 5,
			base_id: 3,
			in_shop: false,
			draft: null,
			pending: null,
			live: null,
			rejection: null,
		};

		expect( parseProduct( product )?.assets ).toEqual( [] );
		expect(
			parseProduct( { ...product, assets: [ ARTWORK ] } )?.assets[ 0 ]?.id
		).toBe( 12 );
		expect(
			parseProduct( {
				...product,
				assets: [ { ...ARTWORK, locked: 1 } ],
			} )
		).toBeNull();
	} );
} );
