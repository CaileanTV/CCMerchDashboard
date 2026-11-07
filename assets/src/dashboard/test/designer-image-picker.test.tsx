/**
 * Tests for the choice of the product image (ADR-096): the chosen image
 * large, a gallery one side at a time, drawn when needed.
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import type { ReactElement } from 'react';
import { createRoot } from 'react-dom/client';

import { ImagePicker } from '../sections/designer/ImagePicker';
import type { ImageChoice } from '../sections/designer/ImagePicker';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const SIDES = [ 'Front', 'Back', 'Sleeve', 'Neck' ];
const COLORS = Array.from(
	{ length: 23 },
	( _, index ) => `Colour ${ index + 1 }`
);

/** 4 sides × 23 colours, as a big product can have. */
const CHOICES: ImageChoice[] = SIDES.flatMap( ( side ) =>
	COLORS.map( ( color, index ) => ( {
		value: `${ side.toLowerCase() }:c${ index + 1 }`,
		label: `${ color } – ${ side }`,
	} ) )
);

/**
 * Renders an element.
 *
 * @param element The element.
 */
async function show( element: ReactElement ): Promise< void > {
	const container = document.createElement( 'div' );

	document.body.append( container );

	await act( async () => {
		createRoot( container ).render( element );
		await Promise.resolve();
	} );
}

/** Lets timers and the drawing queue run. */
async function settle(): Promise< void > {
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 20 ) );
	} );
}

/**
 * A button by its text.
 *
 * @param text The text.
 */
function button( text: string ): HTMLButtonElement | undefined {
	return Array.from(
		document.querySelectorAll< HTMLButtonElement >( 'button' )
	).find( ( candidate ) => candidate.textContent === text );
}

describe( 'the product image picker', () => {
	afterEach( () => {
		document.body.replaceChildren();
	} );

	it( 'draws the chosen image large and only the side shown', async () => {
		const draw = jest.fn( ( value: string ) =>
			Promise.resolve( `data:image/png;base64,${ value }` )
		);
		const onSelect = jest.fn();

		await show(
			<ImagePicker
				choices={ CHOICES }
				selected="back:c5"
				onSelect={ onSelect }
				draw={ draw }
				drawKey={ 1 }
				delay={ 0 }
			/>
		);
		await settle();
		await settle();

		expect(
			document
				.querySelector( '.merchboard-image-picker__large img' )
				?.getAttribute( 'src' )
		).toBe( 'data:image/png;base64,back:c5' );
		expect(
			document.querySelectorAll(
				'.merchboard-product-designer__image-gallery button'
			)
		).toHaveLength( 23 );
		// The chosen one and the 23 of its side, never all 92.
		expect( draw ).toHaveBeenCalledTimes( 23 );
		expect(
			new Set(
				draw.mock.calls.map( ( call ) => call[ 0 ].split( ':' )[ 0 ] )
			)
		).toEqual( new Set( [ 'back' ] ) );

		await act( async () => {
			button( 'Sleeve' )?.click();
			await Promise.resolve();
		} );
		await settle();
		expect( draw ).toHaveBeenCalledTimes( 46 );

		const tile = document.querySelector< HTMLButtonElement >(
			'[title="Colour 2 – Sleeve"]'
		);

		await act( async () => {
			tile?.click();
			await Promise.resolve();
		} );
		expect( onSelect ).toHaveBeenCalledWith( 'sleeve:c2' );
	} );

	it( 'shows the chosen image in full size', async () => {
		await show(
			<ImagePicker
				choices={ [
					{
						value: 'front:black',
						label: 'Black – Front',
						url: 'https://shop.example/thumb.jpg',
						large: 'https://shop.example/large.jpg',
					},
					{
						value: 'front:white',
						label: 'White – Front',
						url: 'https://shop.example/white.jpg',
						large: null,
					},
				] }
				selected={ null }
				onSelect={ jest.fn() }
			/>
		);

		expect(
			document
				.querySelector( '.merchboard-image-picker__large img' )
				?.getAttribute( 'src' )
		).toBe( 'https://shop.example/large.jpg' );

		await act( async () => {
			document
				.querySelector< HTMLButtonElement >(
					'.merchboard-image-picker__large'
				)
				?.click();
			await Promise.resolve();
		} );

		expect(
			document
				.querySelector( '.merchboard-image-picker__full' )
				?.getAttribute( 'src' )
		).toBe( 'https://shop.example/large.jpg' );
		expect(
			Array.from(
				document.querySelectorAll< HTMLImageElement >(
					'.merchboard-product-designer__image-gallery img'
				)
			).map( ( image ) => image.getAttribute( 'loading' ) )
		).toEqual( [ 'lazy', 'lazy' ] );
	} );

	it( 'draws everything again when the design changes', async () => {
		const draw = jest.fn( ( value: string ) =>
			Promise.resolve( `data:image/png;base64,${ value }` )
		);
		const picker = ( key: number ) => (
			<ImagePicker
				choices={ CHOICES.slice( 0, 2 ) }
				selected={ null }
				onSelect={ jest.fn() }
				draw={ draw }
				drawKey={ key }
				delay={ 0 }
			/>
		);
		const container = document.createElement( 'div' );
		const root = createRoot( container );

		document.body.append( container );

		await act( async () => {
			root.render( picker( 1 ) );
			await Promise.resolve();
		} );
		await settle();
		expect( draw ).toHaveBeenCalledTimes( 2 );

		await act( async () => {
			root.render( picker( 2 ) );
			await Promise.resolve();
		} );
		await settle();
		await settle();
		expect( draw ).toHaveBeenCalledTimes( 4 );
	} );
} );
