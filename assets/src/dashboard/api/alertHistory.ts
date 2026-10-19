/**
 * `/me/alerts/history` (phase 25, step 7): the creator's orders with
 * alerts, resend of an alert or an order, and "Stop all alerts". The creator
 * is the one the server resolves for the logged-in user; ids only select
 * among the creator's own alerts.
 */
import type { PageLoader } from '../hooks/usePagedApi';
import type { HistoryAlert, HistoryOrder } from '../types/alerts';
import { request, requestList } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

const PATH = '/merchboard/v1/me/alerts/history';
const ISO_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

function isId( value: unknown ): value is number {
	return Number.isSafeInteger( value ) && ( value as number ) >= 1;
}

function isTime( value: unknown ): value is string {
	return (
		typeof value === 'string' &&
		ISO_UTC.test( value ) &&
		! Number.isNaN( Date.parse( value ) )
	);
}

function parseAlert( body: unknown ): HistoryAlert | null {
	if (
		! isRecord( body ) ||
		! isId( body.id ) ||
		typeof body.product !== 'string' ||
		typeof body.variation !== 'string' ||
		! Number.isSafeInteger( body.quantity ) ||
		( body.quantity as number ) < 0 ||
		! isTime( body.created_at )
	) {
		return null;
	}

	return {
		id: body.id,
		product: body.product,
		variation: body.variation,
		quantity: body.quantity as number,
		createdAt: body.created_at,
	};
}

/**
 * Validates one order of `GET /me/alerts/history`.
 *
 * @param body Item of the response array.
 */
export function parseHistoryOrder( body: unknown ): HistoryOrder | null {
	if (
		! isRecord( body ) ||
		! isId( body.order_id ) ||
		typeof body.order_number !== 'string' ||
		typeof body.username !== 'string' ||
		! isTime( body.created_at ) ||
		! Array.isArray( body.alerts ) ||
		body.alerts.length === 0
	) {
		return null;
	}

	const alerts: HistoryAlert[] = [];

	for ( const item of body.alerts as unknown[] ) {
		const alert = parseAlert( item );

		if ( alert === null ) {
			return null;
		}

		alerts.push( alert );
	}

	return {
		orderId: body.order_id,
		orderNumber: body.order_number,
		username: body.username,
		createdAt: body.created_at,
		alerts,
	};
}

/**
 * Validates `{ sent: n }`.
 *
 * @param body Response body.
 */
export function parseSent( body: unknown ): number | null {
	return isRecord( body ) && isId( body.sent ) ? body.sent : null;
}

/**
 * Validates `{ stopped: true }`.
 *
 * @param body Response body.
 */
export function parseStopped( body: unknown ): true | null {
	return isRecord( body ) && body.stopped === true ? true : null;
}

/**
 * One page of the creator's orders with alerts, the newest first.
 *
 * @param page    Page, from 1.
 * @param perPage Page size.
 * @param signal  Abort signal.
 */
export const getAlertHistory: PageLoader< HistoryOrder > = (
	page,
	perPage,
	signal
) => requestList( { path: PATH, page, perPage, signal }, parseHistoryOrder );

/**
 * Shows one alert again in the overlay.
 *
 * @param alertId Alert id.
 */
export function resendAlert( alertId: number ): Promise< ApiResult< number > > {
	return request(
		{
			path: `${ PATH }/alerts/${ String( alertId ) }/resend`,
			method: 'POST',
		},
		parseSent
	);
}

/**
 * Shows all alerts of an order again in the overlay.
 *
 * @param orderId Order id.
 */
export function resendOrder( orderId: number ): Promise< ApiResult< number > > {
	return request(
		{
			path: `${ PATH }/orders/${ String( orderId ) }/resend`,
			method: 'POST',
		},
		parseSent
	);
}

/**
 * Stops all alerts at once: the overlay drops what it shows and its queue.
 */
export function stopAlerts(): Promise< ApiResult< true > > {
	return request(
		{ path: '/merchboard/v1/me/alerts/stop', method: 'POST' },
		parseStopped
	);
}

/**
 * Validates `{ anonymize: bool }`.
 *
 * @param body Response body.
 */
export function parsePreferences(
	body: unknown
): { readonly anonymize: boolean } | null {
	return isRecord( body ) && typeof body.anonymize === 'boolean'
		? { anonymize: body.anonymize }
		: null;
}

/**
 * The logged-in user's history preferences.
 *
 * @param signal Abort signal.
 */
export function getAlertPreferences(
	signal?: AbortSignal
): Promise< ApiResult< { readonly anonymize: boolean } > > {
	return request(
		{
			path: '/merchboard/v1/me/alerts/preferences',
			...( signal === undefined ? {} : { signal } ),
		},
		parsePreferences
	);
}

/**
 * Saves "Anonymize usernames" for the logged-in user.
 *
 * @param anonymize Whether usernames are shortened.
 */
export function updateAlertPreferences(
	anonymize: boolean
): Promise< ApiResult< { readonly anonymize: boolean } > > {
	return request(
		{
			path: '/merchboard/v1/me/alerts/preferences',
			method: 'PATCH',
			data: { anonymize },
		},
		parsePreferences
	);
}
