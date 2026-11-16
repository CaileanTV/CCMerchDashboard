import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseMerchAd } from '../api/merchAd';
import { parseMerchCommand } from '../api/merchCommand';
import { ConfigContext } from '../app/config-context';
import { RouterProvider } from '../app/router';
import { MerchCommandCard } from '../sections/alerts/MerchCommandCard';
import { MerchAdSection } from '../sections/community/MerchAdSection';
import { listResponse, mockApi, response } from '../testing/api';
import type { RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/community/merch-ad';
const COMMAND = '/merchboard/v1/me/alerts/chatbot/merch-command';
const PRODUCTS = '/merchboard/v1/me/products?page=1&per_page=100';

const STATE = {
	enabled: false,
	design: 'spotlight',
	product_ids: [],
	note: '',
	every: 10,
	seconds: 15,
	enter: 'slide',
	leave: 'fade',
	accent: '#9146ff',
	theme: 'dark',
	pill_position: 'below',
	pill_size: 'medium',
	show_names: true,
	glow: true,
	pill_gradient: true,
	stack_spread: 50,
	url: null,
	preview_url: null,
	products: [],
	limits: {
		max_products: 12,
		max_note: 60,
		every: [ 1, 60 ],
		seconds: [ 5, 60 ],
	},
};

const SAVED = {
	...STATE,
	enabled: true,
	design: 'showcase',
	product_ids: [ 7, 8 ],
	url: 'https://shop.test/?merchboard_merch_ad=abc',
	preview_url: 'https://shop.test/?merchboard_merch_ad=abc&preview=1',
	products: [
		{
			id: 7,
			name: 'Hoodie',
			image: 'https://shop.test/7.png',
			transparent: true,
		},
		{ id: 8, name: 'Mug', image: 'https://shop.test/8.jpg' },
	],
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

const MERCH = {
	enabled: false,
	text: '',
	cooldown: 30,
	default_text: 'Find my merch here: $Link',
	example: 'Find my merch here: https://shop.test/c/nova/',
	link: 'https://shop.test/c/nova/',
	channels: [ 'twitch' ],
	limits: { max_text: 200, cooldown: [ 10, 300 ] },
};

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

async function show(
	routes: Readonly< Record< string, Route > >,
	what: 'ad' | 'command' = 'ad'
): Promise< { container: HTMLElement; requests: RecordedRequest[] } > {
	const requests = mockApi( routes );
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<RouterProvider>
				{ what === 'ad' ? (
					<MerchAdSection />
				) : (
					<MerchCommandCard onSave={ () => undefined } />
				) }
			</RouterProvider>
		</ConfigContext.Provider>
	);
	await flush();
	await flush();
	await flush();

	return { container: rendered.container, requests };
}

async function submit( container: HTMLElement ): Promise< void > {
	act( () => {
		container
			.querySelector( '.merchboard-community-form' )
			?.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
	} );

	for ( let i = 0; i < 6; i++ ) {
		await flush();
	}
}

describe( 'the merch ad and !merch (ADR-113)', () => {
	it( 'parses only valid answers', () => {
		expect( parseMerchAd( SAVED )?.products[ 0 ]?.name ).toBe( 'Hoodie' );
		expect( parseMerchAd( { ...SAVED, design: 'banner' } ) ).toBeNull();
		expect( parseMerchAd( { ...SAVED, url: 'javascript:x' } ) ).toBeNull();
		expect( parseMerchCommand( MERCH )?.channels ).toEqual( [ 'twitch' ] );
		expect(
			parseMerchCommand( { ...MERCH, channels: [ 'youtube' ] } )
		).toBeNull();
	} );

	it( 'saves the ad and shows the OBS link, with the shop images as they are', async () => {
		const { container, requests } = await show( {
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
			[ `POST ${ PATH }` ]: () => response( 200, SAVED ),
		} );

		expect( container.textContent ).toContain(
			'Save the ad to get your link for OBS.'
		);

		click( container.querySelector( '#merchboard-merch-ad-enabled' ) );
		click(
			container.querySelector( '#merchboard-merch-ad-design-showcase' )
		);
		click( container.querySelector( '#merchboard-merch-ad-product-7' ) );
		click( container.querySelector( '#merchboard-merch-ad-product-8' ) );
		await flush();
		await submit( container );

		const saved = requests.find(
			( request ) => request.method === 'POST' && request.path === PATH
		);
		expect( saved?.data ).toMatchObject( {
			enabled: true,
			design: 'showcase',
			product_ids: [ 7, 8 ],
		} );
		// The images are the shop's (without background since ADR-114): nothing to cut out or upload.
		expect(
			requests.filter( ( request ) =>
				request.path.startsWith( `${ PATH }/` )
			)
		).toHaveLength( 0 );
		expect( container.textContent ).not.toContain( 'Upload PNG' );
		expect(
			(
				container.querySelector(
					'.merchboard-merch-ad-link input'
				) as HTMLInputElement | null
			 )?.value
		).toBe( SAVED.url );
		expect(
			container.querySelector( 'iframe' )?.getAttribute( 'src' )
		).toBe( SAVED.preview_url );
	} );

	it( 'saves !merch and shows what the chat sees', async () => {
		const { container, requests } = await show(
			{
				[ `GET ${ COMMAND }` ]: () => response( 200, MERCH ),
				[ `POST ${ COMMAND }` ]: () =>
					response( 400, {
						code: 'merchboard_invalid_merch_command',
						message: 'Invalid.',
						data: { status: 400, fields: { text: 'is_command' } },
					} ),
			},
			'command'
		);

		expect( container.textContent ).toContain(
			'Find my merch here: https://shop.test/c/nova/'
		);
		click( container.querySelector( '#merchboard-merch-command-enabled' ) );
		await submit( container );

		expect(
			requests.find( ( request ) => request.method === 'POST' )?.data
		).toEqual( { enabled: true, text: '', cooldown: 30 } );
		expect( container.textContent ).toContain(
			'The answer must not start with !merch.'
		);
	} );
} );
