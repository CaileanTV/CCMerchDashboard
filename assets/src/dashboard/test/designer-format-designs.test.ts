/**
 * Tests for one design shared by a poster's formats (ADR-118).
 */
import { describe, expect, it } from '@jest/globals';

import {
	adapted,
	covers,
	linked,
	sharedWith,
	started,
	synced,
	toMaster,
	unlinked,
	withAdapt,
	withSideBackground,
	withSideObjects,
	type FormatView,
} from '../sections/designer/format-designs';
import {
	coversSizes,
	parseDesign,
	type DesignDocument,
	type DesignObject,
} from '../sections/designer/design-model';

/** An A3 print file (2:3) and a square one. */
const A3 = { width: 2000, height: 3000 };
const SQUARE = { width: 3000, height: 3000 };

const views: FormatView[] = [
	{
		key: 'lodret-a',
		colors: [ 'lodret' ],
		sizes: [ 'A3', 'A2' ],
		printWidth: 2000,
		printHeight: 3000,
		format: true,
	},
	{
		key: 'lodret-square',
		colors: [ 'lodret' ],
		sizes: [ '30x30' ],
		printWidth: 3000,
		printHeight: 3000,
		format: true,
	},
	{
		key: 'vandret-a',
		colors: [ 'vandret' ],
		sizes: [ 'A3', 'A2' ],
		printWidth: 3000,
		printHeight: 2000,
		format: true,
	},
];

const rect = ( fields: Partial< DesignObject > ): DesignObject =>
	( {
		type: 'rect',
		id: 'r',
		x: 0,
		y: 0,
		width: 100,
		height: 100,
		angle: 0,
		scaleX: 1,
		scaleY: 1,
		flipX: false,
		flipY: false,
		opacity: 1,
		locked: false,
		hidden: false,
		fill: '#ff0000',
		stroke: null,
		strokeWidth: 0,
		radius: 0,
		...fields,
	} ) as DesignObject;

// A photo covering the whole A3 and a logo in its top left corner.
const photo = rect( {
	id: 'photo',
	x: 1000,
	y: 1500,
	width: 2000,
	height: 3000,
} );
const logo = rect( { id: 'logo', x: 200, y: 200, width: 200, height: 200 } );

describe( 'fitting a shared design to a format', () => {
	it( 'fills: the whole design covers the square, the top and bottom are cut', () => {
		const [ moved ] = adapted( [ photo ], A3, SQUARE, 'fill' );

		expect( moved ).toMatchObject( {
			x: 1500,
			y: 1500,
			scaleX: 1.5,
			scaleY: 1.5,
		} );
		expect( covers( moved as DesignObject, SQUARE ) ).toBe( true );
	} );

	it( 'fits: the whole design is inside the square, with bare sides', () => {
		const [ moved ] = adapted( [ photo ], A3, SQUARE, 'fit' );

		expect( moved ).toMatchObject( {
			x: 1500,
			y: 1500,
			scaleX: 1,
			scaleY: 1,
		} );
		expect( covers( moved as DesignObject, SQUARE ) ).toBe( false );
	} );

	it( 'smart: the photo keeps covering and the logo stays in its corner at its size', () => {
		const [ moved, corner ] = adapted(
			[ photo, logo ],
			A3,
			SQUARE,
			'smart'
		);

		expect( covers( moved as DesignObject, SQUARE ) ).toBe( true );
		expect( corner ).toMatchObject( {
			x: 300,
			y: 200,
			scaleX: 1,
			scaleY: 1,
		} );
	} );

	it( 'goes back to the master the way it came', () => {
		for ( const mode of [ 'fill', 'fit', 'smart' ] as const ) {
			const there = adapted( [ photo, logo ], A3, SQUARE, mode );

			expect( toMaster( there, A3, SQUARE, mode ) ).toEqual( [
				photo,
				logo,
			] );
		}
	} );
} );

describe( 'formats sharing a design', () => {
	const sizes = [ 'A3', '30x30' ];
	const colors = [ 'lodret' ];
	const fresh = started( { version: 1, sides: {} }, views );

	it( 'starts a new poster design with every format on its orientation', () => {
		expect( fresh.formats?.links ).toEqual( {
			'lodret-a': 'lodret',
			'lodret-square': 'lodret',
			'vandret-a': 'vandret',
		} );
		expect( sharedWith( fresh, 'lodret-a' ) ).toEqual( [
			'lodret-square',
		] );
		expect(
			started( { version: 1, sides: { 'lodret-a': [ logo ] } }, views )
				.formats
		).toBeUndefined();
	} );

	it( 'puts an edit on one format on every sold format of the orientation', () => {
		const edited = withSideObjects(
			fresh,
			views,
			'lodret-a',
			[ logo ],
			sizes,
			colors
		);

		expect( edited.sides[ 'lodret-a' ] ).toEqual( [ logo ] );
		expect( edited.sides[ 'lodret-square' ]?.[ 0 ] ).toMatchObject( {
			x: 300,
			y: 200,
		} );
		expect( edited.sides[ 'vandret-a' ] ).toBeUndefined();
		expect( coversSizes( views, edited, sizes, colors ) ).toBe( true );

		// Moved on the square, the A3 follows.
		const moved = withSideObjects(
			edited,
			views,
			'lodret-square',
			[ { ...logo, x: 1500 } ],
			sizes,
			colors
		);

		expect( moved.sides[ 'lodret-a' ]?.[ 0 ] ).toMatchObject( {
			x: 1000,
			y: 200,
		} );
	} );

	it( 'leaves out a format whose sizes are not sold', () => {
		const edited = withSideObjects(
			fresh,
			views,
			'lodret-a',
			[ logo ],
			[ 'A3' ],
			colors
		);

		expect( Object.keys( edited.sides ) ).toEqual( [ 'lodret-a' ] );
		expect( coversSizes( views, edited, [ 'A3' ], colors ) ).toBe( true );
	} );

	it( 'gives a format its own design and back', () => {
		const edited = withSideObjects(
			fresh,
			views,
			'lodret-a',
			[ logo ],
			sizes,
			colors
		);
		const own = unlinked( edited, 'lodret-square' );
		const changed = withSideObjects(
			own,
			views,
			'lodret-a',
			[ { ...logo, x: 400 } ],
			sizes,
			colors
		);

		expect( changed.sides[ 'lodret-square' ]?.[ 0 ] ).toMatchObject( {
			x: 300,
		} );
		expect( sharedWith( changed, 'lodret-a' ) ).toEqual( [] );

		const back = linked( changed, views, 'lodret-square', sizes, colors );

		expect( back.sides[ 'lodret-square' ]?.[ 0 ] ).toMatchObject( {
			x: 600,
		} );
	} );

	it( 'lets each format be fitted its own way and shares the background colour', () => {
		const edited = withSideObjects(
			fresh,
			views,
			'lodret-a',
			[ photo ],
			sizes,
			colors
		);
		const fit = withAdapt(
			edited,
			views,
			'lodret-square',
			'fit',
			sizes,
			colors
		);

		expect( fit.sides[ 'lodret-square' ]?.[ 0 ] ).toMatchObject( {
			scaleX: 1,
		} );
		expect( fit.formats?.fit ).toEqual( { 'lodret-square': 'fit' } );

		const coloured = withSideBackground(
			fit,
			views,
			[ 'lodret-square' ],
			'#112233',
			sizes,
			colors
		);

		expect( coloured.background ).toEqual( {
			'lodret-a': '#112233',
			'lodret-square': '#112233',
		} );
	} );

	it( 'reads the shared formats back as stored, an empty record coming as a list', () => {
		const edited = withSideObjects(
			fresh,
			views,
			'lodret-a',
			[ logo ],
			sizes,
			colors
		);
		const stored = JSON.parse(
			JSON.stringify( {
				...edited,
				formats: { ...edited.formats, fit: [] },
			} )
		) as unknown;

		expect( parseDesign( stored )?.formats ).toEqual( edited.formats );
		expect(
			synced(
				parseDesign( stored ) as DesignDocument,
				views,
				sizes,
				colors
			).sides
		).toEqual( edited.sides );
	} );
} );
