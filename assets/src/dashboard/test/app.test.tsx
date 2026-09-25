import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';
import { act } from 'react';

import { App } from '../app/App';
import { mountDashboard } from '../mount';
import type { DashboardConfig } from '../types/config';
import {
	CONFIG,
	ORIGIN,
	RAW_CONFIG,
	click,
	render,
	setUrl,
} from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

describe( 'dashboard app', () => {
	let rendered: Rendered | null = null;

	beforeEach( () => {
		setUrl( '/creator-dashboard/' );
	} );

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
		jest.restoreAllMocks();
	} );

	function renderApp( config: DashboardConfig = CONFIG ): HTMLElement {
		rendered = render( <App config={ config } /> );

		return rendered.container;
	}

	it( 'renders the shell with landmarks, one h1 and the overview', () => {
		const container = renderApp();

		expect( container.querySelectorAll( 'header' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( 'main' ) ).toHaveLength( 1 );
		expect(
			container.querySelectorAll( 'nav[aria-label="Dashboard"]' )
		).toHaveLength( 2 );
		expect( container.querySelectorAll( 'h1' ) ).toHaveLength( 1 );
		expect( container.querySelector( 'h1' )?.textContent ).toBe(
			'Overview'
		);
		expect( container.querySelector( 'h2' )?.textContent ).toBe(
			'Welcome'
		);
		expect( container.textContent ).toContain( 'Nothing to show yet.' );
		expect(
			container
				.querySelector( '.merchboard-skip-link' )
				?.getAttribute( 'href' )
		).toBe( '#merchboard-main' );
		expect( document.title ).toBe( 'Overview · Merch Shop' );
	} );

	it( 'marks the current section and links with real URLs', () => {
		const container = renderApp();
		const link = container.querySelector( '.merchboard-sidebar a' );

		expect( link?.getAttribute( 'href' ) ).toBe(
			`${ ORIGIN }/creator-dashboard/`
		);
		expect( link?.getAttribute( 'aria-current' ) ).toBe( 'page' );
	} );

	it( 'shows "not found" for an unknown deep link and navigates back client-side', () => {
		setUrl( '/creator-dashboard/sales/2026/' );
		const container = renderApp();

		expect( container.querySelector( 'h1' )?.textContent ).toBe(
			'Page not found'
		);
		expect(
			container
				.querySelector( '.merchboard-sidebar a' )
				?.getAttribute( 'aria-current' )
		).toBeNull();

		const pushState = jest.spyOn( window.history, 'pushState' );
		click( container.querySelector( 'main a' ) );

		expect( pushState ).toHaveBeenCalledWith(
			null,
			'',
			`${ ORIGIN }/creator-dashboard/`
		);
		expect( container.querySelector( 'h1' )?.textContent ).toBe(
			'Overview'
		);
	} );

	it( 'follows the browser back button', () => {
		const container = renderApp();

		act( () => {
			setUrl( '/creator-dashboard/other/' );
			window.dispatchEvent( new PopStateEvent( 'popstate' ) );
		} );

		expect( container.querySelector( 'h1' )?.textContent ).toBe(
			'Page not found'
		);
	} );

	it( 'works with the query-string fallback', () => {
		setUrl( '/?merchboard_dashboard=1&merchboard_dashboard_path=sales' );
		const container = renderApp( {
			...CONFIG,
			routing: 'query',
			dashboardUrl: `${ ORIGIN }/?merchboard_dashboard=1`,
		} );

		expect( container.querySelector( 'h1' )?.textContent ).toBe(
			'Page not found'
		);
		expect(
			container
				.querySelector( '.merchboard-sidebar a' )
				?.getAttribute( 'href' )
		).toBe( `${ ORIGIN }/?merchboard_dashboard=1` );
	} );

	it( 'shows a known section the server allows but this build does not have yet', () => {
		setUrl( '/creator-dashboard/profile/' );
		const container = renderApp( {
			...CONFIG,
			sections: [
				...CONFIG.sections,
				{ id: 'profile', label: 'Profile', path: 'profile' },
			],
		} );

		expect( container.querySelector( 'h1' )?.textContent ).toBe(
			'Profile'
		);
		expect( container.textContent ).toContain(
			'This section is not available yet.'
		);
	} );

	it( 'opens and closes the mobile navigation with the button and Escape', () => {
		const container = renderApp();
		const button = container.querySelector< HTMLButtonElement >(
			'.merchboard-topbar__menu-button'
		);
		const mobileNav = container.querySelector( '#merchboard-mobile-nav' );

		expect( button?.getAttribute( 'aria-controls' ) ).toBe(
			'merchboard-mobile-nav'
		);
		expect( button?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( mobileNav?.hasAttribute( 'hidden' ) ).toBe( true );

		click( button );
		expect( button?.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( mobileNav?.hasAttribute( 'hidden' ) ).toBe( false );

		act( () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Escape' } )
			);
		} );
		expect( button?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( button?.ownerDocument.activeElement ).toBe( button );

		click( button );
		click( container.querySelector( '#merchboard-mobile-nav a' ) );
		expect( button?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	it( 'renders server values as text, never as HTML', () => {
		const container = renderApp( {
			...CONFIG,
			siteName: '<img src=x onerror="window.pwned=1">',
		} );

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect(
			container.querySelector( '.merchboard-topbar__site' )?.textContent
		).toBe( '<img src=x onerror="window.pwned=1">' );
	} );

	it( 'links to the server-provided logout URL', () => {
		const container = renderApp();

		expect(
			container
				.querySelector( '.merchboard-topbar__logout' )
				?.getAttribute( 'href' )
		).toBe( CONFIG.logoutUrl );
	} );

	it( 'stores nothing in browser storage', () => {
		const setItem = jest.spyOn( Storage.prototype, 'setItem' );
		const container = renderApp();
		click( container.querySelector( '.merchboard-topbar__menu-button' ) );

		expect( setItem ).not.toHaveBeenCalled();
		expect( window.localStorage ).toHaveLength( 0 );
		expect( window.sessionStorage ).toHaveLength( 0 );
		expect( document.cookie ).toBe( '' );
	} );
} );

describe( 'mountDashboard', () => {
	it( 'mounts the app into the root element and unmounts it', () => {
		setUrl( '/creator-dashboard/' );
		const root = document.createElement( 'div' );
		let unmount: () => void = () => undefined;

		act( () => {
			unmount = mountDashboard( root, RAW_CONFIG, ORIGIN );
		} );
		expect( root.querySelector( 'h1' )?.textContent ).toBe( 'Overview' );

		act( () => unmount() );
		expect( root.innerHTML ).toBe( '' );
	} );

	it.each( [
		undefined,
		{},
		{ ...RAW_CONFIG, logout_url: 'https://evil.example/' },
	] )( 'shows a generic error for invalid bootstrap data (%#)', ( raw ) => {
		const root = document.createElement( 'div' );

		act( () => {
			mountDashboard( root, raw, ORIGIN );
		} );

		expect( root.querySelector( '[role="alert"]' )?.textContent ).toContain(
			'The dashboard could not be started.'
		);
		expect( root.querySelector( 'a' ) ).toBeNull();
		expect( root.querySelector( 'nav' ) ).toBeNull();
	} );
} );
