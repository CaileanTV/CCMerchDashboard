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
import type { DashboardConfig, DashboardLogo } from '../types/config';
import { ME, mockApi, response } from '../testing/api';
import { CONFIG, ORIGIN, flush, render, setUrl } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const DEFAULT_LOGO: DashboardLogo = {
	url: `${ ORIGIN }/wp-content/plugins/merchboard/assets/brand/logo.svg?ver=0.1.0`,
	width: 64,
	height: 64,
	alt: '',
	custom: false,
};

const CUSTOM_LOGO: DashboardLogo = {
	url: `${ ORIGIN }/wp-content/uploads/logo.png`,
	width: 400,
	height: 100,
	alt: 'Merch Shop',
	custom: true,
};

describe( 'header logo (ADR-042)', () => {
	let rendered: Rendered | null = null;

	beforeEach( () => {
		setUrl( '/creator-dashboard/' );
		mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
			'GET /merchboard/v1/me/sales': () => response( 404, {} ),
		} );
	} );

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
	} );

	async function header( config: DashboardConfig ): Promise< HTMLElement > {
		rendered = render( <App config={ config } /> );
		await flush();

		const element = rendered.container.querySelector( 'header' );

		if ( element === null ) {
			throw new Error( 'No header.' );
		}

		return element;
	}

	it( 'shows the site name as text without a logo', async () => {
		const element = await header( CONFIG );

		expect( element.querySelector( 'img' ) ).toBeNull();
		expect(
			element.querySelector( '.merchboard-topbar__site' )?.textContent
		).toBe( 'Merch Shop' );
	} );

	it( 'shows the default mark next to the site name, as decoration', async () => {
		const element = await header( { ...CONFIG, logo: DEFAULT_LOGO } );
		const image = element.querySelector( '.merchboard-logo img' );

		expect( image?.getAttribute( 'src' ) ).toBe( DEFAULT_LOGO.url );
		expect( image?.getAttribute( 'alt' ) ).toBe( '' );
		expect( image?.getAttribute( 'width' ) ).toBe( '64' );
		expect( image?.getAttribute( 'height' ) ).toBe( '64' );
		expect(
			element.querySelector( '.merchboard-topbar__site' )?.textContent
		).toBe( 'Merch Shop' );
	} );

	it( 'lets a custom logo replace the site name, with the name as alt text', async () => {
		const element = await header( { ...CONFIG, logo: CUSTOM_LOGO } );
		const image = element.querySelector( '.merchboard-logo img' );

		expect( image?.getAttribute( 'src' ) ).toBe( CUSTOM_LOGO.url );
		expect( image?.getAttribute( 'alt' ) ).toBe( 'Merch Shop' );
		expect(
			element.querySelector( '.merchboard-topbar__site' )
		).toBeNull();
		expect( image?.hasAttribute( 'style' ) ).toBe( false );
	} );

	it( 'falls back to the site name when the image fails to load', async () => {
		const element = await header( { ...CONFIG, logo: CUSTOM_LOGO } );
		const image = element.querySelector( 'img' );

		act( () => {
			image?.dispatchEvent( new Event( 'error' ) );
		} );

		expect( element.querySelector( 'img' ) ).toBeNull();
		expect(
			element.querySelector( '.merchboard-topbar__site' )?.textContent
		).toBe( 'Merch Shop' );
	} );

	it( 'is small with the plate when no style is sent', async () => {
		const element = await header( { ...CONFIG, logo: CUSTOM_LOGO } );

		expect( element.querySelector( '.merchboard-logo' )?.className ).toBe(
			'merchboard-logo merchboard-logo--small'
		);
	} );

	it( 'applies the size, the plate setting and the dark logo (ADR-103)', async () => {
		const dark = {
			url: `${ ORIGIN }/wp-content/uploads/logo-dark.png`,
			width: 400,
			height: 100,
			alt: 'Merch Shop',
		};
		const element = await header( {
			...CONFIG,
			logo: CUSTOM_LOGO,
			logoStyle: { size: 'large', plate: false, dark },
		} );
		const wrapper = element.querySelector( '.merchboard-logo' );

		expect( wrapper?.className ).toBe(
			'merchboard-logo merchboard-logo--large merchboard-logo--no-plate merchboard-logo--has-dark'
		);
		expect(
			wrapper
				?.querySelector( '.merchboard-logo__image--light' )
				?.getAttribute( 'src' )
		).toBe( CUSTOM_LOGO.url );
		expect(
			wrapper
				?.querySelector( '.merchboard-logo__image--dark' )
				?.getAttribute( 'src' )
		).toBe( dark.url );
	} );

	it( 'has no dark image without a dark logo', async () => {
		const element = await header( {
			...CONFIG,
			logo: CUSTOM_LOGO,
			logoStyle: { size: 'medium', plate: true, dark: null },
		} );

		expect(
			element.querySelectorAll( '.merchboard-logo img' )
		).toHaveLength( 1 );
		expect( element.querySelector( '.merchboard-logo' )?.className ).toBe(
			'merchboard-logo merchboard-logo--medium'
		);
	} );

	it( 'renders alt text as text only', async () => {
		const element = await header( {
			...CONFIG,
			siteName: '<img src=x onerror="window.pwned=1">',
			logo: {
				...CUSTOM_LOGO,
				alt: '<img src=x onerror="window.pwned=1">',
			},
		} );

		expect( element.querySelectorAll( 'img' ) ).toHaveLength( 1 );
		expect( element.querySelector( 'img' )?.getAttribute( 'alt' ) ).toBe(
			'<img src=x onerror="window.pwned=1">'
		);
	} );
} );
