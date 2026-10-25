import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';

import { App } from '../app/App';
import { parseConfig } from '../bootstrap';
import type { DashboardConfig } from '../types/config';
import { ME, mockApi, response } from '../testing/api';
import {
	CONFIG,
	ORIGIN,
	RAW_CONFIG,
	flush,
	render,
	setUrl,
} from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const END_URL = `${ ORIGIN }/wp-admin/admin-post.php?action=merchboard_impersonate_end&_wpnonce=abc`;

describe( '"view as creator" (step 27.2, ADR-075)', () => {
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

	async function banner(
		config: DashboardConfig
	): Promise< Element | null > {
		rendered = render( <App config={ config } /> );
		await flush();

		return rendered.container.querySelector( '.merchboard-impersonation' );
	}

	it( 'reads the end link only on the page’s own origin', () => {
		expect(
			parseConfig(
				{ ...RAW_CONFIG, impersonation_end_url: END_URL },
				ORIGIN
			)?.impersonationEndUrl
		).toBe( END_URL );

		for ( const value of [
			null,
			'https://evil.example/end',
			'javascript:alert(1)',
			42,
		] ) {
			const config = parseConfig(
				{ ...RAW_CONFIG, impersonation_end_url: value },
				ORIGIN
			);

			expect( config ).not.toBeNull();
			expect( config?.impersonationEndUrl ).toBeUndefined();
		}
	} );

	it( 'shows no bar for the creator themself', async () => {
		expect( await banner( CONFIG ) ).toBeNull();
	} );

	it( 'shows who the administrator acts as and how to stop', async () => {
		const element = await banner( {
			...CONFIG,
			impersonationEndUrl: END_URL,
		} );

		expect( element?.getAttribute( 'role' ) ).toBe( 'status' );
		expect( element?.textContent ).toContain( 'Nova' );
		expect(
			element
				?.querySelector( '.merchboard-impersonation__end' )
				?.getAttribute( 'href' )
		).toBe( END_URL );
	} );
} );
