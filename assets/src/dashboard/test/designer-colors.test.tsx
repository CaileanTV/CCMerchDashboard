/**
 * Tests for the creator's choice and order of colours.
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { shifted } from '../sections/designer/design-model';
import { ProductPanel } from '../sections/designer/DesignerEditor';
import type { DesignerBase } from '../api/designer';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const BASE = {
	name: 'Tee',
	sizes: [ 'M' ],
	stitches: [ 'white', 'black' ],
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
 * @param base     The base product.
 * @param onStitch Called with the new stitch colour.
 */
async function show(
	colors: readonly string[],
	onColors: ( value: readonly string[] ) => void,
	base: DesignerBase = BASE,
	onStitch: ( value: string ) => void = jest.fn()
): Promise< void > {
	const container = document.createElement( 'div' );

	document.body.append( container );

	await act( async () => {
		createRoot( container ).render(
			<ProductPanel
				base={ base }
				locale="da-DK"
				creator="Nova"
				title=""
				colors={ colors }
				sizes={ [ 'M' ] }
				onTitle={ jest.fn() }
				onColors={ onColors }
				onSizes={ jest.fn() }
				stitch="white"
				onStitch={ onStitch }
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

	it( 'moves an item within its list', () => {
		expect( shifted( [ 'a', 'b', 'c' ], 2, -1 ) ).toEqual( [
			'a',
			'c',
			'b',
		] );
		expect( shifted( [ 'a', 'b' ], 0, -1 ) ).toEqual( [ 'a', 'b' ] );
		expect( shifted( [ 'a', 'b' ], 1, 1 ) ).toEqual( [ 'a', 'b' ] );
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

	it( 'offers the stitching of an all-over product', async () => {
		const onStitch = jest.fn();

		await show( [ 'black' ], jest.fn(), BASE, onStitch );

		const radios = document.querySelectorAll< HTMLInputElement >(
			'input[type="radio"]'
		);

		expect( radios ).toHaveLength( 2 );
		expect( radios[ 0 ]?.checked ).toBe( true );

		await act( async () => {
			radios[ 1 ]?.click();
			await Promise.resolve();
		} );

		expect( onStitch ).toHaveBeenCalledWith( 'black' );
	} );

	it( 'offers no stitching without a choice', async () => {
		await show( [ 'black' ], jest.fn(), {
			...BASE,
			stitches: [ 'black' ],
		} );

		expect( document.querySelector( 'input[type="radio"]' ) ).toBeNull();
	} );

	it( 'has no order with one colour', async () => {
		await show( [ 'black' ], jest.fn() );

		expect( document.querySelector( 'ol' ) ).toBeNull();
	} );
} );
