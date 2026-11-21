/**
 * Tests for sides of some colours and sizes: a poster's format groups (ADR-115).
 */
import { describe, expect, it, jest } from '@jest/globals';

import type { DesignerView } from '../api/designer';
import { submissionSize } from '../sections/designer/submission';
import {
	coversSizes,
	onColor,
	usedAreas,
} from '../sections/designer/design-model';
import type { DesignDocument } from '../sections/designer/design-model';

jest.mock( '../sections/designer/stitch-surface', () => ( {
	STITCH_PX_PER_MM: 9.2,
	STITCH_SURFACE: 'data:image/jpeg;base64,',
} ) );

describe( 'format groups of a poster', () => {
	// Two groups per orientation: A sizes and squares.
	const views = [
		{ key: 'vertical-a', sizes: [ 'A3', 'A2' ], colors: [ 'vertical' ] },
		{ key: 'vertical-square', sizes: [ '30x30' ], colors: [ 'vertical' ] },
		{
			key: 'horizontal-a',
			sizes: [ 'A3', 'A2' ],
			colors: [ 'horizontal' ],
		},
		{
			key: 'horizontal-square',
			sizes: [ '30x30' ],
			colors: [ 'horizontal' ],
		},
	];
	const design = ( ...sides: string[] ) =>
		( {
			version: 1,
			sides: Object.fromEntries(
				sides.map( ( side ) => [ side, [ { id: side } ] ] )
			),
		} ) as unknown as DesignDocument;
	const all = [
		'vertical-a',
		'vertical-square',
		'horizontal-a',
		'horizontal-square',
	];

	it( 'wants a design for every chosen orientation and size', () => {
		expect(
			coversSizes(
				views,
				design( ...all ),
				[ 'A3', '30x30' ],
				[ 'vertical', 'horizontal' ]
			)
		).toBe( true );
		expect(
			coversSizes(
				views,
				design( 'vertical-a', 'vertical-square' ),
				[ 'A3', '30x30' ],
				[ 'vertical', 'horizontal' ]
			)
		).toBe( false );
	} );

	it( 'sells only the vertical posters with only their designs', () => {
		expect(
			coversSizes(
				views,
				design( 'vertical-a', 'vertical-square' ),
				[ 'A3', '30x30' ],
				[ 'vertical' ]
			)
		).toBe( true );
		expect(
			coversSizes(
				views,
				design( 'vertical-a', 'vertical-square', 'horizontal-a' ),
				[ 'A3', '30x30' ],
				[ 'vertical' ]
			)
		).toBe( false );
	} );

	it( 'counts one design area per poster', () => {
		expect(
			usedAreas(
				views,
				design( ...all ),
				[ 'A3', '30x30' ],
				[ 'vertical', 'horizontal' ]
			)
		).toBe( 1 );
	} );

	it( 'gives a side images of its own orientation only', () => {
		expect( onColor( views, all, 'vertical' ) ).toEqual( [
			'vertical-a',
			'vertical-square',
		] );
		expect( onColor( views, [ 'gone' ], 'vertical' ) ).toEqual( [
			'gone',
		] );
		expect(
			submissionSize(
				design( 'vertical-a', 'horizontal-a' ),
				[ 'vertical', 'horizontal' ],
				views.map(
					( view ) =>
						( {
							...view,
							scene: null,
							embroidery: null,
						} ) as unknown as DesignerView
				)
			)
		).toBe( 4 );
	} );
} );
