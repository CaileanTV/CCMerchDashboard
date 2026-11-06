import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseGoLive } from '../api/goLive';
import { GoLiveNotice } from '../components/GoLiveNotice';
import { mockApi, response } from '../testing/api';
import { click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/go-live';

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

function button( label: string ): HTMLButtonElement | null {
	return (
		Array.from(
			rendered?.container.querySelectorAll( 'button' ) ?? []
		).find( ( element ) => element.textContent === label ) ?? null
	);
}

describe( 'parseGoLive', () => {
	it( 'accepts the documented shape only', () => {
		expect(
			parseGoLive( { live: false, agreement: true, request: null } )
		).toEqual( { live: false, agreement: true, request: null } );
		expect(
			parseGoLive( {
				live: false,
				agreement: true,
				request: { status: 'odd', note: '', requested_at: '' },
			} )
		).toBeNull();
	} );
} );

describe( 'GoLiveNotice', () => {
	it( 'shows nothing to a creator who is live', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { live: true, agreement: true, request: null } ),
		} );
		rendered = render( <GoLiveNotice /> );
		await flush();

		expect( rendered.container.textContent ).toBe( '' );
	} );

	it( 'waits for the agreement, then sends the request', async () => {
		let agreement = false;
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { live: false, agreement, request: null } ),
			[ `POST ${ PATH }` ]: () =>
				response( 200, {
					live: false,
					agreement: true,
					request: {
						status: 'pending',
						note: '',
						requested_at: '2026-11-06T12:00:00+00:00',
					},
				} ),
		} );
		rendered = render( <GoLiveNotice /> );
		await flush();

		expect( button( 'Ask to go live' )?.disabled ).toBe( true );
		expect( rendered.container.textContent ).toContain(
			'Accept our agreement first.'
		);

		agreement = true;
		act( () => {
			window.dispatchEvent(
				new Event( 'merchboard:agreement-accepted' )
			);
		} );
		await flush();

		click( button( 'Ask to go live' ) );
		await flush();

		expect(
			requests.filter( ( request ) => request.method === 'POST' )
		).toHaveLength( 1 );
		expect( rendered.container.textContent ).toContain(
			'Your request to go live is waiting for our approval (sent 2026-11-06).'
		);
		expect( button( 'Ask to go live' ) ).toBeNull();
	} );

	it( 'shows the note of a declined request and lets the creator ask again', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					live: false,
					agreement: true,
					request: {
						status: 'declined',
						note: 'Add one more design first.',
						requested_at: '2026-11-05T12:00:00+00:00',
					},
				} ),
		} );
		rendered = render( <GoLiveNotice /> );
		await flush();

		expect( rendered.container.textContent ).toContain(
			'Add one more design first.'
		);
		expect( button( 'Ask again to go live' )?.disabled ).toBe( false );
	} );
} );
