/**
 * `/me/designer` (phase 30, steps 30.8–30.10, ADR-080): the base products,
 * files, fonts and designer products of the creator the server resolves, and
 * the submission of a draft with the files the browser rendered. Every
 * answer is validated; files are fetched with the session and shown as
 * `data:` URLs (the dashboard's CSP allows only `img-src 'self' data:`).
 */
import { request, requestFile } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';
import type { UnknownRecord } from './validate';
import type { DesignDocument } from '../sections/designer/design-model';
import { parseDesign } from '../sections/designer/design-model';

const PATH = '/merchboard/v1/me/designer';

export const LAYERS = [
	'background',
	'color',
	'shading',
	'highlight',
] as const;

export type LayerKind = ( typeof LAYERS )[ number ];

export interface DesignerColor {
	readonly key: string;
	readonly name: string;
	readonly hex: string;
}

export interface DesignerView {
	readonly key: string;
	readonly name: string;
	readonly width: number;
	readonly height: number;
	readonly layers: Partial< Record< LayerKind, number > >;
	readonly area: {
		readonly x: number;
		readonly y: number;
		readonly width: number;
		readonly height: number;
	};
	readonly printWidth: number;
	readonly printHeight: number;
	readonly dpi: number;
}

export interface DesignerBase {
	readonly id: number;
	readonly name: string;
	readonly description: string;
	readonly basePrice: string;
	readonly currency: string;
	readonly colors: readonly DesignerColor[];
	readonly sizes: readonly string[];
	readonly views: readonly DesignerView[];
}

export interface DesignerAsset {
	readonly id: number;
	readonly name: string;
	readonly width: number;
	readonly height: number;
	readonly mime: string;
}

export interface DesignerFont {
	readonly family: string;
	readonly weights: readonly number[];
}

export interface DesignerProductSummary {
	readonly id: number;
	readonly baseId: number;
	readonly title: string;
	readonly status: string;
	readonly inShop: boolean;
	readonly updatedAt: string;
}

export interface DesignerOverview {
	readonly bases: readonly DesignerBase[];
	readonly cliparts: readonly DesignerAsset[];
	readonly uploads: readonly DesignerAsset[];
	readonly fonts: readonly DesignerFont[];
	readonly products: readonly DesignerProductSummary[];
	readonly maxUploadBytes: number;
	readonly maxObjects: number;
	readonly priceDecimals: number;
}

export type ProfitKind = 'percent' | 'fixed';

export interface DesignerDraft {
	readonly number: number;
	readonly title: string;
	readonly description: string;
	readonly colors: readonly string[];
	readonly sizes: readonly string[];
	readonly design: DesignDocument;
	readonly profitKind: ProfitKind;
	readonly profitValue: string;
	readonly price: string | null;
	readonly updatedAt: string;
}

/** The version waiting for approval. */
export interface DesignerPending {
	readonly number: number;
	readonly title: string;
	readonly price: string | null;
	readonly submittedAt: string;
}

/** The version in the shop. */
export interface DesignerLive {
	readonly number: number;
	readonly title: string;
	readonly price: string | null;
}

/** The administrator's rejection a draft continues. */
export interface DesignerRejection {
	readonly number: number;
	readonly note: string;
	readonly decidedAt: string;
}

export interface DesignerProduct {
	readonly id: number;
	readonly baseId: number;
	readonly inShop: boolean;
	readonly draft: DesignerDraft | null;
	readonly pending: DesignerPending | null;
	readonly live: DesignerLive | null;
	readonly rejection: DesignerRejection | null;
}

const isInt = ( value: unknown, min = 0 ): value is number =>
	typeof value === 'number' && Number.isInteger( value ) && value >= min;

const isString = ( value: unknown ): value is string =>
	typeof value === 'string';

const DECIMAL = /^-?\d{1,15}\.\d{4}$/;

/**
 * A list where every item passes, or null.
 *
 * @param value Value.
 * @param item  Item parser.
 */
function list< T >(
	value: unknown,
	item: ( entry: unknown ) => T | null
): T[] | null {
	if ( ! Array.isArray( value ) ) {
		return null;
	}

	const result: T[] = [];

	for ( const entry of value ) {
		const parsed = item( entry );

		if ( parsed === null ) {
			return null;
		}

		result.push( parsed );
	}

	return result;
}

/**
 * A string list.
 *
 * @param value Value.
 */
function strings( value: unknown ): string[] | null {
	return list( value, ( entry ) => ( isString( entry ) ? entry : null ) );
}

/**
 * An asset.
 *
 * @param value Value.
 */
export function parseAsset( value: unknown ): DesignerAsset | null {
	return isRecord( value ) &&
		isInt( value.id, 1 ) &&
		isString( value.name ) &&
		isInt( value.width, 1 ) &&
		isInt( value.height, 1 ) &&
		isString( value.mime ) &&
		value.mime.startsWith( 'image/' )
		? {
				id: value.id,
				name: value.name,
				width: value.width,
				height: value.height,
				mime: value.mime,
			}
		: null;
}

/**
 * A side.
 *
 * @param value Value.
 */
function parseView( value: unknown ): DesignerView | null {
	if (
		! isRecord( value ) ||
		! isString( value.key ) ||
		! isString( value.name ) ||
		! isInt( value.width, 1 ) ||
		! isInt( value.height, 1 ) ||
		! isRecord( value.layers ) ||
		! isRecord( value.area ) ||
		! isInt( value.print_width, 1 ) ||
		! isInt( value.print_height, 1 ) ||
		! isInt( value.dpi, 1 )
	) {
		return null;
	}

	const layers: Partial< Record< LayerKind, number > > = {};

	for ( const kind of LAYERS ) {
		const id = ( value.layers as UnknownRecord )[ kind ];

		if ( id !== undefined ) {
			if ( ! isInt( id, 1 ) ) {
				return null;
			}

			layers[ kind ] = id;
		}
	}

	const area = value.area as UnknownRecord;

	if (
		! isInt( area.x ) ||
		! isInt( area.y ) ||
		! isInt( area.width, 1 ) ||
		! isInt( area.height, 1 )
	) {
		return null;
	}

	return {
		key: value.key,
		name: value.name,
		width: value.width,
		height: value.height,
		layers,
		area: { x: area.x, y: area.y, width: area.width, height: area.height },
		printWidth: value.print_width,
		printHeight: value.print_height,
		dpi: value.dpi,
	};
}

/**
 * A base product.
 *
 * @param value Value.
 */
function parseBase( value: unknown ): DesignerBase | null {
	if (
		! isRecord( value ) ||
		! isInt( value.id, 1 ) ||
		! isString( value.name ) ||
		! isString( value.description ) ||
		! isString( value.base_price ) ||
		! DECIMAL.test( value.base_price ) ||
		! isString( value.currency )
	) {
		return null;
	}

	const colors = list( value.colors, ( entry ) =>
		isRecord( entry ) &&
		isString( entry.key ) &&
		isString( entry.name ) &&
		isString( entry.hex ) &&
		/^#[0-9a-f]{6}$/.test( entry.hex )
			? { key: entry.key, name: entry.name, hex: entry.hex }
			: null
	);
	const sizes = strings( value.sizes );
	const views = list( value.views, parseView );

	return colors === null || sizes === null || views === null
		? null
		: {
				id: value.id,
				name: value.name,
				description: value.description,
				basePrice: value.base_price,
				currency: value.currency,
				colors,
				sizes,
				views,
			};
}

/**
 * Validates `GET /me/designer`.
 *
 * @param body Response body.
 */
export function parseOverview( body: unknown ): DesignerOverview | null {
	if ( ! isRecord( body ) || ! isRecord( body.limits ) ) {
		return null;
	}

	const bases = list( body.bases, parseBase );
	const cliparts = list( body.cliparts, parseAsset );
	const uploads = list( body.uploads, parseAsset );
	const fonts = list( body.fonts, ( entry ) => {
		const weights = isRecord( entry )
			? list( entry.weights, ( weight ) =>
					isInt( weight, 100 ) ? weight : null
				)
			: null;

		return isRecord( entry ) && isString( entry.family ) && weights !== null
			? { family: entry.family, weights }
			: null;
	} );
	const products = list( body.products, ( entry ) =>
		isRecord( entry ) &&
		isInt( entry.id, 1 ) &&
		isInt( entry.base_id, 1 ) &&
		isString( entry.title ) &&
		isString( entry.status ) &&
		typeof entry.in_shop === 'boolean' &&
		isString( entry.updated_at )
			? {
					id: entry.id,
					baseId: entry.base_id,
					title: entry.title,
					status: entry.status,
					inShop: entry.in_shop,
					updatedAt: entry.updated_at,
				}
			: null
	);
	const limits = body.limits;

	return bases === null ||
		cliparts === null ||
		uploads === null ||
		fonts === null ||
		products === null ||
		! isInt( limits.max_upload_bytes, 1 ) ||
		! isInt( limits.max_objects, 1 ) ||
		! isInt( limits.price_decimals ) ||
		limits.price_decimals > 4
		? null
		: {
				bases,
				cliparts,
				uploads,
				fonts,
				products,
				maxUploadBytes: limits.max_upload_bytes,
				maxObjects: limits.max_objects,
				priceDecimals: limits.price_decimals,
			};
}

/**
 * Validates a designer product.
 *
 * @param body Response body.
 */
export function parseProduct( body: unknown ): DesignerProduct | null {
	if (
		! isRecord( body ) ||
		! isInt( body.id, 1 ) ||
		! isInt( body.base_id, 1 ) ||
		typeof body.in_shop !== 'boolean'
	) {
		return null;
	}

	const pending = parsePending( body.pending );
	const live = parseLive( body.live );
	const rejection = parseRejection( body.rejection );

	if (
		pending === undefined ||
		live === undefined ||
		rejection === undefined
	) {
		return null;
	}

	if ( body.draft === null ) {
		return {
			id: body.id,
			baseId: body.base_id,
			inShop: body.in_shop,
			draft: null,
			pending,
			live,
			rejection,
		};
	}

	const draft = body.draft;

	if ( ! isRecord( draft ) ) {
		return null;
	}

	const colors = strings( draft.colors );
	const sizes = strings( draft.sizes );
	const design = parseDesign( draft.design );

	if (
		colors === null ||
		sizes === null ||
		design === null ||
		! isInt( draft.number, 1 ) ||
		! isString( draft.title ) ||
		! isString( draft.description ) ||
		( draft.profit_kind !== 'percent' && draft.profit_kind !== 'fixed' ) ||
		! isString( draft.profit_value ) ||
		( draft.price !== null && ! isString( draft.price ) ) ||
		! isString( draft.updated_at )
	) {
		return null;
	}

	return {
		id: body.id,
		baseId: body.base_id,
		inShop: body.in_shop,
		draft: {
			number: draft.number,
			title: draft.title,
			description: draft.description,
			colors,
			sizes,
			design,
			profitKind: draft.profit_kind,
			profitValue: draft.profit_value,
			price: draft.price,
			updatedAt: draft.updated_at,
		},
		pending,
		live,
		rejection,
	};
}

/**
 * The live version: null without one, undefined when invalid.
 *
 * @param value Response field (missing: none).
 */
function parseLive( value: unknown ): DesignerLive | null | undefined {
	if ( value === null || value === undefined ) {
		return null;
	}

	if (
		! isRecord( value ) ||
		! isInt( value.number, 1 ) ||
		! isString( value.title ) ||
		( value.price !== null && ! isString( value.price ) )
	) {
		return undefined;
	}

	return { number: value.number, title: value.title, price: value.price };
}

/**
 * The rejection: null without one, undefined when invalid.
 *
 * @param value Response field (missing: none).
 */
function parseRejection(
	value: unknown
): DesignerRejection | null | undefined {
	if ( value === null || value === undefined ) {
		return null;
	}

	if (
		! isRecord( value ) ||
		! isInt( value.number, 1 ) ||
		! isString( value.note ) ||
		! isString( value.decided_at )
	) {
		return undefined;
	}

	return {
		number: value.number,
		note: value.note,
		decidedAt: value.decided_at,
	};
}

/**
 * The waiting version: null when none waits, undefined when invalid.
 *
 * @param value Response field (missing: none waits).
 */
function parsePending( value: unknown ): DesignerPending | null | undefined {
	if ( value === null || value === undefined ) {
		return null;
	}

	if (
		! isRecord( value ) ||
		! isInt( value.number, 1 ) ||
		! isString( value.title ) ||
		( value.price !== null && ! isString( value.price ) ) ||
		! isString( value.submitted_at )
	) {
		return undefined;
	}

	return {
		number: value.number,
		title: value.title,
		price: value.price,
		submittedAt: value.submitted_at,
	};
}

/**
 * The designer's state.
 *
 * @param signal Abort signal.
 */
export function getDesigner(
	signal?: AbortSignal
): Promise< ApiResult< DesignerOverview > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseOverview
	);
}

/**
 * Uploads an image.
 *
 * @param file The image.
 */
export function uploadDesignerImage(
	file: File
): Promise< ApiResult< DesignerAsset > > {
	const body = new FormData();
	body.append( 'file', file );

	return request(
		{ path: `${ PATH }/uploads`, method: 'POST', body },
		parseAsset
	);
}

/**
 * Deletes an upload.
 *
 * @param id Asset id.
 */
export function deleteDesignerImage(
	id: number
): Promise< ApiResult< DesignerOverview > > {
	return request(
		{ path: `${ PATH }/uploads/${ id }`, method: 'DELETE' },
		parseOverview
	);
}

/**
 * Starts a designer product.
 *
 * @param baseId Base product.
 */
export function createDesignerProduct(
	baseId: number
): Promise< ApiResult< DesignerProduct > > {
	return request(
		{
			path: `${ PATH }/products`,
			method: 'POST',
			data: { base_id: baseId },
		},
		parseProduct
	);
}

/**
 * A designer product with its draft.
 *
 * @param id     Designer product.
 * @param signal Abort signal.
 */
export function getDesignerProduct(
	id: number,
	signal?: AbortSignal
): Promise< ApiResult< DesignerProduct > > {
	return request(
		{
			path: `${ PATH }/products/${ id }`,
			...( signal === undefined ? {} : { signal } ),
		},
		parseProduct
	);
}

export interface DraftChanges {
	readonly title: string;
	readonly description: string;
	readonly colors: readonly string[];
	readonly sizes: readonly string[];
	readonly profitKind: ProfitKind;
	readonly profitValue: string;
	readonly design: DesignDocument;
}

/**
 * Saves the draft.
 *
 * @param id      Designer product.
 * @param changes The draft.
 */
export function saveDesignerDraft(
	id: number,
	changes: DraftChanges
): Promise< ApiResult< DesignerProduct > > {
	return request(
		{
			path: `${ PATH }/products/${ id }`,
			method: 'PATCH',
			data: {
				title: changes.title,
				description: changes.description,
				colors: changes.colors,
				sizes: changes.sizes,
				profit_kind: changes.profitKind,
				profit_value: changes.profitValue,
				design: changes.design,
			},
		},
		parseProduct
	);
}

/**
 * Deletes a designer product that never left the draft.
 *
 * @param id Designer product.
 */
export function deleteDesignerProduct(
	id: number
): Promise< ApiResult< DesignerOverview > > {
	return request(
		{ path: `${ PATH }/products/${ id }`, method: 'DELETE' },
		parseOverview
	);
}

/**
 * Sends one rendered file of the submission (`print:<side>` or
 * `mockup:<side>:<colour>`).
 *
 * @param id   Designer product.
 * @param slot Slot.
 * @param file The rendered image.
 * @param name File name (its extension matches the type).
 */
export function uploadSubmissionFile(
	id: number,
	slot: string,
	file: Blob,
	name: string
): Promise< ApiResult< DesignerProduct > > {
	const body = new FormData();
	body.append( 'slot', slot );
	body.append( 'file', file, name );

	return request(
		{ path: `${ PATH }/products/${ id }/files`, method: 'POST', body },
		parseProduct
	);
}

/**
 * Sends the draft for approval.
 *
 * @param id Designer product.
 */
export function submitDesign(
	id: number
): Promise< ApiResult< DesignerProduct > > {
	return request(
		{ path: `${ PATH }/products/${ id }/submit`, method: 'POST' },
		parseProduct
	);
}

/**
 * Withdraws the waiting version; a new draft continues it.
 *
 * @param id Designer product.
 */
export function withdrawDesign(
	id: number
): Promise< ApiResult< DesignerProduct > > {
	return request(
		{ path: `${ PATH }/products/${ id }/withdraw`, method: 'POST' },
		parseProduct
	);
}

/**
 * Starts a change of a product in the shop: a new draft of the live version.
 *
 * @param id Designer product.
 */
export function startChange(
	id: number
): Promise< ApiResult< DesignerProduct > > {
	return request(
		{ path: `${ PATH }/products/${ id }/change`, method: 'POST' },
		parseProduct
	);
}

const IMAGE_DATA_URL = /^data:image\/(png|jpeg|webp);base64,/;

/**
 * A file as a `data:` URL, or null.
 *
 * @param asset      The asset.
 * @param asset.id   Asset id.
 * @param asset.mime Its type.
 * @param signal     Abort signal.
 */
export async function designerAssetUrl(
	asset: { readonly id: number; readonly mime: string },
	signal?: AbortSignal
): Promise< string | null > {
	const result = await requestFile(
		{
			path: `${ PATH }/assets/${ asset.id }`,
			...( signal === undefined ? {} : { signal } ),
		},
		asset.mime
	);

	if ( ! result.ok ) {
		return null;
	}

	return new Promise( ( resolve ) => {
		const reader = new FileReader();
		reader.onload = () =>
			resolve(
				typeof reader.result === 'string' &&
					IMAGE_DATA_URL.test( reader.result )
					? reader.result
					: null
			);
		reader.onerror = () => resolve( null );
		reader.readAsDataURL( result.data );
	} );
}
