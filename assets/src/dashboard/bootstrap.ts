/**
 * Parses the bootstrap data the shell prints as `window.merchboardDashboard`.
 */
import { isRecord, readSameOriginUrl, readString } from './api/validate';
import { normalizePath } from './app/routes';
import type {
	DashboardConfig,
	DashboardLogo,
	DashboardSection,
	RoutingMode,
} from './types/config';

const GROUP_ID = /^[a-z][a-z0-9_-]{0,39}$/;

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

		if ( item.group === undefined ) {
			sections.push( { id, label, path } );
			continue;
		}

		const groupLabel = readString( item, 'group_label' );

		if (
			typeof item.group !== 'string' ||
			! GROUP_ID.test( item.group ) ||
			groupLabel === null
		) {
			return null;
		}

		sections.push( {
			id,
			label,
			path,
			group: { id: item.group, label: groupLabel },
		} );
	}

	return sections;
}

const MAX_LOGO_SIDE = 4000;

function isSize( value: unknown ): value is number {
	return (
		typeof value === 'number' &&
		Number.isInteger( value ) &&
		value > 0 &&
		value <= MAX_LOGO_SIDE
	);
}

/**
 * The logo, or null when it is missing or invalid. A bad logo never breaks
 * the dashboard: the header then shows the site name as text.
 *
 * @param value  Raw `logo` value.
 * @param origin Origin of the current page.
 */
function parseLogo( value: unknown, origin: string ): DashboardLogo | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const url = readSameOriginUrl( value.url, origin );
	const { width, height, alt, custom } = value;

	if (
		url === null ||
		! isSize( width ) ||
		! isSize( height ) ||
		typeof alt !== 'string' ||
		typeof custom !== 'boolean'
	) {
		return null;
	}

	return { url, width, height, alt, custom };
}

/**
 * The end link of a "view as creator" session (ADR-075), on the page's own
 * origin; nothing when there is none or it is invalid.
 *
 * @param value  Raw value.
 * @param origin Origin of the current page.
 */
function parseImpersonation(
	value: unknown,
	origin: string
): { impersonationEndUrl?: string } {
	const url = readSameOriginUrl( value, origin );

	return url === null ? {} : { impersonationEndUrl: url };
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

	// Display only, and WordPress allows an empty site title.
	const siteName = typeof raw.site_name === 'string' ? raw.site_name : null;
	const dashboardUrl = readSameOriginUrl( raw.dashboard_url, origin );
	const logoutUrl = readSameOriginUrl( raw.logout_url, origin );
	const loginUrl = readSameOriginUrl( raw.login_url, origin );
	const passwordUrl = readSameOriginUrl( raw.password_url, origin );
	const locale = readString( raw, 'locale' );
	const routing: RoutingMode | null =
		raw.routing === 'path' || raw.routing === 'query' ? raw.routing : null;
	const sections = parseSections( raw.sections );

	if (
		siteName === null ||
		dashboardUrl === null ||
		logoutUrl === null ||
		loginUrl === null ||
		passwordUrl === null ||
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
		passwordUrl,
		locale,
		sections,
		logo: parseLogo( raw.logo, origin ),
		...( raw.impersonating === true ? { impersonating: true } : {} ),
		...parseImpersonation( raw.impersonation_end_url, origin ),
	};
}
