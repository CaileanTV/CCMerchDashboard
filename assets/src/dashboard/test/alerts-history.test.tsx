import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseHistoryOrder } from '../api/alertHistory';
import { ConfigContext } from '../app/config-context';
import {
	AlertsHistorySection,
	anonymize,
} from '../sections/alerts/AlertsHistorySection';
import { listResponse, mockApi, response } from '../testing/api';
import type { Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const LIST = '/merchboard/v1/me/alerts/history?page=1&per_page=10';
const PREFERENCES = '/merchboard/v1/me/alerts/preferences';

const ORDER = {
	order_id: 41,
	order_number: '1041',
	username: 'nova_fan',
	created_at: '2026-10-19T18:30:00Z',
	alerts: [
		{
			id: 7,
			product: 'Hoodie',
			variation: 'Size: L',
			quantity: 2,
			created_at: '2026-10-19T18:30:00Z',
		},
		{
			id: 8,
			product: 'Mug',
			variation: '',
			quantity: 1,
			created_at: '2026-10-19T18:30:00Z',
		},
	],
};

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

async function show( routes: Readonly< Record< string, Route > > ) {
	const requests = mockApi( {
		[ `GET ${ PREFERENCES }` ]: () => response( 200, { anonymize: false } ),
		...routes,
	} );
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<AlertsHistorySection />
		</ConfigContext.Provider>
	);
	await flush();

	return requests;
}

const button = ( label: string ) =>
	Array.from( rendered?.container.querySelectorAll( 'button' ) ?? [] ).find(
		( element ) => element.textContent === label
	) ?? null;

describe( 'parseHistoryOrder', () => {
	it( 'reads an order and refuses anything malformed', () => {
		expect( parseHistoryOrder( ORDER )?.alerts ).toHaveLength( 2 );

		for ( const bad of [
			{ ...ORDER, order_id: 0 },
			{ ...ORDER, created_at: 'yesterday' },
			{ ...ORDER, alerts: [] },
			{ ...ORDER, alerts: [ { ...ORDER.alerts[ 0 ], id: 'x' } ] },
			{ ...ORDER, username: null },
		] ) {
			expect( parseHistoryOrder( bad ) ).toBeNull();
		}
	} );

	it( 'shortens usernames to their first character', () => {
		expect( anonymize( 'nova_fan' ) ).toBe( 'n•••' );
		expect( anonymize( 'Æblegrød' ) ).toBe( 'Æ•••' );
		expect( anonymize( '' ) ).toBe( '' );
	} );
} );

describe( 'AlertsHistorySection', () => {
	it( 'lists orders with their alerts and resends an alert or an order', async () => {
		const requests = await show( {
			[ `GET ${ LIST }` ]: () => listResponse( [ ORDER ], 1, 1 ),
			'POST /merchboard/v1/me/alerts/history/alerts/7/resend': () =>
				response( 200, { sent: 1 } ),
			'POST /merchboard/v1/me/alerts/history/orders/41/resend': () =>
				response( 200, { sent: 2 } ),
		} );

		const text = rendered?.container.textContent ?? '';
		expect( text ).toContain( '#1041' );
		expect( text ).toContain( 'nova_fan' );
		expect( text ).toContain( '2 × Hoodie (Size: L)' );
		expect( text ).toContain( '1 × Mug' );

		click(
			Array.from(
				rendered?.container.querySelectorAll( 'button' ) ?? []
			).filter(
				( element ) => element.textContent === 'Resend alert'
			)[ 0 ] ?? null
		);
		await flush();
		expect( rendered?.container.textContent ).toContain(
			'1 alert was sent to your overlay.'
		);

		click( button( 'Resend order' ) );
		await flush();
		expect(
			requests
				.filter( ( r ) => r.method === 'POST' )
				.map( ( r ) => r.path )
		).toEqual( [
			'/merchboard/v1/me/alerts/history/alerts/7/resend',
			'/merchboard/v1/me/alerts/history/orders/41/resend',
		] );
		expect( rendered?.container.textContent ).toContain(
			'2 alerts were sent to your overlay.'
		);
	} );

	it( 'anonymizes usernames and saves the choice for the user, not in the browser', async () => {
		const requests = await show( {
			[ `GET ${ LIST }` ]: () => listResponse( [ ORDER ], 1, 1 ),
			[ `PATCH ${ PREFERENCES }` ]: ( request ) =>
				response( 200, request.data ),
		} );
		const toggle = rendered?.container.querySelector< HTMLInputElement >(
			'input[type="checkbox"]'
		);

		expect( toggle?.checked ).toBe( false );
		click( toggle ?? null );
		await flush();

		expect( rendered?.container.textContent ).toContain( 'n•••' );
		expect( rendered?.container.textContent ).not.toContain( 'nova_fan' );
		expect( requests.find( ( r ) => r.method === 'PATCH' )?.data ).toEqual(
			{ anonymize: true }
		);
		expect( window.localStorage.length ).toBe( 0 );
	} );

	it( 'starts anonymized when the user chose it before', async () => {
		await show( {
			[ `GET ${ PREFERENCES }` ]: () =>
				response( 200, { anonymize: true } ),
			[ `GET ${ LIST }` ]: () => listResponse( [ ORDER ], 1, 1 ),
		} );

		expect( rendered?.container.textContent ).toContain( 'n•••' );
	} );

	it( 'stops all alerts only after confirmation', async () => {
		const requests = await show( {
			[ `GET ${ LIST }` ]: () => listResponse( [], 0, 0 ),
			'POST /merchboard/v1/me/alerts/stop': () =>
				response( 200, { stopped: true } ),
		} );
		const confirm = jest
			.spyOn( window, 'confirm' )
			.mockReturnValueOnce( false )
			.mockReturnValueOnce( true );

		expect( rendered?.container.textContent ).toContain(
			'You have no alerts yet.'
		);

		click( button( 'Stop all alerts' ) );
		await flush();
		expect( requests.some( ( r ) => r.method === 'POST' ) ).toBe( false );

		click( button( 'Stop all alerts' ) );
		await flush();
		expect(
			requests
				.filter( ( r ) => r.method === 'POST' )
				.map( ( r ) => r.path )
		).toEqual( [ '/merchboard/v1/me/alerts/stop' ] );
		expect( rendered?.container.textContent ).toContain(
			'All alerts were stopped.'
		);
		confirm.mockRestore();
	} );

	it( 'refreshes on request and every minute without hiding the list meanwhile', async () => {
		let release: ( () => void ) | null = null;
		let loads = 0;
		const interval = jest.spyOn( window, 'setInterval' );
		const requests = await show( {
			[ `GET ${ LIST }` ]: () => {
				loads += 1;

				if ( loads === 1 ) {
					return listResponse( [ ORDER ], 1, 1 );
				}

				// Later loads wait until released.
				return new Promise( ( resolve ) => {
					release = () =>
						resolve(
							listResponse(
								[
									{
										...ORDER,
										order_id: 42,
										order_number: '1042',
									},
									ORDER,
								],
								2,
								1
							)
						);
				} );
			},
		} );

		const refresh = () =>
			rendered?.container.querySelector< HTMLButtonElement >(
				'.merchboard-card__actions button[aria-label="Refresh"]'
			) ?? null;
		expect( refresh()?.textContent ).toBe( 'Refresh (60)' );

		click( refresh() );
		await flush();
		expect( loads ).toBe( 2 );
		expect( rendered?.container.textContent ).toContain( '#1041' );
		expect( rendered?.container.textContent ).not.toContain(
			'Loading alerts…'
		);

		act( () => {
			( release as unknown as () => void )();
		} );
		await flush();
		expect( rendered?.container.textContent ).toContain( '#1042' );

		// The button counts down every second and refreshes at 0.
		const tick = interval.mock.calls.find(
			( call ) => call[ 1 ] === 1000
		)?.[ 0 ] as ( () => void ) | undefined;
		expect( tick ).toBeDefined();
		act( () => {
			tick?.();
		} );
		expect( refresh()?.textContent ).toBe( 'Refresh (59)' );
		expect( requests.filter( ( r ) => r.path === LIST ).length ).toBe( 2 );

		for ( let second = 0; second < 59; second++ ) {
			act( () => {
				tick?.();
			} );
		}

		await flush();
		expect( refresh()?.textContent ).toBe( 'Refresh (60)' );
		expect( requests.filter( ( r ) => r.path === LIST ).length ).toBe( 3 );
		interval.mockRestore();
	} );
} );
