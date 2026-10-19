/**
 * Tests for menu groups (phase 25, step 6.4): sections of a group sit under
 * one item that opens and closes.
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';

import { App } from '../app/App';
import { navEntries } from '../app/layout/Navigation';
import { resolvePage } from '../app/pages';
import { parseConfig } from '../bootstrap';
import { ME, mockApi, response } from '../testing/api';
import {
	CONFIG,
	ORIGIN,
	RAW_CONFIG,
	click,
	flush,
	render,
	setUrl,
} from '../testing/helpers';
import type { Rendered } from '../testing/helpers';
import type { DashboardSection } from '../types/config';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const ALERTS = { id: 'alerts', label: 'Alerts' };

const SECTIONS: DashboardSection[] = [
	{ id: 'overview', label: 'Overview', path: '' },
	{ id: 'alerts', label: 'Overlay settings', path: 'alerts', group: ALERTS },
	{
		id: 'alerts-history',
		label: 'History',
		path: 'alerts/history',
		group: ALERTS,
	},
	{ id: 'profile', label: 'Profile', path: 'profile' },
];

describe( 'menu groups', () => {
	let rendered: Rendered | null = null;

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
	} );

	it( "parses a section's group and rejects an invalid one", () => {
		const raw = ( extra: Record< string, unknown > ) => ( {
			...RAW_CONFIG,
			sections: [
				{ id: 'overview', label: 'Overview', path: '' },
				{
					id: 'alerts',
					label: 'Overlay settings',
					path: 'alerts',
					...extra,
				},
			],
		} );

		expect(
			parseConfig(
				raw( { group: 'alerts', group_label: 'Alerts' } ),
				ORIGIN
			)?.sections[ 1 ]
		).toEqual( {
			id: 'alerts',
			label: 'Overlay settings',
			path: 'alerts',
			group: ALERTS,
		} );
		expect( parseConfig( raw( {} ), ORIGIN )?.sections[ 1 ] ).toEqual( {
			id: 'alerts',
			label: 'Overlay settings',
			path: 'alerts',
		} );
		expect(
			parseConfig(
				raw( { group: 'Bad group', group_label: 'X' } ),
				ORIGIN
			)
		).toBeNull();
		expect( parseConfig( raw( { group: 'alerts' } ), ORIGIN ) ).toBeNull();
	} );

	it( 'puts a group where its first section is', () => {
		expect(
			navEntries( SECTIONS ).map( ( entry ) =>
				entry.kind === 'group'
					? `${ entry.label }: ${ entry.sections
							.map( ( section ) => section.label )
							.join( ', ' ) }`
					: entry.section.label
			)
		).toEqual( [
			'Overview',
			'Alerts: Overlay settings, History',
			'Profile',
		] );
	} );

	it( 'names a group page with the group', () => {
		expect( resolvePage( 'alerts/history', SECTIONS ).title ).toBe(
			'Alerts: History'
		);
		expect( resolvePage( 'profile', SECTIONS ).title ).toBe( 'Profile' );
	} );

	it( 'opens the group on its pages and toggles it on click', async () => {
		mockApi( {
			'GET /merchboard/v1/me': () => response( 200, ME ),
		} );
		setUrl( '/creator-dashboard/' );
		rendered = render(
			<App config={ { ...CONFIG, sections: SECTIONS } } />
		);
		await flush();

		const toggle = () =>
			rendered?.container.querySelector< HTMLButtonElement >(
				'.merchboard-sidebar .merchboard-nav__toggle'
			) ?? null;
		// The controlled list directly follows its button.
		const list = () => {
			const next = toggle()?.nextElementSibling;

			return next instanceof HTMLUListElement &&
				next.id === toggle()?.getAttribute( 'aria-controls' )
				? next
				: null;
		};

		expect( toggle()?.textContent ).toBe( 'Alerts' );
		expect( toggle()?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( list()?.hidden ).toBe( true );

		click( toggle() );
		expect( toggle()?.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( list()?.hidden ).toBe( false );
		expect(
			Array.from( list()?.querySelectorAll( 'a' ) ?? [] ).map(
				( link ) => link.textContent
			)
		).toEqual( [ 'Overlay settings', 'History' ] );

		click( toggle() );
		expect( list()?.hidden ).toBe( true );

		rendered.unmount();
		setUrl( '/creator-dashboard/alerts/history/' );
		rendered = render(
			<App config={ { ...CONFIG, sections: SECTIONS } } />
		);
		await flush();

		expect( toggle()?.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect(
			list()?.querySelector( '[aria-current="page"]' )?.textContent
		).toBe( 'History' );
	} );
} );
