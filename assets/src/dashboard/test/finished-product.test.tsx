/**
 * Tests for the finished product before a design is sent.
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { parsePreview } from '../api/designer';
import { shifted } from '../sections/designer/design-model';
import { FinishedProduct } from '../sections/designer/FinishedProduct';
import { mockApi, response } from '../testing/api';
import type { DesignerView } from '../api/designer';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const VIEWS = [ { key: 'front', name: 'Front print' } ] as DesignerView[];
const IMAGE = 'data:image/png;base64,AA';

/**
 * Renders the dialog and lets everything settle.
 *
 * @param prepare Sends the files.
 * @param order   The creator's order of the photos.
 * @param onOrder Called with a new order.
 */
async function show(
	prepare: () => Promise< string | null >,
	order: readonly number[] = [],
	onOrder: ( next: readonly number[] ) => void = jest.fn()
): Promise< void > {
	const container = document.createElement( 'div' );

	document.body.append( container );

	await act( async () => {
		createRoot( container ).render(
			<FinishedProduct
				product={ 7 }
				views={ VIEWS }
				prepare={ prepare }
				order={ order }
				onOrder={ onOrder }
				onClose={ jest.fn() }
			/>
		);
		await Promise.resolve();
	} );
}

describe( 'the finished product', () => {
	afterEach( () => {
		jest.useRealTimers();
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

	it( 'keeps only images it can show', () => {
		expect(
			parsePreview( {
				status: 'done',
				images: [
					{ side: 'front', color: 'white', url: IMAGE },
					{
						side: 'back',
						color: 'white',
						url: 'https://evil.example/x.png',
					},
				],
			} )?.images
		).toHaveLength( 1 );
		expect( parsePreview( { status: 'odd', images: [] } ) ).toBeNull();
	} );

	it( 'sends the files first and shows the photos', async () => {
		jest.useFakeTimers();

		const prepare = jest.fn( () => Promise.resolve( null ) );

		mockApi( {
			'POST /merchboard/v1/me/designer/products/7/preview': () =>
				response( 200, { status: 'pending' } ),
			'GET /merchboard/v1/me/designer/products/7/preview': () =>
				response( 200, {
					status: 'done',
					images: [ { side: 'front', color: 'white', url: IMAGE } ],
				} ),
		} );

		await show( prepare );
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 3100 );
		} );

		expect( prepare ).toHaveBeenCalledTimes( 1 );
		expect(
			document.querySelector( 'img[alt="Front print"]' )
		).not.toBeNull();
		expect(
			document.querySelector( '.merchboard-product-designer__spinner' )
		).toBeNull();
	} );

	it( 'shows that it is working while the photos are made', async () => {
		jest.useFakeTimers();
		mockApi( {
			'POST /merchboard/v1/me/designer/products/7/preview': () =>
				response( 200, { status: 'pending' } ),
			'GET /merchboard/v1/me/designer/products/7/preview': () =>
				response( 200, { status: 'pending', images: [] } ),
		} );

		await show( () => Promise.resolve( null ) );

		expect(
			document.querySelector( '.merchboard-product-designer__spinner' )
		).not.toBeNull();
		expect(
			document.querySelector( '[role="status"]' )?.textContent
		).toContain( 'We are making photos' );
	} );

	it( 'gives a chosen photo no side', async () => {
		jest.useFakeTimers();
		mockApi( {
			'POST /merchboard/v1/me/designer/products/7/preview': () =>
				response( 200, { status: 'pending' } ),
			'GET /merchboard/v1/me/designer/products/7/preview': () =>
				response( 200, {
					status: 'done',
					images: [
						{ side: '_photo-15710', color: 'white', url: IMAGE },
					],
				} ),
		} );

		await show( () => Promise.resolve( null ) );
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 3100 );
		} );

		expect( document.querySelector( 'figcaption' )?.textContent ).toBe(
			'Main image'
		);
		expect( document.querySelector( '[aria-label="Later"]' ) ).toBeNull();
	} );

	it( 'lets the creator choose the order of the photos', async () => {
		jest.useFakeTimers();
		mockApi( {
			'POST /merchboard/v1/me/designer/products/7/preview': () =>
				response( 200, { status: 'pending' } ),
			'GET /merchboard/v1/me/designer/products/7/preview': () =>
				response( 200, {
					status: 'done',
					images: [
						{ side: '_photo-1', color: 'white', url: IMAGE },
						{
							side: '_photo-2',
							color: 'white',
							url: `${ IMAGE }B`,
						},
						{
							side: '_photo-3',
							color: 'white',
							url: `${ IMAGE }C`,
						},
					],
				} ),
		} );

		const onOrder = jest.fn();

		await show( () => Promise.resolve( null ), [ 3 ], onOrder );
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 3100 );
		} );

		const sources = () =>
			Array.from( document.querySelectorAll( 'img' ) ).map( ( image ) =>
				image.getAttribute( 'src' )
			);

		expect( sources() ).toEqual( [ `${ IMAGE }C`, IMAGE, `${ IMAGE }B` ] );

		const later = document.querySelectorAll< HTMLButtonElement >(
			'[aria-label="Later"]'
		);

		await act( async () => {
			later[ 0 ]?.click();
			await Promise.resolve();
		} );

		expect( onOrder ).toHaveBeenCalledWith( [ 1, 3, 2 ] );
		expect( sources()[ 0 ] ).toBe( IMAGE );
	} );

	it( 'stops when the files cannot be sent', async () => {
		await show( () => Promise.resolve( 'render' ) );

		expect( document.body.textContent ).toContain(
			'The photos could not be made'
		);
	} );
} );
