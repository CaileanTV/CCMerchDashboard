/**
 * Tests for undo and redo in the overlay designer (phase 25, step 6.6).
 */
import { describe, expect, it } from '@jest/globals';

import { historyKey } from '../sections/alerts/OverlayDesigner';
import {
	HISTORY_LIMIT,
	checkpoint,
	record,
	redo,
	replace,
	settle,
	startHistory,
	undo,
} from '../sections/alerts/history';

describe( 'designer history', () => {
	it( 'undoes and redoes steps; a new step clears the redo steps', () => {
		let history = record( record( startHistory( 'a' ), 'b' ), 'c' );

		history = undo( history );
		expect( history.present ).toBe( 'b' );
		history = undo( history );
		expect( history.present ).toBe( 'a' );
		expect( undo( history ) ).toBe( history );

		history = redo( history );
		expect( history.present ).toBe( 'b' );

		history = record( history, 'd' );
		expect( history.future ).toEqual( [] );
		expect( redo( history ) ).toBe( history );
		expect( history.past ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'records nothing for an unchanged value', () => {
		const history = startHistory( 'a' );

		expect( record( history, 'a' ) ).toBe( history );
	} );

	it( 'makes a drag one step and drops an empty one', () => {
		let history = checkpoint( startHistory( 'a' ) );
		history = replace( history, 'a1' );
		history = replace( history, 'a2' );
		history = settle( history );

		expect( history.present ).toBe( 'a2' );
		expect( history.past ).toEqual( [ 'a' ] );
		expect( undo( history ).present ).toBe( 'a' );

		expect( settle( checkpoint( startHistory( 'b' ) ) ).past ).toEqual(
			[]
		);
	} );

	it( 'keeps at most the limit of steps', () => {
		let history = startHistory( 0 );

		for ( let step = 1; step <= HISTORY_LIMIT + 10; step++ ) {
			history = record( history, step );
		}

		expect( history.past ).toHaveLength( HISTORY_LIMIT );
	} );

	it( 'knows the shortcuts', () => {
		const key = ( init: KeyboardEventInit ) =>
			historyKey( new KeyboardEvent( 'keydown', init ) );

		expect( key( { key: 'z', ctrlKey: true } ) ).toBe( 'undo' );
		expect( key( { key: 'z', metaKey: true } ) ).toBe( 'undo' );
		expect( key( { key: 'Z', ctrlKey: true, shiftKey: true } ) ).toBe(
			'redo'
		);
		expect( key( { key: 'y', ctrlKey: true } ) ).toBe( 'redo' );
		expect( key( { key: 'z' } ) ).toBeNull();
		expect( key( { key: 'z', ctrlKey: true, altKey: true } ) ).toBeNull();
	} );
} );
