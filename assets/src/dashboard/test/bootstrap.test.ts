import { describe, expect, it } from '@jest/globals';

import { parseConfig } from '../bootstrap';
import { CONFIG, ORIGIN, RAW_CONFIG } from '../testing/helpers';

describe( 'parseConfig', () => {
	it( 'turns valid bootstrap data into the typed configuration', () => {
		expect( parseConfig( RAW_CONFIG, ORIGIN ) ).toEqual( CONFIG );
	} );

	it.each( [
		null,
		undefined,
		'x',
		42,
		[],
		{ ...RAW_CONFIG, site_name: '' },
	] )( 'rejects %p', ( raw ) => {
		expect( parseConfig( raw, ORIGIN ) ).toBeNull();
	} );

	it.each( [
		'site_name',
		'dashboard_url',
		'routing',
		'logout_url',
		'login_url',
		'locale',
		'sections',
	] )( 'rejects data without %s', ( key ) => {
		const raw: Record< string, unknown > = { ...RAW_CONFIG };
		delete raw[ key ];

		expect( parseConfig( raw, ORIGIN ) ).toBeNull();
	} );

	it.each( [
		[ 'another host', 'https://evil.example/wp-login.php' ],
		[ 'protocol-relative', '//evil.example/wp-login.php' ],
		[ 'javascript', 'javascript:alert(1)' ],
		[ 'data', 'data:text/html,<script>alert(1)</script>' ],
		[ 'relative', '/wp-login.php' ],
		[
			'credentials',
			`${ ORIGIN.replace( '://', '://user:pass@' ) }/wp-login.php`,
		],
		[ 'not a string', 42 ],
	] )( 'rejects a %s URL', ( _label, url ) => {
		expect(
			parseConfig( { ...RAW_CONFIG, logout_url: url }, ORIGIN )
		).toBeNull();
		expect(
			parseConfig( { ...RAW_CONFIG, login_url: url }, ORIGIN )
		).toBeNull();
		expect(
			parseConfig( { ...RAW_CONFIG, dashboard_url: url }, ORIGIN )
		).toBeNull();
	} );

	it( 'rejects unknown routing modes and invalid sections', () => {
		expect(
			parseConfig( { ...RAW_CONFIG, routing: 'hash' }, ORIGIN )
		).toBeNull();
		expect(
			parseConfig( { ...RAW_CONFIG, sections: 'overview' }, ORIGIN )
		).toBeNull();
		expect(
			parseConfig(
				{
					...RAW_CONFIG,
					sections: [ { id: 'x', label: 'X', path: '../wp-admin' } ],
				},
				ORIGIN
			)
		).toBeNull();
		expect(
			parseConfig(
				{ ...RAW_CONFIG, sections: [ { id: 'x', path: '' } ] },
				ORIGIN
			)
		).toBeNull();
		expect(
			parseConfig( { ...RAW_CONFIG, sections: [ null ] }, ORIGIN )
		).toBeNull();
	} );

	it( 'ignores unknown fields such as a creator id', () => {
		const config = parseConfig(
			{ ...RAW_CONFIG, creator_id: 7, user_id: 9 },
			ORIGIN
		);

		expect( config ).toEqual( CONFIG );
		expect( Object.keys( config ?? {} ) ).toEqual( [
			'siteName',
			'dashboardUrl',
			'routing',
			'logoutUrl',
			'loginUrl',
			'locale',
			'sections',
		] );
	} );
} );
