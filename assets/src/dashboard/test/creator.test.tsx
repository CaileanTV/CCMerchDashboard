import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';
import { act } from 'react';

import {
	getMe,
	getProfile,
	parseMe,
	parseProfile,
	updateProfile,
} from '../api/creator';
import { App } from '../app/App';
import { validateProfile } from '../sections/profile/validation';
import type { DashboardConfig } from '../types/config';
import { ME, PROFILE, mockApi, response } from '../testing/api';
import type { RecordedRequest } from '../testing/api';
import { CONFIG, click, flush, render, setUrl } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const WITH_PROFILE: DashboardConfig = {
	...CONFIG,
	sections: [
		...CONFIG.sections,
		{ id: 'profile', label: 'Profile', path: 'profile' },
	],
};

describe( 'response validation', () => {
	it( 'accepts GET /me and drops unknown fields', () => {
		expect(
			parseMe( { ...ME, id: 7, email: 'nova@example.org' } )
		).toEqual( { displayName: 'Nova', status: 'active' } );
	} );

	it.each( [
		null,
		[],
		'Nova',
		{ status: 'active' },
		{ display_name: '', status: 'active' },
		{ display_name: 42, status: 'active' },
		{ display_name: 'Nova', status: 'banned' },
	] )( 'rejects malformed /me %#', ( body ) => {
		expect( parseMe( body ) ).toBeNull();
	} );

	it( 'accepts /me/profile with every platform', () => {
		expect( parseProfile( PROFILE ) ).toEqual( {
			displayName: 'Nova',
			bio: 'Merch & music',
			socialLinks: PROFILE.social_links,
		} );
	} );

	it.each( [
		[ 'missing platform', { ...PROFILE, social_links: { website: '' } } ],
		[
			'http link',
			{
				...PROFILE,
				social_links: { ...PROFILE.social_links, x: 'http://x.com' },
			},
		],
		[
			'javascript link',
			{
				...PROFILE,
				social_links: {
					...PROFILE.social_links,
					x: 'javascript:alert(1)',
				},
			},
		],
		[ 'bio not a string', { ...PROFILE, bio: null } ],
		[ 'no display name', { ...PROFILE, display_name: '' } ],
		[ 'links not an object', { ...PROFILE, social_links: [] } ],
	] )( 'rejects a profile with %s', ( _label, body ) => {
		expect( parseProfile( body ) ).toBeNull();
	} );
} );

describe( 'endpoint functions', () => {
	let requests: RecordedRequest[] = [];

	beforeEach( () => {
		requests = mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
			'GET /merchboard/v1/me/profile': () => response( 200, PROFILE ),
			'PATCH /merchboard/v1/me/profile': () => response( 200, PROFILE ),
		} );
	} );

	it( 'call the documented routes without any creator id', async () => {
		await getMe();
		await getProfile();
		await updateProfile( {
			displayName: 'Nova',
			socialLinks: { x: 'https://x.com/nova' },
		} );

		expect( requests.map( ( r ) => `${ r.method } ${ r.path }` ) ).toEqual(
			[
				'GET /merchboard/v1/me',
				'GET /merchboard/v1/me/profile',
				'PATCH /merchboard/v1/me/profile',
			]
		);
		expect( requests[ 2 ]?.data ).toEqual( {
			display_name: 'Nova',
			social_links: { x: 'https://x.com/nova' },
		} );
		expect( JSON.stringify( requests ) ).not.toMatch(
			/creator_id|user_id|"id"/
		);
	} );

	it( 'reports 400 field codes but never server messages', async () => {
		mockApi( {
			'PATCH /merchboard/v1/me/profile': () =>
				response( 400, {
					code: 'merchboard_invalid_profile',
					message: 'Some <b>server</b> text',
					data: {
						status: 400,
						fields: {
							bio: 'too_long',
							'social_links.x': 'invalid_url',
							'<img>': 'x',
							display_name: 'Bad <b>value</b>',
						},
					},
				} ),
		} );

		const result = await updateProfile( { bio: 'x' } );

		expect( result ).toEqual( {
			ok: false,
			error: {
				kind: 'invalid_request',
				status: 400,
				code: 'merchboard_invalid_profile',
				fields: { bio: 'too_long', 'social_links.x': 'invalid_url' },
			},
		} );
	} );
} );

describe( 'client-side profile validation', () => {
	const values = {
		displayName: 'Nova',
		bio: '',
		socialLinks: PROFILE.social_links,
	};

	it( 'mirrors the server rules', () => {
		expect( validateProfile( values ) ).toEqual( {} );
		expect( validateProfile( { ...values, displayName: '  ' } ) ).toEqual( {
			display_name: 'required',
		} );
		expect(
			validateProfile( { ...values, bio: 'a'.repeat( 1001 ) } )
		).toEqual( { bio: 'too_long' } );
		expect(
			validateProfile( {
				...values,
				socialLinks: {
					...values.socialLinks,
					x: 'http://x.com',
					youtube: 'https://a:b@youtube.com',
					tiktok: 'tiktok.com/@nova',
				},
			} )
		).toEqual( {
			'social_links.x': 'invalid_url',
			'social_links.youtube': 'invalid_url',
			'social_links.tiktok': 'invalid_url',
		} );
	} );
} );

/**
 * Types into a controlled input like a user.
 *
 * @param element Input or textarea.
 * @param value   New value.
 */
function type( element: Element | null, value: string ): void {
	if ( ! (
		element instanceof HTMLInputElement ||
		element instanceof HTMLTextAreaElement
	) ) {
		throw new Error( 'Input not found.' );
	}

	const setter = Object.getOwnPropertyDescriptor(
		Object.getPrototypeOf( element ),
		'value'
	)?.set;

	act( () => {
		setter?.call( element, value );
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

async function submit( container: HTMLElement ): Promise< void > {
	act( () => {
		container
			.querySelector( 'form' )
			?.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
	} );
	await flush();
}

describe( 'dashboard with the creator API', () => {
	let rendered: Rendered | null = null;

	beforeEach( () => {
		setUrl( '/creator-dashboard/' );
	} );

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
	} );

	async function renderApp(
		config: DashboardConfig = WITH_PROFILE
	): Promise< HTMLElement > {
		rendered = render( <App config={ config } /> );
		await flush();

		return rendered.container;
	}

	it( 'shows a skeleton while /me loads, then the creator name', async () => {
		let resolve: ( value: ReturnType< typeof response > ) => void = () =>
			undefined;
		mockApi( {
			'GET /merchboard/v1/me': () =>
				new Promise( ( r ) => {
					resolve = r;
				} ),
		} );

		rendered = render( <App config={ WITH_PROFILE } /> );
		const container = rendered.container;

		expect(
			container.querySelector( '[role="status"]' )?.textContent
		).toContain( 'Loading your dashboard…' );
		expect( container.textContent ).not.toContain( 'Key figures' );

		await act( async () =>
			resolve(
				response( 200, {
					display_name: 'Nova <b>Studio</b>',
					status: 'active',
				} )
			)
		);
		await flush();

		expect(
			container.querySelector( '.merchboard-topbar__creator' )
				?.textContent
		).toBe( 'Nova <b>Studio</b>' );
		expect( container.querySelector( 'b' ) ).toBeNull();
		expect( container.textContent ).toContain( 'Key figures' );
	} );

	it.each( [
		[ 401, 'Your session has expired.', CONFIG.loginUrl ],
		[ 403, 'You do not have access to this.', CONFIG.logoutUrl ],
	] )(
		'/me %i: error state with a link, no content and no redirect',
		async ( status, text, href ) => {
			mockApi( {
				'GET /merchboard/v1/me': () =>
					response( status, {
						code: 'rest_forbidden',
						message: 'Internal <b>detail</b>',
					} ),
			} );
			const before = window.location.href;

			const container = await renderApp();

			expect(
				container.querySelector( 'main [role="alert"]' )?.textContent
			).toContain( text );
			expect(
				container
					.querySelector( 'main [role="alert"] a' )
					?.getAttribute( 'href' )
			).toBe( href );
			expect( container.textContent ).not.toContain( 'Key figures' );
			expect( container.textContent ).not.toContain( 'Internal' );
			expect(
				container.querySelector( '.merchboard-topbar__creator' )
			).toBeNull();
			expect( window.location.href ).toBe( before );
		}
	);

	it( 'retries /me after a server error', async () => {
		let calls = 0;
		mockApi( {
			'GET /merchboard/v1/me': () => {
				calls++;
				return calls === 1
					? response( 500, { code: 'merchboard_internal_error' } )
					: response( 200, ME );
			},
		} );

		const container = await renderApp();
		expect( container.textContent ).toContain( 'Something went wrong.' );

		click( container.querySelector( 'main button' ) );
		await flush();

		expect( calls ).toBe( 2 );
		expect( container.textContent ).toContain( 'Key figures' );
	} );

	it( 'treats a malformed /me as an error', async () => {
		mockApi( {
			'GET /merchboard/v1/me': () =>
				response( 200, { display_name: 'Nova', status: 'admin' } ),
		} );

		const container = await renderApp();

		expect( container.textContent ).toContain( 'Something went wrong.' );
		expect(
			container.querySelector( '.merchboard-topbar__creator' )
		).toBeNull();
	} );

	describe( 'profile section', () => {
		let requests: RecordedRequest[] = [];
		let patchResponse: (
			request: RecordedRequest
		) => ReturnType< typeof response > = () => response( 200, PROFILE );

		beforeEach( () => {
			setUrl( '/creator-dashboard/profile/' );
			patchResponse = () => response( 200, PROFILE );
			requests = mockApi( {
				'GET /merchboard/v1/me': () => response( 200, ME ),
				'GET /merchboard/v1/me/profile': () => response( 200, PROFILE ),
				'PATCH /merchboard/v1/me/profile': ( request ) =>
					patchResponse( request ),
			} );
		} );

		const input = ( container: HTMLElement, label: string ) => {
			const id = Array.from( container.querySelectorAll( 'label' ) )
				.find( ( l ) => l.textContent === label )
				?.getAttribute( 'for' );

			return id ? container.querySelector( `[id="${ id }"]` ) : null;
		};

		it( 'shows the saved profile and a password link', async () => {
			const container = await renderApp();

			expect( container.querySelector( 'h1' )?.textContent ).toBe(
				'Profile'
			);
			expect(
				( input( container, 'Display name' ) as HTMLInputElement ).value
			).toBe( 'Nova' );
			expect(
				( input( container, 'Bio' ) as HTMLTextAreaElement ).value
			).toBe( 'Merch & music' );
			expect(
				( input( container, 'Website' ) as HTMLInputElement ).value
			).toBe( 'https://nova.example' );
			expect(
				Array.from( container.querySelectorAll( 'a' ) )
					.find( ( a ) => a.textContent === 'Change your password' )
					?.getAttribute( 'href' )
			).toBe( CONFIG.passwordUrl );
		} );

		it( 'sends only changed fields, updates the name and confirms with a toast', async () => {
			patchResponse = () =>
				response( 200, {
					...PROFILE,
					display_name: 'Nova Studio',
					bio: 'New bio',
				} );
			const container = await renderApp();

			type( input( container, 'Display name' ), 'Nova Studio' );
			type( input( container, 'Bio' ), 'New bio' );
			await submit( container );

			const patch = requests.find( ( r ) => r.method === 'PATCH' );
			expect( patch?.data ).toEqual( {
				display_name: 'Nova Studio',
				bio: 'New bio',
			} );
			expect(
				container.querySelector( '.merchboard-toast-region' )
					?.textContent
			).toContain( 'Profile saved.' );
			expect(
				container.querySelector( '.merchboard-topbar__creator' )
					?.textContent
			).toBe( 'Nova Studio' );

			click( container.querySelector( '.merchboard-toast__dismiss' ) );
			expect( container.querySelector( '.merchboard-toast' ) ).toBeNull();
		} );

		it( 'does not send a request without changes', async () => {
			const container = await renderApp();

			await submit( container );

			expect( requests.some( ( r ) => r.method === 'PATCH' ) ).toBe(
				false
			);
			expect( container.textContent ).toContain( 'No changes to save.' );
		} );

		it( 'blocks invalid input before sending', async () => {
			const container = await renderApp();

			type( input( container, 'X' ), 'http://x.com/nova' );
			await submit( container );

			expect( requests.some( ( r ) => r.method === 'PATCH' ) ).toBe(
				false
			);
			const field = input( container, 'X' );
			expect( field?.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
			expect( container.textContent ).toContain(
				'Enter a full address starting with https://'
			);
		} );

		it( "shows the server's field errors as fixed messages", async () => {
			patchResponse = () =>
				response( 400, {
					code: 'merchboard_invalid_profile',
					message: 'Server <b>text</b>',
					data: { status: 400, fields: { bio: 'too_long' } },
				} );
			const container = await renderApp();

			type( input( container, 'Bio' ), 'Changed' );
			await submit( container );

			expect(
				input( container, 'Bio' )?.getAttribute( 'aria-invalid' )
			).toBe( 'true' );
			expect( container.textContent ).toContain(
				'Use at most 1000 characters.'
			);
			expect( container.textContent ).not.toContain( 'Server' );
			expect( container.textContent ).not.toContain( 'Profile saved.' );
		} );

		it.each( [
			[ 401, 'Your session has expired.' ],
			[ 403, 'You do not have access to this.' ],
			[ 500, 'Something went wrong.' ],
		] )(
			'a save that fails with %i shows its error state and keeps the input',
			async ( status, text ) => {
				patchResponse = () =>
					response( status, { code: 'x', message: 'Internal' } );
				const container = await renderApp();

				type( input( container, 'Bio' ), 'Unsaved text' );
				await submit( container );

				expect(
					container.querySelector( 'form [role="alert"]' )
						?.textContent
				).toContain( text );
				expect(
					( input( container, 'Bio' ) as HTMLTextAreaElement ).value
				).toBe( 'Unsaved text' );
				expect( container.textContent ).not.toContain( 'Internal' );
			}
		);

		it( 'shows an error state with retry when the profile cannot be loaded', async () => {
			let calls = 0;
			mockApi( {
				'GET /merchboard/v1/me': () => response( 200, ME ),
				'GET /merchboard/v1/me/profile': () => {
					calls++;
					return calls === 1
						? response( 200, { display_name: 'Nova' } )
						: response( 200, PROFILE );
				},
			} );

			const container = await renderApp();
			expect( container.textContent ).toContain(
				'Something went wrong.'
			);

			click( container.querySelector( 'main button' ) );
			await flush();

			expect(
				( input( container, 'Display name' ) as HTMLInputElement ).value
			).toBe( 'Nova' );
		} );
	} );
} );
