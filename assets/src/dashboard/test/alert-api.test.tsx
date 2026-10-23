import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseAlertApi } from '../api/alertApi';
import { ConfigContext } from '../app/config-context';
import { AlertsApiSection } from '../sections/alerts/AlertsApiSection';
import { mockApi, response } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/alerts/api';
const KEY = `mbk_0123456789ab_${ 'c'.repeat( 48 ) }`;

const key = ( id: number, name: string ) => ( {
	id,
	name,
	hint: 'mbk_0123456789ab_…',
	created_at: '2026-10-23T10:00:00+00:00',
	last_used_at: null,
} );

const page = ( keys: unknown[], extra: Record< string, unknown > = {} ) => ( {
	enabled: true,
	endpoint: 'https://shop.example/wp-json/merchboard/v1/alerts/api/events',
	limit: 60,
	max_keys: 5,
	keys,
	...extra,
} );

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
	document.body.innerHTML = '';
} );

function button( label: string ): Element | null {
	return (
		Array.from( document.querySelectorAll( 'button' ) ).find(
			( candidate ) => candidate.textContent === label
		) ?? null
	);
}

function type( element: Element | null, value: string ): void {
	if ( ! ( element instanceof HTMLInputElement ) ) {
		throw new Error( 'Input not found.' );
	}

	const setter = Object.getOwnPropertyDescriptor(
		HTMLInputElement.prototype,
		'value'
	)?.set;

	act( () => {
		setter?.call( element, value );
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

function show(): Rendered {
	return render(
		<ConfigContext.Provider value={ CONFIG }>
			<AlertsApiSection />
		</ConfigContext.Provider>
	);
}

describe( 'alert API page', () => {
	it( 'validates the answer', () => {
		expect( parseAlertApi( page( [ key( 1, 'Bot' ) ] ) )?.keys ).toEqual( [
			{
				id: 1,
				name: 'Bot',
				hint: 'mbk_0123456789ab_…',
				createdAt: '2026-10-23T10:00:00+00:00',
				lastUsedAt: null,
			},
		] );
		expect( parseAlertApi( page( [], { key: KEY } ) )?.key ).toBe( KEY );
		expect( parseAlertApi( page( [], { key: 'secret' } ) ) ).toBeNull();
		expect(
			parseAlertApi( page( [], { endpoint: 'javascript:x' } ) )
		).toBeNull();
		expect(
			parseAlertApi( page( [ { ...key( 1, 'x' ), id: 0 } ] ) )
		).toBeNull();
	} );

	it( 'shows a new key once and explains how to use it', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, page( [] ) ),
			[ `POST ${ PATH }/keys` ]: () =>
				response( 201, page( [ key( 1, 'Discord' ) ], { key: KEY } ) ),
		} );
		rendered = show();
		await flush();

		expect( rendered.container.textContent ).toContain(
			'Authorization: Bearer'
		);
		expect( rendered.container.textContent ).toContain(
			'https://shop.example/wp-json/merchboard/v1/alerts/api/events'
		);

		type( rendered.container.querySelector( 'form input' ), 'Discord' );
		click( button( 'Make a key' ) );
		await flush();

		expect( requests.at( -1 )?.data ).toEqual( { name: 'Discord' } );
		const input = rendered.container.querySelector(
			'.merchboard-alert-api__key'
		);
		expect( ( input as HTMLInputElement | null )?.value ).toBe( KEY );
		expect( rendered.container.textContent ).toContain(
			'It is shown only this once.'
		);

		click( button( 'I have saved it' ) );
		expect(
			rendered.container.querySelector( '.merchboard-alert-api__key' )
		).toBeNull();
		expect( rendered.container.textContent ).not.toContain( KEY );
		expect( rendered.container.textContent ).toContain( 'Discord' );
	} );

	it( 'revokes a key only after a confirmation', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, page( [ key( 7, 'Old bot' ) ] ) ),
			[ `DELETE ${ PATH }/keys/7` ]: () => response( 200, page( [] ) ),
		} );
		rendered = show();
		await flush();

		click( button( 'Revoke' ) );
		expect(
			requests.filter( ( r ) => r.method === 'DELETE' )
		).toHaveLength( 0 );
		click( button( 'Revoke key' ) );
		await flush();

		expect( requests.at( -1 )?.path ).toBe( `${ PATH }/keys/7` );
		expect( rendered.container.textContent ).toContain(
			'You have no keys yet.'
		);
	} );

	it( 'tells the creator when the shop has the API off', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response(
					200,
					page( [ key( 1, 'Bot' ) ], { enabled: false } )
				),
		} );
		rendered = show();
		await flush();

		expect( rendered.container.textContent ).toContain(
			'switched off by the shop'
		);
		expect(
			( button( 'Send test event' ) as HTMLButtonElement | null )
				?.disabled
		).toBe( true );
		expect( button( 'Make a key' ) ).toBeNull();
	} );
} );
