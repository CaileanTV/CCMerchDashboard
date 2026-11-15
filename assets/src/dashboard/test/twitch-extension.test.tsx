import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseTwitchExtension } from '../api/twitchExtension';
import { ConfigContext } from '../app/config-context';
import { RouterProvider } from '../app/router';
import { TwitchExtensionSection } from '../sections/community/TwitchExtensionSection';
import { listResponse, mockApi, response } from '../testing/api';
import type { RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/community/twitch-extension';
const PRODUCTS = '/merchboard/v1/me/products?page=1&per_page=100';

const STATE = {
	enabled: false,
	product_ids: [],
	max_products: 12,
	note: 'Skriv !merch i chatten',
	max_note: 60,
	preview: [
		{ id: 8, name: 'Mug', image: 'https://shop.test/8.jpg' },
		{ id: 7, name: 'Hoodie', image: null },
	],
	channel: { login: 'nova' },
	twitch: true,
	ready: true,
	install_url:
		'https://dashboard.twitch.tv/extensions/abcdefghij0123456789klmnop-0.0.1',
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
				<TwitchExtensionSection />
			</RouterProvider>
		</ConfigContext.Provider>
	);
	await flush();
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

function button( container: HTMLElement, label: string ): Element | null {
	return (
		Array.from( container.querySelectorAll( 'button' ) ).find(
			( candidate ) =>
				candidate.textContent === label ||
				candidate.getAttribute( 'aria-label' ) === label
		) ?? null
	);
}

const ROUTES = {
	[ `GET ${ PATH }` ]: () => response( 200, STATE ),
	[ `GET ${ PRODUCTS }` ]: () =>
		listResponse(
			[
				{ ...PRODUCT, id: 7, name: 'Hoodie' },
				{ ...PRODUCT, id: 8, name: 'Mug' },
			],
			2,
			1
		),
};

describe( 'parseTwitchExtension', () => {
	it( 'reads the documented fields', () => {
		expect(
			parseTwitchExtension( { ...STATE, product_ids: [ 8, 7 ] } )
		).toEqual( {
			enabled: false,
			productIds: [ 8, 7 ],
			note: 'Skriv !merch i chatten',
			maxProducts: 12,
			maxNote: 60,
			preview: [
				{ id: 8, name: 'Mug', image: 'https://shop.test/8.jpg' },
				{ id: 7, name: 'Hoodie', image: null },
			],
			channel: 'nova',
			twitch: true,
			ready: true,
			installUrl: STATE.install_url,
		} );
	} );

	it( 'refuses anything else', () => {
		expect(
			parseTwitchExtension( {
				...STATE,
				install_url: 'https://evil.test/ext',
			} )
		).toBeNull();
		expect(
			parseTwitchExtension( {
				...STATE,
				preview: [ { id: 1, name: 'x', image: 'javascript:alert(1)' } ],
			} )
		).toBeNull();
		expect(
			parseTwitchExtension( { ...STATE, product_ids: [ 'all' ] } )
		).toBeNull();
		expect(
			parseTwitchExtension( { ...STATE, channel: { login: '<b>' } } )
		).toBeNull();
	} );
} );

describe( 'Twitch Extension (ADR-112)', () => {
	it( 'chooses products in order, shows the preview and the guide', async () => {
		const { container, requests } = await show( {
			...ROUTES,
			[ `POST ${ PATH }` ]: ( request ) =>
				response( 200, {
					...STATE,
					...( request.data as object ),
				} ),
		} );

		expect( container.textContent ).toContain(
			'Twitch does not allow prices, links to the shop or buying'
		);
		expect(
			Array.from(
				container.querySelectorAll(
					'.merchboard-twitch-panel-preview li'
				)
			).map( ( item ) => item.textContent )
		).toEqual( [ 'Mug', 'Hoodie' ] );
		expect(
			container.querySelector( '.merchboard-twitch-panel-pill strong' )
				?.textContent
		).toBe( '!merch' );
		expect(
			container
				.querySelector( '.merchboard-twitch-guide a' )
				?.getAttribute( 'href' )
		).toBe( STATE.install_url );

		click(
			container.querySelector( '#merchboard-twitch-extension-enabled' )
		);
		click(
			container.querySelector( '#merchboard-twitch-extension-product-7' )
		);
		click(
			container.querySelector( '#merchboard-twitch-extension-product-8' )
		);
		await flush();
		// The order is 7, 8: move Mug (8) up.
		const second = container.querySelectorAll(
			'.merchboard-twitch-panel-order li'
		)[ 1 ];
		expect( second?.textContent ).toContain( 'Mug' );
		click(
			second?.querySelector( 'button[aria-label="Move up"]' ) ?? null
		);
		await flush();
		expect( button( container, 'Move up' ) ).toBeDefined();
		await submit( container );

		const saved = requests.find( ( request ) => request.method === 'POST' );
		expect( saved?.data ).toEqual( {
			enabled: true,
			product_ids: [ 8, 7 ],
			note: 'Skriv !merch i chatten',
		} );
		expect( container.textContent ).toContain( 'Twitch Extension saved.' );
	} );

	it( 'shows the field errors and that the shop is not ready', async () => {
		const { container } = await show( {
			...ROUTES,
			[ `GET ${ PATH }` ]: () =>
				response( 200, { ...STATE, ready: false, install_url: null } ),
			[ `POST ${ PATH }` ]: () =>
				response( 400, {
					code: 'merchboard_invalid_twitch_extension',
					message: 'Invalid.',
					data: {
						status: 400,
						fields: { product_ids: 'not_owned', note: 'no_links' },
					},
				} ),
		} );

		expect( container.textContent ).toContain(
			'The shop has not finished setting up the Twitch Extension yet.'
		);
		expect(
			container.querySelector( '.merchboard-twitch-guide a' )
		).toBeNull();

		await submit( container );
		expect( container.textContent ).toContain(
			'Choose only your own products.'
		);
		expect( container.textContent ).toContain(
			'Links and web addresses are not allowed'
		);
	} );
} );
