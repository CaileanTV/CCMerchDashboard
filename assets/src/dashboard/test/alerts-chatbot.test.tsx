import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { connectChatbot, parseChatbot } from '../api/chatbot';
import { ConfigContext } from '../app/config-context';
import {
	AlertsChatbotSection,
	connectMessage,
	previewMessage,
	statusLabel,
} from '../sections/alerts/AlertsChatbotSection';
import { mockApi, response } from '../testing/api';
import type { Route } from '../testing/api';
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

const PATH = '/merchboard/v1/me/alerts/chatbot';

const STATE = {
	available: true,
	bot_login: 'merch_bot',
	mode: 'line',
	item_format: '$Quantity× $Product',
	separator: ', ',
	variables: [ '$UserName', '$Name', '$Product', '$Quantity' ],
	enabled: true,
	template: '',
	default_template: '🔔 $Name just bought $Product!',
	channel: null,
	last_message: null,
};

const CONNECTED = {
	...STATE,
	channel: { login: 'nova', connected_at: '2026-10-20T10:00:00Z' },
	last_message: {
		status: 'sent',
		reason: '',
		at: '2026-10-20T10:05:00Z',
	},
};

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
	setUrl( `${ ORIGIN }/` );
} );

async function show( routes: Readonly< Record< string, Route > > ) {
	const requests = mockApi( routes );
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<AlertsChatbotSection />
		</ConfigContext.Provider>
	);
	await flush();

	return requests;
}

const button = ( label: string ) =>
	Array.from( rendered?.container.querySelectorAll( 'button' ) ?? [] ).find(
		( element ) => element.textContent === label
	) ?? null;

describe( 'parseChatbot', () => {
	it( 'reads the state and refuses anything malformed', () => {
		expect( parseChatbot( STATE )?.botLogin ).toBe( 'merch_bot' );
		expect( parseChatbot( CONNECTED )?.channel?.login ).toBe( 'nova' );

		for ( const bad of [
			{ ...STATE, mode: 'both' },
			{ ...STATE, bot_login: 'not a login' },
			{ ...STATE, variables: [ 1 ] },
			{ ...STATE, channel: { login: 'nova', connected_at: 'now' } },
			{
				...STATE,
				channel: { login: '<b>', connected_at: '2026-10-20T10:00:00Z' },
			},
			{ ...STATE, last_message: { status: 'sent', reason: '', at: 3 } },
			{ ...STATE, enabled: 'yes' },
		] ) {
			expect( parseChatbot( bad ) ).toBeNull();
		}
	} );

	it( "only accepts Twitch's own authorization page", async () => {
		mockApi( {
			[ `POST ${ PATH }/connect` ]: () =>
				response( 200, {
					url: 'https://evil.example/oauth2/authorize',
				} ),
		} );
		expect( ( await connectChatbot() ).ok ).toBe( false );

		mockApi( {
			[ `POST ${ PATH }/connect` ]: () =>
				response( 200, {
					url: 'https://id.twitch.tv/oauth2/authorize?client_id=x',
				} ),
		} );
		const answer = await connectChatbot();
		expect( answer.ok && answer.data ).toBe(
			'https://id.twitch.tv/oauth2/authorize?client_id=x'
		);
	} );
} );

describe( 'previewMessage', () => {
	it( 'fills the variables with sample values', () => {
		expect(
			previewMessage( '🔔 $Name just bought $Product!', '', ', ' )
		).toBe( '🔔 TestFan just bought Hoodie!' );
	} );

	it( 'lists the products with the item format and separator', () => {
		expect(
			previewMessage(
				'$UserName bought $Products ($ItemCount items)$Amount',
				'$Quantity× $Product',
				' + '
			)
		).toBe( 'TestFan bought 1× Hoodie + 2× Mug (3 items)' );
	} );

	it( 'maps the result codes of the redirect', () => {
		expect( connectMessage( '' ) ).toBeNull();
		expect( connectMessage( 'connected' )?.tone ).toBe( 'success' );
		expect( connectMessage( 'denied' )?.tone ).toBe( 'info' );
		expect( connectMessage( 'bot_is_broadcaster' )?.text ).toContain(
			"bot's own account"
		);
		expect( connectMessage( 'whatever' )?.tone ).toBe( 'danger' );
	} );

	it( 'names the status of the latest message', () => {
		expect( statusLabel( 'sent' ) ).toBe( 'sent' );
		expect( statusLabel( 'waiting' ) ).toBe( 'waiting to be sent' );
		expect( statusLabel( 'failed' ) ).toBe( 'not sent' );
	} );
} );

describe( 'AlertsChatbotSection', () => {
	it( 'explains that the chatbot is not set up yet', async () => {
		await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { ...STATE, available: false, bot_login: '' } ),
		} );

		expect( rendered?.container.textContent ).toContain(
			'The chatbot is not set up yet.'
		);
		expect( button( 'Add the bot to your channel' )?.disabled ).toBe(
			true
		);
	} );

	it( 'shows the result of Twitch and removes it from the address', async () => {
		setUrl(
			`${ ORIGIN }/creator-dashboard/alerts/chatbot?twitch=connected`
		);
		await show( { [ `GET ${ PATH }` ]: () => response( 200, CONNECTED ) } );

		expect( rendered?.container.textContent ).toContain(
			'The bot was added to your channel.'
		);
		expect( window.location.search ).toBe( '' );
		expect( rendered?.container.textContent ).toContain(
			'The bot merch_bot writes in the chat of nova.'
		);
		expect( rendered?.container.textContent ).toContain(
			'/mod merch_bot or /vip merch_bot'
		);
		expect( rendered?.container.textContent ).toContain(
			'Latest message: sent'
		);
	} );

	it( 'saves the message and previews it', async () => {
		const requests = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, CONNECTED ),
			[ `PATCH ${ PATH }` ]: ( request ) =>
				response( 200, {
					...CONNECTED,
					...( request.data as object ),
				} ),
		} );
		const textarea =
			rendered?.container.querySelector< HTMLTextAreaElement >(
				'textarea'
			);

		expect( rendered?.container.textContent ).toContain(
			'🔔 TestFan just bought Hoodie!'
		);

		act( () => {
			const setter = Object.getOwnPropertyDescriptor(
				HTMLTextAreaElement.prototype,
				'value'
			)?.set;
			setter?.call( textarea, 'Thanks $Name for the $Product!' );
			textarea?.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		expect( rendered?.container.textContent ).toContain(
			'Thanks TestFan for the Hoodie!'
		);

		click( button( 'Save chatbot settings' ) );
		await flush();
		expect( requests.find( ( r ) => r.method === 'PATCH' )?.data ).toEqual(
			{ enabled: true, template: 'Thanks $Name for the $Product!' }
		);
		expect( rendered?.container.textContent ).toContain(
			'Chatbot settings saved.'
		);
	} );

	it( 'sends a test message and removes the bot only after confirmation', async () => {
		const requests = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, CONNECTED ),
			[ `POST ${ PATH }/test` ]: () =>
				response( 200, {
					result: 'rate_limited',
					reason: '',
					message: 'x',
				} ),
			[ `DELETE ${ PATH }/channel` ]: () => response( 200, STATE ),
		} );
		const confirm = jest
			.spyOn( window, 'confirm' )
			.mockReturnValueOnce( false )
			.mockReturnValueOnce( true );

		click( button( 'Send test message' ) );
		await flush();
		expect( rendered?.container.textContent ).toContain(
			'Twitch asked the bot to wait.'
		);

		click( button( 'Remove the bot' ) );
		await flush();
		expect( requests.some( ( r ) => r.method === 'DELETE' ) ).toBe( false );

		click( button( 'Remove the bot' ) );
		await flush();
		expect( requests.some( ( r ) => r.method === 'DELETE' ) ).toBe( true );
		expect( button( 'Add the bot to your channel' ) ).not.toBeNull();
		confirm.mockRestore();
	} );

	it( 'shows a fixed error text when the bot cannot be added', async () => {
		await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
			[ `POST ${ PATH }/connect` ]: () =>
				response( 409, {
					code: 'merchboard_chatbot_unavailable',
					message: 'The chatbot is not set up yet.',
				} ),
		} );

		expect( rendered?.container.textContent ).not.toContain(
			'The bot could not be added.'
		);
		click( button( 'Add the bot to your channel' ) );
		await flush();
		expect( rendered?.container.textContent ).toContain(
			'Something went wrong.'
		);
		expect( rendered?.container.textContent ).not.toContain(
			'The chatbot is not set up yet.'
		);
	} );
} );
