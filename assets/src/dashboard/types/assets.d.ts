/**
 * Stylesheets are imported for their side effect only; webpack extracts
 * them into build/dashboard.css.
 */
declare module '*.scss';

interface Window {
	/** Raw bootstrap data printed by the shell; validated by parseConfig(). */
	merchboardDashboard?: unknown;
}
