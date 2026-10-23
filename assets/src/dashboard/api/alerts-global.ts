/**
 * `/admin/alerts/global-overlay` (step 25.9.4): the global overlay for the
 * admin page, only for users who manage alerts. It answers in the creator
 * overlay's shape, so the same cards and validation are used, plus whether
 * the overlay is switched on and a replay of the latest order.
 */
import { overlayApi, parseAlertsOverlay } from './alerts';
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';
import type { AlertsOverlayState } from '../types/alerts';

const PATH = '/merchboard/v1/admin/alerts/global-overlay';

/** The global overlay's calls for the shared cards. */
export const globalOverlayApi = overlayApi( PATH );

/** The global overlay with whether it is switched on. */
export interface GlobalOverlayState {
	readonly state: AlertsOverlayState;
	readonly enabled: boolean;
}

/**
 * Validates the creator overlay's shape plus `overlay.enabled`.
 *
 * @param body Response body.
 */
export function parseGlobalOverlay( body: unknown ): GlobalOverlayState | null {
	const state = parseAlertsOverlay( body );

	if ( state === null || ! isRecord( body ) ) {
		return null;
	}

	if ( state.overlay === null ) {
		return { state, enabled: false };
	}

	const enabled = isRecord( body.overlay ) ? body.overlay.enabled : null;

	return typeof enabled === 'boolean' ? { state, enabled } : null;
}

/**
 * The global overlay.
 *
 * @param signal Abort signal.
 */
export function getGlobalOverlay(
	signal?: AbortSignal
): Promise< ApiResult< GlobalOverlayState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseGlobalOverlay
	);
}

/**
 * Switches the global overlay on or off.
 *
 * @param enabled Whether it is on.
 */
export function setGlobalOverlayEnabled(
	enabled: boolean
): Promise< ApiResult< GlobalOverlayState > > {
	return request(
		{ path: `${ PATH }/enabled`, method: 'POST', data: { enabled } },
		parseGlobalOverlay
	);
}

/**
 * Shows the latest order's alerts again in the global overlay.
 *
 * @return The number of alerts sent.
 */
export function replayLatestOrder(): Promise< ApiResult< number > > {
	return request( { path: `${ PATH }/replay`, method: 'POST' }, ( body ) =>
		isRecord( body ) &&
		typeof body.sent === 'number' &&
		Number.isInteger( body.sent ) &&
		body.sent > 0
			? body.sent
			: null
	);
}
