import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { maxPercent, parseTwitchSubDiscount } from '../api/community';
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

const ALL = {
	id: 'aaaaaaaaaaaaaaaa',
	name: 'Alle subs',
	enabled: true,
	tiers: { 1: 10, 2: 15, 3: 16.67 },
	scope: 'all',
	product_ids: [],
	starts: null,
	ends: null,
};

const SAVED = {
	enabled: true,
	gifted: true,
	campaigns: [ ALL ],
	profit_percents: { 7: 25, 8: 16.67 },
	channel: { login: 'nova' },
	twitch: true,
};

const PRODUCT = {
	status: 'publish',
	price: null,
	stock_status: 'instock',
	image: null,
	profit: null,
	designer: false,
	removal_pending: false,
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

function button( container: HTMLElement, text: string ): Element | null {
	return (
		Array.from( container.querySelectorAll( 'button' ) ).find(
			( candidate ) => candidate.textContent === text
		) ?? null
	);
}

describe( 'parseTwitchSubDiscount', () => {
	it( 'reads the documented fields', () => {
		expect( parseTwitchSubDiscount( SAVED ) ).toEqual( {
			enabled: true,
			gifted: true,
			campaigns: [
				{
					id: 'aaaaaaaaaaaaaaaa',
					name: 'Alle subs',
					enabled: true,
					tiers: { 1: 10, 2: 15, 3: 16.67 },
					scope: 'all',
					productIds: [],
					starts: '',
					ends: '',
				},
			],
			profitPercents: { 7: 25, 8: 16.67 },
			channel: 'nova',
			twitch: true,
		} );
	} );

	it( 'refuses anything else', () => {
		expect(
			parseTwitchSubDiscount( {
				...SAVED,
				campaigns: [ { ...ALL, tiers: { 1: 101, 2: 0, 3: 0 } } ],
			} )
		).toBeNull();
		expect(
			parseTwitchSubDiscount( {
				...SAVED,
				campaigns: [ { ...ALL, tiers: { 1: 10.555, 2: 0, 3: 0 } } ],
			} )
		).toBeNull();
		expect(
			parseTwitchSubDiscount( {
				...SAVED,
				campaigns: [ { ...ALL, starts: '10-11-2026' } ],
			} )
		).toBeNull();
		expect(
			parseTwitchSubDiscount( { ...SAVED, profit_percents: { x: 5 } } )
		).toBeNull();
		expect(
			parseTwitchSubDiscount( { ...SAVED, enabled: undefined } )
		).toBeNull();
		expect(
			parseTwitchSubDiscount( {
				...SAVED,
				channel: { login: '<b>' },
			} )
		).toBeNull();
	} );

	it( 'gives the lowest profit percent as the most a campaign may give', () => {
		const percents = { 7: 25, 8: 16.67 };

		expect( maxPercent( { scope: 'all', productIds: [] }, percents ) ).toBe(
			16.67
		);
		expect(
			maxPercent( { scope: 'selected', productIds: [ 7 ] }, percents )
		).toBe( 25 );
		expect(
			maxPercent( { scope: 'selected', productIds: [] }, {} )
		).toBeNull();
	} );
} );

describe( 'Twitch subscriber discount campaigns (ADR-106)', () => {
	it( 'saves every campaign and shows the most it may give', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, SAVED ),
			[ `POST ${ PATH }` ]: () => response( 200, SAVED ),
		} );

		expect( container.textContent ).toContain( 'Connected as nova' );
		expect( container.textContent ).toContain(
			'This feature needs our bot connected to your Twitch channel.'
		);
		expect( container.textContent ).toContain( 'At most 16,67 %' );

		await submit( container );

		const saved = requests.find( ( request ) => request.method === 'POST' );
		expect( saved?.data ).toEqual( {
			enabled: true,
			gifted: true,
			campaigns: [
				{
					id: 'aaaaaaaaaaaaaaaa',
					name: 'Alle subs',
					enabled: true,
					tiers: { 1: 10, 2: 15, 3: 16.67 },
					scope: 'all',
					product_ids: [],
					starts: '',
					ends: '',
				},
			],
		} );
		expect( container.textContent ).toContain(
			'Twitch subscriber discount saved.'
		);
	} );

	it( 'asks to connect Twitch without a channel', async () => {
		const { container } = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { ...SAVED, campaigns: [], channel: null } ),
		} );

		expect( container.textContent ).toContain(
			'Your Twitch channel is not connected yet.'
		);
		expect( button( container, 'Connect Twitch' ) ).not.toBeNull();
		expect( container.textContent ).toContain(
			'You have no campaigns yet.'
		);
	} );

	it( 'connects Twitch from this page and comes back to it', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { ...SAVED, campaigns: [], channel: null } ),
			// Refused here, so the test stays on the page (no navigation).
			'POST /merchboard/v1/me/alerts/chatbot/twitch/connect': () =>
				response( 409, {
					code: 'merchboard_chatbot_unavailable',
					message: 'The chatbot is not set up yet.',
					data: { status: 409 },
				} ),
		} );

		click( button( container, 'Connect Twitch' ) );
		await flush();
		await flush();

		const connect = requests.find( ( request ) =>
			request.path.endsWith( '/twitch/connect' )
		);
		expect( connect?.data ).toEqual( {
			return: 'community/twitch-discount',
		} );
		expect( container.textContent ).toContain( 'Something went wrong.' );
	} );

	it( 'switches the whole discount off', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, SAVED ),
			[ `POST ${ PATH }` ]: () =>
				response( 200, { ...SAVED, enabled: false } ),
		} );

		click( container.querySelector( '#merchboard-twitch-sub-enabled' ) );
		expect( container.textContent ).toContain(
			'none of your campaigns gives a discount'
		);
		await submit( container );

		const saved = requests.find( ( request ) => request.method === 'POST' );
		expect( saved?.data ).toMatchObject( { enabled: false } );
	} );

	it( 'adds a campaign of chosen products and shows its field errors', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, SAVED ),
			[ `GET ${ PRODUCTS }` ]: () =>
				listResponse(
					[
						{ ...PRODUCT, id: 7, name: 'Hoodie' },
						{ ...PRODUCT, id: 8, name: 'Mug' },
					],
					2,
					1
				),
			[ `POST ${ PATH }` ]: () =>
				response( 400, {
					code: 'merchboard_invalid_twitch_sub_discount',
					message: 'Invalid.',
					data: {
						status: 400,
						fields: { 'campaigns.1.tier_two': 'too_high' },
					},
				} ),
		} );

		click( button( container, 'Add campaign' ) );
		await flush();
		await flush();

		click(
			container.querySelector( '#merchboard-twitch-sub-1-product-7' )
		);
		await submit( container );

		const saved = requests.find( ( request ) => request.method === 'POST' );
		const campaigns = ( saved?.data as { campaigns: unknown[] } ).campaigns;
		expect( campaigns ).toHaveLength( 2 );
		expect( campaigns[ 1 ] ).toMatchObject( {
			id: '',
			scope: 'selected',
			product_ids: [ 7 ],
		} );
		expect( container.textContent ).toContain(
			"Higher than your lowest profit on the campaign's products."
		);
	} );
} );
