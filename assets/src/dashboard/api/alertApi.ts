/**
 * `/me/alerts/api` (step 25.12.1, ADR-073): the creator's API keys for their
 * own bots. A new key comes back once, in the answer that made it; the list
 * only has a name and the start of each key. Every answer is validated.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

const PATH = '/merchboard/v1/me/alerts/api';

export interface AlertApiKeyItem {
	readonly id: number;
	readonly name: string;
	/** The start of the key, e.g. "mbk_0123456789ab_…". */
	readonly hint: string;
	readonly createdAt: string;
	readonly lastUsedAt: string | null;
}

export interface AlertApiState {
	readonly enabled: boolean;
	/** The bots' URL. */
	readonly endpoint: string;
	/** Requests per key per minute. */
	readonly limit: number;
	readonly maxKeys: number;
	readonly keys: readonly AlertApiKeyItem[];
	/** A key just made; shown once. */
	readonly key?: string;
}

function isTime( value: unknown ): value is string {
	return typeof value === 'string' && ! Number.isNaN( Date.parse( value ) );
}

function isCount( value: unknown ): value is number {
	return Number.isSafeInteger( value ) && ( value as number ) >= 1;
}

/**
 * Validates the API page.
 *
 * @param body Response body.
 */
export function parseAlertApi( body: unknown ): AlertApiState | null {
	if (
		! isRecord( body ) ||
		typeof body.enabled !== 'boolean' ||
		typeof body.endpoint !== 'string' ||
		! /^https?:\/\//.test( body.endpoint ) ||
		! isCount( body.limit ) ||
		! isCount( body.max_keys ) ||
		! Array.isArray( body.keys ) ||
		( body.key !== undefined &&
			( typeof body.key !== 'string' ||
				! /^mbk_[0-9a-f]{12}_[0-9a-f]{48}$/.test( body.key ) ) )
	) {
		return null;
	}

	const keys: AlertApiKeyItem[] = [];

	for ( const item of body.keys as unknown[] ) {
		if (
			! isRecord( item ) ||
			! isCount( item.id ) ||
			typeof item.name !== 'string' ||
			typeof item.hint !== 'string' ||
			! isTime( item.created_at ) ||
			( item.last_used_at !== null && ! isTime( item.last_used_at ) )
		) {
			return null;
		}

		keys.push( {
			id: item.id,
			name: item.name,
			hint: item.hint,
			createdAt: item.created_at,
			lastUsedAt: item.last_used_at as string | null,
		} );
	}

	return {
		enabled: body.enabled,
		endpoint: body.endpoint,
		limit: body.limit,
		maxKeys: body.max_keys,
		keys,
		...( typeof body.key === 'string' ? { key: body.key } : {} ),
	};
}

/**
 * The creator's API page.
 *
 * @param signal Abort signal.
 */
export function getAlertApi(
	signal?: AbortSignal
): Promise< ApiResult< AlertApiState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseAlertApi
	);
}

/**
 * Makes a key; the answer has the whole key once.
 *
 * @param name The creator's name for it.
 */
export function createAlertApiKey(
	name: string
): Promise< ApiResult< AlertApiState > > {
	return request(
		{ path: `${ PATH }/keys`, method: 'POST', data: { name } },
		parseAlertApi
	);
}

/**
 * Revokes a key.
 *
 * @param id Key.
 */
export function revokeAlertApiKey(
	id: number
): Promise< ApiResult< AlertApiState > > {
	return request(
		{ path: `${ PATH }/keys/${ id }`, method: 'DELETE' },
		parseAlertApi
	);
}

/**
 * Sends a test event to the creator's bots.
 */
export function testAlertApi(): Promise< ApiResult< { sent: number } > > {
	return request( { path: `${ PATH }/test`, method: 'POST' }, ( body ) =>
		isRecord( body ) &&
		Number.isSafeInteger( body.sent ) &&
		( body.sent as number ) >= 0
			? { sent: body.sent as number }
			: null
	);
}
