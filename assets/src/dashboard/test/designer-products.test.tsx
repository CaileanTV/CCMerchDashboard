import { afterEach, describe, expect, it, jest } from '@jest/globals';

import { parseShopProducts } from '../api/designer';
import { ConfigContext } from '../app/config-context';
import { RouterProvider } from '../app/router';
import { ProductsSection } from '../sections/products/ProductsSection';
import { listResponse, mockApi, response } from '../testing/api';
import type { Route } from '../testing/api';
import { CONFIG, ORIGIN, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';
import type { DashboardConfig } from '../types/config';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PRODUCTS = '/merchboard/v1/me/products?page=1&per_page=20';
const DESIGNS = '/merchboard/v1/me/designer/products';

const WITH_DESIGNER: DashboardConfig = {
	...CONFIG,
	sections: [
		...CONFIG.sections,
		{ id: 'products', label: 'Products', path: 'products' },
		{ id: 'designer', label: 'Designer', path: 'designer' },
	],
};

const LIVE = {
	id: 3,
	title: 'Stream tee',
	state: 'in_shop',
	currency: 'DKK',
	price: '179.9500',
	url: `${ ORIGIN }/product/stream-tee/`,
	pending: {
		number: 3,
		price: '199.9500',
		submitted_at: '2026-10-29T10:00:00+00:00',
	},
	rejection: null,
	draft: false,
};

const WAITING = {
	id: 4,
	title: 'Hoodie',
	state: 'waiting',
	currency: 'DKK',
	price: null,
	url: null,
	pending: {
		number: 1,
		price: '349.0000',
		submitted_at: '2026-10-28T09:00:00+00:00',
	},
	rejection: null,
	draft: false,
};

const REJECTED = {
	id: 5,
	title: 'Cap',
	state: 'rejected',
	currency: 'DKK',
	price: null,
	url: null,
	pending: null,
	rejection: {
		number: 1,
		note: 'The logo is too small.',
		decided_at: '2026-10-27T12:00:00+00:00',
	},
	draft: true,
};

const RETIRED = {
	...REJECTED,
	id: 6,
	title: 'Mug',
	state: 'retired',
	rejection: null,
	draft: false,
};

const DRAFT = {
	...RETIRED,
	id: 7,
	title: 'Poster',
	state: 'draft',
	draft: true,
};

/**
 * An amount as the dashboard formats it (da_DK, DKK).
 *
 * @param amount Decimal.
 */
function money( amount: string ): string {
	return new Intl.NumberFormat( 'da-DK', {
		style: 'currency',
		currency: 'DKK',
	} ).format( Number( amount ) );
}

/**
 * A day as the dashboard formats it (da_DK).
 *
 * @param value YYYY-MM-DD.
 */
function day( value: string ): string {
	return new Intl.DateTimeFormat( 'da-DK', {
		dateStyle: 'medium',
		timeZone: 'UTC',
	} ).format( new Date( `${ value }T00:00:00Z` ) );
}

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

async function show(
	config: DashboardConfig,
	routes: Readonly< Record< string, Route > >
): Promise< { container: HTMLElement; paths: () => string[] } > {
	const requests = mockApi( routes );
	rendered = render(
		<ConfigContext.Provider value={ config }>
			<RouterProvider>
				<ProductsSection />
			</RouterProvider>
		</ConfigContext.Provider>
	);
	await flush();

	return {
		container: rendered.container,
		paths: () => requests.map( ( r ) => r.path ),
	};
}

/**
 * The rows of the designs card (the last table).
 *
 * @param container Container.
 */
function designRows( container: HTMLElement ): string[][] {
	const tables = container.querySelectorAll( 'table' );
	const table = tables[ tables.length - 1 ];

	return Array.from( table?.querySelectorAll( 'tbody tr' ) ?? [] ).map(
		( tr ) =>
			Array.from( tr.querySelectorAll( 'td' ) ).map(
				( td ) => td.textContent ?? ''
			)
	);
}

describe( 'parseShopProducts', () => {
	it( 'accepts the documented list', () => {
		expect(
			parseShopProducts( [ LIVE, WAITING, REJECTED, RETIRED, DRAFT ] )
		).toEqual( [
			{
				id: 3,
				title: 'Stream tee',
				state: 'in_shop',
				currency: 'DKK',
				price: '179.9500',
				url: `${ ORIGIN }/product/stream-tee/`,
				pending: {
					number: 3,
					price: '199.9500',
					submittedAt: '2026-10-29T10:00:00+00:00',
				},
				rejection: null,
				draft: false,
			},
			expect.objectContaining( { id: 4, state: 'waiting' } ),
			expect.objectContaining( {
				id: 5,
				rejection: {
					number: 1,
					note: 'The logo is too small.',
					decidedAt: '2026-10-27T12:00:00+00:00',
				},
			} ),
			expect.objectContaining( { id: 6, state: 'retired' } ),
			expect.objectContaining( { id: 7, state: 'draft' } ),
		] );
	} );

	it( 'drops a link to another site', () => {
		expect(
			parseShopProducts( [
				{ ...LIVE, url: 'https://evil.example/product/' },
			] )?.[ 0 ]?.url
		).toBeNull();
	} );

	it.each( [
		[ 'not a list', LIVE ],
		[ 'unknown state', [ { ...LIVE, state: 'live' } ] ],
		[ 'price as a number', [ { ...LIVE, price: 179.95 } ] ],
		[ 'price not a decimal', [ { ...LIVE, price: '179,95' } ] ],
		[
			'pending without date',
			[ { ...WAITING, pending: { number: 1, price: null } } ],
		],
		[
			'rejection without note',
			[ { ...REJECTED, rejection: { number: 1, decided_at: 'x' } } ],
		],
		[ 'draft missing', [ { ...LIVE, draft: undefined } ] ],
	] )( 'rejects a malformed list (%s)', ( _case, body ) => {
		expect( parseShopProducts( body ) ).toBeNull();
	} );
} );

describe( 'Products section with the designer', () => {
	it( 'shows the designs in the shop, waiting and rejected, not the drafts', async () => {
		const { container, paths } = await show( WITH_DESIGNER, {
			[ `GET ${ PRODUCTS }` ]: () => listResponse( [], 0, 0 ),
			[ `GET ${ DESIGNS }` ]: () =>
				response( 200, [ LIVE, WAITING, REJECTED, RETIRED, DRAFT ] ),
		} );

		expect( paths().sort() ).toEqual( [ DESIGNS, PRODUCTS ] );
		expect(
			Array.from( container.querySelectorAll( 'h2' ) ).map(
				( h ) => h.textContent
			)
		).toEqual( [ 'Your products', 'Your designs' ] );
		expect( designRows( container ) ).toEqual( [
			[
				'Stream tee',
				`In the shop · a change waits for approval (sent ${ day(
					'2026-10-29'
				) })`,
				`${ money( '179.95' ) } (after approval ${ money(
					'199.95'
				) })`,
			],
			[
				'Hoodie',
				`Waiting for approval (sent ${ day( '2026-10-28' ) })`,
				money( '349' ),
			],
			[ 'Cap', 'RejectedNote: The logo is too small.', '–' ],
			[ 'Mug', 'Taken out of the shop', '–' ],
		] );
		expect(
			container.querySelector( 'a[href$="/product/stream-tee/"]' )
				?.textContent
		).toBe( 'Stream tee' );
		expect(
			container.querySelector(
				`a[href^="${ ORIGIN }/creator-dashboard/designer"]`
			)?.textContent
		).toBe( 'Open the designer' );
		expect(
			container.querySelector( '.merchboard-designer__drafts' )
				?.textContent
		).toBe( 'You have 2 drafts in the designer.' );
	} );

	it( 'shows an empty state without designs', async () => {
		const { container } = await show( WITH_DESIGNER, {
			[ `GET ${ PRODUCTS }` ]: () => listResponse( [], 0, 0 ),
			[ `GET ${ DESIGNS }` ]: () => response( 200, [ DRAFT ] ),
		} );

		expect( container.textContent ).toContain(
			'No designs in the shop or waiting for approval yet.'
		);
		expect( container.textContent ).toContain(
			'You have 1 draft in the designer.'
		);
	} );

	it( 'asks nothing of the designer while its section is not there', async () => {
		const { container, paths } = await show( CONFIG, {
			[ `GET ${ PRODUCTS }` ]: () => listResponse( [], 0, 0 ),
		} );

		expect( paths() ).toEqual( [ PRODUCTS ] );
		expect( container.querySelectorAll( 'h2' ) ).toHaveLength( 1 );
	} );
} );
