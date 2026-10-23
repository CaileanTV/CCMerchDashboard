/**
 * The standard overlays (step 25.9.5): the creator's list with what the
 * preview needs and the copy into the creator's own overlay
 * (`/me/alerts/presets`, `/me/alerts/overlay/preset`), and the admin page's
 * list, making, renaming and deleting (`/admin/alerts/presets`). Every
 * answer is validated before use.
 */
import {
	overlayApi,
	parseAlertsOverlay,
	parseImages,
	parseLayout,
	parseOverlaySettings,
} from './alerts';
import type { OverlayApi } from './alerts';
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';
import type {
	AlertsImages,
	AlertsOverlayState,
	OverlayLayout,
	OverlaySettings,
} from '../types/alerts';

const ADMIN_PATH = '/merchboard/v1/admin/alerts/presets';

const ID = /^[0-9a-f]{16}$/;

/** A standard overlay a creator can start from, with its preview. */
export interface AlertPreset {
	readonly id: string;
	readonly name: string;
	readonly settings: OverlaySettings;
	readonly layout: OverlayLayout;
	readonly images: AlertsImages | null;
}

/** A standard overlay in the admin page's list. */
export interface PresetEntry {
	readonly id: string;
	readonly name: string;
}

/** The admin page's list. */
export interface PresetList {
	readonly presets: readonly PresetEntry[];
	readonly max: number;
}

function parsePreset( value: unknown ): AlertPreset | null {
	if (
		! isRecord( value ) ||
		typeof value.id !== 'string' ||
		! ID.test( value.id ) ||
		typeof value.name !== 'string'
	) {
		return null;
	}

	const settings = parseOverlaySettings( value.settings );
	const layout = parseLayout( value.layout );
	const images = parseImages( value.images );

	return settings === null || layout === null || images === undefined
		? null
		: { id: value.id, name: value.name, settings, layout, images };
}

/**
 * Validates `{ presets: [ { id, name, settings, layout, images } ] }`.
 *
 * @param body Response body.
 */
export function parsePresets( body: unknown ): AlertPreset[] | null {
	if ( ! isRecord( body ) || ! Array.isArray( body.presets ) ) {
		return null;
	}

	const presets: AlertPreset[] = [];

	for ( const value of body.presets ) {
		const preset = parsePreset( value );

		if ( preset === null ) {
			return null;
		}

		presets.push( preset );
	}

	return presets;
}

/**
 * Validates `{ presets: [ { id, name } ], max }`.
 *
 * @param body Response body.
 */
export function parsePresetList( body: unknown ): PresetList | null {
	if (
		! isRecord( body ) ||
		! Array.isArray( body.presets ) ||
		typeof body.max !== 'number'
	) {
		return null;
	}

	const presets: PresetEntry[] = [];

	for ( const value of body.presets ) {
		if (
			! isRecord( value ) ||
			typeof value.id !== 'string' ||
			! ID.test( value.id ) ||
			typeof value.name !== 'string'
		) {
			return null;
		}

		presets.push( { id: value.id, name: value.name } );
	}

	return { presets, max: body.max };
}

/**
 * The standard overlays the creator can start from.
 *
 * @param signal Abort signal.
 */
export function getAlertPresets(
	signal?: AbortSignal
): Promise< ApiResult< AlertPreset[] > > {
	return request(
		{
			path: '/merchboard/v1/me/alerts/presets',
			...( signal === undefined ? {} : { signal } ),
		},
		parsePresets
	);
}

/**
 * Copies a standard overlay's look and texts into the creator's overlay.
 *
 * @param id The standard overlay's id.
 */
export function applyAlertPreset(
	id: string
): Promise< ApiResult< AlertsOverlayState > > {
	return request(
		{
			path: '/merchboard/v1/me/alerts/overlay/preset',
			method: 'POST',
			data: { preset: id },
		},
		parseAlertsOverlay
	);
}

/**
 * The admin page's list.
 *
 * @param signal Abort signal.
 */
export function listPresets(
	signal?: AbortSignal
): Promise< ApiResult< PresetList > > {
	return request(
		{ path: ADMIN_PATH, ...( signal === undefined ? {} : { signal } ) },
		parsePresetList
	);
}

/**
 * Makes a standard overlay from the defaults.
 *
 * @param name Name.
 */
export function createPreset(
	name: string
): Promise< ApiResult< PresetList > > {
	return request(
		{ path: ADMIN_PATH, method: 'POST', data: { name } },
		parsePresetList
	);
}

/**
 * Renames a standard overlay.
 *
 * @param id   Id.
 * @param name New name.
 */
export function renamePreset(
	id: string,
	name: string
): Promise< ApiResult< PresetList > > {
	return request(
		{
			path: `${ ADMIN_PATH }/${ id }/name`,
			method: 'POST',
			data: { name },
		},
		parsePresetList
	);
}

/**
 * Deletes a standard overlay.
 *
 * @param id Id.
 */
export function deletePreset( id: string ): Promise< ApiResult< PresetList > > {
	return request(
		{ path: `${ ADMIN_PATH }/${ id }`, method: 'DELETE' },
		parsePresetList
	);
}

/**
 * One standard overlay's calls for the shared settings form and designer.
 *
 * @param id Id.
 */
export function presetApi( id: string ): OverlayApi {
	return overlayApi( `${ ADMIN_PATH }/${ id }` );
}
