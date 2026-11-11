/**
 * `/me/community/giveaways` (ADR-110): the creator's own giveaways (never
 * mixed up with the gifts viewers buy for Twitch gifting), the products
 * that can be given away with their price, the available balance and the
 * connected Twitch channel for giveaways drawn by the bot.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export type GiveawayStatus =
	'awaiting_payment' | 'ready' | 'used' | 'expired' | 'cancelled';

/** `bot`: drawn in the Twitch chat; `manual`: a link for the creator's own giveaway. */
export type GiveawayMode = 'bot' | 'manual';

/** `balance`: from the balance when the code is used; `shop`: paid in the shop. */
export type GiveawayFunding = 'balance' | 'shop';

export type GiveawayGiftStatus =
	'waiting' | 'entering' | 'won' | 'claimed' | 'returned' | 'cancelled';

const STATUSES: readonly GiveawayStatus[] = [
	'awaiting_payment',
	'ready',
	'used',
	'expired',
	'cancelled',
];

const GIFT_STATUSES: readonly GiveawayGiftStatus[] = [
	'waiting',
	'entering',
	'won',
	'claimed',
	'returned',
	'cancelled',
];

export interface GiveawayGift {
	readonly status: GiveawayGiftStatus;
	/** The Twitch winner's login, or null (a link has none). */
	readonly winner: string | null;
	readonly draws: number;
	/** The link to pass on while nobody has redeemed it, or null. */
	readonly link: string | null;
	/** UTC deadline while the gift is won or a link, or null. */
	readonly deadline: string | null;
}

export interface GiveawayRow {
	readonly id: number;
	readonly status: GiveawayStatus;
	readonly mode: GiveawayMode;
	readonly funding: GiveawayFunding;
	/** The product's name ('' when it is gone). */
	readonly product: string;
	/** What the creator pays (decimal). */
	readonly cost: string;
	readonly currency: string;
	/** The `!enter` window in seconds. */
	readonly window: number;
	/** UTC, `YYYY-MM-DD HH:MM:SS`. */
	readonly createdAt: string;
	/** The shop checkout while it awaits payment, or null. */
	readonly payUrl: string | null;
	readonly gift: GiveawayGift | null;
}

export interface GiveawayProduct {
	readonly id: number;
	readonly name: string;
	/** Thumbnail URL, or ''. */
	readonly image: string;
	/** Base price plus 50 kr. shipping (decimal). */
	readonly cost: string;
	readonly currency: string;
}

export interface GiveawaysState {
	readonly giveaways: readonly GiveawayRow[];
	readonly products: readonly GiveawayProduct[];
	/** The available balance (decimal), or null when unknown. */
	readonly available: string | null;
	readonly windowMin: number;
	readonly windowMax: number;
	readonly windowDefault: number;
	readonly maxOpen: number;
	/** The connected Twitch channel's login, or null. */
	readonly channel: string | null;
	/** Whether Twitch is set up on the site. */
	readonly twitch: boolean;
	/** Whether the creator is live; null without a channel. */
	readonly live: boolean | null;
	/** After "create": the new giveaway's id. */
	readonly created?: number;
	/** After "start": `started`, `not_live`, `busy` or `not_waiting`. */
	readonly started?: string;
}

export interface NewGiveaway {
	readonly productId: number;
	readonly mode: GiveawayMode;
	readonly funding: GiveawayFunding;
	readonly window: number;
}

const PATH = '/merchboard/v1/me/community/giveaways';
const DATE = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;
const AMOUNT = /^-?\d+(\.\d+)?$/;

/**
 * A whole number ≥ 0, or null.
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
 * A giveaway's gift, null for none, undefined when invalid.
 *
 * @param body Gift.
 */
function parseGift( body: unknown ): GiveawayGift | null | undefined {
	if ( body === null ) {
		return null;
	}

	if ( ! isRecord( body ) ) {
		return undefined;
	}

	const draws = whole( body.draws );
	const winner = optional( body.winner );
	const link = optional( body.link );
	const deadline = optional( body.deadline );

	if (
		draws === null ||
		winner === undefined ||
		link === undefined ||
		deadline === undefined ||
		( deadline !== null && ! DATE.test( deadline ) ) ||
		( link !== null && ! /^https?:\/\//.test( link ) ) ||
		! GIFT_STATUSES.includes( body.status as GiveawayGiftStatus )
	) {
		return undefined;
	}

	return {
		status: body.status as GiveawayGiftStatus,
		winner,
		draws,
		link,
		deadline,
	};
}

/**
 * One giveaway, or null.
 *
 * @param body Giveaway.
 */
function parseGiveaway( body: unknown ): GiveawayRow | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const id = whole( body.id );
	const window = whole( body.window );
	const payUrl = optional( body.pay_url );
	const gift = parseGift( body.gift );
	const { status, mode, funding, product, cost, currency } = body;
	const created = body.created_at;

	if (
		id === null ||
		window === null ||
		payUrl === undefined ||
		gift === undefined ||
		! STATUSES.includes( status as GiveawayStatus ) ||
		( mode !== 'bot' && mode !== 'manual' ) ||
		( funding !== 'balance' && funding !== 'shop' ) ||
		typeof product !== 'string' ||
		typeof cost !== 'string' ||
		! AMOUNT.test( cost ) ||
		typeof currency !== 'string' ||
		typeof created !== 'string' ||
		! DATE.test( created )
	) {
		return null;
	}

	return {
		id,
		status: status as GiveawayStatus,
		mode,
		funding,
		product,
		cost,
		currency,
		window,
		createdAt: created,
		payUrl,
		gift,
	};
}

/**
 * One product, or null.
 *
 * @param body Product.
 */
function parseProduct( body: unknown ): GiveawayProduct | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const id = whole( body.id );
	const { name, image, cost, currency } = body;

	if (
		id === null ||
		typeof name !== 'string' ||
		typeof image !== 'string' ||
		typeof cost !== 'string' ||
		! AMOUNT.test( cost ) ||
		typeof currency !== 'string'
	) {
		return null;
	}

	return { id, name, image, cost, currency };
}

/**
 * Validates the response.
 *
 * @param body Response body.
 */
export function parseGiveaways( body: unknown ): GiveawaysState | null {
	if (
		! isRecord( body ) ||
		! isRecord( body.limits ) ||
		! Array.isArray( body.giveaways ) ||
		! Array.isArray( body.products )
	) {
		return null;
	}

	const { available, channel, twitch, live, created, started } = body;
	const windowMin = whole( body.limits.window_min );
	const windowMax = whole( body.limits.window_max );
	const windowDefault = whole( body.limits.window_default );
	const maxOpen = whole( body.limits.max_open );
	const giveaways = body.giveaways.map( parseGiveaway );
	const products = body.products.map( parseProduct );

	if (
		typeof twitch !== 'boolean' ||
		( live !== null && typeof live !== 'boolean' ) ||
		( available !== null &&
			( typeof available !== 'string' || ! AMOUNT.test( available ) ) ) ||
		( created !== undefined && whole( created ) === null ) ||
		( started !== undefined && typeof started !== 'string' ) ||
		windowMin === null ||
		windowMax === null ||
		windowDefault === null ||
		maxOpen === null ||
		giveaways.some( ( row ) => row === null ) ||
		products.some( ( row ) => row === null ) ||
		( channel !== null &&
			( ! isRecord( channel ) ||
				typeof channel.login !== 'string' ||
				! /^\w{1,25}$/.test( channel.login ) ) )
	) {
		return null;
	}

	return {
		giveaways: giveaways as GiveawayRow[],
		products: products as GiveawayProduct[],
		available,
		windowMin,
		windowMax,
		windowDefault,
		maxOpen,
		channel:
			channel !== null && isRecord( channel )
				? String( channel.login )
				: null,
		twitch,
		live,
		...( created === undefined ? {} : { created: created as number } ),
		...( started === undefined ? {} : { started: started as string } ),
	};
}

/**
 * The creator's giveaways.
 *
 * @param signal Abort signal.
 */
export function getGiveaways(
	signal?: AbortSignal
): Promise< ApiResult< GiveawaysState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseGiveaways
	);
}

/**
 * Creates a giveaway.
 *
 * @param values The form.
 */
export function createGiveaway(
	values: NewGiveaway
): Promise< ApiResult< GiveawaysState > > {
	return request(
		{
			path: PATH,
			method: 'POST',
			data: {
				product_id: values.productId,
				mode: values.mode,
				funding: values.funding,
				window: values.window,
			},
		},
		parseGiveaways
	);
}

/**
 * Starts a bot giveaway in the chat.
 *
 * @param id Giveaway.
 */
export function startGiveaway(
	id: number
): Promise< ApiResult< GiveawaysState > > {
	return request(
		{ path: `${ PATH }/${ id }/start`, method: 'POST' },
		parseGiveaways
	);
}

/**
 * Cancels a giveaway.
 *
 * @param id Giveaway.
 */
export function cancelGiveaway(
	id: number
): Promise< ApiResult< GiveawaysState > > {
	return request(
		{ path: `${ PATH }/${ id }/cancel`, method: 'POST' },
		parseGiveaways
	);
}
