/**
 * `/me/community/twitch-extension` (ADR-112): the creator's panel in the
 * shop's Twitch Extension (on/off and the chosen products in order), a
 * preview of what it shows, the connected Twitch channel and where to
 * install the extension.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export interface PanelProduct {
	readonly id: number;
	readonly name: string;
	/** Image URL, or null without an image. */
	readonly image: string | null;
}

export interface TwitchExtensionValues {
	readonly enabled: boolean;
	/** Chosen products in order; empty shows the newest. */
	readonly productIds: readonly number[];
}

export interface TwitchExtensionState extends TwitchExtensionValues {
	readonly maxProducts: number;
	/** What the panel shows now. */
	readonly preview: readonly PanelProduct[];
	/** The connected Twitch channel's login, or null. */
	readonly channel: string | null;
	/** Whether the shop has set the extension up. */
	readonly ready: boolean;
	/** Whether Twitch is set up in the shop (the app and the bot). */
	readonly twitch: boolean;
	/** The extension's page on Twitch, or null. */
	readonly installUrl: string | null;
}

const PATH = '/merchboard/v1/me/community/twitch-extension';

/**
 * A positive whole number, or null.
 *
 * @param value Value.
 */
function positive( value: unknown ): number | null {
	return typeof value === 'number' && Number.isInteger( value ) && value > 0
		? value
		: null;
}

/**
 * One preview product, or null.
 *
 * @param body Product.
 */
function parseProduct( body: unknown ): PanelProduct | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const id = positive( body.id );
	const { name, image } = body;

	if (
		id === null ||
		typeof name !== 'string' ||
		( image !== null &&
			( typeof image !== 'string' || ! /^https?:\/\//.test( image ) ) )
	) {
		return null;
	}

	return { id, name, image };
}

/**
 * Validates the response.
 *
 * @param body Response body.
 */
export function parseTwitchExtension(
	body: unknown
): TwitchExtensionState | null {
	if (
		! isRecord( body ) ||
		! Array.isArray( body.product_ids ) ||
		! Array.isArray( body.preview )
	) {
		return null;
	}

	const { enabled, ready, twitch, channel } = body;
	const installUrl = body.install_url;
	const maxProducts = positive( body.max_products );
	const productIds = body.product_ids.map( positive );
	const preview = body.preview.map( parseProduct );

	if (
		typeof enabled !== 'boolean' ||
		typeof ready !== 'boolean' ||
		typeof twitch !== 'boolean' ||
		maxProducts === null ||
		productIds.some( ( id ) => id === null ) ||
		preview.some( ( product ) => product === null ) ||
		( installUrl !== null &&
			( typeof installUrl !== 'string' ||
				! /^https:\/\/(dashboard|www)\.twitch\.tv\//.test(
					installUrl
				) ) ) ||
		( channel !== null &&
			( ! isRecord( channel ) ||
				typeof channel.login !== 'string' ||
				! /^\w{1,25}$/.test( channel.login ) ) )
	) {
		return null;
	}

	return {
		enabled,
		productIds: productIds as number[],
		maxProducts,
		preview: preview as PanelProduct[],
		channel:
			channel !== null && isRecord( channel )
				? String( channel.login )
				: null,
		ready,
		twitch,
		installUrl: installUrl as string | null,
	};
}

/**
 * The creator's panel.
 *
 * @param signal Abort signal.
 */
export function getTwitchExtension(
	signal?: AbortSignal
): Promise< ApiResult< TwitchExtensionState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseTwitchExtension
	);
}

/**
 * Saves on/off and the chosen products.
 *
 * @param values The form.
 */
export function saveTwitchExtension(
	values: TwitchExtensionValues
): Promise< ApiResult< TwitchExtensionState > > {
	return request(
		{
			path: PATH,
			method: 'POST',
			data: {
				enabled: values.enabled,
				product_ids: values.productIds,
			},
		},
		parseTwitchExtension
	);
}
