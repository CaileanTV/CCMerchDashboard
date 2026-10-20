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

const TWITCH = {
	platform: 'twitch',
	label: 'Twitch',
	available: true,
	bot_login: 'merch_bot',
	enabled: true,
	channel: null,
	last_message: null,
};

const KICK = {
	platform: 'kick',
	label: 'Kick',
	available: true,
	bot_login: '',
	enabled: true,
	channel: null,
	last_message: null,
};

const STATE = {
	mode: 'line',
	item_format: '$Quantity× $Product',
	separator: ', ',
	variables: [ '$UserName', '$Name', '$Product', '$Quantity' ],
	template: '',
	default_template: '🔔 $Name just bought $Product!',
	platforms: [ TWITCH, KICK ],
};

const CONNECTED = {
	...STATE,
	platforms: [
		{
			...TWITCH,
			channel: {
				login: 'nova',
				url: 'https://www.twitch.tv/nova',
				connected_at: '2026-10-20T10:00:00Z',
			},
			last_message: {
				status: 'sent',
				reason: '',
				at: '2026-10-20T10:05:00Z',
			},
		},
		{
			...KICK,
			channel: {
				login: 'nova-kick',
				url: 'https://kick.com/nova-kick',
				connected_at: '2026-10-20T10:00:00Z',
			},
		},
	],
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

const buttons = ( label: string ) =>
	Array.from( rendered?.container.querySelectorAll( 'button' ) ?? [] ).filter(
		( element ) => element.textContent === label
	);

const text = () => rendered?.container.textContent ?? '';

describe( 'parseChatbot', () => {
	it( 'reads the state of every platform and refuses anything malformed', () => {
		const state = parseChatbot( CONNECTED );

		expect( state?.platforms.map( ( p ) => p.channel?.login ) ).toEqual( [
			'nova',
			'nova-kick',
		] );

		for ( const bad of [
			{ ...STATE, mode: 'both' },
			{ ...STATE, variables: [ 1 ] },
			{ ...STATE, platforms: [ { ...TWITCH, platform: 'youtube' } ] },
			{
				...STATE,
				platforms: [ { ...TWITCH, bot_login: 'not a login' } ],
			},
			{
				...STATE,
				platforms: [
					{
						...KICK,
						channel: {
							login: 'nova',
							url: 'https://evil.example/nova',
							connected_at: '2026-10-20T10:00:00Z',
						},
					},
				],
			},
			{
				...STATE,
				platforms: [
					{
						...TWITCH,
						last_message: { status: 'sent', reason: '', at: 3 },
					},
				],
			},
			{ ...STATE, platforms: 'twitch' },
		] ) {
			expect( parseChatbot( bad ) ).toBeNull();
		}
	} );

	it( "only accepts the platform's own authorization page", async () => {
		mockApi( {
			[ `POST ${ PATH }/kick/connect` ]: () =>
				response( 200, {
					url: 'https://id.twitch.tv/oauth2/authorize',
				} ),
		} );
		expect( ( await connectChatbot( 'kick' ) ).ok ).toBe( false );

		mockApi( {
			[ `POST ${ PATH }/kick/connect` ]: () =>
				response( 200, {
					url: 'https://id.kick.com/oauth/authorize?client_id=x',
				} ),
		} );
		const answer = await connectChatbot( 'kick' );
		expect( answer.ok && answer.data ).toBe(
			'https://id.kick.com/oauth/authorize?client_id=x'
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

	it( 'maps the result codes of the redirect and the statuses', () => {
		expect( connectMessage( '', 'Kick' ) ).toBeNull();
		expect( connectMessage( 'connected', 'Kick' )?.text ).toContain(
			'your Kick channel'
		);
		expect( connectMessage( 'denied', 'Twitch' )?.tone ).toBe( 'info' );
		expect(
			connectMessage( 'bot_is_broadcaster', 'Twitch' )?.text
		).toContain( "bot's own account" );
		expect( connectMessage( 'whatever', 'Kick' )?.tone ).toBe( 'danger' );
		expect( connectMessage( 'unexpected_answer', 'Kick' )?.text ).toContain(
			'error code: unexpected_answer'
		);
		expect( statusLabel( 'sent' ) ).toBe( 'sent' );
		expect( statusLabel( 'waiting' ) ).toBe( 'waiting to be sent' );
		expect( statusLabel( 'failed' ) ).toBe( 'not sent' );
	} );
} );

describe( 'AlertsChatbotSection', () => {
	it( 'explains that the chatbot is not set up yet', async () => {
		await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...STATE,
					platforms: [
						{ ...TWITCH, available: false, bot_login: '' },
						{ ...KICK, available: false },
					],
				} ),
		} );

		expect( text() ).toContain( 'The chatbot is not set up yet.' );
		expect( buttons( 'Add the bot to your channel' ) ).toHaveLength( 0 );
	} );

	it( 'shows only the platforms the administrator set up', async () => {
		await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...STATE,
					platforms: [ TWITCH, { ...KICK, available: false } ],
				} ),
		} );

		expect( text() ).toContain( 'Your Twitch channel' );
		expect( text() ).not.toContain( 'Your Kick channel' );
		expect( text() ).not.toContain( 'Write in the Kick chat' );
	} );

	it( 'shows the result of the platform and removes it from the address', async () => {
		setUrl(
			`${ ORIGIN }/creator-dashboard/alerts/chatbot?chatbot=connected&platform=kick`
		);
		await show( { [ `GET ${ PATH }` ]: () => response( 200, CONNECTED ) } );

		expect( text() ).toContain( 'The bot was added to your Kick channel.' );
		expect( window.location.search ).toBe( '' );
		expect( text() ).toContain(
			'The bot merch_bot writes in the chat of nova.'
		);
		expect( text() ).toContain(
			'The bot writes in the chat of nova-kick.'
		);
		expect( text() ).toContain( '/mod merch_bot or /vip merch_bot' );
		expect( text() ).toContain( 'Latest message: sent' );
	} );

	it( 'saves the shared message and the platforms on or off', async () => {
		const requests = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, CONNECTED ),
			[ `PATCH ${ PATH }` ]: () => response( 200, CONNECTED ),
		} );
		const textarea =
			rendered?.container.querySelector< HTMLTextAreaElement >(
				'textarea'
			);
		const kick = Array.from(
			rendered?.container.querySelectorAll< HTMLInputElement >(
				'input[type="checkbox"]'
			) ?? []
		)[ 1 ];

		act( () => {
			const setter = Object.getOwnPropertyDescriptor(
				HTMLTextAreaElement.prototype,
				'value'
			)?.set;
			setter?.call( textarea, 'Thanks $Name for the $Product!' );
			textarea?.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		expect( text() ).toContain( 'Thanks TestFan for the Hoodie!' );

		click( kick ?? null );
		click( buttons( 'Save chatbot settings' )[ 0 ] ?? null );
		await flush();
		expect( requests.find( ( r ) => r.method === 'PATCH' )?.data ).toEqual(
			{
				template: 'Thanks $Name for the $Product!',
				enabled: { twitch: true, kick: false },
			}
		);
		expect( text() ).toContain( 'Chatbot settings saved.' );
	} );

	it( 'tests and removes the bot per platform, removing only after confirmation', async () => {
		const requests = await show( {
			[ `GET ${ PATH }` ]: () => response( 200, CONNECTED ),
			[ `POST ${ PATH }/kick/test` ]: () =>
				response( 200, {
					result: 'rate_limited',
					reason: '',
					message: 'x',
				} ),
			[ `DELETE ${ PATH }/kick/channel` ]: () =>
				response( 200, {
					...CONNECTED,
					platforms: [ CONNECTED.platforms[ 0 ], KICK ],
				} ),
		} );
		const confirm = jest
			.spyOn( window, 'confirm' )
			.mockReturnValueOnce( false )
			.mockReturnValueOnce( true );

		click( buttons( 'Send test message' )[ 1 ] ?? null );
		await flush();
		expect( text() ).toContain( 'Kick asked the bot to wait.' );

		click( buttons( 'Remove the bot' )[ 1 ] ?? null );
		await flush();
		expect( requests.some( ( r ) => r.method === 'DELETE' ) ).toBe( false );

		click( buttons( 'Remove the bot' )[ 1 ] ?? null );
		await flush();
		expect(
			requests
				.filter( ( r ) => r.method === 'DELETE' )
				.map( ( r ) => r.path )
		).toEqual( [ `${ PATH }/kick/channel` ] );
		expect( buttons( 'Add the bot to your channel' ) ).toHaveLength( 1 );
		confirm.mockRestore();
	} );

	it( 'shows a fixed error text when the bot cannot be added', async () => {
		await show( {
			[ `GET ${ PATH }` ]: () => response( 200, STATE ),
			[ `POST ${ PATH }/twitch/connect` ]: () =>
				response( 409, {
					code: 'merchboard_chatbot_unavailable',
					message: 'The chatbot is not set up yet.',
				} ),
		} );

		expect( text() ).not.toContain( 'The bot could not be added.' );
		click( buttons( 'Add the bot to your channel' )[ 0 ] ?? null );
		await flush();
		expect( text() ).toContain( 'Something went wrong.' );
	} );
} );
