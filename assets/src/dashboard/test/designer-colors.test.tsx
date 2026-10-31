/**
 * Tests for the creator's choice and order of colours.
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { ProductPanel } from '../sections/designer/DesignerEditor';
import type { DesignerBase } from '../api/designer';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const BASE = {
	name: 'Tee',
	sizes: [ 'M' ],
	colors: [
		{ key: 'black', name: 'Black', hex: '#111111' },
		{ key: 'sand', name: 'Sand', hex: '#d8c8a8' },
		{ key: 'red', name: 'Red', hex: '#ff0000' },
	],
} as unknown as DesignerBase;

/**
 * Renders the panel.
 *
 * @param colors   The chosen colours.
 * @param onColors Called with the new colours.
 */
async function show(
	colors: readonly string[],
	onColors: ( value: readonly string[] ) => void
): Promise< void > {
	const container = document.createElement( 'div' );

	document.body.append( container );

	await act( async () => {
		createRoot( container ).render(
			<ProductPanel
				base={ BASE }
				locale="da-DK"
				creator="Nova"
				title=""
				colors={ colors }
				sizes={ [ 'M' ] }
				onTitle={ jest.fn() }
				onColors={ onColors }
				onSizes={ jest.fn() }
			/>
		);
		await Promise.resolve();
	} );
}

/**
 * Clicks a control by its name.
 *
 * @param selector The control.
 */
function click( selector: string ): void {
	document.querySelector< HTMLElement >( selector )?.click();
}

describe( 'the colours of a design', () => {
	afterEach( () => {
		document.body.replaceChildren();
	} );

	it( 'lists the chosen colours in the creator’s order', async () => {
		await show( [ 'sand', 'black' ], jest.fn() );

		expect(
			Array.from( document.querySelectorAll( 'ol li' ) ).map(
				( row ) => row.textContent
			)
		).toEqual( [ 'Sand', 'Black' ] );
		expect(
			document
				.querySelector( '[aria-label="Move Sand up"]' )
				?.hasAttribute( 'disabled' )
		).toBe( true );
	} );

	it( 'moves a colour and puts a new one last', async () => {
		const onColors = jest.fn();

		await show( [ 'black', 'sand' ], onColors );
		await act( async () => {
			click( '[aria-label="Move Sand up"]' );
			await Promise.resolve();
		} );

		expect( onColors ).toHaveBeenLastCalledWith( [ 'sand', 'black' ] );

		const boxes = document.querySelectorAll< HTMLInputElement >(
			'input[type="checkbox"]'
		);

		await act( async () => {
			boxes[ 2 ]?.click();
			await Promise.resolve();
		} );

		expect( onColors ).toHaveBeenLastCalledWith( [
			'black',
			'sand',
			'red',
		] );
	} );

	it( 'has no order with one colour', async () => {
		await show( [ 'black' ], jest.fn() );

		expect( document.querySelector( 'ol' ) ).toBeNull();
	} );
} );
