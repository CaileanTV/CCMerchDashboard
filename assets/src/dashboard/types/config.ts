/**
 * Dashboard configuration from the server (bootstrap data, docs/FRONTEND.md).
 *
 * Only non-sensitive values. Who the creator is never comes from here: from
 * phase 14 the creator is read from the REST API, which decides it
 * server-side (CreatorContext).
 */

/** How dashboard URLs are built: pretty permalinks or the query-string fallback. */
export type RoutingMode = 'path' | 'query';

/** A navigation entry the server allows for the current user. */
export interface DashboardSection {
	readonly id: string;
	readonly label: string;
	/** Path below the dashboard start page ('' for the start page). */
	readonly path: string;
	/** The menu group the section belongs to (e.g. "Alerts"), if any. */
	readonly group?: { readonly id: string; readonly label: string };
}

/**
 * The header logo (ADR-042), on the page's own origin. A custom (admin) logo
 * replaces the site name and carries it as alt text; the default logo is a
 * mark next to the site name (empty alt text).
 */
export interface DashboardLogo {
	readonly url: string;
	readonly width: number;
	readonly height: number;
	readonly alt: string;
	readonly custom: boolean;
}

/** Validated configuration used by the app (camelCase, URLs checked). */
export interface DashboardConfig {
	readonly siteName: string;
	/** Absolute URL of the dashboard start page. */
	readonly dashboardUrl: string;
	readonly routing: RoutingMode;
	readonly logoutUrl: string;
	readonly loginUrl: string;
	/** WordPress' own password reset page. */
	readonly passwordUrl: string;
	readonly locale: string;
	readonly sections: readonly DashboardSection[];
	/** Null: no usable logo; the site name is shown as text. */
	readonly logo: DashboardLogo | null;
}
