/**
 * Tests for the finished product before a design is sent.
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { parsePreview } from '../api/designer';
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
 */
async function show(
	prepare: () => Promise< string | null >
): Promise< void > {
	const container = document.createElement( 'div' );

	document.body.append( container );

	await act( async () => {
		createRoot( container ).render(
			<FinishedProduct
				product={ 7 }
				views={ VIEWS }
				prepare={ prepare }
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

		expect( document.querySelector( 'figcaption' ) ).toBeNull();
		expect(
			document.querySelector( 'img[alt="Your finished product"]' )
		).not.toBeNull();
	} );

	it( 'stops when the files cannot be sent', async () => {
		await show( () => Promise.resolve( 'render' ) );

		expect( document.body.textContent ).toContain(
			'The photos could not be made'
		);
	} );
} );
