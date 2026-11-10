import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseTwitchSubDiscount } from '../api/community';
import { ConfigContext } from '../app/config-context';
import { RouterProvider } from '../app/router';
import { TwitchSubDiscountSection } from '../sections/community/TwitchSubDiscountSection';
import { listResponse, mockApi, response } from '../testing/api';
import type { RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/community/twitch-sub-discount';
const PRODUCTS = '/merchboard/v1/me/products?page=1&per_page=100';

const SAVED = {
	enabled: true,
	tiers: { 1: 10, 2: 15, 3: 20 },
	gifted: true,
	scope: 'all',
	product_ids: [],
	channel: { login: 'nova' },
	twitch: true,
};

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

async function show(
	routes: Readonly< Record< string, Route > >
): Promise< { container: HTMLElement; requests: RecordedRequest[] } > {
	const requests = mockApi( routes );
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<RouterProvider>
				<TwitchSubDiscountSection />
			</RouterProvider>
		</ConfigContext.Provider>
	);
	await flush();
	await flush();

	return { container: rendered.container, requests };
}

async function submit( container: HTMLElement ): Promise< void > {
	const form = container.querySelector( '.merchboard-community-form' );

	act( () => {
		form?.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
	} );
	await flush();
	await flush();
}

describe( 'parseTwitchSubDiscount', () => {
	it( 'reads the documented fields', () => {
		expect( parseTwitchSubDiscount( SAVED ) ).toEqual( {
			enabled: true,
			tiers: { 1: 10, 2: 15, 3: 20 },
			gifted: true,
			scope: 'all',
			productIds: [],
			channel: 'nova',
			twitch: true,
		} );
	} );

	it( 'refuses anything else', () => {
		expect(
			parseTwitchSubDiscount( {
				...SAVED,
				tiers: { 1: 101, 2: 0, 3: 0 },
			} )
		).toBeNull();
		expect(
			parseTwitchSubDiscount( { ...SAVED, scope: 'some' } )
		).toBeNull();
		expect(
			parseTwitchSubDiscount( { ...SAVED, product_ids: [ 0 ] } )
		).toBeNull();
		expect(
			parseTwitchSubDiscount( {
				...SAVED,
				channel: { login: '<b>' },
			} )
		).toBeNull();
	} );
} );

describe( 'Twitch subscriber discount (ADR-106)', () => {
	it( 'saves every field and shows the connected channel', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, SAVED ),
			[ `POST ${ PATH }` ]: () => response( 200, SAVED ),
		} );

		expect( container.textContent ).toContain( 'Twitch channel: nova' );

		await submit( container );

		const saved = requests.find( ( request ) => request.method === 'POST' );
		expect( saved?.data ).toEqual( {
			enabled: true,
			tiers: { 1: 10, 2: 15, 3: 20 },
			gifted: true,
			scope: 'all',
			product_ids: [],
		} );
		expect( container.textContent ).toContain(
			'Twitch subscriber discount saved.'
		);
	} );

	it( 'asks to connect Twitch without a channel', async () => {
		const { container } = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { ...SAVED, enabled: false, channel: null } ),
		} );

		expect( container.textContent ).toContain(
			'Connect your Twitch channel'
		);
		expect(
			Array.from( container.querySelectorAll( 'button' ) ).some(
				( button ) => button.textContent === 'Connect Twitch'
			)
		).toBe( true );
	} );

	it( 'lets the creator choose products and shows field errors', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...SAVED,
					scope: 'selected',
					product_ids: [ 7 ],
				} ),
			[ `GET ${ PRODUCTS }` ]: () =>
				listResponse(
					[
						{
							id: 7,
							name: 'Hoodie',
							status: 'publish',
							price: null,
							stock_status: 'instock',
							image: null,
							profit: null,
							designer: false,
							removal_pending: false,
						},
						{
							id: 8,
							name: 'Mug',
							status: 'publish',
							price: null,
							stock_status: 'instock',
							image: null,
							profit: null,
							designer: false,
							removal_pending: false,
						},
					],
					2,
					1
				),
			[ `POST ${ PATH }` ]: () =>
				response( 400, {
					code: 'merchboard_invalid_twitch_sub_discount',
					message: 'Invalid.',
					data: { status: 400, fields: { tier_two: 'invalid' } },
				} ),
		} );
		await flush();

		expect( container.textContent ).toContain( '1 chosen' );

		click( container.querySelector( '#merchboard-twitch-sub-product-8' ) );
		await submit( container );

		const saved = requests.find( ( request ) => request.method === 'POST' );
		expect(
			( saved?.data as { product_ids: number[] } ).product_ids
		).toEqual( [ 7, 8 ] );
		expect( container.textContent ).toContain( 'Check this field.' );
	} );
} );
