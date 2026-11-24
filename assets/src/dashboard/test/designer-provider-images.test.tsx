/**
 * Tests for choosing the main image among the finished product's real
 * photos (step 57.22).
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { parsePreview } from '../api/designer';
import {
	ProviderImages,
	providerColor,
} from '../sections/designer/ProviderImages';
import type { RecordedRequest } from '../testing/api';
import { mockApi, response } from '../testing/api';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const IMAGE = 'data:image/png;base64,AA';
const PATH = '/merchboard/v1/me/designer/products/7/preview';
const COLORS = [
	{ key: 'lime', name: 'Lime' },
	{ key: 'magenta', name: 'Magenta' },
];

/**
 * Printify's answer for the colour last started.
 *
 * @param requests The requests so far.
 */
function photos( requests: RecordedRequest[] ) {
	const started = requests.filter( ( request ) => request.method === 'POST' );
	const color =
		( started.at( -1 )?.data as { color?: string } | undefined )?.color ??
		'lime';

	return response( 200, {
		status: 'done',
		images: [
			{
				side: 'all',
				color,
				url: IMAGE,
				choice: '_view-front',
				label: 'Front',
			},
			{
				side: 'all',
				color,
				url: IMAGE,
				choice: '_view-left-side',
				label: 'Left side',
			},
		],
	} );
}

/**
 * Renders the choice and lets the first photos come.
 *
 * @param selected The main image so far.
 * @param prepare  Sends the files.
 */
async function show(
	selected: string | null,
	prepare: () => Promise< string | null > = () => Promise.resolve( null )
) {
	const onSelect = jest.fn< ( value: string ) => void >();
	const container = document.createElement( 'div' );

	document.body.append( container );

	await act( async () => {
		createRoot( container ).render(
			<ProviderImages
				product={ 7 }
				colors={ COLORS }
				prepare={ prepare }
				selected={ selected }
				onSelect={ onSelect }
				pollMs={ 10 }
				fallback={ <p className="fallback">Drawn</p> }
			/>
		);
		await Promise.resolve();
	} );
	await act( async () => {
		await jest.advanceTimersByTimeAsync( 50 );
	} );

	return { container, onSelect };
}

describe( 'the main image among the finished product’s photos', () => {
	afterEach( () => {
		jest.useRealTimers();
		document.body.replaceChildren();
	} );

	it( 'reads a choice only with its name and of a main image’s form', () => {
		const images = parsePreview( {
			status: 'done',
			images: [
				{
					side: 'all',
					color: 'lime',
					url: IMAGE,
					choice: '_view-front',
					label: 'Front',
				},
				{ side: 'all', color: 'lime', url: IMAGE, choice: '_view-x' },
				{
					side: 'all',
					color: 'lime',
					url: IMAGE,
					choice: 'bad:key',
					label: 'Odd',
				},
			],
		} )?.images;

		expect( images?.map( ( image ) => image.choice ?? null ) ).toEqual( [
			'_view-front',
			null,
			null,
		] );
		expect( providerColor( '_view-left-side:magenta' ) ).toBe( 'magenta' );
		expect( providerColor( '_photo-15710:white' ) ).toBe( 'white' );
		expect( providerColor( 'front:lime' ) ).toBeNull();
		expect( providerColor( null ) ).toBeNull();
	} );

	it( 'shows the views of a colour, named, and the shop gets what is shown', async () => {
		jest.useFakeTimers();
		const requests = mockApi( {
			[ `POST ${ PATH }` ]: () => response( 200, { status: 'pending' } ),
			[ `GET ${ PATH }` ]: () => photos( requests ),
		} );
		const prepare = jest.fn( () => Promise.resolve( null ) );
		const { container, onSelect } = await show( 'front:lime', prepare );

		expect( prepare ).toHaveBeenCalledTimes( 1 );
		expect(
			requests.find( ( request ) => request.method === 'POST' )?.data
		).toEqual( { color: 'lime' } );
		expect(
			Array.from( container.querySelectorAll( 'img' ) ).map(
				( image ) => image.alt
			)
		).toEqual(
			expect.arrayContaining( [ 'Lime – Front', 'Lime – Left side' ] )
		);
		// A drawn choice of this colour becomes its first view.
		expect( onSelect ).toHaveBeenLastCalledWith( '_view-front:lime' );

		const left = container.querySelector< HTMLButtonElement >(
			'button[aria-label="Lime – Left side"], button[title="Lime – Left side"]'
		);
		const tiles = Array.from(
			container.querySelectorAll< HTMLButtonElement >(
				'.merchboard-product-designer__image-gallery button'
			)
		);

		act( () => ( left ?? tiles[ 1 ] )?.click() );
		expect( onSelect ).toHaveBeenLastCalledWith( '_view-left-side:lime' );
	} );

	it( 'makes another colour’s photos once, when it is chosen', async () => {
		jest.useFakeTimers();
		const requests = mockApi( {
			[ `POST ${ PATH }` ]: () => response( 200, { status: 'pending' } ),
			[ `GET ${ PATH }` ]: () => photos( requests ),
		} );
		const prepare = jest.fn( () => Promise.resolve( null ) );
		const { container, onSelect } = await show(
			'_view-left-side:lime',
			prepare
		);

		// A view already chosen stays.
		expect( onSelect ).not.toHaveBeenCalled();

		const magenta = Array.from(
			container.querySelectorAll< HTMLButtonElement >( 'button' )
		).find( ( button ) => button.textContent === 'Magenta' );

		await act( async () => {
			magenta?.click();
			await jest.advanceTimersByTimeAsync( 50 );
		} );

		const starts = requests.filter(
			( request ) => request.method === 'POST'
		);

		expect( starts.map( ( request ) => request.data ) ).toEqual( [
			{ color: 'lime' },
			{ color: 'magenta' },
		] );
		expect( prepare ).toHaveBeenCalledTimes( 1 );
		expect(
			container.querySelector( 'img[alt="Magenta – Left side"]' )
		).not.toBeNull();
		// The choice of another colour is kept.
		expect( onSelect ).not.toHaveBeenCalled();

		// Back to lime: its photos are not made again.
		const lime = Array.from(
			container.querySelectorAll< HTMLButtonElement >( 'button' )
		).find( ( button ) => button.textContent === 'Lime' );

		await act( async () => {
			lime?.click();
			await jest.advanceTimersByTimeAsync( 50 );
		} );
		expect(
			requests.filter( ( request ) => request.method === 'POST' )
		).toHaveLength( 2 );
	} );

	it( 'offers the drawn images when the photos cannot be made', async () => {
		jest.useFakeTimers();
		mockApi( {
			[ `POST ${ PATH }` ]: () =>
				response( 429, {
					code: 'preview_quota',
					data: { reason: 'preview_quota' },
				} ),
		} );

		const { container, onSelect } = await show( null );

		expect( container.querySelector( '.fallback' ) ).not.toBeNull();
		expect( container.textContent ).toContain(
			'You have made the most photos for today'
		);
		expect( onSelect ).not.toHaveBeenCalled();
	} );

	it( 'offers the drawn images when the files cannot be sent', async () => {
		jest.useFakeTimers();
		const requests = mockApi( {} );
		const { container } = await show( null, () =>
			Promise.resolve( 'save' )
		);

		expect( container.querySelector( '.fallback' ) ).not.toBeNull();
		expect( requests ).toHaveLength( 0 );
	} );
} );
