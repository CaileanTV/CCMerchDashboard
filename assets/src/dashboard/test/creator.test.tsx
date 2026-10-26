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
	uploadProfileImage,
	withdrawProposal,
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

	it( 'accepts /me/profile with its category and proposals', () => {
		const pending = {
			description: {
				id: 4,
				value: 'New story',
				file: null,
				remove: false,
				created_at: '2026-10-26T10:00:00Z',
			},
			banner: {
				id: 5,
				value: '',
				file: { mime: 'image/png', width: 1400, height: 300 },
				remove: false,
				created_at: '2026-10-26T10:01:00Z',
			},
		};

		expect(
			parseProfile( {
				...PROFILE,
				pending,
				rejected: {
					social_x: {
						id: 3,
						reason: 'Wrong account',
						decided_at: null,
					},
				},
			} )
		).toEqual( {
			displayName: 'Nova',
			fields: PROFILE.fields,
			category: {
				name: 'Nova',
				description: 'Merch & music',
				imageUrl: 'https://shop.example/logo.png',
				bannerUrl: 'https://shop.example/ccm-bannere/Banner_Nova.png',
				bannerDefault: true,
				bannerMobileUrl: '',
				bannerLink: '',
				socialLinks: PROFILE.category.social_links,
			},
			pending: {
				description: {
					id: 4,
					value: 'New story',
					file: null,
					remove: false,
					createdAt: '2026-10-26T10:00:00Z',
				},
				banner: {
					id: 5,
					value: '',
					file: { mime: 'image/png', width: 1400, height: 300 },
					remove: false,
					createdAt: '2026-10-26T10:01:00Z',
				},
			},
			rejected: {
				social_x: { id: 3, reason: 'Wrong account', decidedAt: null },
			},
		} );
		expect(
			parseProfile( {
				...PROFILE,
				category: null,
				pending: [],
				rejected: [],
			} )?.category
		).toBeNull();
	} );

	it.each( [
		[
			'missing platform',
			{
				...PROFILE,
				category: {
					...PROFILE.category,
					social_links: { instagram: '' },
				},
			},
		],
		[
			'link not a string',
			{
				...PROFILE,
				category: { ...PROFILE.category, banner_link: 42 },
			},
		],
		[
			'javascript image',
			{
				...PROFILE,
				category: { ...PROFILE.category, image_url: 'javascript:x' },
			},
		],
		[ 'unknown field', { ...PROFILE, fields: [ 'website' ] } ],
		[
			'unknown pending field',
			{ ...PROFILE, pending: { website: PROFILE.fields } },
		],
		[
			'svg proposal',
			{
				...PROFILE,
				pending: {
					image: {
						id: 1,
						value: '',
						file: { mime: 'image/svg+xml', width: 1, height: 1 },
						remove: false,
						created_at: 'x',
					},
				},
			},
		],
		[ 'no display name', { ...PROFILE, display_name: '' } ],
		[ 'category not an object', { ...PROFILE, category: [] } ],
	] )( 'rejects a profile with %s', ( _label, body ) => {
		expect( parseProfile( body ) ).toBeNull();
	} );

	it( 'drops category links that are not https instead of rejecting the profile', () => {
		const category = parseProfile( {
			...PROFILE,
			category: {
				...PROFILE.category,
				banner_link: 'http://nova.example',
				social_links: {
					...PROFILE.category.social_links,
					x: 'javascript:alert(1)',
					twitch: 'http://twitch.tv/nova',
				},
			},
		} )?.category;

		expect( category?.bannerLink ).toBe( '' );
		expect( category?.socialLinks.x ).toBe( '' );
		expect( category?.socialLinks.twitch ).toBe( '' );
	} );
} );

describe( 'endpoint functions', () => {
	let requests: RecordedRequest[] = [];

	beforeEach( () => {
		requests = mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
			'GET /merchboard/v1/me/profile': () => response( 200, PROFILE ),
			'PATCH /merchboard/v1/me/profile': () => response( 200, PROFILE ),
			'POST /merchboard/v1/me/profile/files/banner': () =>
				response( 200, PROFILE ),
			'DELETE /merchboard/v1/me/profile/proposals/description': () =>
				response( 200, PROFILE ),
		} );
	} );

	it( 'call the documented routes without any creator id', async () => {
		await getMe();
		await getProfile();
		await updateProfile( {
			displayName: 'Nova',
			socialLinks: { kick: '@nova' },
			remove: [ 'banner' ],
		} );
		await uploadProfileImage(
			'banner',
			new File( [ 'x' ], 'banner.png', { type: 'image/png' } )
		);
		await withdrawProposal( 'description' );

		expect( requests.map( ( r ) => `${ r.method } ${ r.path }` ) ).toEqual(
			[
				'GET /merchboard/v1/me',
				'GET /merchboard/v1/me/profile',
				'PATCH /merchboard/v1/me/profile',
				'POST /merchboard/v1/me/profile/files/banner',
				'DELETE /merchboard/v1/me/profile/proposals/description',
			]
		);
		expect( requests[ 2 ]?.data ).toEqual( {
			display_name: 'Nova',
			social_links: { kick: '@nova' },
			banner: '',
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
							description: 'too_long',
							'social_links.x': 'invalid_url',
							'<img>': 'x',
							display_name: 'Bad <b>value</b>',
						},
					},
				} ),
		} );

		const result = await updateProfile( { description: 'x' } );

		expect( result ).toEqual( {
			ok: false,
			error: {
				kind: 'invalid_request',
				status: 400,
				code: 'merchboard_invalid_profile',
				fields: {
					description: 'too_long',
					'social_links.x': 'invalid_url',
				},
			},
		} );
	} );
} );

describe( 'client-side profile validation', () => {
	const values = {
		displayName: 'Nova',
		description: '',
		bannerLink: '',
		socialLinks: PROFILE.category.social_links,
	};

	it( 'mirrors the server rules (the server checks the domains)', () => {
		expect( validateProfile( values ) ).toEqual( {} );
		expect( validateProfile( { ...values, displayName: '  ' } ) ).toEqual( {
			display_name: 'required',
		} );
		expect(
			validateProfile( { ...values, description: 'a'.repeat( 1001 ) } )
		).toEqual( { description: 'too_long' } );
		expect(
			validateProfile( { ...values, bannerLink: 'http://nova.example' } )
		).toEqual( { banner_link: 'invalid_url' } );
		expect(
			validateProfile( {
				...values,
				socialLinks: {
					...values.socialLinks,
					x: 'x.com/<b>',
					kick: '@nova',
					tiktok: 'tiktok.com/@nova',
				},
			} )
		).toEqual( { 'social_links.x': 'invalid_url' } );
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
				'DELETE /merchboard/v1/me/profile/proposals/description': () =>
					response( 200, PROFILE ),
			} );
		} );

		const input = ( container: HTMLElement, label: string ) => {
			const id = Array.from( container.querySelectorAll( 'label' ) )
				.find( ( l ) => l.textContent === label )
				?.getAttribute( 'for' );

			return id ? container.querySelector( `[id="${ id }"]` ) : null;
		};

		it( 'shows the name, the category now and a password link', async () => {
			const container = await renderApp();

			expect( container.querySelector( 'h1' )?.textContent ).toBe(
				'Profile'
			);
			expect(
				( input( container, 'Name' ) as HTMLInputElement ).value
			).toBe( 'Nova' );
			expect(
				( input( container, 'Description' ) as HTMLTextAreaElement )
					.value
			).toBe( 'Merch & music' );
			expect(
				( input( container, 'Instagram' ) as HTMLInputElement ).value
			).toBe( 'https://www.instagram.com/nova' );
			expect( input( container, 'Twitch' ) ).not.toBeNull();
			expect( input( container, 'Website' ) ).toBeNull();
			expect( container.textContent ).toContain(
				'Now: the shop’s standard banner with your logo.'
			);
			expect( container.textContent ).toContain( 'Square, JPG' );
			expect(
				Array.from( container.querySelectorAll( 'a' ) )
					.find( ( a ) => a.textContent === 'Change your password' )
					?.getAttribute( 'href' )
			).toBe( CONFIG.passwordUrl );
		} );

		it( 'sends only changed fields for approval and shows them as waiting', async () => {
			patchResponse = () =>
				response( 200, {
					...PROFILE,
					pending: {
						display_name: {
							id: 7,
							value: 'Nova Studio',
							file: null,
							remove: false,
							created_at: '2026-10-26T10:00:00Z',
						},
					},
				} );
			const container = await renderApp();

			type( input( container, 'Name' ), 'Nova Studio' );
			type( input( container, 'Kick' ), '@nova' );
			await submit( container );

			const patch = requests.find( ( r ) => r.method === 'PATCH' );
			expect( patch?.data ).toEqual( {
				display_name: 'Nova Studio',
				social_links: { kick: '@nova' },
			} );
			expect(
				container.querySelector( '.merchboard-toast-region' )
					?.textContent
			).toContain( 'Sent for approval.' );
			expect( container.textContent ).toContain(
				'Waiting for approval: “Nova Studio”'
			);
			expect(
				container.querySelector( '.merchboard-topbar__creator' )
					?.textContent
			).toBe( 'Nova' );
		} );

		it( 'withdraws a pending proposal and shows a rejection note', async () => {
			mockApi( {
				'GET /merchboard/v1/me': () => response( 200, ME ),
				'GET /merchboard/v1/me/profile': () =>
					response( 200, {
						...PROFILE,
						pending: {
							description: {
								id: 4,
								value: 'New story',
								file: null,
								remove: false,
								created_at: '2026-10-26T10:00:00Z',
							},
						},
						rejected: {
							social_x: {
								id: 3,
								reason: 'Wrong <b>account</b>',
								decided_at: null,
							},
						},
					} ),
				'DELETE /merchboard/v1/me/profile/proposals/description': () =>
					response( 200, PROFILE ),
			} );
			const container = await renderApp();

			expect(
				( input( container, 'Description' ) as HTMLTextAreaElement )
					.value
			).toBe( 'New story' );
			expect( container.textContent ).toContain(
				'Your last proposal was not approved: Wrong <b>account</b>'
			);
			expect( container.querySelector( 'b' ) ).toBeNull();

			click(
				container.querySelector(
					'[aria-label="Withdraw the proposal for Description"]'
				)
			);
			await flush();

			expect(
				( input( container, 'Description' ) as HTMLTextAreaElement )
					.value
			).toBe( 'Merch & music' );
			expect( container.textContent ).toContain( 'Proposal withdrawn.' );
		} );

		it( 'shows a proposed image as a data URL, which the dashboard CSP allows', async () => {
			const png = new Blob(
				[ new Uint8Array( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a ] ) ],
				{ type: 'image/png' }
			);
			const fetched = mockApi( {
				'GET /merchboard/v1/me': () => response( 200, ME ),
				'GET /merchboard/v1/me/profile': () =>
					response( 200, {
						...PROFILE,
						pending: {
							image: {
								id: 9,
								value: '',
								file: {
									mime: 'image/png',
									width: 400,
									height: 400,
								},
								remove: false,
								created_at: '2026-10-26T10:00:00Z',
							},
						},
					} ),
				'GET /merchboard/v1/me/profile/files/9': () => ( {
					...response( 200, null, { 'Content-Type': 'image/png' } ),
					blob: () => Promise.resolve( png ),
				} ),
			} );
			const container = await renderApp();

			for ( let i = 0; i < 5; i++ ) {
				await flush();
			}

			// Not a blob: URL: the dashboard CSP is `img-src 'self' data:`.
			expect(
				container
					.querySelector( 'img[alt="Proposed image"]' )
					?.getAttribute( 'src' )
			).toBe( 'data:image/png;base64,iVBORw0K' );

			type( input( container, 'Name' ), 'Nova Studio' );
			await flush();

			expect(
				fetched.filter( ( r ) => r.path.includes( '/files/9' ) )
			).toHaveLength( 1 );
		} );

		it( 'keeps unsent edits of other fields after a withdrawal', async () => {
			mockApi( {
				'GET /merchboard/v1/me': () => response( 200, ME ),
				'GET /merchboard/v1/me/profile': () =>
					response( 200, {
						...PROFILE,
						pending: {
							description: {
								id: 4,
								value: 'New story',
								file: null,
								remove: false,
								created_at: '2026-10-26T10:00:00Z',
							},
						},
					} ),
				'DELETE /merchboard/v1/me/profile/proposals/description': () =>
					response( 200, PROFILE ),
			} );
			const container = await renderApp();

			type( input( container, 'Name' ), 'Nova Studio' );
			type( input( container, 'Kick' ), '@nova' );
			click(
				container.querySelector(
					'[aria-label="Withdraw the proposal for Description"]'
				)
			);
			await flush();

			expect(
				( input( container, 'Description' ) as HTMLTextAreaElement )
					.value
			).toBe( 'Merch & music' );
			expect(
				( input( container, 'Name' ) as HTMLInputElement ).value
			).toBe( 'Nova Studio' );
			expect(
				( input( container, 'Kick' ) as HTMLInputElement ).value
			).toBe( '@nova' );
		} );

		it( 'does not send a request without changes', async () => {
			const container = await renderApp();

			await submit( container );

			expect( requests.some( ( r ) => r.method === 'PATCH' ) ).toBe(
				false
			);
			expect( container.textContent ).toContain( 'No changes to send.' );
		} );

		it( 'blocks invalid input before sending', async () => {
			const container = await renderApp();

			type(
				input( container, 'Link on the banner' ),
				'http://nova.example'
			);
			await submit( container );

			expect( requests.some( ( r ) => r.method === 'PATCH' ) ).toBe(
				false
			);
			expect(
				input( container, 'Link on the banner' )?.getAttribute(
					'aria-invalid'
				)
			).toBe( 'true' );
		} );

		it( "shows the server's field errors as fixed messages", async () => {
			patchResponse = () =>
				response( 400, {
					code: 'merchboard_invalid_profile',
					message: 'Server <b>text</b>',
					data: {
						status: 400,
						fields: { 'social_links.kick': 'invalid_url' },
					},
				} );
			const container = await renderApp();

			type( input( container, 'Kick' ), 'https://twitch.tv/nova' );
			await submit( container );

			expect(
				input( container, 'Kick' )?.getAttribute( 'aria-invalid' )
			).toBe( 'true' );
			expect( container.textContent ).toContain(
				'Enter a full https:// address on the platform, or just the user name.'
			);
			expect( container.textContent ).not.toContain( 'Server' );
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

				type( input( container, 'Description' ), 'Unsaved text' );
				await submit( container );

				expect(
					Array.from(
						container.querySelectorAll( 'form [role="alert"]' )
					)
						.map( ( alert ) => alert.textContent )
						.join( ' ' )
				).toContain( text );
				expect(
					( input( container, 'Description' ) as HTMLTextAreaElement )
						.value
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
				( input( container, 'Name' ) as HTMLInputElement ).value
			).toBe( 'Nova' );
		} );
	} );
} );
