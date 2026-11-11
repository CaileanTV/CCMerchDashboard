import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseGifting } from '../api/gifting';
import { ConfigContext } from '../app/config-context';
import { RouterProvider } from '../app/router';
import { TwitchGiftingSection } from '../sections/community/TwitchGiftingSection';
import { mockApi, response } from '../testing/api';
import type { RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/community/twitch-gifting';

const GIFT = {
	id: 4,
	status: 'waiting',
	product: 'Hoodie',
	buyer: null,
	winner: null,
	draws: 1,
	created_at: '2026-11-10 20:15:00',
	claim_deadline: null,
};

const STATE = {
	enabled: true,
	paused: false,
	window: 60,
	language: 'da',
	limits: { window_min: 20, window_max: 180 },
	channel: { login: 'nova' },
	twitch: true,
	live: true,
	test: false,
	gifts: [
		GIFT,
		{
			...GIFT,
			id: 3,
			status: 'won',
			buyer: 'Mads',
			winner: 'viewer1',
			claim_deadline: '2026-11-11 20:15:00',
		},
	],
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
				<TwitchGiftingSection />
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

describe( 'parseGifting', () => {
	it( 'reads the documented fields', () => {
		expect( parseGifting( { ...STATE, started: true } ) ).toEqual( {
			enabled: true,
			paused: false,
			window: 60,
			language: 'da',
			windowMin: 20,
			windowMax: 180,
			channel: 'nova',
			twitch: true,
			live: true,
			test: false,
			started: true,
			gifts: [
				{
					id: 4,
					status: 'waiting',
					product: 'Hoodie',
					buyer: null,
					winner: null,
					draws: 1,
					createdAt: '2026-11-10 20:15:00',
					claimDeadline: null,
				},
				{
					id: 3,
					status: 'won',
					product: 'Hoodie',
					buyer: 'Mads',
					winner: 'viewer1',
					draws: 1,
					createdAt: '2026-11-10 20:15:00',
					claimDeadline: '2026-11-11 20:15:00',
				},
			],
		} );
	} );

	it( 'refuses anything else', () => {
		expect( parseGifting( { ...STATE, language: 'de' } ) ).toBeNull();
		expect( parseGifting( { ...STATE, live: 'yes' } ) ).toBeNull();
		expect(
			parseGifting( { ...STATE, gifts: [ { ...GIFT, status: 'lost' } ] } )
		).toBeNull();
		expect(
			parseGifting( {
				...STATE,
				gifts: [ { ...GIFT, created_at: '10-11-2026' } ],
			} )
		).toBeNull();
		expect(
			parseGifting( { ...STATE, channel: { login: '<b>' } } )
		).toBeNull();
	} );
} );

describe( 'Twitch gifting (ADR-107)', () => {
	it( 'saves the settings without the pause', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
			[ `POST ${ PATH }` ]: () => response( 200, STATE ),
		} );

		expect( container.textContent ).toContain( 'Twitch channel: nova' );
		expect( container.textContent ).toContain( 'You are live now.' );
		expect( container.textContent ).not.toContain( 'test mode' );
		expect( container.textContent ).toContain( 'Gifts waiting: 1' );
		expect( container.textContent ).toContain( 'Anonymous' );
		expect( container.textContent ).toContain( 'viewer1' );

		const form = container.querySelector( '.merchboard-community-form' );
		act( () => {
			form?.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
		} );
		await flush();
		await flush();

		const saved = requests.find( ( request ) => request.method === 'POST' );
		expect( saved?.data ).toEqual( {
			enabled: true,
			window: 60,
			language: 'da',
		} );
		expect( container.textContent ).toContain( 'Twitch gifting saved.' );
	} );

	it( 'pauses and starts the waiting gifts', async () => {
		const { container, requests } = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
			[ `POST ${ PATH }/pause` ]: () =>
				response( 200, { ...STATE, paused: true } ),
			[ `POST ${ PATH }/start` ]: () =>
				response( 200, { ...STATE, started: true } ),
		} );

		click( button( container, 'Start waiting gifts' ) );
		await flush();
		await flush();
		expect( container.textContent ).toContain(
			'The next gift is announced in your chat now.'
		);

		click( button( container, 'Pause gifting' ) );
		await flush();
		await flush();

		const paused = requests.find(
			( request ) => request.path === `${ PATH }/pause`
		);
		expect( paused?.data ).toEqual( { paused: true } );
		expect( container.textContent ).toContain( 'Gifting is paused' );
		expect( button( container, 'Resume gifting' ) ).not.toBeNull();
	} );

	it( 'tells the creator when the test mode is on', async () => {
		const { container } = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { ...STATE, live: true, test: true } ),
		} );

		expect( container.textContent ).toContain(
			'Test mode: you count as live.'
		);
		expect( container.textContent ).toContain(
			'An administrator has turned on the test mode'
		);
		expect( container.textContent ).not.toContain( 'You are live now.' );
	} );

	it( 'shows the field errors and asks to connect Twitch', async () => {
		const { container } = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...STATE,
					enabled: false,
					channel: null,
					live: null,
					gifts: [],
				} ),
			[ `POST ${ PATH }` ]: () =>
				response( 400, {
					code: 'merchboard_invalid_twitch_gifting',
					message: 'Invalid.',
					data: {
						status: 400,
						fields: { enabled: 'not_connected' },
					},
				} ),
		} );

		expect( button( container, 'Connect Twitch' ) ).not.toBeNull();
		expect( container.textContent ).toContain( 'No gifts yet.' );
		expect( container.textContent ).not.toContain( 'In the stream' );

		const form = container.querySelector( '.merchboard-community-form' );
		act( () => {
			form?.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
		} );
		await flush();
		await flush();

		expect( container.textContent ).toContain(
			'Connect your Twitch channel before you switch gifting on.'
		);
	} );
} );
