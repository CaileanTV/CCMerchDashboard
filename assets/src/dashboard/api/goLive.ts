/**
 * `GET /me/go-live` and `POST /me/go-live` (ADR-091): whether the creator is
 * live in the shop, their newest request and asking to go live. The creator
 * is always the one the server resolves; nothing here sends an id.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export type GoLiveRequestStatus = 'pending' | 'approved' | 'declined';

export interface GoLiveState {
	readonly live: boolean;
	/** Whether the agreement allows asking (accepted, or none to accept). */
	readonly agreement: boolean;
	readonly request: {
		readonly status: GoLiveRequestStatus;
		/** The administrator's note of a declined request ('' otherwise). */
		readonly note: string;
		readonly requestedAt: string;
	} | null;
}

const STATUSES: readonly GoLiveRequestStatus[] = [
	'pending',
	'approved',
	'declined',
];

/**
 * Validates the go-live response.
 *
 * @param body Response body.
 */
export function parseGoLive( body: unknown ): GoLiveState | null {
	if (
		! isRecord( body ) ||
		typeof body.live !== 'boolean' ||
		typeof body.agreement !== 'boolean'
	) {
		return null;
	}

	if ( body.request === null ) {
		return { live: body.live, agreement: body.agreement, request: null };
	}

	const asked = body.request;

	if (
		! isRecord( asked ) ||
		! STATUSES.includes( asked.status as GoLiveRequestStatus ) ||
		typeof asked.note !== 'string' ||
		typeof asked.requested_at !== 'string'
	) {
		return null;
	}

	return {
		live: body.live,
		agreement: body.agreement,
		request: {
			status: asked.status as GoLiveRequestStatus,
			note: asked.note,
			requestedAt: asked.requested_at,
		},
	};
}

/**
 * Where the creator stands.
 *
 * @param signal Cancels the request.
 */
export function getGoLive(
	signal?: AbortSignal
): Promise< ApiResult< GoLiveState > > {
	return request(
		{
			path: '/merchboard/v1/me/go-live',
			...( signal === undefined ? {} : { signal } ),
		},
		parseGoLive
	);
}

/**
 * Asks to go live. A refusal comes back with its `reason`
 * (`agreement_required`, `request_pending`, `already_live`).
 */
export function askGoLive(): Promise< ApiResult< GoLiveState > > {
	return request(
		{ path: '/merchboard/v1/me/go-live', method: 'POST' },
		parseGoLive
	);
}
