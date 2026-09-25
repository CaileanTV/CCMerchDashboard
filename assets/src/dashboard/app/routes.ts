/**
 * Pure URL logic of the client-side router. Mirrors the server
 * (DashboardRoute): a dashboard path is '' (start page) or lowercase segments
 * of letters, digits and hyphens. Anything else is not a dashboard path, so
 * the router can never be pointed at another site or a `javascript:` URL.
 */
import type { DashboardConfig } from '../types/config';

/** Query var carrying the deep link without pretty permalinks. */
export const PATH_QUERY_VAR = 'merchboard_dashboard_path';

const PATH_PATTERN = /^[a-z0-9-]+(?:\/[a-z0-9-]+)*$/;

/**
 * The normalized path, or null when the value is not a dashboard path.
 *
 * @param raw Path like "sales/2026", "/Sales/" or "".
 */
export function normalizePath( raw: string ): string | null {
	const path = raw.toLowerCase().replace( /^\/+|\/+$/g, '' );

	if ( path === '' ) {
		return '';
	}

	return path.length <= 200 && PATH_PATTERN.test( path ) ? path : null;
}

/**
 * Path part of the start page URL, always ending with "/".
 */
function basePathname( config: DashboardConfig ): string {
	const pathname = new URL( config.dashboardUrl ).pathname;

	return pathname.endsWith( '/' ) ? pathname : `${ pathname }/`;
}

/**
 * The dashboard path of a browser location ('' when it is not a valid path).
 *
 * @param location Current location.
 * @param config   Dashboard configuration.
 */
export function pathFromLocation(
	location: Pick< Location, 'pathname' | 'search' >,
	config: DashboardConfig
): string {
	let raw = '';

	if ( config.routing === 'query' ) {
		raw =
			new URLSearchParams( location.search ).get( PATH_QUERY_VAR ) ?? '';
	} else {
		const base = basePathname( config );
		const pathname = location.pathname.endsWith( '/' )
			? location.pathname
			: `${ location.pathname }/`;

		raw = pathname.startsWith( base ) ? pathname.slice( base.length ) : '';
	}

	return normalizePath( raw ) ?? '';
}

/**
 * Absolute URL of a dashboard path.
 *
 * @param path   Normalized dashboard path.
 * @param config Dashboard configuration.
 */
export function urlForPath( path: string, config: DashboardConfig ): string {
	const url = new URL( config.dashboardUrl );

	if ( config.routing === 'query' ) {
		url.searchParams.delete( PATH_QUERY_VAR );

		if ( path !== '' ) {
			url.searchParams.set( PATH_QUERY_VAR, path );
		}

		return url.href;
	}

	url.pathname = basePathname( config ) + ( path === '' ? '' : `${ path }/` );
	url.search = '';
	url.hash = '';

	return url.href;
}
