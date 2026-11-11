/**
 * `/me/community/twitch-gifting` (ADR-107): the creator's Twitch gifting
 * (on/off, paused, the `!enter` window, the chat language), the connected
 * Twitch channel, whether the creator is live and the latest gifts.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export type GiftLanguage = 'da' | 'en';

export type GiftStatus =
	'waiting' | 'entering' | 'won' | 'claimed' | 'returned' | 'cancelled';

const STATUSES: readonly GiftStatus[] = [
	'waiting',
	'entering',
	'won',
	'claimed',
	'returned',
	'cancelled',
];

export interface GiftRow {
	readonly id: number;
	readonly status: GiftStatus;
	/** The product's name ('' when it is gone). */
	readonly product: string;
	/** The buyer's chosen name, or null for an anonymous gift. */
	readonly buyer: string | null;
	/** The winner's Twitch login, or null. */
	readonly winner: string | null;
	readonly draws: number;
	/** UTC, `YYYY-MM-DD HH:MM:SS`. */
	readonly createdAt: string;
	/** UTC deadline of a won gift, or null. */
	readonly claimDeadline: string | null;
}

export interface GiftingValues {
	readonly enabled: boolean;
	/** `!enter` window in seconds. */
	readonly window: number;
	readonly language: GiftLanguage;
}

export interface GiftingState extends GiftingValues {
	readonly paused: boolean;
	readonly windowMin: number;
	readonly windowMax: number;
	/** The connected Twitch channel's login, or null. */
	readonly channel: string | null;
	/** Whether Twitch is set up on the site. */
	readonly twitch: boolean;
	/** Whether the creator is live; null without a channel. */
	readonly live: boolean | null;
	/** Whether an administrator set the test mode (the creator counts as live). */
	readonly test: boolean;
	readonly gifts: readonly GiftRow[];
	/** After "start": whether a gift started. */
	readonly started?: boolean;
}

const PATH = '/merchboard/v1/me/community/twitch-gifting';
const DATE = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;

/**
 * A positive whole number, or null.
 *
 * @param value Value.
 */
function whole( value: unknown ): number | null {
	return typeof value === 'number' && Number.isInteger( value ) && value >= 0
		? value
		: null;
}

/**
 * A string or null; undefined when it is neither.
 *
 * @param value Value.
 */
function optional( value: unknown ): string | null | undefined {
	return value === null || typeof value === 'string' ? value : undefined;
}

/**
 * One gift from the response, or null.
 *
 * @param body Gift.
 */
function parseGift( body: unknown ): GiftRow | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const id = whole( body.id );
	const draws = whole( body.draws );
	const buyer = optional( body.buyer );
	const winner = optional( body.winner );
	const deadline = optional( body.claim_deadline );
	const { status, product } = body;
	const created = body.created_at;

	if (
		id === null ||
		draws === null ||
		buyer === undefined ||
		winner === undefined ||
		deadline === undefined ||
		( deadline !== null && ! DATE.test( deadline ) ) ||
		typeof product !== 'string' ||
		typeof created !== 'string' ||
		! DATE.test( created ) ||
		! STATUSES.includes( status as GiftStatus )
	) {
		return null;
	}

	return {
		id,
		status: status as GiftStatus,
		product,
		buyer,
		winner,
		draws,
		createdAt: created,
		claimDeadline: deadline,
	};
}

/**
 * Validates the response.
 *
 * @param body Response body.
 */
export function parseGifting( body: unknown ): GiftingState | null {
	if (
		! isRecord( body ) ||
		! isRecord( body.limits ) ||
		! Array.isArray( body.gifts )
	) {
		return null;
	}

	const { enabled, paused, language, channel, twitch, live, test, started } =
		body;
	const window = whole( body.window );
	const windowMin = whole( body.limits.window_min );
	const windowMax = whole( body.limits.window_max );
	const gifts = body.gifts.map( parseGift );

	if (
		typeof enabled !== 'boolean' ||
		typeof paused !== 'boolean' ||
		typeof twitch !== 'boolean' ||
		typeof test !== 'boolean' ||
		( live !== null && typeof live !== 'boolean' ) ||
		( started !== undefined && typeof started !== 'boolean' ) ||
		( language !== 'da' && language !== 'en' ) ||
		window === null ||
		windowMin === null ||
		windowMax === null ||
		gifts.some( ( gift ) => gift === null ) ||
		( channel !== null &&
			( ! isRecord( channel ) ||
				typeof channel.login !== 'string' ||
				! /^\w{1,25}$/.test( channel.login ) ) )
	) {
		return null;
	}

	return {
		enabled,
		paused,
		window,
		language,
		windowMin,
		windowMax,
		channel:
			channel !== null && isRecord( channel )
				? String( channel.login )
				: null,
		twitch,
		live,
		test,
		gifts: gifts as GiftRow[],
		...( started === undefined ? {} : { started } ),
	};
}

/**
 * The creator's gifting.
 *
 * @param signal Abort signal.
 */
export function getGifting(
	signal?: AbortSignal
): Promise< ApiResult< GiftingState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseGifting
	);
}

/**
 * Saves on/off, the window and the language.
 *
 * @param values The form.
 */
export function saveGifting(
	values: GiftingValues
): Promise< ApiResult< GiftingState > > {
	return request(
		{
			path: PATH,
			method: 'POST',
			data: {
				enabled: values.enabled,
				window: values.window,
				language: values.language,
			},
		},
		parseGifting
	);
}

/**
 * Pauses or resumes gifting (the chat is told).
 *
 * @param paused Paused.
 */
export function pauseGifting(
	paused: boolean
): Promise< ApiResult< GiftingState > > {
	return request(
		{ path: `${ PATH }/pause`, method: 'POST', data: { paused } },
		parseGifting
	);
}

/** Starts the waiting gifts in the chat (as `!gift start`). */
export function startGifts(): Promise< ApiResult< GiftingState > > {
	return request( { path: `${ PATH }/start`, method: 'POST' }, parseGifting );
}
