import { describe, expect, it } from '@jest/globals';

import {
	backgroundOf,
	designedSides,
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
