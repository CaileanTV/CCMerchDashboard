/**
 * Parses the bootstrap data the shell prints as `window.merchboardDashboard`.
 */
import { isRecord, readSameOriginUrl, readString } from './api/validate';
import { normalizePath } from './app/routes';
import type {
	DashboardConfig,
	DashboardSection,
	RoutingMode,
} from './types/config';

function parseSections( value: unknown ): DashboardSection[] | null {
	if ( ! Array.isArray( value ) ) {
		return null;
	}

	const sections: DashboardSection[] = [];

	for ( const item of value ) {
		if ( ! isRecord( item ) ) {
			return null;
		}

		const id = readString( item, 'id' );
		const label = readString( item, 'label' );
		const path =
			typeof item.path === 'string' ? normalizePath( item.path ) : null;

		if ( id === null || label === null || path === null ) {
			return null;
		}

		sections.push( { id, label, path } );
	}

	return sections;
}

/**
 * The validated configuration, or null when anything is missing or invalid.
 * All URLs must be on the page's own origin.
 *
 * @param raw    Raw bootstrap value.
 * @param origin Origin of the current page.
 */
export function parseConfig(
	raw: unknown,
	origin: string
): DashboardConfig | null {
	if ( ! isRecord( raw ) ) {
		return null;
	}

	const siteName = readString( raw, 'site_name' );
	const dashboardUrl = readSameOriginUrl( raw.dashboard_url, origin );
	const logoutUrl = readSameOriginUrl( raw.logout_url, origin );
	const loginUrl = readSameOriginUrl( raw.login_url, origin );
	const locale = readString( raw, 'locale' );
	const routing: RoutingMode | null =
		raw.routing === 'path' || raw.routing === 'query' ? raw.routing : null;
	const sections = parseSections( raw.sections );

	if (
		siteName === null ||
		dashboardUrl === null ||
		logoutUrl === null ||
		loginUrl === null ||
		locale === null ||
		routing === null ||
		sections === null
	) {
		return null;
	}

	return {
		siteName,
		dashboardUrl,
		routing,
		logoutUrl,
		loginUrl,
		locale,
		sections,
	};
}
