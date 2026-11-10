/**
 * `GET` and `PUT /me/community/twitch-sub-discount` (ADR-106): the creator's
 * discount for Twitch subscribers, the connected Twitch channel and whether
 * Twitch is set up on the site.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export type TwitchSubScope = 'all' | 'selected';

/** Whole percents per tier (0–100). */
export interface TwitchSubTiers {
	readonly 1: number;
	readonly 2: number;
	readonly 3: number;
}

export interface TwitchSubDiscountValues {
	readonly enabled: boolean;
	readonly tiers: TwitchSubTiers;
	/** Whether gifted subscriptions count. */
	readonly gifted: boolean;
	readonly scope: TwitchSubScope;
	/** Selected parent products (scope `selected`). */
	readonly productIds: readonly number[];
}

export interface TwitchSubDiscountState extends TwitchSubDiscountValues {
	/** The connected Twitch channel's login, or null. */
	readonly channel: string | null;
	/** Whether Twitch is set up on the site (a channel can be connected). */
	readonly twitch: boolean;
}

const PATH = '/merchboard/v1/me/community/twitch-sub-discount';

/**
 * A whole percent 0–100, or null.
 *
 * @param value Value.
 */
function percent( value: unknown ): number | null {
	return typeof value === 'number' &&
		Number.isInteger( value ) &&
		value >= 0 &&
		value <= 100
		? value
		: null;
}

/**
 * Validates the response.
 *
 * @param body Response body.
 */
export function parseTwitchSubDiscount(
	body: unknown
): TwitchSubDiscountState | null {
	if ( ! isRecord( body ) || ! isRecord( body.tiers ) ) {
		return null;
	}

	const { enabled, gifted, scope, twitch, channel } = body;
	const ids = body.product_ids;
	const tier1 = percent( body.tiers[ '1' ] );
	const tier2 = percent( body.tiers[ '2' ] );
	const tier3 = percent( body.tiers[ '3' ] );

	if (
		typeof enabled !== 'boolean' ||
		typeof gifted !== 'boolean' ||
		typeof twitch !== 'boolean' ||
		( scope !== 'all' && scope !== 'selected' ) ||
		tier1 === null ||
		tier2 === null ||
		tier3 === null ||
		! Array.isArray( ids ) ||
		! ids.every(
			( id ) => typeof id === 'number' && Number.isInteger( id ) && id > 0
		) ||
		( channel !== null &&
			( ! isRecord( channel ) ||
				typeof channel.login !== 'string' ||
				! /^\w{1,25}$/.test( channel.login ) ) )
	) {
		return null;
	}

	return {
		enabled,
		tiers: { 1: tier1, 2: tier2, 3: tier3 },
		gifted,
		scope,
		productIds: ids as number[],
		channel:
			channel !== null && isRecord( channel )
				? String( channel.login )
				: null,
		twitch,
	};
}

/**
 * The creator's settings.
 *
 * @param signal Abort signal.
 */
export function getTwitchSubDiscount(
	signal?: AbortSignal
): Promise< ApiResult< TwitchSubDiscountState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseTwitchSubDiscount
	);
}

/**
 * Saves every field.
 *
 * @param values The form.
 */
export function saveTwitchSubDiscount(
	values: TwitchSubDiscountValues
): Promise< ApiResult< TwitchSubDiscountState > > {
	return request(
		{
			path: PATH,
			method: 'POST',
			data: {
				enabled: values.enabled,
				tiers: {
					1: values.tiers[ 1 ],
					2: values.tiers[ 2 ],
					3: values.tiers[ 3 ],
				},
				gifted: values.gifted,
				scope: values.scope,
				product_ids:
					values.scope === 'selected' ? [ ...values.productIds ] : [],
			},
		},
		parseTwitchSubDiscount
	);
}
