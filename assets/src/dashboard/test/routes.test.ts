import { describe, expect, it } from '@jest/globals';

import { normalizePath, pathFromLocation, urlForPath } from '../app/routes';
import type { DashboardConfig } from '../types/config';
import { CONFIG, ORIGIN } from '../testing/helpers';

const QUERY: DashboardConfig = {
	...CONFIG,
	routing: 'query',
	dashboardUrl: `${ ORIGIN }/?merchboard_dashboard=1`,
};

describe( 'normalizePath', () => {
	it.each( [
		[ '', '' ],
		[ '/', '' ],
		[ 'sales', 'sales' ],
		[ '/Sales/2026/', 'sales/2026' ],
		[ 'my-page', 'my-page' ],
	] )( 'accepts %p as %p', ( raw, expected ) => {
		expect( normalizePath( raw ) ).toBe( expected );
	} );

	it.each( [
		'../wp-admin',
		'sales//x',
		'https://evil.example',
		'//evil.example',
		'javascript:alert(1)',
		'<script>',
		'sales?x=1',
		'sales#x',
		'a%2Fb',
		'x'.repeat( 201 ),
	] )( 'rejects %p', ( raw ) => {
		expect( normalizePath( raw ) ).toBeNull();
	} );
} );

describe( 'pretty permalinks', () => {
	it( 'reads the path below the dashboard', () => {
		expect(
			pathFromLocation(
				{ pathname: '/creator-dashboard/', search: '' },
				CONFIG
			)
		).toBe( '' );
		expect(
			pathFromLocation(
				{ pathname: '/creator-dashboard', search: '' },
				CONFIG
			)
		).toBe( '' );
		expect(
			pathFromLocation(
				{ pathname: '/creator-dashboard/sales/2026/', search: '' },
				CONFIG
			)
		).toBe( 'sales/2026' );
		expect(
			pathFromLocation(
				{ pathname: '/creator-dashboard/%3Cscript%3E/', search: '' },
				CONFIG
			)
		).toBe( '' );
		expect(
			pathFromLocation( { pathname: '/other/', search: '' }, CONFIG )
		).toBe( '' );
	} );

	it( 'builds dashboard URLs', () => {
		expect( urlForPath( '', CONFIG ) ).toBe(
			`${ ORIGIN }/creator-dashboard/`
		);
		expect( urlForPath( 'sales/2026', CONFIG ) ).toBe(
			`${ ORIGIN }/creator-dashboard/sales/2026/`
		);
	} );
} );

describe( 'query-string fallback', () => {
	it( 'reads the path from the query var', () => {
		expect(
			pathFromLocation(
				{ pathname: '/', search: '?merchboard_dashboard=1' },
				QUERY
			)
		).toBe( '' );
		expect(
			pathFromLocation(
				{
					pathname: '/',
					search: '?merchboard_dashboard=1&merchboard_dashboard_path=sales',
				},
				QUERY
			)
		).toBe( 'sales' );
		expect(
			pathFromLocation(
				{
					pathname: '/',
					search: '?merchboard_dashboard=1&merchboard_dashboard_path=..%2Fx',
				},
				QUERY
			)
		).toBe( '' );
	} );

	it( 'builds dashboard URLs', () => {
		expect( urlForPath( '', QUERY ) ).toBe(
			`${ ORIGIN }/?merchboard_dashboard=1`
		);
		expect( urlForPath( 'sales', QUERY ) ).toBe(
			`${ ORIGIN }/?merchboard_dashboard=1&merchboard_dashboard_path=sales`
		);
	} );
} );
