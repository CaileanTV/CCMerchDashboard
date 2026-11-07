/**
 * Tests for colour designs: some colours with a design of their own (ADR-096).
 */
import { describe, expect, it } from '@jest/globals';

import {
	designNumber,
	effectiveParts,
	MAX_DESIGNS,
	moveColor,
	newColorDesign,
	newShape,
	parseDesign,
	partDoc,
	partOf,
	prunedDesigns,
	SHARED,
	withObjects,
	withPart,
} from '../sections/designer/design-model';
import type { DesignDocument } from '../sections/designer/design-model';

const RECT = { ...newShape( 'rect', 1000, 1000 ), id: 'shared-rect' };
const STAR = { ...newShape( 'triangle', 1000, 1000 ), id: 'star' };

const SHARED_ONLY: DesignDocument = {
	version: 1,
	sides: { front: [ RECT ] },
	stitch: 'white',
};

describe( 'colour designs', () => {
	it( 'reads a design from before without colour designs', () => {
		const parsed = parseDesign( { version: 1, sides: { front: [] } } );

		expect( parsed ).toEqual( { version: 1, sides: { front: [] } } );
		expect( parsed && 'designs' in parsed ).toBe( false );
	} );

	it( 'reads colour designs and refuses a bad one', () => {
		const parsed = parseDesign( {
			version: 1,
			sides: {},
			designs: [
				{ id: 'd1', colors: [ 'orange' ], sides: { back: [] } },
			],
		} );

		expect( parsed?.designs ).toEqual( [
			{ id: 'd1', colors: [ 'orange' ], sides: { back: [] } },
		] );
		expect(
			parseDesign( {
				version: 1,
				sides: {},
				designs: [ { id: 'orange', colors: [], sides: {} } ],
			} )
		).toBeNull();
		expect(
			parseDesign( {
				version: 1,
				sides: {},
				designs: [ { id: 'd1', colors: [ 1 ], sides: {} } ],
			} )
		).toBeNull();
	} );

	it( 'splits a colour into a copy of its design and edits it on its own', () => {
		const split = newColorDesign( SHARED_ONLY, 'orange' );

		expect( split?.id ).toBe( 'd1' );
		expect( partOf( split!.design, 'orange' ) ).toBe( 'd1' );
		expect( partOf( split!.design, 'black' ) ).toBe( SHARED );
		expect( partDoc( split!.design, 'd1' ).sides ).toEqual(
			SHARED_ONLY.sides
		);
		expect( partDoc( split!.design, 'd1' ).stitch ).toBe( 'white' );

		const edited = withPart(
			split!.design,
			'd1',
			withObjects( partDoc( split!.design, 'd1' ), 'back', [ STAR ] )
		);

		expect( partDoc( edited, 'd1' ).sides.back ).toEqual( [ STAR ] );
		expect( partDoc( edited, SHARED ).sides ).toEqual( SHARED_ONLY.sides );
		expect( designNumber( edited, 'd1' ) ).toBe( 2 );
		expect( designNumber( edited, SHARED ) ).toBe( 1 );
	} );

	it( 'edits the shared design for every colour that uses it', () => {
		const split = newColorDesign( SHARED_ONLY, 'orange' )!.design;
		const edited = withPart(
			split,
			SHARED,
			withObjects( partDoc( split, SHARED ), 'front', [] )
		);

		expect( edited.sides.front ?? [] ).toEqual( [] );
		expect( edited.stitch ).toBe( 'white' );
		expect( edited.designs ).toEqual( split.designs );
	} );

	it( 'lists the designs in use with their colours, the shared first', () => {
		const one = newColorDesign( SHARED_ONLY, 'orange' )!.design;
		const two = newColorDesign( one, 'purple' )!;

		expect( two.id ).toBe( 'd2' );
		expect(
			effectiveParts( two.design, [ 'purple', 'black', 'orange' ] )
		).toEqual( [
			{ id: SHARED, colors: [ 'black' ] },
			{ id: 'd1', colors: [ 'orange' ] },
			{ id: 'd2', colors: [ 'purple' ] },
		] );
		expect( effectiveParts( two.design, [ 'orange' ] ) ).toEqual( [
			{ id: 'd1', colors: [ 'orange' ] },
		] );
	} );

	it( 'joins colours in one design and asks how to merge', () => {
		const one = newColorDesign( SHARED_ONLY, 'orange' )!.design;
		const mine = withPart(
			one,
			'd1',
			withObjects( partDoc( one, 'd1' ), 'front', [ RECT, STAR ] )
		);
		const purple = newColorDesign( mine, 'purple' )!.design;

		// Purple joins orange's design and takes it.
		const target = moveColor( purple, 'purple', 'd1', 'target' );
		expect( partOf( target, 'purple' ) ).toBe( 'd1' );
		expect( target.designs?.map( ( item ) => item.id ) ).toEqual( [
			'd1',
		] );

		// Orange goes back to the shared design with its own layers on top.
		const both = moveColor( mine, 'orange', SHARED, 'both' );
		expect( both.designs ).toBeUndefined();
		expect( both.sides.front?.map( ( object ) => object.type ) ).toEqual( [
			'rect',
			'rect',
			'triangle',
		] );
		expect(
			new Set( both.sides.front?.map( ( object ) => object.id ) ).size
		).toBe( 3 );

		// Orange's design replaces the shared one for every colour.
		const replaced = moveColor( mine, 'orange', SHARED, 'mine' );
		expect( replaced.sides.front ).toEqual( [ RECT, STAR ] );
		expect( replaced.designs ).toBeUndefined();
		expect( replaced.stitch ).toBe( 'white' );

		// Back to the shared design as it is.
		expect( moveColor( mine, 'orange', SHARED, 'target' ) ).toEqual(
			SHARED_ONLY
		);
	} );

	it( 'has at most eight colour designs', () => {
		let design = SHARED_ONLY;

		for ( let index = 0; index < MAX_DESIGNS; index++ ) {
			design = newColorDesign( design, `c${ index }` )!.design;
		}

		expect( design.designs ).toHaveLength( MAX_DESIGNS );
		expect( newColorDesign( design, 'one-more' ) ).toBeNull();
	} );

	it( 'drops colours that are not chosen and designs left without colours', () => {
		const one = newColorDesign( SHARED_ONLY, 'orange' )!.design;
		const two = newColorDesign( one, 'purple' )!.design;
		const pruned = prunedDesigns( two, [ 'black', 'purple' ] );

		expect( pruned.designs?.map( ( item ) => item.id ) ).toEqual( [
			'd2',
		] );
		expect( prunedDesigns( two, [ 'black' ] ) ).toEqual( SHARED_ONLY );
	} );
} );
