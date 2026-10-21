/**
 * `/me/alerts/chatbot` (phase 25, steps 8, 8.2, 8.4 and 8.5): the creator's
 * chatbot on Twitch, Kick and YouTube; YouTube asks the creator to accept its
 * privacy policy and terms. The creator is the one the server resolves for
 * the logged-in user.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

const PATH = '/merchboard/v1/me/alerts/chatbot';
const ISO_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
/** A channel or bot name: a login, a slug or a YouTube channel name (shown as text). */
const NAME_MAX = 100;

/** The platforms, in the order the page shows them. */
export const PLATFORMS = [ 'twitch', 'kick', 'youtube' ] as const;
export type ChatPlatform = ( typeof PLATFORMS )[ number ];

/** The authorization page of each platform (the only accepted hosts). */
const AUTHORIZE_HOSTS: Readonly< Record< ChatPlatform, string > > = {
	twitch: 'id.twitch.tv',
	kick: 'id.kick.com',
	youtube: 'accounts.google.com',
};

/** The channel URL's host of each platform. */
const CHANNEL_HOSTS: Readonly< Record< ChatPlatform, string > > = {
	twitch: 'www.twitch.tv',
	kick: 'kick.com',
	youtube: 'www.youtube.com',
};

/** One platform of the chatbot. */
export interface ChatbotPlatformState {
	readonly platform: ChatPlatform;
	/** The platform's name (a brand). */
	readonly label: string;
	/** Whether an administrator has set the platform up. */
	readonly available: boolean;
	/** The bot's name; '' when unknown. */
	readonly botLogin: string;
	/** Whether purchases are announced on this platform. */
	readonly enabled: boolean;
	/** The channel the creator added the bot to, or null. */
	readonly channel: {
		readonly login: string;
		readonly url: string;
		readonly connectedAt: string;
	} | null;
	/** The latest message's outcome, or null. */
	readonly lastMessage: {
		readonly status: string;
		readonly reason: string;
		readonly at: string;
	} | null;
	/**
	 * The privacy policy and terms the creator must accept (YouTube, ADR-071),
	 * or null when the platform asks for none.
	 */
	readonly terms: {
		readonly privacyUrl: string;
		readonly termsUrl: string;
		readonly version: number;
		readonly accepted: boolean;
	} | null;
}

/** The chatbot as the dashboard shows it. */
export interface ChatbotState {
	/** One message per order line or per order (the administrator's choice). */
	readonly mode: 'line' | 'order';
	/** How each product shows in $Products, and between them. */
	readonly itemFormat: string;
	readonly separator: string;
	/** The variables the template can use. */
	readonly variables: readonly string[];
	/** The template shared by the platforms; '' for the default. */
	readonly template: string;
	readonly defaultTemplate: string;
	readonly platforms: readonly ChatbotPlatformState[];
}

/** The outcome of a test message. */
export interface ChatbotTest {
	/** sent, dropped, rate_limited, unauthorized, forbidden or failed. */
	readonly result: string;
	readonly reason: string;
	/** The message as it was sent. */
	readonly message: string;
}

function isPlatform( value: unknown ): value is ChatPlatform {
	return ( PLATFORMS as readonly unknown[] ).includes( value );
}

function parsePlatform( body: unknown ): ChatbotPlatformState | null {
	if (
		! isRecord( body ) ||
		! isPlatform( body.platform ) ||
		typeof body.label !== 'string' ||
		typeof body.available !== 'boolean' ||
		typeof body.bot_login !== 'string' ||
		body.bot_login.length > NAME_MAX ||
		typeof body.enabled !== 'boolean'
	) {
		return null;
	}

	const platform = body.platform;
	let channel: ChatbotPlatformState[ 'channel' ] = null;

	if ( body.channel !== null ) {
		if (
			! isRecord( body.channel ) ||
			typeof body.channel.login !== 'string' ||
			body.channel.login === '' ||
			body.channel.login.length > NAME_MAX ||
			typeof body.channel.url !== 'string' ||
			! isHttpsOn( body.channel.url, CHANNEL_HOSTS[ platform ] ) ||
			typeof body.channel.connected_at !== 'string' ||
			! ISO_UTC.test( body.channel.connected_at )
		) {
			return null;
		}

		channel = {
			login: body.channel.login,
			url: body.channel.url,
			connectedAt: body.channel.connected_at,
		};
	}

	let lastMessage: ChatbotPlatformState[ 'lastMessage' ] = null;

	if ( body.last_message !== null ) {
		if (
			! isRecord( body.last_message ) ||
			typeof body.last_message.status !== 'string' ||
			typeof body.last_message.reason !== 'string' ||
			typeof body.last_message.at !== 'string' ||
			! ISO_UTC.test( body.last_message.at )
		) {
			return null;
		}

		lastMessage = {
			status: body.last_message.status,
			reason: body.last_message.reason,
			at: body.last_message.at,
		};
	}

	let terms: ChatbotPlatformState[ 'terms' ] = null;

	if ( body.terms !== null && body.terms !== undefined ) {
		if (
			! isRecord( body.terms ) ||
			typeof body.terms.privacy_url !== 'string' ||
			! isSameOrigin( body.terms.privacy_url ) ||
			typeof body.terms.terms_url !== 'string' ||
			! isSameOrigin( body.terms.terms_url ) ||
			typeof body.terms.version !== 'number' ||
			! Number.isInteger( body.terms.version ) ||
			body.terms.version < 1 ||
			typeof body.terms.accepted !== 'boolean'
		) {
			return null;
		}

		terms = {
			privacyUrl: new URL( body.terms.privacy_url ).href,
			termsUrl: new URL( body.terms.terms_url ).href,
			version: body.terms.version,
			accepted: body.terms.accepted,
		};
	}

	return {
		platform,
		label: body.label,
		available: body.available,
		botLogin: body.bot_login,
		enabled: body.enabled,
		channel,
		lastMessage,
		terms,
	};
}

/**
 * Whether a URL is on this site (the public pages of the privacy policy and
 * terms are).
 *
 * @param value URL.
 */
function isSameOrigin( value: string ): boolean {
	try {
		const url = new URL( value );

		return (
			url.origin === window.location.origin &&
			( url.protocol === 'https:' || url.protocol === 'http:' )
		);
	} catch {
		return false;
	}
}

/**
 * Validates the chatbot answer.
 *
 * @param body Response body.
 */
export function parseChatbot( body: unknown ): ChatbotState | null {
	if (
		! isRecord( body ) ||
		( body.mode !== 'line' && body.mode !== 'order' ) ||
		typeof body.item_format !== 'string' ||
		typeof body.separator !== 'string' ||
		! Array.isArray( body.variables ) ||
		! ( body.variables as unknown[] ).every(
			( item ) => typeof item === 'string'
		) ||
		typeof body.template !== 'string' ||
		typeof body.default_template !== 'string' ||
		! Array.isArray( body.platforms )
	) {
		return null;
	}

	const platforms = ( body.platforms as unknown[] ).map( parsePlatform );

	if ( platforms.some( ( platform ) => platform === null ) ) {
		return null;
	}

	return {
		mode: body.mode,
		itemFormat: body.item_format,
		separator: body.separator,
		variables: body.variables as string[],
		template: body.template,
		defaultTemplate: body.default_template,
		platforms: platforms as ChatbotPlatformState[],
	};
}

function isHttpsOn( value: string, host: string ): boolean {
	try {
		const url = new URL( value );

		return url.protocol === 'https:' && url.host === host;
	} catch {
		return false;
	}
}

function parseUrl( platform: ChatPlatform ) {
	return ( body: unknown ): string | null =>
		isRecord( body ) &&
		typeof body.url === 'string' &&
		// Only the platform's own authorization page.
		isHttpsOn( body.url, AUTHORIZE_HOSTS[ platform ] )
			? new URL( body.url ).href
			: null;
}

function parseTest( body: unknown ): ChatbotTest | null {
	return isRecord( body ) &&
		typeof body.result === 'string' &&
		typeof body.reason === 'string' &&
		typeof body.message === 'string'
		? { result: body.result, reason: body.reason, message: body.message }
		: null;
}

/**
 * The creator's chatbot.
 *
 * @param signal Abort signal.
 */
export function getChatbot(
	signal?: AbortSignal
): Promise< ApiResult< ChatbotState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseChatbot
	);
}

/**
 * Saves the shared template and messages on or off per platform.
 *
 * @param changes          Changes.
 * @param changes.template Template; '' for the default.
 * @param changes.enabled  Messages on or off by platform.
 */
export function updateChatbot( changes: {
	readonly template: string;
	readonly enabled: Readonly< Partial< Record< ChatPlatform, boolean > > >;
} ): Promise< ApiResult< ChatbotState > > {
	return request(
		{
			path: PATH,
			method: 'PATCH',
			data: { template: changes.template, enabled: changes.enabled },
		},
		parseChatbot
	);
}

/**
 * The platform's page where the creator adds the bot to the own channel.
 *
 * @param platform    Platform.
 * @param acceptTerms Whether the creator accepted the privacy policy and terms (YouTube requires it).
 */
export function connectChatbot(
	platform: ChatPlatform,
	acceptTerms = false
): Promise< ApiResult< string > > {
	return request(
		{
			path: `${ PATH }/${ platform }/connect`,
			method: 'POST',
			...( acceptTerms ? { data: { accept_terms: true } } : {} ),
		},
		parseUrl( platform )
	);
}

/**
 * Accepts the current privacy policy and terms of the YouTube chatbot (e.g.
 * a new version).
 */
export function acceptChatbotTerms(): Promise< ApiResult< ChatbotState > > {
	return request(
		{
			path: `${ PATH }/youtube/terms`,
			method: 'POST',
			data: { accept: true },
		},
		parseChatbot
	);
}

/**
 * Removes the bot from the creator's channel on a platform.
 *
 * @param platform Platform.
 */
export function disconnectChatbot(
	platform: ChatPlatform
): Promise< ApiResult< ChatbotState > > {
	return request(
		{ path: `${ PATH }/${ platform }/channel`, method: 'DELETE' },
		parseChatbot
	);
}

/**
 * Sends a test message to the creator's channel on a platform.
 *
 * @param platform Platform.
 */
export function testChatbot(
	platform: ChatPlatform
): Promise< ApiResult< ChatbotTest > > {
	return request(
		{ path: `${ PATH }/${ platform }/test`, method: 'POST' },
		parseTest
	);
}
