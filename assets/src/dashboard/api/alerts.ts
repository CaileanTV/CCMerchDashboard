/**
 * `/me/alerts/overlay` (ADR-065): the creator's OBS overlay. The creator is
 * always the one the server resolves; nothing here sends an id. Every
 * answer is validated before use; the overlay URL must be on this site.
 */
import { parseAnimation } from '../../overlay/animations';
import { parseTextFill } from '../../overlay/textfill';
import { parseTextFrame } from '../../overlay/frame';
import { isTextFit } from '../../overlay/fit';
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord, readSameOriginUrl } from './validate';
import { parsePageLayout } from '../sections/alerts/page-layout';
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
	AlertImageFile,
	AlertsImages,
	AlertSoundFile,
	AlertsAudio,
	LibrarySound,
	AlertsOverlayState,
	FontWeight,
	LayoutBox,
	LayoutElement,
	OverlayLayout,
	OverlaySettings,
	SoundSlot,
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

function parseSound( value: unknown ): AlertSoundFile | null | undefined {
	if ( value === null ) {
		return null;
	}

	if (
		! isRecord( value ) ||
		typeof value.name !== 'string' ||
		! isIntegerIn( value.seconds, { min: 0, max: 86400 } ) ||
		! isIntegerIn( value.library_id, {
			min: 0,
			max: Number.MAX_SAFE_INTEGER,
		} )
	) {
		return undefined;
	}

	const url = readSameOriginUrl( value.url, window.location.origin );

	return url === null
		? undefined
		: {
				url,
				name: value.name,
				seconds: value.seconds,
				libraryId: value.library_id,
			};
}

function parseLibrary( value: unknown ): LibrarySound[] | null {
	if ( ! Array.isArray( value ) ) {
		return null;
	}

	const sounds: LibrarySound[] = [];

	for ( const item of value as unknown[] ) {
		const url = isRecord( item )
			? readSameOriginUrl( item.url, window.location.origin )
			: null;

		if (
			! isRecord( item ) ||
			url === null ||
			! isIntegerIn( item.id, {
				min: 1,
				max: Number.MAX_SAFE_INTEGER,
			} ) ||
			typeof item.name !== 'string' ||
			! isIntegerIn( item.seconds, { min: 0, max: 86400 } )
		) {
			return null;
		}

		sounds.push( {
			id: item.id,
			url,
			name: item.name,
			seconds: item.seconds,
		} );
	}

	return sounds;
}

/**
 * Validates the sounds of an answer; undefined when invalid.
 *
 * @param value Candidate.
 */
export function parseAudio( value: unknown ): AlertsAudio | null | undefined {
	if ( value === null ) {
		return null;
	}

	if (
		! isRecord( value ) ||
		typeof value.enabled !== 'boolean' ||
		! Array.isArray( value.categories ) ||
		! isIntegerIn( value.max_bytes, {
			min: 1,
			max: Number.MAX_SAFE_INTEGER,
		} ) ||
		! isIntegerIn( value.max_seconds, { min: 1, max: 3600 } )
	) {
		return undefined;
	}

	const defaultSound = parseSound( value.default );
	const library = parseLibrary( value.library );
	const categories: Array< AlertsAudio[ 'categories' ][ number ] > = [];

	if ( defaultSound === undefined || library === null ) {
		return undefined;
	}

	for ( const item of value.categories as unknown[] ) {
		const sound = isRecord( item ) ? parseSound( item.sound ) : undefined;

		if (
			! isRecord( item ) ||
			! isIntegerIn( item.term_id, {
				min: 1,
				max: Number.MAX_SAFE_INTEGER,
			} ) ||
			typeof item.name !== 'string' ||
			sound === undefined
		) {
			return undefined;
		}

		categories.push( { termId: item.term_id, name: item.name, sound } );
	}

	return {
		enabled: value.enabled,
		defaultSound,
		categories,
		library,
		maxBytes: value.max_bytes,
		maxSeconds: value.max_seconds,
	};
}

function parseImageList( value: unknown ): AlertImageFile[] | null {
	if ( ! Array.isArray( value ) ) {
		return null;
	}

	const images: AlertImageFile[] = [];
	const size = { min: 1, max: 100000 };

	for ( const item of value as unknown[] ) {
		const url = isRecord( item )
			? readSameOriginUrl( item.url, window.location.origin )
			: null;

		if (
			! isRecord( item ) ||
			url === null ||
			! isIntegerIn( item.id, {
				min: 1,
				max: Number.MAX_SAFE_INTEGER,
			} ) ||
			typeof item.name !== 'string' ||
			! isIntegerIn( item.width, size ) ||
			! isIntegerIn( item.height, size )
		) {
			return null;
		}

		images.push( {
			id: item.id,
			url,
			name: item.name,
			width: item.width,
			height: item.height,
		} );
	}

	return images;
}

/**
 * Validates the text fill images of an answer; undefined when invalid.
 *
 * @param value Candidate.
 */
export function parseImages( value: unknown ): AlertsImages | null | undefined {
	if ( value === null || value === undefined ) {
		return null;
	}

	const own = isRecord( value ) ? parseImageList( value.own ) : null;
	const library = isRecord( value ) ? parseImageList( value.library ) : null;

	if (
		! isRecord( value ) ||
		own === null ||
		library === null ||
		! isIntegerIn( value.max_bytes, {
			min: 1,
			max: Number.MAX_SAFE_INTEGER,
		} ) ||
		! isIntegerIn( value.max_pixels, { min: 1, max: 100000 } ) ||
		! isIntegerIn( value.max_own, { min: 0, max: 1000 } )
	) {
		return undefined;
	}

	return {
		own,
		library,
		maxBytes: value.max_bytes,
		maxPixels: value.max_pixels,
		maxOwn: value.max_own,
	};
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
		! isTextFit( value.text_fit ) ||
		typeof value.text_color !== 'string' ||
		! HEX_COLOR.test( value.text_color ) ||
		typeof value.bg_color !== 'string' ||
		! HEX_COLOR.test( value.bg_color ) ||
		typeof value.text_shadow !== 'string' ||
		value.text_shadow.length > 100 ||
		typeof value.show_secondary !== 'boolean' ||
		typeof value.display_from_sound !== 'boolean' ||
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
		text_fit: value.text_fit,
		image_size: value.image_size as number,
		bg_color: value.bg_color,
		bg_opacity: value.bg_opacity as number,
		border_radius: value.border_radius as number,
		padding: value.padding as number,
		anim_duration: value.anim_duration as number,
		display_duration: value.display_duration as number,
		show_secondary: value.show_secondary,
		display_from_sound: value.display_from_sound,
		// Unknown values become the default animation, as on the server.
		animations: {
			main_text: parseAnimation(
				isRecord( value.animations ) ? value.animations.main_text : null
			),
			secondary_text: parseAnimation(
				isRecord( value.animations )
					? value.animations.secondary_text
					: null
			),
		},
		text_fills: {
			main_text: parseTextFill(
				isRecord( value.text_fills ) ? value.text_fills.main_text : null
			),
			secondary_text: parseTextFill(
				isRecord( value.text_fills )
					? value.text_fills.secondary_text
					: null
			),
		},
		text_frame: parseTextFrame( value.text_frame ),
		font_family: value.font_family,
	};
}

/**
 * Validates `{ overlay: { url, settings, layout, font_approved, audio } | null,
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
		...( 'page' in body ? { page: parsePageLayout( body.page ) } : {} ),
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
	const audio = parseAudio( body.overlay.audio );
	const images = parseImages( body.overlay.images );

	return url === null ||
		settings === null ||
		layout === null ||
		typeof fontApproved !== 'boolean' ||
		audio === undefined ||
		images === undefined
		? null
		: {
				overlay: { url, settings, layout, fontApproved, audio, images },
				...state,
			};
}

/**
 * Validates `{ sent: true }`.
 *
 * @param body Response body.
 */
export function parseTestSent( body: unknown ): true | null {
	return isRecord( body ) && body.sent === true ? true : null;
}

/** The overlay calls of one owner (the creator's or the global overlay's). */
export interface OverlayApi {
	/** The overlay (null before it is set up). */
	readonly get: (
		signal?: AbortSignal
	) => Promise< ApiResult< AlertsOverlayState > >;
	/** Sets the overlay up (idempotent). */
	readonly create: () => Promise< ApiResult< AlertsOverlayState > >;
	/** Saves overlay settings (the form's fields, the font, the layout or whether sounds play). */
	readonly update: (
		changes:
			| Partial< OverlaySettings >
			| { readonly layout: OverlayLayout }
			| { readonly audio_enabled: boolean }
	) => Promise< ApiResult< AlertsOverlayState > >;
	/** Replaces the secret overlay id; the old OBS URL stops working. */
	readonly regenerate: () => Promise< ApiResult< AlertsOverlayState > >;
	/** Uploads a sound into a slot; the slot's old sound is deleted. */
	readonly uploadSound: (
		slot: SoundSlot,
		file: File
	) => Promise< ApiResult< AlertsOverlayState > >;
	/** Puts a sound of the administrator's library into a slot. */
	readonly chooseSound: (
		slot: SoundSlot,
		soundId: number
	) => Promise< ApiResult< AlertsOverlayState > >;
	/** Removes the sound of a slot. */
	readonly removeSound: (
		slot: SoundSlot
	) => Promise< ApiResult< AlertsOverlayState > >;
	/** Uploads an own image that can fill the alert text. */
	readonly uploadImage: (
		file: File
	) => Promise< ApiResult< AlertsOverlayState > >;
	/** Deletes an own image; the texts it filled lose their fill. */
	readonly removeImage: (
		imageId: number
	) => Promise< ApiResult< AlertsOverlayState > >;
	/** Sends a test alert to the overlay. */
	readonly sendTest: () => Promise< ApiResult< true > >;
}

/**
 * The overlay calls under a base path. The creator's overlay uses
 * `/me/alerts/overlay`; the global overlay (step 25.9.4) its admin route,
 * which answers in the same shape, so the cards are shared.
 *
 * @param path Base path of the routes.
 */
export function overlayApi( path: string ): OverlayApi {
	const soundPath = ( slot: SoundSlot ): string =>
		`${ path }/sounds/${ slot === 'default' ? 'default' : String( slot ) }`;

	return {
		get: ( signal ) =>
			request(
				{ path, ...( signal === undefined ? {} : { signal } ) },
				parseAlertsOverlay
			),
		create: () => request( { path, method: 'POST' }, parseAlertsOverlay ),
		update: ( changes ) =>
			request(
				{ path, method: 'PATCH', data: { ...changes } },
				parseAlertsOverlay
			),
		regenerate: () =>
			request(
				{ path: `${ path }/regenerate`, method: 'POST' },
				parseAlertsOverlay
			),
		uploadSound: ( slot, file ) => {
			const body = new FormData();
			body.append( 'file', file );

			return request(
				{ path: soundPath( slot ), method: 'POST', body },
				parseAlertsOverlay
			);
		},
		chooseSound: ( slot, soundId ) =>
			request(
				{
					path: soundPath( slot ),
					method: 'POST',
					data: { library: soundId },
				},
				parseAlertsOverlay
			),
		removeSound: ( slot ) =>
			request(
				{ path: soundPath( slot ), method: 'DELETE' },
				parseAlertsOverlay
			),
		uploadImage: ( file ) => {
			const body = new FormData();
			body.append( 'file', file );

			return request(
				{ path: `${ path }/images`, method: 'POST', body },
				parseAlertsOverlay
			);
		},
		removeImage: ( imageId ) =>
			request(
				{
					path: `${ path }/images/${ String( imageId ) }`,
					method: 'DELETE',
				},
				parseAlertsOverlay
			),
		sendTest: () =>
			request(
				{ path: `${ path }/test`, method: 'POST' },
				parseTestSent
			),
	};
}

/** The creator's overlay calls (the default of the cards). */
export const creatorOverlayApi = overlayApi( PATH );
