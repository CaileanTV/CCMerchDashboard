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
			{ ...STATE, platforms: [ { ...TWITCH, platform: 'tiktok' } ] },
			{
				...STATE,
				platforms: [ { ...TWITCH, bot_login: 'x'.repeat( 101 ) } ],
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
				'$UserName bought $Products ($ItemCount items) for $Amount$OrderNumber',
				'$Quantity× $Product ($LineTotal)',
				' + '
			)
		).toBe(
			'TestFan bought 1× Hoodie (249,00 kr.) + 2× Mug (158,00 kr.) (3 items) for 407,00 kr.'
		);
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
		expect( statusLabel( 'waiting', 'not_live' ) ).toBe(
			'waiting (you were not live; checked again after 5 minutes)'
		);
		expect( statusLabel( 'skipped', 'not_live' ) ).toBe(
			'skipped (you were not live)'
		);
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

	it( 'shows the YouTube channel and explains that messages wait for a livestream', async () => {
		const youtube = {
			platform: 'youtube',
			label: 'YouTube',
			available: true,
			bot_login: 'Merch Bot',
			enabled: true,
			channel: {
				login: 'Nova Streams',
				url: 'https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv',
				connected_at: '2026-10-21T10:00:00Z',
			},
			last_message: {
				status: 'waiting',
				reason: 'not_live',
				at: '2026-10-21T10:05:00Z',
			},
		};
		const results = [
			[ 'not_live', 'not_live' ],
			[ 'forbidden', 'forbidden' ],
			[ 'forbidden', 'live_not_allowed' ],
			[ 'rate_limited', 'quota_exceeded' ],
		];
		await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...STATE,
					platforms: [ TWITCH, KICK, youtube ],
				} ),
			[ `POST ${ PATH }/youtube/test` ]: () => {
				const [ result, reason ] = results.shift() ?? [ 'sent', '' ];

				return response( 200, {
					result,
					reason,
					message: 'x',
				} );
			},
		} );

		expect( text() ).toContain( 'Your YouTube channel' );
		expect( text() ).toContain(
			'The bot Merch Bot writes in the chat of Nova Streams.'
		);
		expect( text() ).toContain( 'only during your livestreams' );
		expect( text() ).toContain(
			'Latest message: waiting (you were not live; checked again after 5 minutes)'
		);
		expect( text() ).not.toContain( '(not_live)' );
		expect( text() ).toContain(
			'Write in the YouTube chat when someone buys'
		);

		click( buttons( 'Send test message' )[ 0 ] ?? null );
		await flush();
		expect( text() ).toContain( 'You are not live on YouTube right now.' );

		// A private stream or a restricted chat: YouTube refuses the bot.
		click( buttons( 'Send test message' )[ 0 ] ?? null );
		await flush();
		expect( text() ).toContain(
			'YouTube did not let the bot write. Check that your livestream is not private'
		);
		expect( text() ).not.toContain( 'Add the bot to your channel again' );

		// Google does not let the creator's account see its livestreams.
		click( buttons( 'Send test message' )[ 0 ] ?? null );
		await flush();
		expect( text() ).toContain(
			'Google did not let your account see your YouTube livestreams.'
		);

		// The day's quota is used up.
		click( buttons( 'Send test message' )[ 0 ] ?? null );
		await flush();
		expect( text() ).toContain(
			"YouTube's daily quota for the chatbot is used up."
		);
	} );

	it( "names YouTube in the result of Google's redirect", async () => {
		setUrl(
			`${ ORIGIN }/creator-dashboard/alerts/chatbot?chatbot=denied&platform=youtube`
		);
		await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...STATE,
					platforms: [
						TWITCH,
						KICK,
						{
							platform: 'youtube',
							label: 'YouTube',
							available: true,
							bot_login: 'Merch Bot',
							enabled: true,
							channel: null,
							last_message: null,
						},
					],
				} ),
		} );

		expect( text() ).toContain( 'You cancelled at YouTube.' );
		expect( window.location.search ).toBe( '' );
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

describe( 'YouTube privacy policy and terms', () => {
	const TERMS = {
		privacy_url: `${ ORIGIN }/creator-dashboard/legal/youtube-privacy/`,
		terms_url: `${ ORIGIN }/creator-dashboard/legal/youtube-terms/`,
		version: 2,
		accepted: false,
	};
	const YOUTUBE = {
		platform: 'youtube',
		label: 'YouTube',
		available: true,
		bot_login: 'Merch Bot',
		enabled: true,
		channel: null,
		last_message: null,
		terms: TERMS,
	};
	const checkbox = () =>
		rendered?.container.querySelector< HTMLInputElement >(
			'input[type="checkbox"]'
		) ?? null;

	it( 'reads the terms and refuses pages on another site', () => {
		expect(
			parseChatbot( { ...STATE, platforms: [ TWITCH, KICK, YOUTUBE ] } )
				?.platforms[ 2 ]?.terms
		).toEqual( {
			privacyUrl: TERMS.privacy_url,
			termsUrl: TERMS.terms_url,
			version: 2,
			accepted: false,
		} );
		expect( parseChatbot( STATE )?.platforms[ 0 ]?.terms ).toBeNull();

		for ( const terms of [
			{ ...TERMS, privacy_url: 'https://evil.example/privacy' },
			{ ...TERMS, terms_url: 'javascript:alert(1)' },
			{ ...TERMS, version: 0 },
			{ ...TERMS, accepted: 'yes' },
		] ) {
			expect(
				parseChatbot( {
					...STATE,
					platforms: [ { ...YOUTUBE, terms } ],
				} )
			).toBeNull();
		}

		expect( statusLabel( 'skipped', 'terms_not_accepted' ) ).toBe(
			'skipped (terms not accepted)'
		);
	} );

	it( 'adds the bot only after the creator accepts the terms', async () => {
		const requests = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { ...STATE, platforms: [ YOUTUBE ] } ),
			[ `POST ${ PATH }/youtube/connect` ]: () =>
				response( 409, {
					code: 'merchboard_chatbot_unavailable',
					message: 'The chatbot is not set up yet.',
				} ),
		} );
		const add = buttons( 'Add the bot to your channel' )[ 0 ];
		const links = Array.from(
			rendered?.container.querySelectorAll( 'label a' ) ?? []
		).map( ( link ) => [ link.textContent, link.getAttribute( 'href' ) ] );

		expect( links ).toEqual( [
			[ 'privacy policy', TERMS.privacy_url ],
			[ 'terms of service', TERMS.terms_url ],
		] );
		expect( add?.disabled ).toBe( true );

		click( checkbox() );
		await flush();
		expect( add?.disabled ).toBe( false );
		click( add ?? null );
		await flush();

		expect(
			requests.find( ( r ) => r.path === `${ PATH }/youtube/connect` )
				?.data
		).toEqual( { accept_terms: true } );
	} );

	it( 'asks a connected creator to accept a new version', async () => {
		const connected = {
			...YOUTUBE,
			channel: {
				login: 'Nova Streams',
				url: 'https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv',
				connected_at: '2026-10-21T10:00:00Z',
			},
		};
		const requests = await show( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, { ...STATE, platforms: [ connected ] } ),
			[ `POST ${ PATH }/youtube/terms` ]: () =>
				response( 200, {
					...STATE,
					platforms: [
						{ ...connected, terms: { ...TERMS, accepted: true } },
					],
				} ),
		} );

		expect( text() ).toContain(
			'The privacy policy and terms of the YouTube chatbot have changed.'
		);
		expect( buttons( 'Accept' )[ 0 ]?.disabled ).toBe( true );

		click( checkbox() );
		await flush();
		click( buttons( 'Accept' )[ 0 ] ?? null );
		await flush();

		expect(
			requests.find( ( r ) => r.path === `${ PATH }/youtube/terms` )?.data
		).toEqual( { accept: true } );
		expect( text() ).not.toContain( 'have changed' );
		expect( text() ).toContain(
			'The bot writes in your YouTube chat again.'
		);
	} );
} );
