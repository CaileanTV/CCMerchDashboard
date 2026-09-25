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
}

/** Validated configuration used by the app (camelCase, URLs checked). */
export interface DashboardConfig {
	readonly siteName: string;
	/** Absolute URL of the dashboard start page. */
	readonly dashboardUrl: string;
	readonly routing: RoutingMode;
	readonly logoutUrl: string;
	readonly loginUrl: string;
	readonly locale: string;
	readonly sections: readonly DashboardSection[];
}
