/**
 * `/me/alerts/chatbot/merch-command` (ADR-113 pkt. 5): the creator's !merch
 * chat command (on/off, text and cooldown), the default text, an example of
 * the answer and the channels it works in.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export interface MerchCommandValues {
	readonly enabled: boolean;
	/** The creator's text; '' for the default. */
	readonly text: string;
	/** Seconds between two answers. */
	readonly cooldown: number;
}

export interface MerchCommandState extends MerchCommandValues {
	readonly defaultText: string;
	/** The answer as the chat sees it. */
	readonly example: string;
	/** The creator's collection. */
	readonly link: string;
	/** The connected channels that answer (`twitch`, `kick`). */
	readonly channels: readonly string[];
	readonly maxText: number;
	readonly cooldownLimits: readonly [ number, number ];
}

const PATH = '/merchboard/v1/me/alerts/chatbot/merch-command';

/**
 * Validates the response.
 *
 * @param body Response body.
 */
export function parseMerchCommand( body: unknown ): MerchCommandState | null {
	if (
		! isRecord( body ) ||
		! isRecord( body.limits ) ||
		! Array.isArray( body.channels ) ||
		! Array.isArray( body.limits.cooldown )
	) {
		return null;
	}

	const { enabled, text, cooldown, example, link } = body;
	const defaultText = body.default_text;
	const maxText = body.limits.max_text;
	const [ least, most ] = body.limits.cooldown as unknown[];

	if (
		typeof enabled !== 'boolean' ||
		typeof text !== 'string' ||
		typeof cooldown !== 'number' ||
		typeof defaultText !== 'string' ||
		typeof example !== 'string' ||
		typeof link !== 'string' ||
		! /^https?:\/\//.test( link ) ||
		typeof maxText !== 'number' ||
		typeof least !== 'number' ||
		typeof most !== 'number' ||
		body.channels.some(
			( channel ) => channel !== 'twitch' && channel !== 'kick'
		)
	) {
		return null;
	}

	return {
		enabled,
		text,
		cooldown,
		defaultText,
		example,
		link,
		channels: body.channels as string[],
		maxText,
		cooldownLimits: [ least, most ],
	};
}

/**
 * The creator's command.
 *
 * @param signal Abort signal.
 */
export function getMerchCommand(
	signal?: AbortSignal
): Promise< ApiResult< MerchCommandState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseMerchCommand
	);
}

/**
 * Saves the command.
 *
 * @param values The form.
 */
export function saveMerchCommand(
	values: MerchCommandValues
): Promise< ApiResult< MerchCommandState > > {
	return request(
		{
			path: PATH,
			method: 'POST',
			data: {
				enabled: values.enabled,
				text: values.text,
				cooldown: values.cooldown,
			},
		},
		parseMerchCommand
	);
}
