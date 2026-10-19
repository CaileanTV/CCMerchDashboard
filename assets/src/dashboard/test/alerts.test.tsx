import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseAlertsOverlay } from '../api/alerts';
import { ConfigContext } from '../app/config-context';
import {
	AlertsSection,
	maskOverlayUrl,
} from '../sections/alerts/AlertsSection';
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
	font_family: 'inherit',
};

const LAYOUT = {
	elements: {
		product_image: {
			x: 40,
			y: 880,
			w: 120,
			h: 120,
			visible: true,
			lock_aspect: true,
			border_radius: 4,
		},
		main_text: {
			x: 176,
			y: 895,
			w: 700,
			h: 80,
			visible: true,
			lock_aspect: false,
			border_radius: 0,
		},
		secondary_text: {
			x: 176,
			y: 985,
			w: 700,
			h: 50,
			visible: false,
			lock_aspect: false,
			border_radius: 0,
		},
	},
	canvas: { snap_grid: false, grid_size: 10 },
};

const ROBOTO = {
	family: 'Roboto',
	url: 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap',
};

const SOUND = {
	url: `${ ORIGIN }/wp-content/uploads/2026/10/ding.mp3`,
	name: 'ding.mp3',
	seconds: 3,
};

const AUDIO = {
	enabled: false,
	default: null,
	categories: [ { term_id: 12, name: 'Hoodies', sound: SOUND } ],
	max_bytes: 10485760,
	max_seconds: 30,
};

function body( url: string | null, extra: Record< string, unknown > = {} ) {
	return {
		overlay:
			url === null
				? null
				: {
						url,
						settings: SETTINGS,
						layout: LAYOUT,
						font_approved: true,
						audio: AUDIO,
					},
		alerts_enabled: true,
		merchalert_active: false,
		fonts: [ ROBOTO ],
		...extra,
	};
}

describe( 'response validation', () => {
	it( 'accepts an overlay and no overlay', () => {
		expect( parseAlertsOverlay( body( URL_A ) )?.overlay?.url ).toBe(
			URL_A
		);
		expect( parseAlertsOverlay( body( URL_A ) )?.overlay?.audio ).toEqual( {
			enabled: false,
			defaultSound: null,
			categories: [ { termId: 12, name: 'Hoodies', sound: SOUND } ],
			maxBytes: 10485760,
			maxSeconds: 30,
		} );
		expect( parseAlertsOverlay( body( null ) ) ).toEqual( {
			overlay: null,
			alertsEnabled: true,
			merchalertActive: false,
			fonts: [ ROBOTO ],
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
		{
			...body( URL_A ),
			fonts: [ { family: 'Roboto', url: 'https://evil.example/x.css' } ],
		},
		{
			...body( URL_A ),
			fonts: [ { family: 'Roboto;}', url: ROBOTO.url } ],
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: SETTINGS,
				font_approved: true,
				layout: {
					...LAYOUT,
					canvas: { snap_grid: false, grid_size: 0 },
				},
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: { ...SETTINGS, font_family: 'x"}' },
				font_approved: true,
				layout: LAYOUT,
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: SETTINGS,
				font_approved: true,
				layout: LAYOUT,
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: SETTINGS,
				font_approved: true,
				layout: LAYOUT,
				audio: {
					...AUDIO,
					default: { ...SOUND, url: 'https://evil.example/x.mp3' },
				},
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: SETTINGS,
				font_approved: true,
				layout: LAYOUT,
				audio: {
					...AUDIO,
					categories: [ { term_id: 0, name: 'x', sound: null } ],
				},
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
		const field = () =>
			rendered?.container.querySelector< HTMLInputElement >(
				'input[readonly]'
			)?.value;

		expect( field() ).toBe( maskOverlayUrl( URL_A ) );
		expect( field() ).not.toContain( 'a'.repeat( 32 ) );
		expect( rendered?.container.innerHTML ).not.toContain(
			'a'.repeat( 32 )
		);

		click( button( 'Show URL' ) );
		expect( field() ).toBe( URL_A );
		click( button( 'Hide URL' ) );
		expect( field() ).toBe( maskOverlayUrl( URL_A ) );

		const open = jest
			.spyOn( window, 'open' )
			.mockImplementation( () => null );
		click( button( 'Open overlay' ) );
		expect( open ).toHaveBeenCalledWith(
			URL_A,
			'_blank',
			'noopener,noreferrer'
		);
		open.mockRestore();
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

		click( button( 'Show URL' ) );
		confirm.mockReturnValueOnce( true );
		click( button( 'Create new URL' ) );
		await flush();
		expect(
			rendered?.container.querySelector< HTMLInputElement >(
				'input[readonly]'
			)?.value
		).toBe( maskOverlayUrl( URL_B ) );

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

	it( 'offers only approved fonts and keeps an unavailable font until another is chosen', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...body( URL_A ),
					overlay: {
						url: URL_A,
						settings: { ...SETTINGS, font_family: 'Lobster' },
						layout: LAYOUT,
						font_approved: false,
						audio: AUDIO,
					},
				} ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		expect( rendered?.container.textContent ).toContain(
			'Your font is no longer available'
		);
		expect(
			Array.from(
				rendered?.container.querySelectorAll< HTMLOptionElement >(
					'option'
				) ?? []
			)
				.filter( ( option ) => ! option.disabled )
				.map( ( option ) => option.value )
		).toEqual( expect.arrayContaining( [ 'inherit', 'Roboto' ] ) );

		click( button( 'Save overlay settings' ) );
		await flush();

		const patch = requests.find( ( r ) => r.method === 'PATCH' );
		expect( patch?.data ).not.toHaveProperty( 'font_family' );
	} );

	it( 'saves the designer layout', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		expect(
			rendered?.container.querySelectorAll( '.merchboard-designer__box' )
		).toHaveLength( 3 );

		click( button( 'Save layout' ) );
		await flush();

		const patch = requests.find( ( r ) => r.method === 'PATCH' );
		expect( patch?.data ).toEqual( { layout: LAYOUT } );
		expect( rendered?.container.textContent ).toContain( 'Layout saved.' );
	} );

	const choose = ( input: HTMLInputElement, file: File ) => {
		Object.defineProperty( input, 'files', {
			configurable: true,
			value: [ file ],
		} );
		act( () => {
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	};

	const fileInputs = () =>
		Array.from(
			rendered?.container.querySelectorAll< HTMLInputElement >(
				'input[type="file"]'
			) ?? []
		);

	it( 'switches sounds on and uploads a default sound', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `POST ${ PATH }/sounds/default` ]: () =>
				response( 200, body( URL_A ) ),
		} );

		await show();

		const toggle = Array.from(
			rendered?.container.querySelectorAll< HTMLInputElement >(
				'input[type="checkbox"]'
			) ?? []
		).find( ( input ) =>
			input.parentElement?.textContent?.includes( 'Play sounds' )
		);
		click( toggle ?? null );
		await flush();

		expect( requests.find( ( r ) => r.method === 'PATCH' )?.data ).toEqual(
			{ audio_enabled: true }
		);

		const file = new File( [ 'x' ], 'ding.mp3', { type: 'audio/mpeg' } );
		choose( fileInputs()[ 0 ] as HTMLInputElement, file );
		await flush();

		const upload = requests.find(
			( r ) => r.path === `${ PATH }/sounds/default`
		);
		expect( upload?.method ).toBe( 'POST' );
		expect( ( upload?.body as FormData ).get( 'file' ) ).toBe( file );
		expect( rendered?.container.textContent ).toContain( 'Sound saved.' );
		expect( rendered?.container.textContent ).toContain(
			'Category: Hoodies'
		);
		expect( rendered?.container.textContent ).toContain( 'ding.mp3 (3 s)' );
	} );

	it( 'explains a refused sound and checks the size before uploading', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `POST ${ PATH }/sounds/default` ]: () =>
				response( 400, {
					code: 'merchboard_invalid_request',
					data: { fields: { file: 'too_long' } },
				} ),
		} );

		await show();

		choose(
			fileInputs()[ 0 ] as HTMLInputElement,
			new File( [ 'x' ], 'long.wav', { type: 'audio/wav' } )
		);
		await flush();
		expect( rendered?.container.textContent ).toContain(
			'The sound is longer than 30 seconds.'
		);

		const big = new File( [ 'x' ], 'big.mp3', { type: 'audio/mpeg' } );
		Object.defineProperty( big, 'size', { value: 10485761 } );
		choose( fileInputs()[ 0 ] as HTMLInputElement, big );
		await flush();

		expect( rendered?.container.textContent ).toContain(
			'The file is larger than 10 MB.'
		);
		expect( requests.filter( ( r ) => r.method === 'POST' ) ).toHaveLength(
			1
		);
	} );

	it( 'deletes a category sound only after confirmation', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `DELETE ${ PATH }/sounds/12` ]: () =>
				response( 200, body( URL_A ) ),
		} );
		const confirm = jest
			.spyOn( window, 'confirm' )
			.mockReturnValueOnce( false )
			.mockReturnValueOnce( true );

		await show();

		click( button( 'Delete sound' ) );
		await flush();
		expect(
			requests.filter( ( r ) => r.method === 'DELETE' )
		).toHaveLength( 0 );

		click( button( 'Delete sound' ) );
		await flush();
		expect(
			requests.filter( ( r ) => r.method === 'DELETE' )
		).toHaveLength( 1 );
		expect( rendered?.container.textContent ).toContain( 'Sound deleted.' );
		confirm.mockRestore();
	} );
} );
