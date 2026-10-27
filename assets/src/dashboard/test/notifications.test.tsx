import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseNotifications, parsePreferences } from '../api/notifications';
import { ConfigContext } from '../app/config-context';
import { RouterProvider } from '../app/router';
import { NotificationBell } from '../components/NotificationBell';
import { EmailNotificationsCard } from '../sections/profile/EmailNotificationsCard';
import { mockApi, response } from '../testing/api';
import type { RecordedRequest } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/notifications';
const PREFERENCES = '/merchboard/v1/me/notification-preferences';

const item = ( id: number, read = false, link = 'payouts' ) => ( {
	id,
	type: 'payout_completed',
	text: `Payout ${ id } <b>completed</b>`,
	link,
	created_at: '2026-10-27T10:00:00+00:00',
	read,
} );

const page = (
	items: readonly unknown[],
	unread: number,
	pages = 1
): Record< string, unknown > => ( {
	items,
	unread,
	total: items.length,
	pages,
} );

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
	document.body.innerHTML = '';
} );

async function settle(): Promise< void > {
	for ( let i = 0; i < 4; i++ ) {
		await flush();
	}
}

function renderBell(): void {
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<RouterProvider>
				<NotificationBell />
			</RouterProvider>
		</ConfigContext.Provider>
	);
}

function bellButton(): HTMLButtonElement {
	const button = document.querySelector< HTMLButtonElement >(
		'.merchboard-bell__button'
	);

	if ( button === null ) {
		throw new Error( 'No bell.' );
	}

	return button;
}

function paths( requests: readonly RecordedRequest[] ): string[] {
	return requests.map(
		( request ) => `${ request.method } ${ request.path }`
	);
}

describe( 'notifications', () => {
	it( 'validates the list and the e-mail choices', () => {
		expect( parseNotifications( page( [ item( 4 ) ], 1 ) ) ).toEqual( {
			items: [
				{
					id: 4,
					type: 'payout_completed',
					text: 'Payout 4 <b>completed</b>',
					link: 'payouts',
					createdAt: '2026-10-27T10:00:00+00:00',
					read: false,
				},
			],
			unread: 1,
			total: 1,
			pages: 1,
		} );
		expect(
			parseNotifications( page( [ item( 4, false, '' ) ], 1 ) )
		).not.toBeNull();

		for ( const bad of [
			{ ...item( 4 ), link: 'https://evil.example' },
			{ ...item( 4 ), link: '//evil.example' },
			{ ...item( 4 ), link: 'javascript:alert(1)' },
			{ ...item( 4 ), id: '4' },
			{ ...item( 4 ), text: '' },
			{ ...item( 4 ), created_at: 'nope' },
		] ) {
			expect( parseNotifications( page( [ bad ], 0 ) ) ).toBeNull();
		}

		expect(
			parseNotifications( { ...page( [], 0 ), unread: -1 } )
		).toBeNull();
		expect(
			parsePreferences( {
				items: [
					{
						key: 'sale_new',
						group: 'sales',
						label: 'New sale',
						description: 'x',
						enabled: true,
					},
				],
				has_user: true,
			} )?.items[ 0 ]?.key
		).toBe( 'sale_new' );
		expect( parsePreferences( { items: [] } ) ).toBeNull();
	} );

	it( 'shows the unread count as a red bubble, 9+ above 9', async () => {
		mockApi( {
			[ `GET ${ PATH }?page=1&per_page=1` ]: () =>
				response( 200, page( [ item( 1 ) ], 12 ) ),
		} );

		renderBell();
		await settle();

		expect(
			document.querySelector( '.merchboard-bell__badge' )?.textContent
		).toBe( '9+' );
		expect( bellButton().getAttribute( 'aria-label' ) ).toBe(
			'Notifications, 12 unread'
		);
		expect( bellButton().getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect(
			document.querySelector( '.merchboard-bell__panel' )
		).toBeNull();
	} );

	it( 'shows no bubble without unread notifications and stays quiet on errors', async () => {
		mockApi( {} );

		renderBell();
		await settle();

		expect(
			document.querySelector( '.merchboard-bell__badge' )
		).toBeNull();
		expect( bellButton().getAttribute( 'aria-label' ) ).toBe(
			'Notifications'
		);
	} );

	it( 'opens a panel of plain text lines, marks the shown ones read and loads more', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }?page=1&per_page=1` ]: () =>
				response( 200, page( [ item( 3 ) ], 2 ) ),
			[ `GET ${ PATH }?page=1&per_page=10` ]: () =>
				response( 200, page( [ item( 3 ), item( 2, true ) ], 2, 2 ) ),
			[ `GET ${ PATH }?page=2&per_page=10` ]: () =>
				response( 200, page( [ item( 1, false, 'sales' ) ], 1, 2 ) ),
			[ `POST ${ PATH }/read` ]: ( request ) =>
				response( 200, {
					unread:
						JSON.stringify( request.data ) === '{"ids":[3]}'
							? 1
							: 0,
				} ),
		} );

		renderBell();
		await settle();
		click( bellButton() );
		await settle();

		const panel = document.querySelector( '.merchboard-bell__panel' );
		expect( bellButton().getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( panel?.querySelector( 'b' ) ).toBeNull();
		expect(
			Array.from(
				panel?.querySelectorAll( '.merchboard-bell__text' ) ?? []
			).map( ( text ) => text.textContent )
		).toEqual( [
			'Payout 3 <b>completed</b>',
			'Payout 2 <b>completed</b>',
		] );
		expect(
			panel
				?.querySelector( '.merchboard-bell__link' )
				?.getAttribute( 'href' )
		).toBe( `${ CONFIG.dashboardUrl }payouts/` );
		expect(
			panel?.querySelectorAll( '.merchboard-bell__item--unread' )
		).toHaveLength( 1 );
		expect( requests.find( ( r ) => r.method === 'POST' )?.data ).toEqual( {
			ids: [ 3 ],
		} );
		expect(
			document.querySelector( '.merchboard-bell__badge' )?.textContent
		).toBe( '1' );

		click(
			Array.from( document.querySelectorAll( 'button' ) ).find(
				( button ) => button.textContent === 'Show more'
			) ?? null
		);
		await settle();

		expect(
			panel?.querySelectorAll( '.merchboard-bell__item' )
		).toHaveLength( 3 );
		expect( paths( requests ) ).toContain(
			`GET ${ PATH }?page=2&per_page=10`
		);
		expect(
			requests.filter( ( r ) => r.method === 'POST' )[ 1 ]?.data
		).toEqual( { ids: [ 1 ] } );
		expect(
			document.querySelector( '.merchboard-bell__badge' )
		).toBeNull();
		expect(
			Array.from( document.querySelectorAll( 'button' ) ).some(
				( button ) => button.textContent === 'Show more'
			)
		).toBe( false );
	} );

	it( 'marks all as read, and closes on Escape and outside clicks', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }?page=1&per_page=1` ]: () =>
				response( 200, page( [], 5 ) ),
			[ `GET ${ PATH }?page=1&per_page=10` ]: () =>
				response( 200, page( [ item( 9, true ) ], 5 ) ),
			[ `POST ${ PATH }/read` ]: () => response( 200, { unread: 0 } ),
		} );

		renderBell();
		await settle();
		click( bellButton() );
		await settle();

		click(
			Array.from( document.querySelectorAll( 'button' ) ).find(
				( button ) => button.textContent === 'Mark all as read'
			) ?? null
		);
		await settle();

		expect( requests.find( ( r ) => r.method === 'POST' )?.data ).toEqual( {
			all: true,
		} );
		expect(
			document.querySelector( '.merchboard-bell__badge' )
		).toBeNull();

		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
			);
		} );
		expect(
			document.querySelector( '.merchboard-bell__panel' )
		).toBeNull();
		expect( bellButton().ownerDocument.activeElement ).toBe( bellButton() );

		click( bellButton() );
		await settle();
		expect(
			document.querySelector( '.merchboard-bell__panel' )
		).not.toBeNull();

		act( () => {
			document.body.dispatchEvent(
				new MouseEvent( 'mousedown', { bubbles: true } )
			);
		} );
		expect(
			document.querySelector( '.merchboard-bell__panel' )
		).toBeNull();
	} );

	it( 'refreshes the count every minute while the tab is visible', async () => {
		jest.useFakeTimers();

		try {
			let unread = 0;
			const requests = mockApi( {
				[ `GET ${ PATH }?page=1&per_page=1` ]: () =>
					response( 200, page( [], unread ) ),
			} );

			renderBell();
			await settle();
			expect(
				document.querySelector( '.merchboard-bell__badge' )
			).toBeNull();

			unread = 3;
			act( () => {
				jest.advanceTimersByTime( 60_000 );
			} );
			await settle();

			expect(
				document.querySelector( '.merchboard-bell__badge' )?.textContent
			).toBe( '3' );
			expect( requests ).toHaveLength( 2 );
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'lets the creator turn e-mails off and restores the choice when saving fails', async () => {
		let fail = false;
		const choices = ( enabled: boolean ) => ( {
			items: [
				{
					key: 'sale_new',
					group: 'sales',
					label: 'New sale',
					description: 'One e-mail per order.',
					enabled,
				},
			],
			has_user: true,
		} );
		const requests = mockApi( {
			[ `GET ${ PREFERENCES }` ]: () => response( 200, choices( true ) ),
			[ `PATCH ${ PREFERENCES }` ]: () =>
				fail
					? response( 500, { code: 'merchboard_internal_error' } )
					: response( 200, choices( false ) ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<EmailNotificationsCard />
			</ConfigContext.Provider>
		);
		await settle();

		const box = () =>
			document.querySelector< HTMLInputElement >(
				'#merchboard-email-sale_new'
			);
		expect( box()?.checked ).toBe( true );
		expect(
			document.querySelector( 'label[for="merchboard-email-sale_new"]' )
				?.textContent
		).toBe( 'New sale' );

		click( box() );
		await settle();

		expect( requests.find( ( r ) => r.method === 'PATCH' )?.data ).toEqual(
			{ off: [ 'sale_new' ] }
		);
		expect( box()?.checked ).toBe( false );

		fail = true;
		click( box() );
		await settle();

		expect(
			requests.filter( ( r ) => r.method === 'PATCH' )[ 1 ]?.data
		).toEqual( { off: [] } );
		expect( box()?.checked ).toBe( false );
	} );

	it( 'says that no e-mails are sent without a login', async () => {
		mockApi( {
			[ `GET ${ PREFERENCES }` ]: () =>
				response( 200, { items: [], has_user: false } ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<EmailNotificationsCard />
			</ConfigContext.Provider>
		);
		await settle();

		expect( document.body.textContent ).toContain(
			'Your creator profile has no login, so no e-mails are sent.'
		);
		expect( document.querySelector( 'input' ) ).toBeNull();
	} );
} );
