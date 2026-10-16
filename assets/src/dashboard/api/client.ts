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
	| 'invalid_request'
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
	/**
	 * Per-field error codes of a 400 response (e.g. `bio` → `too_long`). Only
	 * keys and codes of the form `[a-z_.]`; never messages or values.
	 */
	readonly fields: Readonly< Record< string, string > >;
	/**
	 * Reason code of a refused operation (`data.reason`, e.g. a payout's
	 * `insufficient_balance`), only when present and of the form `[a-z_]`;
	 * never a message.
	 */
	readonly reason?: string;
}

/** The failed side of every ApiResult. */
export interface ApiFailure {
	readonly ok: false;
	readonly error: ApiError;
}

export type ApiResult< T > =
	{ readonly ok: true; readonly data: T } | ApiFailure;

/** Turns an untrusted response body into a typed value, or null. */
export type Validator< T > = ( body: unknown ) => T | null;

export interface ApiRequest {
	/** Path inside the plugin namespace, e.g. "/merchboard/v1/me". */
	readonly path: string;
	readonly method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
	readonly data?: Readonly< Record< string, unknown > >;
	readonly signal?: AbortSignal;
}

/** Default page size of list routes (docs/API.md#paginering). */
export const DEFAULT_PER_PAGE = 20;

/** Largest page size of list routes. */
export const MAX_PER_PAGE = 100;

export interface ListRequest {
	/** Path of a list route inside the plugin namespace, without a query. */
	readonly path: string;
	/** Page, from 1. */
	readonly page: number;
	/** Page size, 1 to MAX_PER_PAGE. */
	readonly perPage: number;
	readonly signal?: AbortSignal;
}

/** One page of a list route and the totals from its headers. */
export interface Paginated< T > {
	readonly items: readonly T[];
	readonly page: number;
	readonly perPage: number;
	/** `X-WP-Total`: items over all pages. */
	readonly total: number;
	/** `X-WP-TotalPages`: 0 when there is nothing to list. */
	readonly totalPages: number;
}

/** Minimal shape of a fetch Response, as rejected or resolved by apiFetch. */
interface ResponseLike {
	readonly status: number;
	readonly ok: boolean;
	readonly headers?: { get: ( name: string ) => string | null };
	json: () => Promise< unknown >;
	blob?: () => Promise< Blob >;
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
	code: string | null = null,
	fields: Readonly< Record< string, string > > = {},
	reason: string | null = null
): ApiFailure {
	return {
		ok: false,
		error: {
			kind,
			status,
			code,
			fields,
			...( reason === null ? {} : { reason } ),
		},
	};
}

const SAFE_CODE = /^[a-z_]{1,50}$/;
const SAFE_FIELD = /^[a-z_]{1,50}(?:\.[a-z_]{1,50})?$/;

/**
 * Field error codes from `data.fields` of a WP_Error body. Anything that is
 * not a plain code is dropped, so no server text can reach the UI.
 *
 * @param body Parsed error body.
 */
function fieldErrors( body: unknown ): Record< string, string > {
	const fields: Record< string, string > = {};

	if (
		! isRecord( body ) ||
		! isRecord( body.data ) ||
		! isRecord( body.data.fields )
	) {
		return fields;
	}

	for ( const [ field, code ] of Object.entries( body.data.fields ) ) {
		if (
			SAFE_FIELD.test( field ) &&
			typeof code === 'string' &&
			SAFE_CODE.test( code )
		) {
			fields[ field ] = code;
		}
	}

	return fields;
}

/**
 * The reason code of a refusal (`data.reason`), or null when it is missing
 * or not a plain code.
 *
 * @param body Parsed error body.
 */
function reasonCode( body: unknown ): string | null {
	return isRecord( body ) &&
		isRecord( body.data ) &&
		typeof body.data.reason === 'string' &&
		SAFE_CODE.test( body.data.reason )
		? body.data.reason
		: null;
}

function kindForStatus( status: number ): ApiErrorKind {
	if ( status === 400 ) {
		return 'invalid_request';
	}

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
): Promise< ApiFailure > {
	const parsed = await readJson( response );
	const code =
		parsed.ok &&
		isRecord( parsed.body ) &&
		typeof parsed.body.code === 'string'
			? parsed.body.code
			: null;

	return failure(
		// WordPress rejects a nonce that no longer matches the session (logged
		// out in another tab, expired login) with 403; for the user that is an
		// expired session, not missing access.
		code === 'rest_cookie_invalid_nonce'
			? 'unauthenticated'
			: kindForStatus( response.status ),
		response.status,
		code !== null && SAFE_CODE.test( code ) ? code : null,
		parsed.ok ? fieldErrors( parsed.body ) : {},
		parsed.ok ? reasonCode( parsed.body ) : null
	);
}

function isAbort( error: unknown ): boolean {
	return isRecord( error ) && error.name === 'AbortError';
}

type Sent = { readonly ok: true; readonly response: ResponseLike } | ApiFailure;

/**
 * Sends a request: a successful response, or the reduced error.
 *
 * @param options Request.
 */
async function send( options: ApiRequest ): Promise< Sent > {
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

	return { ok: true, response };
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
	const sent = await send( options );

	if ( ! sent.ok ) {
		return sent;
	}

	const parsed = await readJson( sent.response );
	const data = parsed.ok ? validate( parsed.body ) : null;

	return data === null
		? failure( 'invalid_response', sent.response.status )
		: { ok: true, data };
}

/**
 * Requests a file (e.g. a PDF) and returns its body. Only a successful
 * response of exactly the expected content type is accepted; anything else
 * is `invalid_response`, and errors are reduced like every other request.
 *
 * @param options     Request.
 * @param contentType Expected media type, e.g. "application/pdf".
 */
export async function requestFile(
	options: ApiRequest,
	contentType: string
): Promise< ApiResult< Blob > > {
	const sent = await send( options );

	if ( ! sent.ok ) {
		return sent;
	}

	const header = sent.response.headers?.get( 'Content-Type' ) ?? '';
	const type = ( header.split( ';' )[ 0 ] ?? '' ).trim().toLowerCase();

	if ( type !== contentType || typeof sent.response.blob !== 'function' ) {
		return failure( 'invalid_response', sent.response.status );
	}

	try {
		return { ok: true, data: await sent.response.blob() };
	} catch {
		return failure( 'invalid_response', sent.response.status );
	}
}

const COUNT = /^(0|[1-9][0-9]{0,8})$/;

/**
 * A count header (`X-WP-Total`, `X-WP-TotalPages`), or null when it is
 * missing or not a plain non-negative integer.
 *
 * @param response Response.
 * @param name     Header name.
 */
function countHeader( response: ResponseLike, name: string ): number | null {
	const value = response.headers?.get( name ) ?? null;

	return value !== null && COUNT.test( value.trim() )
		? Number( value.trim() )
		: null;
}

/**
 * Requests one page of a list route (docs/API.md#paginering): `page` and
 * `per_page` in the query, a plain JSON array as body and the totals in
 * `X-WP-Total` and `X-WP-TotalPages`. Every item is validated; one invalid
 * item, a body that is not an array, or missing or inconsistent totals make
 * the whole response `invalid_response`. A page after the last one is an
 * empty, valid page.
 *
 * @param options      Request.
 * @param validateItem Validator for one item.
 */
export async function requestList< T >(
	options: ListRequest,
	validateItem: Validator< T >
): Promise< ApiResult< Paginated< T > > > {
	const { page, perPage } = options;

	if (
		! Number.isInteger( page ) ||
		page < 1 ||
		! Number.isInteger( perPage ) ||
		perPage < 1 ||
		perPage > MAX_PER_PAGE ||
		options.path.includes( '?' )
	) {
		throw new Error(
			'List requests need a page from 1, a page size from 1 to 100 and a path without a query.'
		);
	}

	const sent = await send( {
		path: `${ options.path }?page=${ page }&per_page=${ perPage }`,
		...( options.signal === undefined ? {} : { signal: options.signal } ),
	} );

	if ( ! sent.ok ) {
		return sent;
	}

	const total = countHeader( sent.response, 'X-WP-Total' );
	const totalPages = countHeader( sent.response, 'X-WP-TotalPages' );
	const parsed = await readJson( sent.response );

	if (
		total === null ||
		totalPages === null ||
		( total === 0 ) !== ( totalPages === 0 ) ||
		! parsed.ok ||
		! Array.isArray( parsed.body ) ||
		parsed.body.length > perPage
	) {
		return failure( 'invalid_response', sent.response.status );
	}

	const items: T[] = [];

	for ( const body of parsed.body as unknown[] ) {
		const item = validateItem( body );

		if ( item === null ) {
			return failure( 'invalid_response', sent.response.status );
		}

		items.push( item );
	}

	return {
		ok: true,
		data: { items, page, perPage, total, totalPages },
	};
}
