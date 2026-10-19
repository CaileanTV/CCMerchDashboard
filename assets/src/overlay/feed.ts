/**
 * The overlay feed: configuration, answers and retry timing (ADR-065 pkt. 4).
 *
 * Pure functions without DOM or network access, so they can be tested alone.
 * Every value from the page or the server is validated before use.
 */

/**
 * One alert as the server renders it: plain text, and an image and a sound
 * URL of this site or ''.
 */
export interface OverlayAlert {
	id: number;
	image: string;
	text: string;
	secondary: string;
	sound: string;
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
	displayDuration: number;
	animDuration: number;
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
		! isCount( value.displayDuration ) ||
		! isCount( value.animDuration )
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

	return {
		feed: value.feed,
		version: value.version,
		displayDuration: value.displayDuration,
		animDuration: value.animDuration,
	};
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
			image: sameOrigin( item.image ) ? item.image : '',
			text: item.text,
			secondary: item.secondary,
			sound: sameOrigin( item.sound ) ? item.sound : '',
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
