import { describe, expect, it } from '@jest/globals';

import {
	cmykToRgb,
	hexToRgb,
	normHex,
	rgbToCmyk,
	rgbToHex,
} from '../sections/designer/color-convert';
import {
	backgroundOf,
	designedSides,
	duplicateObject,
	EMPTY_DESIGN,
	extraAreas,
	newShape,
	parseDesign,
	usedSides,
	withBackground,
	withObjects,
} from '../sections/designer/design-model';

const VIEWS = [
	{ key: 'front', name: 'Front', small: false, sizes: [] },
	{ key: 'back', name: 'Back', small: false, sizes: [] },
];

describe( 'designer background colour (ADR-095)', () => {
	it( 'sets and removes a colour on the chosen sides', () => {
		const coloured = withBackground(
			EMPTY_DESIGN,
			[ 'front', 'back' ],
			'#1F2A44'
		);

		expect( backgroundOf( coloured, 'front' ) ).toBe( '#1f2a44' );
		expect( Object.keys( coloured.sides ) ).toEqual( [ 'front', 'back' ] );

		const removed = withBackground( coloured, [ 'front' ], null );

		expect( backgroundOf( removed, 'front' ) ).toBeNull();
		expect( backgroundOf( removed, 'back' ) ).toBe( '#1f2a44' );
		expect( Object.keys( removed.sides ) ).toEqual( [ 'back' ] );
		expect(
			withBackground( removed, [ 'back' ], null ).background
		).toBeUndefined();
	} );

	it( 'keeps a coloured side when its last object goes', () => {
		const shape = newShape( 'rect', 1000, 1000 );
		const design = withObjects(
			withBackground( EMPTY_DESIGN, [ 'front' ], '#c8102e' ),
			'front',
			[ shape ]
		);

		expect(
			Object.keys( withObjects( design, 'front', [] ).sides )
		).toEqual( [ 'front' ] );
	} );

	it( 'prints a side with only a colour, but it is free', () => {
		const shape = newShape( 'rect', 1000, 1000 );
		const design = withObjects(
			withBackground( EMPTY_DESIGN, [ 'front', 'back' ], '#c8102e' ),
			'back',
			[ shape ]
		);

		expect( usedSides( design ) ).toEqual( [ 'front', 'back' ] );
		expect( designedSides( design ) ).toEqual( [ 'back' ] );
		expect( extraAreas( VIEWS, design, [], '50.0000', '0.0000' ) ).toEqual(
			[]
		);
		expect(
			extraAreas(
				VIEWS,
				withObjects( design, 'front', [ shape ] ),
				[],
				'50.0000',
				'0.0000'
			).length
		).toBe( 1 );
	} );

	it( "converts colours as the owner's module does", () => {
		expect( normHex( 'ABC' ) ).toBe( '#aabbcc' );
		expect( normHex( '#05707A' ) ).toBe( '#05707a' );
		expect( normHex( '#05707' ) ).toBeNull();
		expect( hexToRgb( '#05707a' ) ).toEqual( [ 5, 112, 122 ] );
		expect( rgbToHex( 5, 112, 122 ) ).toBe( '#05707a' );
		expect( rgbToHex( 300, -4, 'x' ) ).toBe( '#ff0000' );
		expect( rgbToCmyk( 0, 0, 0 ) ).toEqual( [ 0, 0, 0, 100 ] );
		expect( rgbToCmyk( 5, 112, 122 ) ).toEqual( [ 96, 8, 0, 52 ] );
		expect( rgbToHex( ...cmykToRgb( 0, 100, 100, 0 ) ) ).toBe( '#ff0000' );
	} );

	it( 'duplicates a layer right above it, a little moved and unlocked', () => {
		const first = { ...newShape( 'rect', 1000, 1000 ), locked: true };
		const second = newShape( 'ellipse', 1000, 1000 );
		const copied = duplicateObject(
			[ first, second ],
			first.id,
			1000,
			1000
		);

		expect( copied?.objects.map( ( object ) => object.type ) ).toEqual( [
			'rect',
			'rect',
			'ellipse',
		] );

		const copy = copied?.objects[ 1 ];

		expect( copy?.id ).toBe( copied?.id );
		expect( copy?.id ).not.toBe( first.id );
		expect( [ copy?.x, copy?.y, copy?.locked ] ).toEqual( [
			first.x + 30,
			first.y + 30,
			false,
		] );
		expect( duplicateObject( [ first ], 'nope', 1000, 1000 ) ).toBeNull();
	} );

	it( 'reads the stored colours and keeps their sides', () => {
		const design = parseDesign( {
			version: 1,
			sides: {},
			background: { front: '#c8102e', back: 'red' },
		} );

		expect( design?.background ).toEqual( { front: '#c8102e' } );
		expect( Object.keys( design?.sides ?? {} ) ).toEqual( [ 'front' ] );
		expect(
			parseDesign( { version: 1, sides: {} } )?.background
		).toBeUndefined();
	} );
} );
