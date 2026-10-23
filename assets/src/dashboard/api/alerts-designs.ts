/**
 * The creator's own saved overlay designs (`/me/alerts/designs`): save the
 * overlay's current design under a name, overwrite, rename, delete and take
 * one into use (`/me/alerts/overlay/design`). Only the creator's own; every
 * answer is validated before use.
 */
import {
	parseAlertsOverlay,
	parseImages,
	parseLayout,
	parseOverlaySettings,
} from './alerts';
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';
import type {
	AlertsImages,
	AlertsOverlayState,
	OverlayLayout,
	OverlaySettings,
} from '../types/alerts';

const PATH = '/merchboard/v1/me/alerts/designs';

const ID = /^[0-9a-f]{16}$/;

/** A saved design, with what its preview needs. */
export interface CreatorDesign {
	readonly id: string;
	readonly name: string;
	readonly savedAt: string;
	readonly settings: OverlaySettings;
	readonly layout: OverlayLayout;
	readonly images: AlertsImages | null;
}

/** The creator's designs. */
export interface DesignList {
	readonly designs: readonly CreatorDesign[];
	readonly max: number;
}

function parseDesign( value: unknown ): CreatorDesign | null {
	if (
		! isRecord( value ) ||
		typeof value.id !== 'string' ||
		! ID.test( value.id ) ||
		typeof value.name !== 'string' ||
		typeof value.saved_at !== 'string'
	) {
		return null;
	}

	const settings = parseOverlaySettings( value.settings );
	const layout = parseLayout( value.layout );
	const images = parseImages( value.images );

	return settings === null || layout === null || images === undefined
		? null
		: {
				id: value.id,
				name: value.name,
				savedAt: value.saved_at,
				settings,
				layout,
				images,
			};
}

/**
 * Validates `{ designs: [ … ], max }`.
 *
 * @param body Response body.
 */
export function parseDesigns( body: unknown ): DesignList | null {
	if (
		! isRecord( body ) ||
		! Array.isArray( body.designs ) ||
		typeof body.max !== 'number'
	) {
		return null;
	}

	const designs: CreatorDesign[] = [];

	for ( const value of body.designs ) {
		const design = parseDesign( value );

		if ( design === null ) {
			return null;
		}

		designs.push( design );
	}

	return { designs, max: body.max };
}

/**
 * The creator's designs.
 *
 * @param signal Abort signal.
 */
export function getDesigns(
	signal?: AbortSignal
): Promise< ApiResult< DesignList > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseDesigns
	);
}

/**
 * Saves the overlay's current design under a name.
 *
 * @param name Name.
 */
export function saveDesign( name: string ): Promise< ApiResult< DesignList > > {
	return request(
		{ path: PATH, method: 'POST', data: { name } },
		parseDesigns
	);
}

/**
 * Overwrites a design with the overlay's current design.
 *
 * @param id Design.
 */
export function updateDesign( id: string ): Promise< ApiResult< DesignList > > {
	return request(
		{ path: `${ PATH }/${ id }/update`, method: 'POST' },
		parseDesigns
	);
}

/**
 * Renames a design.
 *
 * @param id   Design.
 * @param name New name.
 */
export function renameDesign(
	id: string,
	name: string
): Promise< ApiResult< DesignList > > {
	return request(
		{ path: `${ PATH }/${ id }/name`, method: 'POST', data: { name } },
		parseDesigns
	);
}

/**
 * Deletes a design.
 *
 * @param id Design.
 */
export function deleteDesign( id: string ): Promise< ApiResult< DesignList > > {
	return request(
		{ path: `${ PATH }/${ id }`, method: 'DELETE' },
		parseDesigns
	);
}

/**
 * Takes a design into use: its look, texts and sounds replace the overlay's.
 *
 * @param id Design.
 */
export function applyDesign(
	id: string
): Promise< ApiResult< AlertsOverlayState > > {
	return request(
		{
			path: '/merchboard/v1/me/alerts/overlay/design',
			method: 'POST',
			data: { design: id },
		},
		parseAlertsOverlay
	);
}
