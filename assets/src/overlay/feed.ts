/**
 * The overlay feed: configuration, answers and retry timing (ADR-065 pkt. 4).
 *
 * Pure functions without DOM or network access, so they can be tested alone.
 * Every value from the page or the server is validated before use.
 */
import { parseParts } from './look';
import type { TextPart } from './look';
import { parseLook } from './render';
import type { AlertLook } from './render';

/**
 * One alert as the server renders it: plain text (whole and in pieces, the
 * inserted values marked), the variation it is shown with ('' for the
 * default, ADR-088), and an image and a sound URL of this site or ''.
 */
export interface OverlayAlert {
	id: number;
	look: string;
	image: string;
	text: string;
	secondary: string;
	parts: TextPart[];
	secondaryParts: TextPart[];
	sound: string;
	/** The read-out of the text (ADR-089); '' for none. */
	tts: string;
}

/** An answer of `GET merchboard/v1/alerts/overlay/{id}/feed`. */
export interface FeedAnswer {
	cursor: number;
	version: string;
	alerts: OverlayAlert[];
}

/** The configuration the page embeds. */
export interface OverlayConfig {
	feed: string;
	version: string;
	/** The looks by variation id; '' is the default (ADR-088). */
	looks: ReadonlyMap< string, AlertLook >;
}

/** Longest wait between retries after errors, in milliseconds. */
export const MAX_BACKOFF = 30000;

/** Interval of the short-poll fallback, in milliseconds. */
export const SHORT_POLL_INTERVAL = 5000;

/** A request slower than this is aborted; the overlay then short-polls. */
export const REQUEST_TIMEOUT = 20000;

function isRecord( value: unknown ): value is Record< string, unknown > {
	return (
		typeof value === 'object' && value !== null && ! Array.isArray( value )
	);
}

function isCount( value: unknown ): value is number {
	return (
		typeof value === 'number' && Number.isSafeInteger( value ) && value >= 0
	);
}

/**
 * The page configuration, or null when it is missing or invalid.
 *
 * @param value Parsed JSON.
 */
export function parseConfig( value: unknown ): OverlayConfig | null {
	if (
		! isRecord( value ) ||
		typeof value.feed !== 'string' ||
		typeof value.version !== 'string' ||
		! isRecord( value.looks ) ||
		! isRecord( value.looks[ '' ] )
	) {
		return null;
	}

	try {
		const url = new URL( value.feed, window.location.href );

		if ( url.origin !== window.location.origin ) {
			return null;
		}
	} catch {
		return null;
	}

	const looks = new Map< string, AlertLook >();

	for ( const [ id, look ] of Object.entries( value.looks ) ) {
		if ( id === '' || /^[a-z0-9]{6,32}$/.test( id ) ) {
			looks.set( id, parseLook( look, window.location.origin ) );
		}
	}

	return { feed: value.feed, version: value.version, looks };
}

/**
 * The look of a variation; the default for '' or an unknown id.
 *
 * @param config The page configuration.
 * @param id     Variation id.
 */
export function lookOf( config: OverlayConfig, id: string ): AlertLook {
	return (
		config.looks.get( id ) ??
		config.looks.get( '' ) ??
		parseLook( {}, window.location.origin )
	);
}

/**
 * A feed answer, or null when it does not have the expected shape. Alerts
 * with an image or a sound of another origin keep their text but lose the
 * image or the sound.
 *
 * @param value Parsed JSON.
 */
export function parseAnswer( value: unknown ): FeedAnswer | null {
	if (
		! isRecord( value ) ||
		! isCount( value.cursor ) ||
		typeof value.version !== 'string' ||
		! Array.isArray( value.alerts )
	) {
		return null;
	}

	const alerts: OverlayAlert[] = [];

	for ( const item of value.alerts as unknown[] ) {
		if (
			! isRecord( item ) ||
			! isCount( item.id ) ||
			typeof item.image !== 'string' ||
			typeof item.text !== 'string' ||
			typeof item.secondary !== 'string' ||
			typeof item.sound !== 'string'
		) {
			return null;
		}

		alerts.push( {
			id: item.id,
			look:
				typeof item.look === 'string' &&
				/^[a-z0-9]{6,32}$/.test( item.look )
					? item.look
					: '',
			image: sameOrigin( item.image ) ? item.image : '',
			text: item.text,
			secondary: item.secondary,
			parts: parseParts( item.parts, item.text ),
			secondaryParts: parseParts( item.secondary_parts, item.secondary ),
			sound: sameOrigin( item.sound ) ? item.sound : '',
			tts:
				typeof item.tts === 'string' && sameOrigin( item.tts )
					? item.tts
					: '',
		} );
	}

	return { cursor: value.cursor, version: value.version, alerts };
}

/**
 * The URL of the next feed request.
 *
 * @param feed   Feed URL.
 * @param cursor Cursor; null for the first request (answers at once).
 * @param wait   Whether the server may hold the request (long-poll).
 */
export function feedUrl(
	feed: string,
	cursor: number | null,
	wait: boolean
): string {
	const url = new URL( feed, window.location.href );

	if ( cursor === null ) {
		url.searchParams.set( 'wait', 'false' );
	} else {
		url.searchParams.set( 'after', String( cursor ) );
		url.searchParams.set( 'wait', wait ? 'true' : 'false' );
	}

	return url.toString();
}

/** How long a cursor carried over a reload stays valid (ms). */
export const RELOAD_CURSOR_TTL = 60000;

/** Query parameters that carry the cursor over a reload. */
const AFTER_PARAM = 'merchboard_after';
const AT_PARAM = 'merchboard_at';

/**
 * The page's address for the reload after a settings change: it carries the
 * cursor, so alerts recorded while the page reloads are still shown (25.13
 * review). In the address, not in browser storage (docs/SECURITY.md).
 *
 * @param href   The page's address.
 * @param cursor Cursor before the reload.
 * @param now    Current time (ms).
 */
export function reloadUrl( href: string, cursor: number, now: number ): string {
	const url = new URL( href );
	url.searchParams.set( AFTER_PARAM, String( cursor ) );
	url.searchParams.set( AT_PARAM, String( now ) );

	return url.toString();
}

/**
 * The cursor carried over a reload and the address without it; the cursor
 * is null when there is none or it is older than RELOAD_CURSOR_TTL (a later
 * refresh or a new OBS session never replays old alerts).
 *
 * @param href The page's address.
 * @param now  Current time (ms).
 */
export function takeCursor(
	href: string,
	now: number
): { cursor: number | null; href: string } {
	const url = new URL( href );
	const after = url.searchParams.get( AFTER_PARAM );
	const at = Number( url.searchParams.get( AT_PARAM ) );
	url.searchParams.delete( AFTER_PARAM );
	url.searchParams.delete( AT_PARAM );
	const cursor =
		after !== null && /^\d{1,15}$/.test( after ) ? Number( after ) : null;
	const fresh =
		Number.isSafeInteger( at ) &&
		now - at >= 0 &&
		now - at <= RELOAD_CURSOR_TTL;

	return { cursor: fresh ? cursor : null, href: url.toString() };
}

/**
 * Wait before retry `attempt` (0, 1, …): 1 s, 2 s, 4 s … up to MAX_BACKOFF.
 *
 * @param attempt Number of failed requests in a row, minus one.
 */
export function backoff( attempt: number ): number {
	return Math.min(
		1000 * 2 ** Math.max( 0, Math.min( attempt, 10 ) ),
		MAX_BACKOFF
	);
}

function sameOrigin( url: string ): boolean {
	if ( url === '' ) {
		return true;
	}

	try {
		return (
			new URL( url, window.location.href ).origin ===
			window.location.origin
		);
	} catch {
		return false;
	}
}
