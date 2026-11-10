/**
 * `GET` and `POST /me/community/twitch-sub-discount` (ADR-106): the
 * creator's discount campaigns for Twitch subscribers, the creator's profit
 * percent per product (the most a campaign may give is the lowest among its
 * products), the connected Twitch channel and whether Twitch is set up on
 * the site.
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

export interface TwitchSubCampaign {
	/** '' for a new campaign; the server gives it an id. */
	readonly id: string;
	readonly name: string;
	readonly enabled: boolean;
	readonly tiers: TwitchSubTiers;
	readonly scope: TwitchSubScope;
	/** Selected parent products (scope `selected`). */
	readonly productIds: readonly number[];
	/** First day (YYYY-MM-DD) or ''. */
	readonly starts: string;
	/** Last day (YYYY-MM-DD) or ''. */
	readonly ends: string;
}

export interface TwitchSubDiscountValues {
	/** Whether gifted subscriptions count (all campaigns). */
	readonly gifted: boolean;
	readonly campaigns: readonly TwitchSubCampaign[];
}

export interface TwitchSubDiscountState extends TwitchSubDiscountValues {
	/** The creator's profit percent by product id. */
	readonly profitPercents: Readonly< Record< number, number > >;
	/** The connected Twitch channel's login, or null. */
	readonly channel: string | null;
	/** Whether Twitch is set up on the site (a channel can be connected). */
	readonly twitch: boolean;
}

/** Most campaigns. */
export const MAX_CAMPAIGNS = 20;

const PATH = '/merchboard/v1/me/community/twitch-sub-discount';
const DAY = /^\d{4}-\d{2}-\d{2}$/;

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
 * A day or '' (null in the response), or null when invalid.
 *
 * @param value Value.
 */
function day( value: unknown ): string | null {
	if ( value === null ) {
		return '';
	}

	return typeof value === 'string' && DAY.test( value ) ? value : null;
}

/**
 * One campaign from the response, or null.
 *
 * @param body Campaign.
 */
function parseCampaign( body: unknown ): TwitchSubCampaign | null {
	if ( ! isRecord( body ) || ! isRecord( body.tiers ) ) {
		return null;
	}

	const { id, name, enabled, scope } = body;
	const ids = body.product_ids;
	const tier1 = percent( body.tiers[ '1' ] );
	const tier2 = percent( body.tiers[ '2' ] );
	const tier3 = percent( body.tiers[ '3' ] );
	const starts = day( body.starts );
	const ends = day( body.ends );

	if (
		typeof id !== 'string' ||
		! /^[0-9a-f]{16}$/.test( id ) ||
		typeof name !== 'string' ||
		typeof enabled !== 'boolean' ||
		( scope !== 'all' && scope !== 'selected' ) ||
		tier1 === null ||
		tier2 === null ||
		tier3 === null ||
		starts === null ||
		ends === null ||
		! Array.isArray( ids ) ||
		! ids.every(
			( product ) =>
				typeof product === 'number' &&
				Number.isInteger( product ) &&
				product > 0
		)
	) {
		return null;
	}

	return {
		id,
		name,
		enabled,
		tiers: { 1: tier1, 2: tier2, 3: tier3 },
		scope,
		productIds: ids as number[],
		starts,
		ends,
	};
}

/**
 * Validates the response.
 *
 * @param body Response body.
 */
export function parseTwitchSubDiscount(
	body: unknown
): TwitchSubDiscountState | null {
	if (
		! isRecord( body ) ||
		! Array.isArray( body.campaigns ) ||
		! isRecord( body.profit_percents )
	) {
		return null;
	}

	const profitPercents: Record< number, number > = {};

	for ( const [ key, value ] of Object.entries( body.profit_percents ) ) {
		const product = Number( key );
		const share = percent( value );

		if ( ! Number.isInteger( product ) || product < 1 || share === null ) {
			return null;
		}

		profitPercents[ product ] = share;
	}

	const { gifted, twitch, channel } = body;
	const campaigns = body.campaigns.map( parseCampaign );

	if (
		typeof gifted !== 'boolean' ||
		typeof twitch !== 'boolean' ||
		campaigns.some( ( campaign ) => campaign === null ) ||
		( channel !== null &&
			( ! isRecord( channel ) ||
				typeof channel.login !== 'string' ||
				! /^\w{1,25}$/.test( channel.login ) ) )
	) {
		return null;
	}

	return {
		gifted,
		campaigns: campaigns as TwitchSubCampaign[],
		profitPercents,
		channel:
			channel !== null && isRecord( channel )
				? String( channel.login )
				: null,
		twitch,
	};
}

/**
 * The most a campaign may give: the creator's lowest profit percent among
 * its products (all products for "all"), or null when there are none.
 *
 * @param campaign       Campaign.
 * @param profitPercents Profit percent by product.
 */
export function maxPercent(
	campaign: Pick< TwitchSubCampaign, 'scope' | 'productIds' >,
	profitPercents: Readonly< Record< number, number > >
): number | null {
	const shares =
		campaign.scope === 'all'
			? Object.values( profitPercents )
			: campaign.productIds
					.map( ( id ) => profitPercents[ id ] )
					.filter(
						( share ): share is number => share !== undefined
					);

	return shares.length === 0 ? null : Math.min( ...shares );
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
 * Saves all campaigns.
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
				gifted: values.gifted,
				campaigns: values.campaigns.map( ( campaign ) => ( {
					id: campaign.id,
					name: campaign.name,
					enabled: campaign.enabled,
					tiers: {
						1: campaign.tiers[ 1 ],
						2: campaign.tiers[ 2 ],
						3: campaign.tiers[ 3 ],
					},
					scope: campaign.scope,
					product_ids:
						campaign.scope === 'selected'
							? [ ...campaign.productIds ]
							: [],
					starts: campaign.starts,
					ends: campaign.ends,
				} ) ),
			},
		},
		parseTwitchSubDiscount
	);
}
