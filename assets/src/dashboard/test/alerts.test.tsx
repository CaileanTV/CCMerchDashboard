import { afterEach, describe, expect, it, jest } from '@jest/globals';

import { parseAlertsOverlay } from '../api/alerts';
import { ConfigContext } from '../app/config-context';
import { AlertsSection } from '../sections/alerts/AlertsSection';
import { validateOverlaySettings } from '../sections/alerts/OverlaySettingsForm';
import { mockApi, response } from '../testing/api';
import { CONFIG, ORIGIN, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/alerts/overlay';
const URL_A = `${ ORIGIN }/merchboard-alerts/overlay/${ 'a'.repeat( 32 ) }/`;
const URL_B = `${ ORIGIN }/merchboard-alerts/overlay/${ 'b'.repeat( 32 ) }/`;

const SETTINGS = {
	template: '$Product was purchased by $UserName!',
	secondary_template: '',
	font_size: 32,
	font_weight: '700',
	text_color: '#ffffff',
	text_shadow: '2px 2px 4px rgba(0,0,0,0.8)',
	text_align: 'center',
	image_size: 120,
	bg_color: '#000000',
	bg_opacity: 80,
	border_radius: 8,
	padding: 20,
	anim_duration: 500,
	display_duration: 5000,
	show_secondary: false,
};

function body( url: string | null, extra: Record< string, unknown > = {} ) {
	return {
		overlay: url === null ? null : { url, settings: SETTINGS },
		alerts_enabled: true,
		merchalert_active: false,
		...extra,
	};
}

describe( 'response validation', () => {
	it( 'accepts an overlay and no overlay', () => {
		expect( parseAlertsOverlay( body( URL_A ) )?.overlay?.url ).toBe(
			URL_A
		);
		expect( parseAlertsOverlay( body( null ) ) ).toEqual( {
			overlay: null,
			alertsEnabled: true,
			merchalertActive: false,
		} );
	} );

	it.each( [
		null,
		{},
		body( 'https://evil.example/overlay/' ),
		body( URL_A, { alerts_enabled: 'yes' } ),
		{
			...body( URL_A ),
			overlay: { url: URL_A, settings: { ...SETTINGS, font_size: 999 } },
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: { ...SETTINGS, text_color: 'red' },
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: { ...SETTINGS, show_secondary: 1 },
			},
		},
	] )( 'rejects malformed bodies %#', ( candidate ) => {
		expect( parseAlertsOverlay( candidate ) ).toBeNull();
	} );
} );

describe( 'validateOverlaySettings', () => {
	it( 'checks ranges and the main text', () => {
		const errors = validateOverlaySettings( {
			...SETTINGS,
			font_weight: '700',
			text_align: 'center',
			font_size: 500,
			display_duration: Number.NaN,
			template: '  ',
		} );

		expect( Object.keys( errors ).sort() ).toEqual( [
			'display_duration',
			'font_size',
			'template',
		] );
		expect(
			validateOverlaySettings( {
				...SETTINGS,
				font_weight: '700',
				text_align: 'center',
			} )
		).toEqual( {} );
	} );
} );

describe( 'AlertsSection', () => {
	let rendered: Rendered | null = null;

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
	} );

	const button = ( label: string ) =>
		Array.from(
			rendered?.container.querySelectorAll( 'button' ) ?? []
		).find( ( element ) => element.textContent === label ) ?? null;

	const show = async () => {
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<AlertsSection />
			</ConfigContext.Provider>
		);
		await flush();
	};

	it( 'sets the overlay up on request and shows the OBS URL', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( null ) ),
			[ `POST ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		expect(
			rendered?.container.querySelector( 'input[readonly]' )
		).toBeNull();

		click( button( 'Set up overlay' ) );
		await flush();

		expect( requests.map( ( r ) => r.method ) ).toEqual( [
			'GET',
			'POST',
		] );
		expect(
			rendered?.container.querySelector< HTMLInputElement >(
				'input[readonly]'
			)?.value
		).toBe( URL_A );
		expect(
			rendered?.container
				.querySelector( 'a[target="_blank"]' )
				?.getAttribute( 'rel' )
		).toBe( 'noopener noreferrer' );
	} );

	it( 'replaces the URL only after confirmation and sends test alerts', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `POST ${ PATH }/regenerate` ]: () =>
				response( 200, body( URL_B ) ),
			[ `POST ${ PATH }/test` ]: () => response( 200, { sent: true } ),
		} );
		const confirm = jest.spyOn( window, 'confirm' );

		await show();

		confirm.mockReturnValueOnce( false );
		click( button( 'Create new URL' ) );
		await flush();
		expect( requests ).toHaveLength( 1 );

		confirm.mockReturnValueOnce( true );
		click( button( 'Create new URL' ) );
		await flush();
		expect(
			rendered?.container.querySelector< HTMLInputElement >(
				'input[readonly]'
			)?.value
		).toBe( URL_B );

		click( button( 'Send test alert' ) );
		await flush();
		expect( requests.map( ( r ) => r.path ) ).toContain( `${ PATH }/test` );
		expect( rendered?.container.textContent ).toContain(
			'Test alert sent'
		);

		confirm.mockRestore();
	} );

	it( 'saves the settings and tells when alerts are switched off', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, body( URL_A, { alerts_enabled: false } ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		expect( rendered?.container.textContent ).toContain(
			'Purchase alerts are switched off'
		);

		click( button( 'Save overlay settings' ) );
		await flush();

		const patch = requests.find( ( r ) => r.method === 'PATCH' );
		expect( patch?.data ).toEqual( SETTINGS );
		expect( rendered?.container.textContent ).toContain(
			'Overlay settings saved.'
		);
	} );
} );
