/**
 * Tests for placing and turning an element without dragging (phase 60).
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import type { ReactElement } from 'react';
import { createRoot } from 'react-dom/client';

import {
	NumberField,
	PlacementButtons,
} from '../sections/designer/ObjectPlacement';
import { aligned, objectBounds } from '../sections/designer/design-model';
import type { DesignObject } from '../sections/designer/design-model';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

/**
 * A rectangle of 200 × 100 at (500, 400).
 *
 * @param angle Its angle.
 */
function rect( angle = 0 ): DesignObject {
	return {
		type: 'rect',
		id: 'r',
		x: 500,
		y: 400,
		width: 200,
		height: 100,
		angle,
		scaleX: 2,
		scaleY: 2,
		flipX: false,
		flipY: false,
		opacity: 1,
		locked: false,
		hidden: false,
		fill: '#ff0000',
		stroke: null,
		strokeWidth: 0,
		radius: 0,
	};
}

/**
 * Renders a node.
 *
 * @param node The node.
 */
function render( node: ReactElement ) {
	const container = document.createElement( 'div' );

	document.body.append( container );
	const root = createRoot( container );

	act( () => root.render( node ) );

	return { container, root };
}

describe( 'placing an element (step 60.3)', () => {
	afterEach( () => {
		jest.useRealTimers();
		document.body.replaceChildren();
	} );

	it( 'measures the turned and scaled element', () => {
		expect( objectBounds( rect() ) ).toEqual( { width: 400, height: 200 } );

		const turned = objectBounds( rect( 90 ) );

		expect( turned.width ).toBeCloseTo( 200, 6 );
		expect( turned.height ).toBeCloseTo( 400, 6 );
	} );

	it( 'centres or puts it against an edge, keeping its size, angle and the other direction', () => {
		const object = rect( 90 );

		expect( aligned( object, 1000, 800, 'centre-x' ) ).toEqual( {
			x: 500,
			y: 400,
		} );
		expect( aligned( object, 1000, 800, 'centre-y' ) ).toEqual( {
			x: 500,
			y: 400,
		} );
		// Turned 90°: 200 wide and 400 high.
		expect( aligned( object, 1000, 800, 'left' ) ).toEqual( {
			x: 100,
			y: 400,
		} );
		expect( aligned( object, 1000, 800, 'right' ) ).toEqual( {
			x: 900,
			y: 400,
		} );
		expect( aligned( object, 1000, 800, 'top' ) ).toEqual( {
			x: 500,
			y: 200,
		} );
		expect( aligned( object, 1000, 800, 'bottom' ) ).toEqual( {
			x: 500,
			y: 600,
		} );
	} );

	it( 'counts the outline and stays within what the server takes', () => {
		// A shape's outline is not scaled: 400 × 200 plus 10.
		expect(
			aligned(
				{
					...rect(),
					stroke: '#000000',
					strokeWidth: 10,
				} as DesignObject,
				1000,
				800,
				'left'
			)
		).toEqual( { x: 205, y: 400 } );

		// A text's outline is scaled with it: (100 + 8) × 2.
		const text = {
			...rect(),
			type: 'text',
			width: 100,
			height: 40,
			stroke: '#ffffff',
			strokeWidth: 8,
		} as unknown as DesignObject;

		expect( aligned( text, 1000, 800, 'top' ) ).toEqual( {
			x: 500,
			y: 48,
		} );

		expect(
			aligned( { ...rect(), scaleX: 100 }, 1000, 800, 'right' ).x
		).toBe( -9000 );
		expect(
			aligned(
				{ ...rect(), width: 1000, scaleX: 100 },
				1000,
				800,
				'left'
			).x
		).toBe( 20000 );
	} );

	it( 'offers no placement for a locked element, only sideways for a locked clipart', () => {
		const onPlace = jest.fn();
		const { container } = render(
			<PlacementButtons disabled={ false } sideways onPlace={ onPlace } />
		);
		const enabled = Array.from(
			container.querySelectorAll< HTMLButtonElement >( 'button' )
		)
			.filter( ( button ) => ! button.disabled )
			.map( ( button ) => button.textContent );

		expect( enabled ).toEqual( [ 'Centre horizontally', 'Left', 'Right' ] );

		act( () =>
			container
				.querySelectorAll< HTMLButtonElement >( 'button' )[ 2 ]
				?.click()
		);
		expect( onPlace ).toHaveBeenCalledWith( 'left' );

		document.body.replaceChildren();
		const locked = render(
			<PlacementButtons disabled sideways={ false } onPlace={ onPlace } />
		);

		expect(
			Array.from(
				locked.container.querySelectorAll< HTMLButtonElement >(
					'button'
				)
			).every( ( button ) => button.disabled )
		).toBe( true );
	} );
} );

describe( 'a number field (steps 60.2, 60.4)', () => {
	afterEach( () => {
		jest.useRealTimers();
		document.body.replaceChildren();
	} );

	/**
	 * Types a value as the browser does.
	 *
	 * @param field The field.
	 * @param value The value.
	 */
	function type( field: HTMLInputElement, value: string ) {
		const setter = Object.getOwnPropertyDescriptor(
			HTMLInputElement.prototype,
			'value'
		)?.set;

		act( () => {
			setter?.call( field, value );
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
	}

	it( 'is one undo step, kept within its range, and ignores a half typed number', () => {
		jest.useFakeTimers();
		const onBegin = jest.fn();
		const onEnd = jest.fn();
		const onChange = jest.fn< ( value: number, live: boolean ) => void >();
		const { container } = render(
			<NumberField
				label="Angle (°)"
				value={ 0 }
				min={ -360 }
				max={ 360 }
				onBegin={ onBegin }
				onEnd={ onEnd }
				onChange={ onChange }
			/>
		);
		const field = container.querySelector< HTMLInputElement >( 'input' )!;

		type( field, '' );
		expect( onChange ).not.toHaveBeenCalled();

		type( field, '45' );
		type( field, '400' );
		act( () => {
			jest.advanceTimersByTime( 20 );
		} );
		act( () => {
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		expect( onBegin ).toHaveBeenCalledTimes( 1 );
		expect( onEnd ).toHaveBeenCalledTimes( 1 );
		expect( onChange ).toHaveBeenNthCalledWith( 1, 45, true );
		expect( onChange ).toHaveBeenLastCalledWith( 360, true );
	} );

	it( 'cannot change when it is disabled', () => {
		const { container } = render(
			<NumberField
				label="Angle (°)"
				value={ 12.345 }
				min={ -360 }
				max={ 360 }
				disabled
				onBegin={ () => undefined }
				onEnd={ () => undefined }
				onChange={ () => undefined }
			/>
		);
		const field = container.querySelector< HTMLInputElement >( 'input' )!;

		expect( field.disabled ).toBe( true );
		expect( field.value ).toBe( '12.3' );
	} );
} );
