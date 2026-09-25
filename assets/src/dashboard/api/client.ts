/**
 * The only place that talks to the REST API. Components and hooks call typed
 * endpoint functions (from phase 14, e.g. api/me.ts), never fetch() or
 * apiFetch() directly.
 *
 * - Authentication is WordPress' cookie + `X-WP-Nonce`; WordPress configures
 *   the nonce and root URL middlewares of the `wp-api-fetch` script itself.
 *   Nothing is stored in the browser.
 * - Only the plugin namespace `merchboard/v1` can be requested.
 * - Every response is `unknown` until a validator turns it into a typed value;
 *   anything unexpected becomes an `invalid_response` error.
 * - Errors are reduced to a fixed kind; server messages are never shown, so a
 *   401/403/500 body cannot leak details or inject content into the UI.
 * - The API decides who the creator is. Requests never carry a creator id to
 *   select the current creator.
 */
import apiFetch from '@wordpress/api-fetch';

import { isRecord } from './validate';

export const NAMESPACE = '/merchboard/v1/';

export type ApiErrorKind =
	| 'unauthenticated'
	| 'forbidden'
	| 'not_found'
	| 'server'
	| 'network'
	| 'invalid_response'
	| 'aborted';

export interface ApiError {
	readonly kind: ApiErrorKind;
	/** HTTP status, 0 when there was no response. */
	readonly status: number;
	/** WordPress error code (e.g. `rest_forbidden`), for programmatic checks only. */
	readonly code: string | null;
}

export type ApiResult< T > =
	| { readonly ok: true; readonly data: T }
	| { readonly ok: false; readonly error: ApiError };

/** Turns an untrusted response body into a typed value, or null. */
export type Validator< T > = ( body: unknown ) => T | null;

export interface ApiRequest {
	/** Path inside the plugin namespace, e.g. "/merchboard/v1/me". */
	readonly path: string;
	readonly method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
	readonly data?: Readonly< Record< string, unknown > >;
	readonly signal?: AbortSignal;
}

/** Minimal shape of a fetch Response, as rejected or resolved by apiFetch. */
interface ResponseLike {
	readonly status: number;
	readonly ok: boolean;
	json: () => Promise< unknown >;
}

function isResponseLike( value: unknown ): value is ResponseLike {
	return (
		isRecord( value ) &&
		typeof value.status === 'number' &&
		typeof value.json === 'function'
	);
}

function failure(
	kind: ApiErrorKind,
	status = 0,
	code: string | null = null
): ApiResult< never > {
	return { ok: false, error: { kind, status, code } };
}

function kindForStatus( status: number ): ApiErrorKind {
	if ( status === 401 ) {
		return 'unauthenticated';
	}

	if ( status === 403 ) {
		return 'forbidden';
	}

	if ( status === 404 ) {
		return 'not_found';
	}

	return 'server';
}

async function readJson(
	response: ResponseLike
): Promise< { ok: true; body: unknown } | { ok: false } > {
	try {
		return { ok: true, body: await response.json() };
	} catch {
		return { ok: false };
	}
}

async function errorFromResponse(
	response: ResponseLike
): Promise< ApiResult< never > > {
	const parsed = await readJson( response );
	const code =
		parsed.ok &&
		isRecord( parsed.body ) &&
		typeof parsed.body.code === 'string'
			? parsed.body.code
			: null;

	return failure( kindForStatus( response.status ), response.status, code );
}

function isAbort( error: unknown ): boolean {
	return isRecord( error ) && error.name === 'AbortError';
}

/**
 * Sends a request and validates the response.
 *
 * @param options  Request.
 * @param validate Response validator.
 */
export async function request< T >(
	options: ApiRequest,
	validate: Validator< T >
): Promise< ApiResult< T > > {
	if (
		! options.path.startsWith( NAMESPACE ) ||
		options.path.includes( '..' )
	) {
		throw new Error(
			'Dashboard API requests must target the merchboard/v1 namespace.'
		);
	}

	let response: unknown;

	try {
		response = await apiFetch( {
			path: options.path,
			method: options.method ?? 'GET',
			...( options.data === undefined ? {} : { data: options.data } ),
			...( options.signal === undefined
				? {}
				: { signal: options.signal } ),
			parse: false,
		} );
	} catch ( error: unknown ) {
		// With parse: false, apiFetch rejects with the Response for non-2xx statuses.
		if ( isResponseLike( error ) ) {
			return errorFromResponse( error );
		}

		return failure(
			isAbort( error ) || options.signal?.aborted === true
				? 'aborted'
				: 'network'
		);
	}

	if ( ! isResponseLike( response ) ) {
		return failure( 'invalid_response' );
	}

	if ( ! response.ok ) {
		return errorFromResponse( response );
	}

	const parsed = await readJson( response );
	const data = parsed.ok ? validate( parsed.body ) : null;

	return data === null
		? failure( 'invalid_response', response.status )
		: { ok: true, data };
}
