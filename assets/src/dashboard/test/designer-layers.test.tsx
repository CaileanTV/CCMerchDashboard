/**
 * Tests for the designer's box of layers.
 */
import { describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import type { DesignObject } from '../sections/designer/design-model';
import { LayerList } from '../sections/designer/LayerList';

const shape = ( id: string, hidden = false ): DesignObject => ( {
	type: 'rect',
	id,
	x: 0,
	y: 0,
	width: 10,
	height: 10,
	angle: 0,
	scaleX: 1,
	scaleY: 1,
	flipX: false,
	flipY: false,
	opacity: 1,
	locked: false,
	hidden,
	fill: '#000000',
	stroke: null,
	strokeWidth: 0,
	radius: 0,
} );

/**
 * Renders the box.
 *
 * @param objects  Objects, bottom first.
 * @param onChange Changes an object.
 * @param onMove   Moves an object.
 */
function render(
	objects: readonly DesignObject[],
	onChange: (
		id: string,
		change: Partial< DesignObject >
	) => void = jest.fn(),
	onMove: ( id: string, direction: 1 | -1 ) => void = jest.fn()
): HTMLElement {
	const container = document.createElement( 'div' );

	document.body.append( container );
	act( () => {
		createRoot( container ).render(
			<LayerList
				objects={ objects }
				selected="b"
				onSelect={ jest.fn() }
				onChange={ onChange }
				onMove={ onMove }
				onRemove={ jest.fn() }
			/>
		);
	} );

	return container;
}

const buttons = ( row: Element | undefined, label: string ) =>
	row?.querySelector< HTMLButtonElement >(
		`button[aria-label="${ label }"]`
	);

describe( 'the box of layers', () => {
	it( 'says so when a side has no elements', () => {
		expect( render( [] ).textContent ).toContain(
			'This side has no elements yet.'
		);
	} );

	it( 'shows the front layer first and cannot move past the ends', () => {
		const onMove = jest.fn();
		const rows = render(
			[ shape( 'a' ), shape( 'b' ) ],
			jest.fn(),
			onMove
		).querySelectorAll( 'li' );

		expect( rows ).toHaveLength( 2 );
		expect( rows[ 0 ]?.className ).toBe( 'is-selected' );
		expect( buttons( rows[ 0 ], 'Forward' )?.disabled ).toBe( true );
		expect( buttons( rows[ 0 ], 'Backward' )?.disabled ).toBe( false );
		expect( buttons( rows[ 1 ], 'Backward' )?.disabled ).toBe( true );

		act( () => buttons( rows[ 1 ], 'Forward' )?.click() );

		expect( onMove ).toHaveBeenCalledWith( 'a', 1 );
	} );

	it( 'hides and shows an element with its button', () => {
		const onChange = jest.fn();
		const rows = render(
			[ shape( 'a', true ) ],
			onChange
		).querySelectorAll( 'li' );

		act( () => buttons( rows[ 0 ], 'Show' )?.click() );

		expect( onChange ).toHaveBeenCalledWith( 'a', { hidden: false } );
	} );
} );
