/**
 * `/me/alerts/overlay` (ADR-065): the creator's OBS overlay. The creator is
 * always the one the server resolves; nothing here sends an id. Every
 * answer is validated before use; the overlay URL must be on this site.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord, readSameOriginUrl } from './validate';
import {
	FONT_WEIGHTS,
	NUMBER_FIELDS,
	TEMPLATE_MAX_LENGTH,
	TEXT_ALIGNS,
} from '../types/alerts';
import type {
	AlertsOverlayState,
	FontWeight,
	OverlaySettings,
	TextAlign,
} from '../types/alerts';

const PATH = '/merchboard/v1/me/alerts/overlay';

const HEX_COLOR = /^#[0-9a-f]{6}$/;

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
		typeof value.show_secondary !== 'boolean'
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
	};
}

/**
 * Validates `{ overlay: { url, settings } | null, alerts_enabled, merchalert_active }`.
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

	const state = {
		alertsEnabled: body.alerts_enabled,
		merchalertActive: body.merchalert_active,
	};

	if ( body.overlay === null ) {
		return { overlay: null, ...state };
	}

	if ( ! isRecord( body.overlay ) ) {
		return null;
	}

	const url = readSameOriginUrl( body.overlay.url, window.location.origin );
	const settings = parseOverlaySettings( body.overlay.settings );

	return url === null || settings === null
		? null
		: { overlay: { url, settings }, ...state };
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
 * Saves the overlay settings.
 *
 * @param settings Settings.
 */
export function updateAlertsOverlay(
	settings: OverlaySettings
): Promise< ApiResult< AlertsOverlayState > > {
	return request(
		{ path: PATH, method: 'PATCH', data: { ...settings } },
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
