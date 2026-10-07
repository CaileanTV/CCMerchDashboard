import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';
import { act } from 'react';

import { App } from '../app/App';
import { ME, PROFILE, mockApi, response } from '../testing/api';
import type { FakeResponse, RecordedRequest, Route } from '../testing/api';
import {
	CONFIG,
	ORIGIN,
	click,
	flush,
	render,
	setUrl,
} from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const CONFIG_WITH_PROFILE = {
	...CONFIG,
	sections: [
		...CONFIG.sections,
		{ id: 'profile', label: 'Profile', path: 'profile' },
	],
};

/** `GET /me/sales` of the default period without sales. */
const SALES_EMPTY = {
	from: '2026-09-01',
	to: '2026-09-30',
	interval: 'day',
	timezone: 'Europe/Copenhagen',
	currencies: [],
};

function currency(
	code: string,
	totals: Record< string, unknown >,
	extra: Record< string, unknown > = {}
): Record< string, unknown > {
	return {
		currency: code,
		totals: {
			net_sales: '0.0000',
			sales: '0.0000',
			adjustments: '0.0000',
			units: 0,
			orders: 0,
			...totals,
		},
		series: [],
		products: [],
		truncated: false,
		...extra,
	};
}

/** Text with Intl's non-breaking spaces as plain spaces. */
function plain( text: string | null | undefined ): string {
	return ( text ?? '' ).replace( /\s/gu, ' ' );
}

describe( 'overview section', () => {
	let rendered: Rendered | null = null;
	let requests: RecordedRequest[] = [];

	beforeEach( () => {
		setUrl( '/creator-dashboard/' );
	} );

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
	} );

	async function renderWith(
		profileRoute: Route,
		salesRoute: Route = () => response( 200, SALES_EMPTY )
	): Promise< HTMLElement > {
		requests = mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
			'GET /merchboard/v1/me/profile': profileRoute,
			'GET /merchboard/v1/me/sales': salesRoute,
		} );
		rendered = render( <App config={ CONFIG_WITH_PROFILE } /> );
		await flush();

		return rendered.container;
	}

	it( 'welcomes the creator, shows the status and the profile summary', async () => {
		const container = await renderWith( () =>
			response( 200, {
				...PROFILE,
				bio: 'Line one\n<script>alert(1)</script>',
				social_links: {
					...PROFILE.social_links,
					instagram: 'https://instagram.com/nova',
				},
			} )
		);

		expect( container.querySelector( 'h1' )?.textContent ).toBe(
			'Overview'
		);
		expect( container.querySelector( 'h2' )?.textContent ).toBe(
			'Welcome, Nova'
		);
		expect(
			container.querySelector( '.merchboard-badge' )?.textContent
		).toBe( 'Active' );
		expect(
			container.querySelector( '.merchboard-profile-summary__bio' )
				?.textContent
		).toBe( 'Line one\n<script>alert(1)</script>' );
		expect( container.querySelector( 'script' ) ).toBeNull();

		const links = Array.from(
			container.querySelectorAll( '.merchboard-profile-summary__links a' )
		);
		expect( links.map( ( a ) => a.getAttribute( 'href' ) ) ).toEqual( [
			'https://nova.example',
			'https://instagram.com/nova',
		] );
		expect(
			links.every(
				( a ) =>
					a.getAttribute( 'rel' ) === 'noopener noreferrer' &&
					a.getAttribute( 'target' ) === '_blank'
			)
		).toBe( true );
		expect( links[ 0 ]?.textContent ).toBe( 'Website(opens in a new tab)' );
	} );

	it( 'offers the profile when it is empty and navigates there client-side', async () => {
		const container = await renderWith( () =>
			response( 200, {
				...PROFILE,
				bio: '',
				social_links: { ...PROFILE.social_links, website: '' },
			} )
		);

		expect( container.textContent ).toContain(
			'Your profile is still empty.'
		);
		const edit = Array.from( container.querySelectorAll( 'main a' ) ).find(
			( a ) => a.textContent === 'Edit profile'
		);
		expect( edit?.getAttribute( 'href' ) ).toBe(
			`${ ORIGIN }/creator-dashboard/profile/`
		);

		click( edit ?? null );
		await flush();

		expect( container.querySelector( 'h1' )?.textContent ).toBe(
			'Profile'
		);
		expect( window.location.pathname ).toBe(
			'/creator-dashboard/profile/'
		);
	} );

	it( 'shows a skeleton while the profile loads', async () => {
		let resolve: ( value: FakeResponse ) => void = () => undefined;
		const container = await renderWith(
			() =>
				new Promise< FakeResponse >( ( r ) => {
					resolve = r;
				} )
		);

		expect(
			container.querySelector( '.merchboard-overview [role="status"]' )
				?.textContent
		).toContain( 'Loading your profile…' );
		expect( container.textContent ).not.toContain(
			'Sales data coming soon'
		);

		await act( async () => resolve( response( 200, PROFILE ) ) );
		await flush();

		expect(
			container.querySelector( '.merchboard-profile-summary' )
		).not.toBeNull();
	} );

	it.each( [
		[ 401, 'Your session has expired.' ],
		[ 403, 'You do not have access to this.' ],
		[ 500, 'Something went wrong.' ],
	] )(
		'profile %i: error state inside the profile card, rest of the overview stays',
		async ( status, text ) => {
			const container = await renderWith( () =>
				response( status, {
					code: 'x',
					message: 'Internal <b>detail</b>',
				} )
			);

			expect(
				container.querySelector( '.merchboard-overview [role="alert"]' )
					?.textContent
			).toContain( text );
			expect( container.textContent ).not.toContain( 'Internal' );
			expect( container.querySelector( 'h2' )?.textContent ).toBe(
				'Welcome, Nova'
			);
		}
	);

	it( 'rejects a malformed profile and retries', async () => {
		let calls = 0;
		const container = await renderWith( () => {
			calls++;
			return calls === 1
				? response( 200, {
						...PROFILE,
						social_links: {
							...PROFILE.social_links,
							x: 'javascript:alert(1)',
						},
					} )
				: response( 200, PROFILE );
		} );

		expect( container.textContent ).toContain( 'Something went wrong.' );
		expect( container.querySelector( 'a[href^="javascript"]' ) ).toBeNull();

		click(
			container.querySelector(
				'.merchboard-overview [role="alert"] button'
			)
		);
		await flush();

		expect( calls ).toBe( 2 );
		expect(
			container.querySelector( '.merchboard-profile-summary' )
		).not.toBeNull();
	} );

	describe( 'key figures', () => {
		function kpiTexts( root: Element | null | undefined ): string[][] {
			return Array.from(
				root?.querySelectorAll( '.merchboard-kpi' ) ?? []
			).map( ( kpi ) => [
				plain(
					kpi.querySelector( '.merchboard-kpi__label' )?.textContent
				),
				plain(
					kpi.querySelector( '.merchboard-kpi__value' )?.textContent
				),
			] );
		}

		function kpiSection( container: HTMLElement ): Element | null {
			return container.querySelector( '.merchboard-overview__kpis' );
		}

		function withCurrencies(
			currencies: ReadonlyArray< Record< string, unknown > >,
			extra: Record< string, unknown > = {}
		): Route {
			return () =>
				response( 200, { ...SALES_EMPTY, ...extra, currencies } );
		}

		it( 'requests GET /me/sales once without parameters (the default period) and never /me/overview', async () => {
			await renderWith( () => response( 200, PROFILE ) );

			const paths = requests.map(
				( request ) => `${ request.method } ${ request.path }`
			);
			expect(
				paths.filter( ( path ) => path.includes( '/me/sales' ) )
			).toEqual( [ 'GET /merchboard/v1/me/sales' ] );
			expect(
				paths.some( ( path ) => path.includes( 'overview' ) )
			).toBe( false );
			expect( paths.some( ( path ) => path.includes( '?' ) ) ).toBe(
				false
			);
		} );

		it( 'shows net sales (excl. VAT), units and orders of one currency with the period and the explanation', async () => {
			const container = await renderWith(
				() => response( 200, PROFILE ),
				withCurrencies( [
					currency( 'DKK', {
						net_sales: '1234.5000',
						sales: '1500.0000',
						adjustments: '-265.5000',
						units: 1234,
						orders: 7,
					} ),
				] )
			);
			const section = kpiSection( container );

			expect( kpiTexts( section ) ).toEqual( [
				[ 'Net sales (excl. VAT)', '1.234,50 kr.' ],
				[ 'Units', '1.234' ],
				[ 'Orders', '7' ],
			] );
			expect(
				Array.from( section?.querySelectorAll( 'h3' ) ?? [] ).map(
					( heading ) => heading.textContent
				)
			).toEqual( [ 'Net sales (excl. VAT)', 'Units', 'Orders' ] );
			expect( plain( section?.textContent ) ).toContain(
				'Last 30 days: 1. sep. 2026 – 30. sep. 2026'
			);
			expect( section?.textContent ).toContain(
				"The shop's net sales of your products after discounts, excluding VAT, shipping and fees. It is not your payout."
			);
			expect( section?.textContent ).not.toContain( 'Revenue' );
			expect(
				section?.querySelector( '.merchboard-kpi-currency' )
			).toBeNull();
		} );

		it( 'shows one block per currency in the API order and never a sum across currencies', async () => {
			const container = await renderWith(
				() => response( 200, PROFILE ),
				withCurrencies( [
					currency( 'DKK', {
						net_sales: '100.0000',
						units: 2,
						orders: 1,
					} ),
					currency( 'EUR', {
						net_sales: '30.2500',
						units: 3,
						orders: 2,
					} ),
					currency( 'SEK', {
						net_sales: '50.0000',
						units: 4,
						orders: 1,
					} ),
				] )
			);
			const section = kpiSection( container );
			const blocks = Array.from(
				section?.querySelectorAll( '.merchboard-kpi-currency' ) ?? []
			);

			expect(
				blocks.map(
					( block ) => block.querySelector( 'h3' )?.textContent
				)
			).toEqual( [ 'DKK', 'EUR', 'SEK' ] );
			expect( blocks.map( ( block ) => kpiTexts( block ) ) ).toEqual( [
				[
					[ 'Net sales (excl. VAT)', '100,00 kr.' ],
					[ 'Units', '2' ],
					[ 'Orders', '1' ],
				],
				[
					[ 'Net sales (excl. VAT)', '30,25 €' ],
					[ 'Units', '3' ],
					[ 'Orders', '2' ],
				],
				[
					[ 'Net sales (excl. VAT)', '50,00 SEK' ],
					[ 'Units', '4' ],
					[ 'Orders', '1' ],
				],
			] );
			expect(
				blocks.every(
					( block ) =>
						block.getAttribute( 'aria-labelledby' ) ===
						block.querySelector( 'h3' )?.id
				)
			).toBe( true );
			expect(
				blocks.every(
					( block ) =>
						block.querySelectorAll( 'h4.merchboard-kpi__label' )
							.length === 3
				)
			).toBe( true );
			expect( section?.textContent ).toContain(
				'The figures are shown per currency and never added together.'
			);
			expect(
				section?.querySelectorAll( '.merchboard-kpi' )
			).toHaveLength( 9 );
			const text = plain( section?.textContent );
			expect( text ).not.toContain( '180' );
			expect( text ).not.toContain( '9' );
		} );

		it( 'shows negative net sales and units of a period with more refunds than sales', async () => {
			const container = await renderWith(
				() => response( 200, PROFILE ),
				withCurrencies( [
					currency( 'DKK', {
						net_sales: '-266.0000',
						sales: '0.0000',
						adjustments: '-266.0000',
						units: -2,
						orders: 0,
					} ),
				] )
			);

			expect( kpiTexts( kpiSection( container ) ) ).toEqual( [
				[ 'Net sales (excl. VAT)', '-266,00 kr.' ],
				[ 'Units', '-2' ],
				[ 'Orders', '0' ],
			] );
		} );

		it( 'formats the decimal string exactly, without float rounding', async () => {
			const container = await renderWith(
				() => response( 200, PROFILE ),
				withCurrencies( [
					currency( 'DKK', { net_sales: '123456789012345.0050' } ),
				] )
			);

			expect( kpiTexts( kpiSection( container ) )[ 0 ] ).toEqual( [
				'Net sales (excl. VAT)',
				'123.456.789.012.345,01 kr.',
			] );
		} );

		it( 'shows an empty state, not zeros, when the period has no sales', async () => {
			const container = await renderWith( () =>
				response( 200, PROFILE )
			);
			const section = kpiSection( container );

			expect( section?.textContent ).toContain(
				'No net sales in the last 30 days.'
			);
			expect( section?.querySelector( '.merchboard-kpi' ) ).toBeNull();
			expect( plain( section?.textContent ) ).not.toMatch(
				/kr\.|DKK|0,00/u
			);
		} );

		it( 'says the figures are not available when the shop has no sales route (no WooCommerce)', async () => {
			const container = await renderWith(
				() => response( 200, PROFILE ),
				() =>
					response( 404, {
						code: 'rest_no_route',
						message: 'No route',
					} )
			);
			const section = kpiSection( container );

			expect( section?.textContent ).toContain(
				'Sales figures are not available.'
			);
			expect( section?.querySelector( '[role="alert"]' ) ).toBeNull();
			expect( section?.textContent ).not.toContain( 'No route' );
		} );

		it( 'shows an error state with retry, then a skeleton while it loads again', async () => {
			let resolve: ( value: FakeResponse ) => void = () => undefined;
			let calls = 0;
			const container = await renderWith(
				() => response( 200, PROFILE ),
				() => {
					calls++;

					return calls === 1
						? response( 500, {
								code: 'merchboard_internal_error',
								message: 'SQL <b>detail</b>',
							} )
						: new Promise< FakeResponse >( ( r ) => {
								resolve = r;
							} );
				}
			);
			const section = (): Element | null => kpiSection( container );

			expect(
				section()?.querySelector( '[role="alert"]' )?.textContent
			).toContain( 'Something went wrong.' );
			expect( section()?.textContent ).not.toContain( 'SQL' );

			click(
				section()?.querySelector( '[role="alert"] button' ) ?? null
			);
			await flush();

			expect(
				section()?.querySelector( '[role="status"]' )?.textContent
			).toContain( 'Loading key figures…' );

			await act( async () =>
				resolve(
					response( 200, {
						...SALES_EMPTY,
						currencies: [
							currency( 'DKK', {
								net_sales: '10.0000',
								units: 1,
								orders: 1,
							} ),
						],
					} )
				)
			);
			await flush();

			expect( calls ).toBe( 2 );
			expect( kpiTexts( section() )[ 0 ]?.[ 1 ] ).toBe( '10,00 kr.' );
		} );

		it.each( [
			[ 'a float amount', { net_sales: 1234.5 } ],
			[ 'an amount without four decimals', { net_sales: '1234.5' } ],
			[ 'a float unit count', { units: 1.5 } ],
			[ 'negative orders', { orders: -1 } ],
		] )( 'rejects %s', async ( _label, totals ) => {
			const container = await renderWith(
				() => response( 200, PROFILE ),
				withCurrencies( [ currency( 'DKK', totals ) ] )
			);
			const section = kpiSection( container );

			expect( section?.textContent ).toContain( 'Something went wrong.' );
			expect( section?.querySelector( '.merchboard-kpi' ) ).toBeNull();
		} );

		it( 'rejects a response that repeats a currency', async () => {
			const container = await renderWith(
				() => response( 200, PROFILE ),
				withCurrencies( [
					currency( 'DKK', { net_sales: '1.0000' } ),
					currency( 'DKK', { net_sales: '2.0000' } ),
				] )
			);

			expect( kpiSection( container )?.textContent ).toContain(
				'Something went wrong.'
			);
		} );

		it( 'shows only the totals: no product, order or customer data from the response', async () => {
			const container = await renderWith(
				() => response( 200, PROFILE ),
				withCurrencies(
					[
						currency(
							'DKK',
							{
								net_sales: '100.0000',
								units: 1,
								orders: 1,
								email: 'jane@example.com',
							},
							{
								products: [
									{
										product_id: 77,
										name: 'Secret tee',
										net_sales: '100.0000',
										units: 1,
									},
								],
								order_id: 4242,
							}
						),
					],
					{ customer: 'jane@example.com' }
				)
			);
			const text = kpiSection( container )?.textContent ?? '';

			for ( const leak of [ 'jane', 'Secret tee', '4242', '77' ] ) {
				expect( text ).not.toContain( leak );
			}
		} );
	} );

	it( 'renders the overview on refresh of the start page URL', async () => {
		setUrl( '/creator-dashboard' );
		const container = await renderWith( () => response( 200, PROFILE ) );

		expect( container.querySelector( 'h1' )?.textContent ).toBe(
			'Overview'
		);
		expect(
			container.querySelector(
				'.merchboard-sidebar [aria-current="page"]'
			)?.textContent
		).toBe( 'Overview' );
	} );
} );
