import { afterEach, describe, expect, it, jest } from '@jest/globals';

import { parseMessages, renderMessage, startChat } from '../alerts-admin';

const MESSAGE = {
	id: 4,
	name: 'Nova',
	login: 'nova',
	color: '#FF0000',
	text: '<img src=x onerror=alert(1)> hi',
	at: '2026-10-20T10:00:00Z',
};

const settle = async () => {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
};

afterEach( () => {
	document.body.replaceChildren();
	jest.restoreAllMocks();
} );

describe( 'parseMessages', () => {
	it( 'keeps valid messages and skips the rest', () => {
		expect(
			parseMessages( {
				messages: [ MESSAGE, { ...MESSAGE, id: 'x' }, null ],
			} )
		).toEqual( [ MESSAGE ] );
		expect( parseMessages( null ) ).toEqual( [] );
		expect( parseMessages( { messages: 'x' } ) ).toEqual( [] );
	} );
} );

describe( 'renderMessage', () => {
	it( 'inserts the text as text, never as HTML', () => {
		const line = renderMessage( MESSAGE );

		expect( line.querySelector( 'img' ) ).toBeNull();
		expect( line.textContent ).toContain(
			'Nova: <img src=x onerror=alert(1)> hi'
		);
		expect( line.querySelector( 'strong' )?.style.color ).toBe(
			'rgb(255, 0, 0)'
		);
	} );

	it( 'ignores a colour that is not #rrggbb and falls back to the login', () => {
		const line = renderMessage( {
			...MESSAGE,
			name: '',
			color: 'red;background:url(x)',
		} );

		expect( line.querySelector( 'strong' )?.textContent ).toBe( 'nova' );
		expect( line.querySelector( 'strong' )?.getAttribute( 'style' ) ).toBe(
			null
		);
	} );
} );

describe( 'startChat', () => {
	it( 'polls with the nonce and appends only newer messages', async () => {
		const box = document.createElement( 'div' );
		box.dataset.endpoint =
			'https://shop.example/wp-json/merchboard/v1/alerts/twitch/chat/3';
		box.dataset.nonce = 'abc';
		box.dataset.empty = 'No messages yet.';
		document.body.append( box );

		const answers = [
			{ messages: [] },
			{ messages: [ MESSAGE, { ...MESSAGE, id: 5, text: 'second' } ] },
		];
		const fetchMock = jest.fn( ( url: string, init: RequestInit ) => {
			void url;
			void init;

			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( answers.shift() ),
			} as unknown as Response );
		} );
		window.fetch = fetchMock as unknown as typeof window.fetch;
		const timeout = jest.spyOn( window, 'setTimeout' );

		const stop = startChat( box );
		await settle();
		expect( box.textContent ).toBe( 'No messages yet.' );
		expect( fetchMock.mock.calls[ 0 ]?.[ 0 ] ).toContain( 'after=0' );
		expect(
			(
				fetchMock.mock.calls[ 0 ]?.[ 1 ]?.headers as Record<
					string,
					string
				>
			 )[ 'X-WP-Nonce' ]
		).toBe( 'abc' );

		// The next poll is scheduled; run it.
		const next = timeout.mock.calls.at( -1 )?.[ 0 ] as () => void;
		next();
		await settle();
		expect( box.querySelectorAll( 'p' ) ).toHaveLength( 2 );
		expect( box.querySelector( '[data-empty]' ) ).toBeNull();

		const third = timeout.mock.calls.at( -1 )?.[ 0 ] as () => void;
		answers.push( { messages: [] } );
		third();
		await settle();
		expect( fetchMock.mock.calls[ 2 ]?.[ 0 ] ).toContain( 'after=5' );
		expect( box.querySelectorAll( 'p' ) ).toHaveLength( 2 );

		stop();
	} );

	it( 'keeps polling after an error and marks the window', async () => {
		const box = document.createElement( 'div' );
		box.dataset.endpoint = 'https://shop.example/wp-json/x';
		box.dataset.error = 'The chat could not be loaded.';
		window.fetch = jest.fn( () =>
			Promise.resolve( { ok: false } as unknown as Response )
		) as unknown as typeof window.fetch;
		const timeout = jest.spyOn( window, 'setTimeout' );

		const stop = startChat( box );
		await settle();
		expect( box.title ).toBe( 'The chat could not be loaded.' );
		expect( timeout ).toHaveBeenCalled();
		stop();
	} );
} );
