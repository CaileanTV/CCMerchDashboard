/**
 * `/me/collections` (ADR-120): the creator's collections of designs and the
 * requests for them. The creator is the one the server resolves for the
 * logged-in user; nothing here takes or sends a creator id.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

const PATH = '/merchboard/v1/me/collections';

export type CollectionRequestStatus =
	'pending' | 'approved' | 'ready' | 'declined';

const STATUSES: readonly CollectionRequestStatus[] = [
	'pending',
	'approved',
	'ready',
	'declined',
];

export interface CollectableProduct {
	readonly id: number;
	readonly name: string;
}

export interface OwnCollection {
	readonly id: number;
	readonly name: string;
	readonly published: boolean;
	/** The number of designs in it. */
	readonly designs: number;
}

export interface CollectionRequestRow {
	readonly id: number;
	readonly kind: 'new' | 'add';
	readonly name: string;
	readonly status: CollectionRequestStatus;
	/** Our note when it is declined. */
	readonly note: string | null;
	/** The designs' names. */
	readonly designs: readonly string[];
}

export interface CollectionsState {
	/** Whether a request can be sent now. */
	readonly available: boolean;
	readonly products: readonly CollectableProduct[];
	readonly collections: readonly OwnCollection[];
	readonly requests: readonly CollectionRequestRow[];
}

export type CollectionInput =
	| { readonly name: string; readonly productIds: readonly number[] }
	| { readonly collectionId: number; readonly productIds: readonly number[] };

function whole( value: unknown ): number | null {
	return typeof value === 'number' && Number.isInteger( value ) && value >= 0
		? value
		: null;
}

function parseCollectable( body: unknown ): CollectableProduct | null {
	if ( ! isRecord( body ) || typeof body.name !== 'string' ) {
		return null;
	}

	const id = whole( body.id );

	return id === null ? null : { id, name: body.name };
}

function parseOwn( body: unknown ): OwnCollection | null {
	if (
		! isRecord( body ) ||
		typeof body.name !== 'string' ||
		typeof body.published !== 'boolean'
	) {
		return null;
	}

	const id = whole( body.id );
	const designs = whole( body.designs );

	return id === null || designs === null
		? null
		: { id, name: body.name, published: body.published, designs };
}

function parseRow( body: unknown ): CollectionRequestRow | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const { kind, name, status, note, designs } = body;

	if (
		( kind !== 'new' && kind !== 'add' ) ||
		typeof name !== 'string' ||
		! STATUSES.includes( status as CollectionRequestStatus ) ||
		( note !== null && typeof note !== 'string' ) ||
		! Array.isArray( designs ) ||
		! designs.every( ( design ) => typeof design === 'string' )
	) {
		return null;
	}

	const id = whole( body.id );

	return id === null
		? null
		: {
				id,
				kind,
				name,
				status: status as CollectionRequestStatus,
				note,
				designs: designs as string[],
			};
}

/**
 * Validates `GET /me/collections` (and the answer of a request).
 *
 * @param body Response body.
 */
export function parseCollections( body: unknown ): CollectionsState | null {
	if (
		! isRecord( body ) ||
		typeof body.available !== 'boolean' ||
		! Array.isArray( body.products ) ||
		! Array.isArray( body.collections ) ||
		! Array.isArray( body.requests )
	) {
		return null;
	}

	const products = body.products.map( parseCollectable );
	const collections = body.collections.map( parseOwn );
	const requests = body.requests.map( parseRow );

	if (
		products.includes( null ) ||
		collections.includes( null ) ||
		requests.includes( null )
	) {
		return null;
	}

	return {
		available: body.available,
		products: products as CollectableProduct[],
		collections: collections as OwnCollection[],
		requests: requests as CollectionRequestRow[],
	};
}

/**
 * The creator's collections and requests.
 *
 * @param signal Abort signal.
 */
export function getCollections(
	signal?: AbortSignal
): Promise< ApiResult< CollectionsState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseCollections
	);
}

/**
 * Asks for a new collection, or to have designs added to one. Nothing in the
 * shop changes until we have approved it.
 *
 * @param input The collection and its designs.
 */
export function requestCollection(
	input: CollectionInput
): Promise< ApiResult< CollectionsState > > {
	return request(
		{
			path: PATH,
			method: 'POST',
			data: {
				product_ids: input.productIds,
				...( 'name' in input
					? { name: input.name }
					: { collection_id: input.collectionId } ),
			},
		},
		parseCollections
	);
}
