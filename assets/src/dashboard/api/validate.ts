/**
 * Small runtime checks for data that crosses a trust boundary (bootstrap data
 * and REST responses). TypeScript types say nothing about what actually
 * arrives at runtime, so untrusted values are always `unknown` until checked.
 */

export type UnknownRecord = Readonly< Record< string, unknown > >;

export function isRecord( value: unknown ): value is UnknownRecord {
	return (
		typeof value === 'object' && value !== null && ! Array.isArray( value )
	);
}

/**
 * A non-empty string, or null.
 */
export function readString(
	record: UnknownRecord,
	key: string
): string | null {
	const value = record[ key ];

	return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * An absolute http(s) URL on the given origin, or null. Used for every URL the
 * dashboard links to, so a manipulated value cannot send the user elsewhere
 * (open redirect) or run script (`javascript:`).
 *
 * @param value  Candidate URL.
 * @param origin Allowed origin, e.g. window.location.origin.
 */
export function readSameOriginUrl(
	value: unknown,
	origin: string
): string | null {
	if ( typeof value !== 'string' || value === '' ) {
		return null;
	}

	let url: URL;

	try {
		url = new URL( value );
	} catch {
		return null;
	}

	if (
		( url.protocol !== 'https:' && url.protocol !== 'http:' ) ||
		url.origin !== origin ||
		url.username !== '' ||
		url.password !== ''
	) {
		return null;
	}

	return url.href;
}
