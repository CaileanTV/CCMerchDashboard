/**
 * Tests for the designer's "Background" tab (ADR-095).
 */
import { describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import type { Root } from 'react-dom/client';

import { BackgroundPanel } from '../sections/designer/BackgroundPanel';
import type { BackgroundPanelProps } from '../sections/designer/BackgroundPanel';
import {
	EMPTY_DESIGN,
	withBackground,
	withKnownBackground,
} from '../sections/designer/design-model';

/**
 * Renders the panel.
 *
 * @param props Props over the defaults.
 */
function show( props: Partial< BackgroundPanelProps > = {} ): {
	container: HTMLElement;
	root: Root;
	props: BackgroundPanelProps;
} {
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	const all: BackgroundPanelProps = {
		current: null,
		side: 'Front',
		allowed: true,
		others: true,
		onApply: jest.fn(),
		onRemove: jest.fn(),
		...props,
	};

	document.body.append( container );
	act( () => root.render( <BackgroundPanel { ...all } /> ) );

	return { container, root, props: all };
}

/**
 * The button with a text or name.
 *
 * @param container Container.
 * @param name      Text or aria-label.
 */
function button( container: HTMLElement, name: string ): HTMLButtonElement {
	const found = Array.from( container.querySelectorAll( 'button' ) ).find(
		( candidate ) =>
			candidate.textContent === name ||
			candidate.getAttribute( 'aria-label' ) === name
	);

	if ( found === undefined ) {
		throw new Error( `No button ${ name }` );
	}

	return found;
}

/**
 * Types into an input as a person does.
 *
 * @param input The input.
 * @param value The text.
 */
function type( input: HTMLInputElement, value: string ): void {
	const setter = Object.getOwnPropertyDescriptor(
		HTMLInputElement.prototype,
		'value'
	)?.set;

	act( () => {
		setter?.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

describe( 'designer background tab', () => {
	it( 'applies a standard colour to all sides by default', () => {
		const { container, props } = show();

		act( () => button( container, 'Navy' ).click() );

		expect( props.onApply ).toHaveBeenCalledWith( '#1f2a44', true );
	} );

	it( 'applies to the side shown when "All sides" is off', () => {
		const { container, props } = show();
		const all = container.querySelector< HTMLInputElement >(
			'input[type="checkbox"]'
		);

		act( () => all?.click() );
		act( () => button( container, 'Remove background' ).click() );

		expect( props.onRemove ).toHaveBeenCalledWith( false );
	} );

	it( 'keeps the fields in step and refuses an invalid colour', () => {
		const { container, props } = show();
		const [ hex ] = Array.from(
			container.querySelectorAll< HTMLInputElement >(
				'input[type="text"]'
			)
		);
		const numbers = Array.from(
			container.querySelectorAll< HTMLInputElement >(
				'input[type="number"]'
			)
		);

		type( hex!, '#ff0000' );

		expect( numbers.map( ( input ) => input.value ) ).toEqual( [
			'255',
			'0',
			'0',
			'0',
			'100',
			'100',
			'0',
		] );

		type( hex!, '#ff00' );
		act( () => button( container, 'Use the colour' ).click() );

		expect( props.onApply ).not.toHaveBeenCalled();
		expect( container.textContent ).toContain( 'Invalid colour' );
	} );

	it( 'shows the colour of the side, also after another side or an undo', () => {
		const { container, root, props } = show( { current: '#c8102e' } );

		expect( container.textContent ).toContain(
			'Background on Front: #c8102e'
		);

		act( () =>
			root.render(
				<BackgroundPanel { ...props } current="#05707a" side="Back" />
			)
		);

		expect(
			container.querySelector< HTMLInputElement >( 'input[type="text"]' )
				?.value
		).toBe( '#05707a' );
	} );

	it( 'explains why an embroidered side has none', () => {
		const { container } = show( { allowed: false } );

		expect( container.textContent ).toContain(
			'Embroidered sides have no background.'
		);
		expect( container.querySelector( 'button' ) ).toBeNull();
	} );

	it( 'drops colours the base product cannot have', () => {
		const design = withBackground(
			EMPTY_DESIGN,
			[ 'front', 'chest' ],
			'#c8102e'
		);
		const views = [
			{ key: 'front', embroidery: null },
			{ key: 'chest', embroidery: {} },
		];

		expect( withKnownBackground( design, views, true ).background ).toEqual(
			{ front: '#c8102e' }
		);
		expect(
			withKnownBackground( design, views, false ).background
		).toBeUndefined();
		expect( withKnownBackground( EMPTY_DESIGN, views, false ) ).toBe(
			EMPTY_DESIGN
		);
	} );
} );
