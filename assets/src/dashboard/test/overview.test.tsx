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
import type { FakeResponse, Route } from '../testing/api';
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

describe( 'overview section', () => {
	let rendered: Rendered | null = null;

	beforeEach( () => {
		setUrl( '/creator-dashboard/' );
	} );

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
	} );

	async function renderWith( profileRoute: Route ): Promise< HTMLElement > {
		mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
			'GET /merchboard/v1/me/profile': profileRoute,
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

	it( 'shows three key figures as "coming soon" without numbers or requests', async () => {
		const container = await renderWith( () => response( 200, PROFILE ) );
		const kpis = Array.from(
			container.querySelectorAll( '.merchboard-kpi' )
		);

		expect(
			kpis.map( ( kpi ) => kpi.querySelector( 'h3' )?.textContent )
		).toEqual( [ 'Revenue', 'Orders', 'Products sold' ] );
		expect(
			kpis.every( ( kpi ) =>
				kpi.textContent?.includes( 'Sales data coming soon' )
			)
		).toBe( true );
		expect(
			container.querySelector( '.merchboard-overview__kpis' )?.textContent
		).not.toMatch( /\d/ );
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
		expect( container.textContent ).toContain( 'Sales data coming soon' );

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
