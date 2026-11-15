/**
 * `/me/community/merch-ad` (ADR-113): the creator's ad in the stream (design,
 * products, pill, timing, animations and colours), the OBS link and the
 * products with shop and background-free images.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export type MerchAdDesign =
	'spotlight' | 'shelf' | 'carousel' | 'corner' | 'showcase' | 'stack';

export type MerchAdAnimation = 'fade' | 'slide' | 'pop' | 'zoom' | 'blur';

export const DESIGNS: readonly MerchAdDesign[] = [
	'spotlight',
	'shelf',
	'carousel',
	'corner',
	'showcase',
	'stack',
];

export const ANIMATIONS: readonly MerchAdAnimation[] = [
	'fade',
	'slide',
	'pop',
	'zoom',
	'blur',
];

export interface MerchAdProduct {
	readonly id: number;
	readonly name: string;
	/** The shop image, or null. */
	readonly image: string | null;
	/** The background-free image, or null. */
	readonly cutout: string | null;
}

export interface MerchAdValues {
	readonly enabled: boolean;
	readonly design: MerchAdDesign;
	readonly productIds: readonly number[];
	readonly note: string;
	readonly every: number;
	readonly seconds: number;
	readonly enter: MerchAdAnimation;
	readonly leave: MerchAdAnimation;
	readonly accent: string;
	readonly theme: 'dark' | 'light';
	/** The pill below the products, or over the product image (its lower third). */
	readonly pillPosition: 'below' | 'over';
	readonly pillSize: 'small' | 'medium' | 'large';
	/** Whether the products' names are shown. */
	readonly showNames: boolean;
	/** The stack: how far the two products behind stick out (0–100). */
	readonly stackSpread: number;
}

export interface MerchAdState extends MerchAdValues {
	/** The OBS link, or null before the first save. */
	readonly url: string | null;
	readonly previewUrl: string | null;
	readonly products: readonly MerchAdProduct[];
	readonly limits: {
		readonly maxProducts: number;
		readonly maxNote: number;
		readonly every: readonly [ number, number ];
		readonly seconds: readonly [ number, number ];
		readonly maxBytes: number;
		readonly maxPixels: number;
	};
}

const PATH = '/merchboard/v1/me/community/merch-ad';

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
 * A pair of positive whole numbers, or null.
 *
 * @param value Value.
 */
function pair( value: unknown ): [ number, number ] | null {
	const first = Array.isArray( value ) ? positive( value[ 0 ] ) : null;
	const second = Array.isArray( value ) ? positive( value[ 1 ] ) : null;

	return first === null || second === null ? null : [ first, second ];
}

/**
 * An http(s) URL or null; undefined when it is neither.
 *
 * @param value Value.
 */
function url( value: unknown ): string | null | undefined {
	if ( value === null ) {
		return null;
	}

	return typeof value === 'string' && /^https?:\/\//.test( value )
		? value
		: undefined;
}

/**
 * One product, or null.
 *
 * @param body Product.
 */
function parseProduct( body: unknown ): MerchAdProduct | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const id = positive( body.id );
	const image = url( body.image );
	const cutout = url( body.cutout );

	if (
		id === null ||
		typeof body.name !== 'string' ||
		image === undefined ||
		cutout === undefined
	) {
		return null;
	}

	return { id, name: body.name, image, cutout };
}

/**
 * Validates the response.
 *
 * @param body Response body.
 */
export function parseMerchAd( body: unknown ): MerchAdState | null {
	if (
		! isRecord( body ) ||
		! isRecord( body.limits ) ||
		! Array.isArray( body.product_ids ) ||
		! Array.isArray( body.products )
	) {
		return null;
	}

	const { limits } = body;
	const every = positive( body.every );
	const seconds = positive( body.seconds );
	const productIds = body.product_ids.map( positive );
	const products = body.products.map( parseProduct );
	const link = url( body.url );
	const preview = url( body.preview_url );
	const maxProducts = positive( limits.max_products );
	const maxNote = positive( limits.max_note );
	const everyLimits = pair( limits.every );
	const secondsLimits = pair( limits.seconds );
	const maxBytes = positive( limits.max_bytes );
	const maxPixels = positive( limits.max_pixels );

	if (
		typeof body.enabled !== 'boolean' ||
		! DESIGNS.includes( body.design as MerchAdDesign ) ||
		typeof body.note !== 'string' ||
		every === null ||
		seconds === null ||
		! ANIMATIONS.includes( body.enter as MerchAdAnimation ) ||
		! ANIMATIONS.includes( body.leave as MerchAdAnimation ) ||
		typeof body.accent !== 'string' ||
		! /^#[0-9a-f]{6}$/i.test( body.accent ) ||
		( body.theme !== 'dark' && body.theme !== 'light' ) ||
		( body.pill_position !== 'below' && body.pill_position !== 'over' ) ||
		! [ 'small', 'medium', 'large' ].includes( body.pill_size as string ) ||
		typeof body.show_names !== 'boolean' ||
		typeof body.stack_spread !== 'number' ||
		productIds.some( ( id ) => id === null ) ||
		products.some( ( product ) => product === null ) ||
		link === undefined ||
		preview === undefined ||
		maxProducts === null ||
		maxNote === null ||
		everyLimits === null ||
		secondsLimits === null ||
		maxBytes === null ||
		maxPixels === null
	) {
		return null;
	}

	return {
		enabled: body.enabled,
		design: body.design as MerchAdDesign,
		productIds: productIds as number[],
		note: body.note,
		every,
		seconds,
		enter: body.enter as MerchAdAnimation,
		leave: body.leave as MerchAdAnimation,
		accent: body.accent,
		theme: body.theme,
		pillPosition: body.pill_position,
		pillSize: body.pill_size as MerchAdValues[ 'pillSize' ],
		showNames: body.show_names,
		stackSpread: body.stack_spread,
		url: link,
		previewUrl: preview,
		products: products as MerchAdProduct[],
		limits: {
			maxProducts,
			maxNote,
			every: everyLimits,
			seconds: secondsLimits,
			maxBytes,
			maxPixels,
		},
	};
}

/**
 * The creator's ad.
 *
 * @param signal Abort signal.
 */
export function getMerchAd(
	signal?: AbortSignal
): Promise< ApiResult< MerchAdState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseMerchAd
	);
}

/**
 * Saves the form.
 *
 * @param values The form.
 */
export function saveMerchAd(
	values: MerchAdValues
): Promise< ApiResult< MerchAdState > > {
	return request(
		{
			path: PATH,
			method: 'POST',
			data: {
				enabled: values.enabled,
				design: values.design,
				product_ids: values.productIds,
				note: values.note,
				every: values.every,
				seconds: values.seconds,
				enter: values.enter,
				leave: values.leave,
				accent: values.accent,
				theme: values.theme,
				pill_position: values.pillPosition,
				pill_size: values.pillSize,
				show_names: values.showNames,
				stack_spread: values.stackSpread,
			},
		},
		parseMerchAd
	);
}

/**
 * A new OBS link (the old one stops working).
 */
export function regenerateMerchAd(): Promise< ApiResult< MerchAdState > > {
	return request(
		{ path: `${ PATH }/regenerate`, method: 'POST' },
		parseMerchAd
	);
}

/**
 * "Show now".
 */
export function showMerchAd(): Promise< ApiResult< MerchAdState > > {
	return request( { path: `${ PATH }/show`, method: 'POST' }, parseMerchAd );
}

/**
 * Stores a background-free image for a product.
 *
 * @param productId The product.
 * @param png       The image as a `data:image/png;base64,…` URL.
 */
export function addCutout(
	productId: number,
	png: string
): Promise< ApiResult< MerchAdState > > {
	return request(
		{
			path: `${ PATH }/cutouts`,
			method: 'POST',
			data: { product_id: productId, png },
		},
		parseMerchAd
	);
}

/**
 * Removes a product's background-free image.
 *
 * @param productId The product.
 */
export function removeCutout(
	productId: number
): Promise< ApiResult< MerchAdState > > {
	return request(
		{ path: `${ PATH }/cutouts/${ productId }`, method: 'DELETE' },
		parseMerchAd
	);
}
