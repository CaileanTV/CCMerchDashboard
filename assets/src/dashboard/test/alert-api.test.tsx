import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseAlertApi } from '../api/alertApi';
import { ConfigContext } from '../app/config-context';
import { AlertsApiSection } from '../sections/alerts/AlertsApiSection';
import { AlertsWebhooksSection } from '../sections/alerts/AlertsWebhooksSection';
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
	max_webhooks: 3,
	webhooks: [],
	deliveries: [],
	...extra,
} );

const SECRET = `whsec_${ 'a'.repeat( 64 ) }`;

const webhook = ( id: number, extra: Record< string, unknown > = {} ) => ( {
	id,
	name: 'SparkyBot',
	format: 'json',
	host: 'hooks.sparkybot.example',
	hint: 'hooks.sparkybot.exa…/abc',
	template: '',
	enabled: true,
	paused: false,
	failures: 0,
	created_at: '2026-10-23T10:00:00+00:00',
	last_delivery_at: null,
	...extra,
} );

const delivery = ( id: number, extra: Record< string, unknown > = {} ) => ( {
	id,
	webhook_id: 4,
	event_id: 'evt_0123',
	kind: 'test',
	status: 'sent',
	http_status: 204,
	error: null,
	created_at: '2026-10-23T10:05:00+00:00',
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

function showWebhooks(): Rendered {
	return render(
		<ConfigContext.Provider value={ CONFIG }>
			<AlertsWebhooksSection />
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
		// Webhooks have their own page.
		expect( button( 'Add webhook' ) ).toBeNull();

		rendered.unmount();
		rendered = showWebhooks();
		await flush();

		expect( rendered.container.textContent ).toContain(
			'nothing is sent until it is switched on again'
		);
		expect( button( 'Add webhook' ) ).toBeNull();
		// Keys have their own page.
		expect( button( 'Make a key' ) ).toBeNull();
	} );

	it( 'validates webhooks, deliveries and a made secret', () => {
		const state = parseAlertApi(
			page( [], {
				webhooks: [ webhook( 4 ) ],
				deliveries: [ delivery( 9 ) ],
				secret: SECRET,
			} )
		);

		expect( state?.webhooks[ 0 ]?.host ).toBe( 'hooks.sparkybot.example' );
		expect( state?.deliveries[ 0 ]?.httpStatus ).toBe( 204 );
		expect( state?.secret ).toBe( SECRET );
		expect(
			parseAlertApi(
				page( [], { webhooks: [ webhook( 4, { format: 'xml' } ) ] } )
			)
		).toBeNull();
		expect(
			parseAlertApi(
				page( [], {
					deliveries: [ delivery( 9, { status: 'lost' } ) ],
				} )
			)
		).toBeNull();
		expect( parseAlertApi( page( [], { secret: 'plain' } ) ) ).toBeNull();
		expect(
			parseAlertApi( { ...page( [] ), webhooks: undefined } )
		).toBeNull();
	} );

	it( 'adds a JSON webhook, shows a made secret once and lists the deliveries', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, page( [] ) ),
			[ `POST ${ PATH }/webhooks` ]: () =>
				response(
					201,
					page( [], {
						webhooks: [ webhook( 4 ) ],
						secret: SECRET,
					} )
				),
			[ `POST ${ PATH }/webhooks/4/test` ]: () =>
				response(
					200,
					page( [], {
						webhooks: [ webhook( 4 ) ],
						deliveries: [ delivery( 9 ) ],
						delivery: delivery( 9 ),
					} )
				),
		} );
		rendered = showWebhooks();
		await flush();

		const form = Array.from(
			rendered.container.querySelectorAll( 'form' )
		).find( ( candidate ) =>
			candidate.textContent?.includes( 'Add a webhook' )
		);
		const inputs = form?.querySelectorAll( 'input' ) ?? [];
		type( inputs[ 0 ] ?? null, 'SparkyBot' );
		type(
			inputs[ 1 ] ?? null,
			'https://hooks.sparkybot.example/merch/abc'
		);
		click( button( 'Add webhook' ) );
		await flush();

		expect( requests.at( -1 )?.data ).toEqual( {
			name: 'SparkyBot',
			format: 'json',
			url: 'https://hooks.sparkybot.example/merch/abc',
			secret: '',
			template: '',
		} );
		expect(
			(
				rendered.container.querySelector(
					'.merchboard-alert-api__key'
				) as HTMLInputElement | null
			 )?.value
		).toBe( SECRET );

		click( button( 'I have saved it' ) );
		expect( rendered.container.textContent ).not.toContain( SECRET );

		click( button( 'Send test' ) );
		await flush();
		expect( requests.at( -1 )?.path ).toBe( `${ PATH }/webhooks/4/test` );
		expect( rendered.container.textContent ).toContain( 'Delivered (204)' );
	} );

	it( 'deletes a webhook only after a confirmation and offers resume when paused', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response(
					200,
					page( [], {
						webhooks: [
							webhook( 4, { paused: true, failures: 10 } ),
						],
					} )
				),
			[ `DELETE ${ PATH }/webhooks/4` ]: () =>
				response( 200, page( [] ) ),
		} );
		rendered = showWebhooks();
		await flush();

		expect( rendered.container.textContent ).toContain(
			'Paused after 10 failed deliveries'
		);
		expect( button( 'Resume' ) ).not.toBeNull();

		click( button( 'Delete' ) );
		expect(
			requests.filter( ( r ) => r.method === 'DELETE' )
		).toHaveLength( 0 );
		click( button( 'Delete webhook' ) );
		await flush();

		expect( requests.at( -1 )?.path ).toBe( `${ PATH }/webhooks/4` );
		expect( rendered.container.textContent ).toContain(
			'You have no webhooks yet.'
		);
	} );

	it( 'tests every switched-on webhook from the Test card', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response(
					200,
					page( [], {
						webhooks: [
							webhook( 4 ),
							webhook( 5, {
								name: 'Discord',
								format: 'discord',
							} ),
						],
					} )
				),
			[ `POST ${ PATH }/webhooks/test` ]: () =>
				response(
					200,
					page( [], {
						webhooks: [
							webhook( 4 ),
							webhook( 5, {
								name: 'Discord',
								format: 'discord',
							} ),
						],
						tested: [
							delivery( 9 ),
							delivery( 10, {
								webhook_id: 5,
								status: 'failed',
								http_status: 404,
							} ),
						],
					} )
				),
		} );
		rendered = showWebhooks();
		await flush();

		click( button( 'Send test to all webhooks' ) );
		await flush();

		expect( requests.at( -1 )?.path ).toBe( `${ PATH }/webhooks/test` );
		expect( rendered.container.textContent ).toContain(
			'SparkyBot: Delivered (204)'
		);
		expect( rendered.container.textContent ).toContain(
			'Discord: Failed (404)'
		);
	} );

	it( 'disables the Test card without a switched-on webhook', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response(
					200,
					page( [], {
						webhooks: [ webhook( 4, { enabled: false } ) ],
					} )
				),
		} );
		rendered = showWebhooks();
		await flush();

		expect(
			(
				button(
					'Send test to all webhooks'
				) as HTMLButtonElement | null
			 )?.disabled
		).toBe( true );
	} );
} );
