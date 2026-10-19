/**
 * `/me/alerts/overlay` (ADR-065): the creator's OBS overlay. The creator is
 * always the one the server resolves; nothing here sends an id. Every
 * answer is validated before use; the overlay URL must be on this site.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord, readSameOriginUrl } from './validate';
import {
	BOX_LIMITS,
	FONT_WEIGHTS,
	LAYOUT_ELEMENTS,
	NUMBER_FIELDS,
	TEMPLATE_MAX_LENGTH,
	TEXT_ALIGNS,
} from '../types/alerts';
import type {
	AlertFontOption,
	AlertsOverlayState,
	FontWeight,
	LayoutBox,
	LayoutElement,
	OverlayLayout,
	OverlaySettings,
	TextAlign,
} from '../types/alerts';

const PATH = '/merchboard/v1/me/alerts/overlay';

const HEX_COLOR = /^#[0-9a-f]{6}$/;

/** A plain family name, as the server allows (ADR-067). */
const FONT_FAMILY = /^[A-Za-z0-9][A-Za-z0-9 -]{0,63}$/;

/** Every font stylesheet comes from Google Fonts. */
const FONT_URL =
	/^https:\/\/fonts\.googleapis\.com\/css2\?family=[A-Za-z0-9+-]+:wght@[0-9;]+&display=swap$/;

function isFontFamily( value: unknown ): value is string {
	return (
		value === 'inherit' ||
		( typeof value === 'string' && FONT_FAMILY.test( value ) )
	);
}

function isIntegerIn(
	value: unknown,
	range: { readonly min: number; readonly max: number }
): value is number {
	return (
		typeof value === 'number' &&
		Number.isInteger( value ) &&
		value >= range.min &&
		value <= range.max
	);
}

function parseBox( value: unknown ): LayoutBox | null {
	if (
		! isRecord( value ) ||
		! isIntegerIn( value.x, BOX_LIMITS.x ) ||
		! isIntegerIn( value.y, BOX_LIMITS.y ) ||
		! isIntegerIn( value.w, BOX_LIMITS.w ) ||
		! isIntegerIn( value.h, BOX_LIMITS.h ) ||
		! isIntegerIn( value.border_radius, BOX_LIMITS.border_radius ) ||
		typeof value.visible !== 'boolean' ||
		typeof value.lock_aspect !== 'boolean'
	) {
		return null;
	}

	return {
		x: value.x,
		y: value.y,
		w: value.w,
		h: value.h,
		visible: value.visible,
		lock_aspect: value.lock_aspect,
		border_radius: value.border_radius,
	};
}

/**
 * Validates the overlay layout of an answer.
 *
 * @param value Candidate.
 */
export function parseLayout( value: unknown ): OverlayLayout | null {
	if (
		! isRecord( value ) ||
		! isRecord( value.elements ) ||
		! isRecord( value.canvas ) ||
		typeof value.canvas.snap_grid !== 'boolean' ||
		! isIntegerIn( value.canvas.grid_size, { min: 1, max: 100 } )
	) {
		return null;
	}

	const elements: Partial< Record< LayoutElement, LayoutBox > > = {};

	for ( const element of LAYOUT_ELEMENTS ) {
		const box = parseBox( value.elements[ element ] );

		if ( box === null ) {
			return null;
		}

		elements[ element ] = box;
	}

	return {
		elements: elements as Record< LayoutElement, LayoutBox >,
		canvas: {
			snap_grid: value.canvas.snap_grid,
			grid_size: value.canvas.grid_size,
		},
	};
}

function parseFonts( value: unknown ): AlertFontOption[] | null {
	if ( ! Array.isArray( value ) ) {
		return null;
	}

	const fonts: AlertFontOption[] = [];

	for ( const item of value as unknown[] ) {
		if (
			! isRecord( item ) ||
			typeof item.family !== 'string' ||
			! FONT_FAMILY.test( item.family ) ||
			typeof item.url !== 'string' ||
			! FONT_URL.test( item.url )
		) {
			return null;
		}

		fonts.push( { family: item.family, url: item.url } );
	}

	return fonts;
}

function isFontWeight( value: unknown ): value is FontWeight {
	return (
		value === 'normal' ||
		value === 'bold' ||
		( FONT_WEIGHTS as readonly unknown[] ).includes( value )
	);
}

function isTextAlign( value: unknown ): value is TextAlign {
	return ( TEXT_ALIGNS as readonly unknown[] ).includes( value );
}

function isTemplate( value: unknown ): value is string {
	return typeof value === 'string' && value.length <= TEMPLATE_MAX_LENGTH;
}

/**
 * Validates the overlay settings of an answer.
 *
 * @param value Candidate.
 */
export function parseOverlaySettings( value: unknown ): OverlaySettings | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	for ( const [ field, range ] of Object.entries( NUMBER_FIELDS ) ) {
		const number = value[ field ];

		if (
			typeof number !== 'number' ||
			! Number.isInteger( number ) ||
			number < range.min ||
			number > range.max
		) {
			return null;
		}
	}

	if (
		! isTemplate( value.template ) ||
		! isTemplate( value.secondary_template ) ||
		! isFontWeight( value.font_weight ) ||
		! isTextAlign( value.text_align ) ||
		typeof value.text_color !== 'string' ||
		! HEX_COLOR.test( value.text_color ) ||
		typeof value.bg_color !== 'string' ||
		! HEX_COLOR.test( value.bg_color ) ||
		typeof value.text_shadow !== 'string' ||
		value.text_shadow.length > 100 ||
		typeof value.show_secondary !== 'boolean' ||
		! isFontFamily( value.font_family )
	) {
		return null;
	}

	return {
		template: value.template,
		secondary_template: value.secondary_template,
		font_size: value.font_size as number,
		font_weight: value.font_weight,
		text_color: value.text_color,
		text_shadow: value.text_shadow,
		text_align: value.text_align,
		image_size: value.image_size as number,
		bg_color: value.bg_color,
		bg_opacity: value.bg_opacity as number,
		border_radius: value.border_radius as number,
		padding: value.padding as number,
		anim_duration: value.anim_duration as number,
		display_duration: value.display_duration as number,
		show_secondary: value.show_secondary,
		font_family: value.font_family,
	};
}

/**
 * Validates `{ overlay: { url, settings, layout, font_approved } | null,
 * alerts_enabled, merchalert_active, fonts }`.
 *
 * @param body Response body.
 */
export function parseAlertsOverlay( body: unknown ): AlertsOverlayState | null {
	if (
		! isRecord( body ) ||
		typeof body.alerts_enabled !== 'boolean' ||
		typeof body.merchalert_active !== 'boolean' ||
		! ( 'overlay' in body )
	) {
		return null;
	}

	const fonts = parseFonts( body.fonts );

	if ( fonts === null ) {
		return null;
	}

	const state = {
		alertsEnabled: body.alerts_enabled,
		merchalertActive: body.merchalert_active,
		fonts,
	};

	if ( body.overlay === null ) {
		return { overlay: null, ...state };
	}

	if ( ! isRecord( body.overlay ) ) {
		return null;
	}

	const url = readSameOriginUrl( body.overlay.url, window.location.origin );
	const settings = parseOverlaySettings( body.overlay.settings );
	const layout = parseLayout( body.overlay.layout );
	const fontApproved = body.overlay.font_approved;

	return url === null ||
		settings === null ||
		layout === null ||
		typeof fontApproved !== 'boolean'
		? null
		: { overlay: { url, settings, layout, fontApproved }, ...state };
}

/**
 * Validates `{ sent: true }`.
 *
 * @param body Response body.
 */
export function parseTestSent( body: unknown ): true | null {
	return isRecord( body ) && body.sent === true ? true : null;
}

/**
 * The creator's overlay.
 *
 * @param signal Abort signal.
 */
export function getAlertsOverlay(
	signal?: AbortSignal
): Promise< ApiResult< AlertsOverlayState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseAlertsOverlay
	);
}

/**
 * Sets the overlay up (idempotent).
 */
export function createAlertsOverlay(): Promise<
	ApiResult< AlertsOverlayState >
> {
	return request( { path: PATH, method: 'POST' }, parseAlertsOverlay );
}

/**
 * Saves overlay settings (the form's fields, the font or the layout).
 *
 * @param changes Fields to save.
 */
export function updateAlertsOverlay(
	changes: Partial< OverlaySettings > | { readonly layout: OverlayLayout }
): Promise< ApiResult< AlertsOverlayState > > {
	return request(
		{ path: PATH, method: 'PATCH', data: { ...changes } },
		parseAlertsOverlay
	);
}

/**
 * Replaces the secret overlay id; the old OBS URL stops working.
 */
export function regenerateAlertsOverlay(): Promise<
	ApiResult< AlertsOverlayState >
> {
	return request(
		{ path: `${ PATH }/regenerate`, method: 'POST' },
		parseAlertsOverlay
	);
}

/**
 * Sends a test alert to the overlay.
 */
export function sendTestAlert(): Promise< ApiResult< true > > {
	return request( { path: `${ PATH }/test`, method: 'POST' }, parseTestSent );
}
