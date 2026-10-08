/**
 * Test helpers: render into a detached container with React's act(), and a
 * valid dashboard configuration.
 */
import { createRoot } from '@wordpress/element';
import { act } from 'react';
import type { ReactNode } from 'react';

import type { DashboardConfig } from '../types/config';

export const ORIGIN = window.location.origin;

export const RAW_CONFIG = {
	site_name: 'Merch Shop',
	dashboard_url: `${ ORIGIN }/creator-dashboard/`,
	routing: 'path',
	logout_url: `${ ORIGIN }/wp-login.php?action=logout&_wpnonce=abc&redirect_to=x`,
	login_url: `${ ORIGIN }/wp-login.php?redirect_to=x`,
	password_url: `${ ORIGIN }/wp-login.php?action=lostpassword`,
	locale: 'da_DK',
	sections: [ { id: 'overview', label: 'Overview', path: '' } ],
} as const;

export const CONFIG: DashboardConfig = {
	siteName: 'Merch Shop',
	dashboardUrl: `${ ORIGIN }/creator-dashboard/`,
	routing: 'path',
	logoutUrl: `${ ORIGIN }/wp-login.php?action=logout&_wpnonce=abc&redirect_to=x`,
	loginUrl: `${ ORIGIN }/wp-login.php?redirect_to=x`,
	passwordUrl: `${ ORIGIN }/wp-login.php?action=lostpassword`,
	locale: 'da_DK',
	sections: [ { id: 'overview', label: 'Overview', path: '' } ],
	logo: null,
};

export interface Rendered {
	readonly container: HTMLElement;
	readonly unmount: () => void;
}

export function render( ui: ReactNode ): Rendered {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	act( () => root.render( ui ) );

	return {
		container,
		unmount: () => {
			act( () => root.unmount() );
			container.remove();
		},
	};
}

/**
 * Clicks an element inside act().
 */
export function click( element: Element | null ): void {
	if ( element === null ) {
		throw new Error( 'Element to click not found.' );
	}

	act( () => {
		element.dispatchEvent(
			new MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
				button: 0,
			} )
		);
	} );
}

/** Lets pending promises settle inside act(). */
export async function flush(): Promise< void > {
	await act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
}

/**
 * Sets the browser URL without navigating.
 */
export function setUrl( url: string ): void {
	window.history.replaceState( null, '', url );
}
