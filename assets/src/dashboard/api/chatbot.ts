/**
 * `/me/alerts/chatbot` (phase 25, step 8): the creator's Twitch chatbot.
 * The creator is the one the server resolves for the logged-in user.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

const PATH = '/merchboard/v1/me/alerts/chatbot';
const ISO_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const LOGIN = /^\w{1,25}$/;

/** The chatbot as the dashboard shows it. */
export interface ChatbotState {
	/** Whether an administrator has set the Twitch app and the bot up. */
	readonly available: boolean;
	/** The bot's Twitch login; '' before it is connected. */
	readonly botLogin: string;
	/** One message per order line or per order (the administrator's choice). */
	readonly mode: 'line' | 'order';
	/** How each product shows in $Products, and between them. */
	readonly itemFormat: string;
	readonly separator: string;
	/** The variables the template can use. */
	readonly variables: readonly string[];
	readonly enabled: boolean;
	/** The template; '' for the default. */
	readonly template: string;
	readonly defaultTemplate: string;
	/** The channel the creator added the bot to, or null. */
	readonly channel: {
		readonly login: string;
		readonly connectedAt: string;
	} | null;
	/** The latest message's outcome, or null. */
	readonly lastMessage: {
		readonly status: string;
		readonly reason: string;
		readonly at: string;
	} | null;
}

/** The outcome of a test message. */
export interface ChatbotTest {
	/** sent, dropped, rate_limited, unauthorized, forbidden or failed. */
	readonly result: string;
	readonly reason: string;
	/** The message as it was sent. */
	readonly message: string;
}

/**
 * Validates the chatbot answer.
 *
 * @param body Response body.
 */
export function parseChatbot( body: unknown ): ChatbotState | null {
	if (
		! isRecord( body ) ||
		typeof body.available !== 'boolean' ||
		typeof body.bot_login !== 'string' ||
		( body.bot_login !== '' && ! LOGIN.test( body.bot_login ) ) ||
		( body.mode !== 'line' && body.mode !== 'order' ) ||
		typeof body.item_format !== 'string' ||
		typeof body.separator !== 'string' ||
		! Array.isArray( body.variables ) ||
		! ( body.variables as unknown[] ).every(
			( item ) => typeof item === 'string'
		) ||
		typeof body.enabled !== 'boolean' ||
		typeof body.template !== 'string' ||
		typeof body.default_template !== 'string'
	) {
		return null;
	}

	let channel: ChatbotState[ 'channel' ] = null;

	if ( body.channel !== null ) {
		if (
			! isRecord( body.channel ) ||
			typeof body.channel.login !== 'string' ||
			! LOGIN.test( body.channel.login ) ||
			typeof body.channel.connected_at !== 'string' ||
			! ISO_UTC.test( body.channel.connected_at )
		) {
			return null;
		}

		channel = {
			login: body.channel.login,
			connectedAt: body.channel.connected_at,
		};
	}

	let lastMessage: ChatbotState[ 'lastMessage' ] = null;

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

	return {
		available: body.available,
		botLogin: body.bot_login,
		mode: body.mode,
		itemFormat: body.item_format,
		separator: body.separator,
		variables: body.variables as string[],
		enabled: body.enabled,
		template: body.template,
		defaultTemplate: body.default_template,
		channel,
		lastMessage,
	};
}

function parseUrl( body: unknown ): string | null {
	if ( ! isRecord( body ) || typeof body.url !== 'string' ) {
		return null;
	}

	try {
		const url = new URL( body.url );

		// Only Twitch's authorization page.
		return url.protocol === 'https:' && url.host === 'id.twitch.tv'
			? url.href
			: null;
	} catch {
		return null;
	}
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
 * Saves whether purchases are announced and the template.
 *
 * @param changes          Changes.
 * @param changes.enabled  Messages on or off.
 * @param changes.template Template; '' for the default.
 */
export function updateChatbot( changes: {
	readonly enabled: boolean;
	readonly template: string;
} ): Promise< ApiResult< ChatbotState > > {
	return request(
		{ path: PATH, method: 'PATCH', data: { ...changes } },
		parseChatbot
	);
}

/**
 * Twitch's page where the creator adds the bot to the own channel.
 */
export function connectChatbot(): Promise< ApiResult< string > > {
	return request( { path: `${ PATH }/connect`, method: 'POST' }, parseUrl );
}

/**
 * Removes the bot from the creator's channel.
 */
export function disconnectChatbot(): Promise< ApiResult< ChatbotState > > {
	return request(
		{ path: `${ PATH }/channel`, method: 'DELETE' },
		parseChatbot
	);
}

/**
 * Sends a test message to the creator's channel.
 */
export function testChatbot(): Promise< ApiResult< ChatbotTest > > {
	return request( { path: `${ PATH }/test`, method: 'POST' }, parseTest );
}
