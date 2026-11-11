import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseGiveaways } from '../api/giveaways';
import { ConfigContext } from '../app/config-context';
import { RouterProvider } from '../app/router';
import { GiveawaysSection } from '../sections/community/GiveawaysSection';
import { mockApi, response } from '../testing/api';
import type { RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/community/giveaways';

const LINK = 'https://shop.test/?merchboard_twitch=claim%3Aabc';

const GIVEAWAY = {
	id: 7,
	status: 'ready',
	mode: 'manual',
	funding: 'balance',
	product: 'Hoodie',
	cost: '199.0000',
	currency: 'DKK',
	window: 60,
	created_at: '2026-11-11 18:00:00',
	pay_url: null,
	gift: {
		status: 'won',
		winner: null,
		draws: 0,
		link: LINK,
		deadline: '2026-12-11 18:00:00',
	},
};

const STATE = {
	giveaways: [
		GIVEAWAY,
		{
			...GIVEAWAY,
			id: 6,
			mode: 'bot',
			gift: {
				...GIVEAWAY.gift,
				status: 'waiting',
				link: null,
				deadline: null,
			},
		},
	],
	products: [
		{
			id: 77,
			name: 'Hoodie',
			image: '',
			cost: '199.0000',
			currency: 'DKK',
		},
		{
			id: 78,
			name: 'Beanie',
			image: '',
			cost: '900.0000',
			currency: 'DKK',
		},
	],
	available: '500.0000',
	limits: {
		window_min: 20,
		window_max: 180,
		window_default: 60,
		max_open: 20,
	},
	channel: { login: 'nova' },
	twitch: true,
	live: true,
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
				<GiveawaysSection />
			</RouterProvider>
		</ConfigContext.Provider>
	);
	await flush();
	await flush();

	return { container: rendered.container, requests };
}

function button( container: HTMLElement, text: string ): Element | null {
	return (
		Array.from( container.querySelectorAll( 'button' ) ).find(
			( candidate ) => candidate.textContent === text
		) ?? null
	);
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

describe( 'parseGiveaways', () => {
	it( 'reads the documented fields', () => {
		const parsed = parseGiveaways( { ...STATE, created: 7 } );

		expect( parsed?.created ).toBe( 7 );
		expect( parsed?.available ).toBe( '500.0000' );
		expect( parsed?.maxOpen ).toBe( 20 );
		expect( parsed?.channel ).toBe( 'nova' );
		expect( parsed?.giveaways[ 0 ] ).toEqual( {
			id: 7,
			status: 'ready',
			mode: 'manual',
			funding: 'balance',
			product: 'Hoodie',
			cost: '199.0000',
			currency: 'DKK',
			window: 60,
			createdAt: '2026-11-11 18:00:00',
			payUrl: null,
			gift: {
				status: 'won',
				winner: null,
				draws: 0,
				link: LINK,
				deadline: '2026-12-11 18:00:00',
			},
		} );
	} );

	it( 'refuses anything else', () => {
		expect(
			parseGiveaways( {
				...STATE,
				giveaways: [ { ...GIVEAWAY, status: 'lost' } ],
			} )
		).toBeNull();
		expect(
			parseGiveaways( {
				...STATE,
				giveaways: [ { ...GIVEAWAY, mode: 'raffle' } ],
			} )
		).toBeNull();
		expect(
			parseGiveaways( {
				...STATE,
				giveaways: [
					{
						...GIVEAWAY,
						gift: { ...GIVEAWAY.gift, link: 'javascript:alert(1)' },
					},
				],
			} )
		).toBeNull();
		expect( parseGiveaways( { ...STATE, available: 'lots' } ) ).toBeNull();
		expect(
			parseGiveaways( { ...STATE, channel: { login: '<b>' } } )
		).toBeNull();
	} );
} );

describe( 'Giveaways (ADR-110)', () => {
	it( 'creates a giveaway from the balance and shows the link to pass on', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
			[ `POST ${ PATH }` ]: () =>
				response( 201, { ...STATE, created: 7 } ),
		} );

		expect( container.textContent ).toContain(
			'This is not Twitch gifting'
		);
		expect( container.textContent ).toContain( 'Link ready to pass on' );
		expect( container.textContent ).toContain(
			'Waiting for you to start it in the chat'
		);
		expect(
			(
				container.querySelector(
					'.merchboard-giveaway__link input'
				) as HTMLInputElement | null
			 )?.value
		).toBe( LINK );
		expect( button( container, 'Create giveaway' ) ).not.toBeNull();

		await submit( container );

		const created = requests.find(
			( request ) => request.method === 'POST'
		);
		expect( created?.data ).toEqual( {
			product_id: 77,
			mode: 'manual',
			funding: 'balance',
			window: 60,
		} );
		expect( container.textContent ).toContain(
			'Copy the link and pass it on to your winner.'
		);
	} );

	it( 'pays in the shop when the balance does not cover the product', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
			[ `POST ${ PATH }` ]: () =>
				response( 409, {
					code: 'merchboard_giveaway_limit',
					message: 'Limit.',
					data: { status: 409 },
				} ),
		} );

		const select = container.querySelector(
			'#merchboard-giveaway-product'
		) as HTMLSelectElement;
		act( () => {
			select.value = '78';
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await flush();

		const balance = container.querySelector(
			'#merchboard-giveaway-balance'
		) as HTMLInputElement;
		expect( balance.disabled ).toBe( true );
		expect(
			button( container, 'Create and pay in the shop' )
		).not.toBeNull();

		await submit( container );

		const created = requests.find(
			( request ) => request.method === 'POST'
		);
		expect( created?.data ).toMatchObject( {
			product_id: 78,
			funding: 'shop',
		} );
		expect( container.textContent ).toContain(
			'You have the most open giveaways allowed.'
		);
	} );

	it( 'starts a bot giveaway in the chat and cancels one', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
			[ `POST ${ PATH }/6/start` ]: () =>
				response( 200, { ...STATE, started: 'not_live' } ),
			[ `POST ${ PATH }/7/cancel` ]: () =>
				response( 200, { ...STATE, giveaways: [] } ),
		} );

		click( button( container, 'Start in the chat' ) );
		await flush();
		await flush();
		expect( container.textContent ).toContain(
			'The giveaway can only start while you are live on Twitch.'
		);

		click( button( container, 'Cancel' ) );
		await flush();
		await flush();
		expect(
			requests.some(
				( request ) => request.path === `${ PATH }/7/cancel`
			)
		).toBe( true );
		expect( container.textContent ).toContain( 'No giveaways yet.' );
	} );
} );
