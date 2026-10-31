/**
 * Tests for the list of fonts, where each is shown in its own font.
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { FontPicker } from '../components/FontPicker';
import type { FontChoice } from '../components/FontPicker';

const CHOICES: readonly FontChoice[] = [
	{ value: 'inherit', label: 'Default font', url: null },
	{
		value: 'Lobster',
		label: 'Lobster',
		url: 'https://fonts.googleapis.com/css2?family=Lobster',
	},
	{
		value: 'Roboto',
		label: 'Roboto',
		url: 'https://fonts.googleapis.com/css2?family=Roboto',
	},
	{ value: 'Gone', label: 'Gone', url: null, disabled: true },
];

/**
 * Shows the picker.
 *
 * @param onChange Called with a new choice.
 */
function show( onChange: ( value: string ) => void ): HTMLElement {
	const holder = document.createElement( 'div' );

	document.body.append( holder );
	act( () => {
		createRoot( holder ).render(
			<FontPicker
				value="Lobster"
				choices={ CHOICES }
				onChange={ onChange }
				aria-label="Font"
			/>
		);
	} );

	return holder;
}

/**
 * Clicks an element.
 *
 * @param element The element.
 */
function click( element: Element | null ): void {
	act( () => {
		( element as HTMLElement ).click();
	} );
}

/**
 * The stylesheets on the page.
 */
function sheets(): string[] {
	return Array.from(
		document.head.querySelectorAll< HTMLLinkElement >(
			'link[data-merchboard-font]'
		)
	).map( ( link ) => link.href );
}

describe( 'the font picker', () => {
	afterEach( () => {
		document.body.replaceChildren();
		document.head.replaceChildren();
	} );

	it( 'shows the chosen font in that font and fetches only it until opened', () => {
		const holder = show( jest.fn() );
		const button = holder.querySelector( 'button' ) as HTMLElement;

		expect( button.textContent ).toBe( 'Lobster' );
		expect( button.style.fontFamily ).toContain( 'Lobster' );
		expect( sheets() ).toHaveLength( 1 );
	} );

	it( 'shows each font in its own font when it is opened', () => {
		const holder = show( jest.fn() );

		click( holder.querySelector( 'button' ) );

		const options = Array.from(
			holder.querySelectorAll< HTMLElement >( '[role="option"]' )
		);

		expect( options.map( ( option ) => option.textContent ) ).toEqual( [
			'Default font',
			'Lobster',
			'Roboto',
			'Gone',
		] );
		expect( options[ 2 ]?.style.fontFamily ).toContain( 'Roboto' );
		expect( options[ 0 ]?.style.fontFamily ).toBe( '' );
		expect( sheets() ).toHaveLength( 2 );
		expect( holder.ownerDocument.activeElement ).toBe( options[ 1 ] );
	} );

	it( 'saves a choice and closes, but not an unavailable font', () => {
		const onChange = jest.fn();
		const holder = show( onChange );

		click( holder.querySelector( 'button' ) );
		click( holder.querySelector( '[data-value="Gone"]' ) );

		expect( onChange ).not.toHaveBeenCalled();
		expect( holder.querySelector( '[role="listbox"]' ) ).not.toBeNull();

		click( holder.querySelector( '[data-value="Roboto"]' ) );

		expect( onChange ).toHaveBeenCalledWith( 'Roboto' );
		expect( holder.querySelector( '[role="listbox"]' ) ).toBeNull();
	} );

	it( 'closes with Escape and moves with the arrow keys past what cannot be chosen', () => {
		const holder = show( jest.fn() );

		click( holder.querySelector( 'button' ) );

		const list = holder.querySelector( '[role="listbox"]' ) as HTMLElement;
		const press = ( key: string ) =>
			act( () => {
				holder.ownerDocument.activeElement?.dispatchEvent(
					new KeyboardEvent( 'keydown', { key, bubbles: true } )
				);
			} );

		press( 'ArrowDown' );
		expect( holder.ownerDocument.activeElement?.textContent ).toBe(
			'Roboto'
		);

		press( 'ArrowDown' );
		expect( holder.ownerDocument.activeElement?.textContent ).toBe(
			'Roboto'
		);

		press( 'Escape' );
		expect( list.isConnected ).toBe( false );
		expect( holder.ownerDocument.activeElement ).toBe(
			holder.querySelector( 'button' )
		);
	} );
} );
